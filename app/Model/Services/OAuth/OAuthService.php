<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\OAuthIdentity;
use greeny\SatisfactoryTools\Api\Model\Entities\OAuthState;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthIdentityRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthStateRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Services\AuthService;
use Nette\Utils\Random;

class OAuthService
{

	public function __construct(
		private readonly OAuthProviderRegistry $registry,
		private readonly OAuthStateRepository $stateRepository,
		private readonly OAuthIdentityRepository $identityRepository,
		private readonly UserRepository $userRepository,
		private readonly AuthService $authService,
		private readonly string $callbackBaseUrl,
		private readonly int $stateTtl,
	)
	{
	}

	/** @return list<string> */
	public function getProviderKeys(): array
	{
		return $this->registry->keys();
	}

	public function hasProvider(string $providerKey): bool
	{
		return $this->registry->has($providerKey);
	}

	/**
	 * Begin an authorization flow. When $linkUser is set the flow links the provider to
	 * that already-authenticated user rather than logging in / signing up.
	 *
	 * @return array{authorizationUrl: string, state: string}
	 */
	public function start(string $providerKey, ?User $linkUser = null): array
	{
		$provider = $this->registry->get($providerKey);
		$this->stateRepository->deleteExpired();

		$state = new OAuthState();
		$state->state = Random::generate(48, '0-9a-zA-Z');
		$state->provider = $providerKey;
		$state->linkUser = $linkUser;
		$state->expiresAt = new DateTimeImmutable('+' . $this->stateTtl . ' seconds');
		$state->createdAt = new DateTimeImmutable();
		$this->stateRepository->save($state);

		return [
			'authorizationUrl' => $provider->getAuthorizationUrl($state->state, $this->redirectUriFor($providerKey)),
			'state' => $state->state,
		];
	}

	/**
	 * Validate the provider callback and either authenticate (login/signup) or link.
	 *
	 * @param array<string, mixed> $params the query parameters the provider sent to the callback
	 * @throws OAuthException
	 */
	public function handleCallback(string $providerKey, array $params): OAuthCallbackResult
	{
		$provider = $this->registry->get($providerKey);

		$stateValue = (string) ($params['state'] ?? '');
		if ($stateValue === '') {
			throw new OAuthException('Missing OAuth state');
		}

		$state = $this->stateRepository->getByState($stateValue);
		if ($state === null || $state->provider !== $providerKey || $state->expiresAt < new DateTimeImmutable()) {
			if ($state !== null) {
				$this->stateRepository->delete($state);
			}
			throw new OAuthException('Invalid or expired OAuth state');
		}

		$linkUser = $state->linkUser;
		// State is single-use: consume it before doing anything else.
		$this->stateRepository->delete($state);

		$info = $provider->fetchUserInfo($params, $this->redirectUriFor($providerKey));

		return $linkUser !== null
			? $this->link($linkUser, $providerKey, $info)
			: $this->loginOrSignup($providerKey, $info);
	}

	/**
	 * @return list<array{provider: string, email: string|null, connectedAt: string, canDisconnect: bool}>
	 */
	public function listConnections(User $user): array
	{
		$identities = $this->identityRepository->findAllForUser($user);

		// A password-less account must keep at least one connection, so its single remaining
		// connection is not removable. With a password (or 2+ connections) any can be removed.
		$removable = $user->passwordHash !== null || count($identities) > 1;

		$result = [];
		foreach ($identities as $identity) {
			$result[] = [
				'provider' => $identity->provider,
				'email' => $identity->email,
				'connectedAt' => $identity->createdAt->format(DATE_ATOM),
				'canDisconnect' => $removable,
			];
		}

		return $result;
	}

	/** @throws OAuthException */
	public function unlink(User $user, string $providerKey): void
	{
		if (!$this->registry->has($providerKey)) {
			throw new OAuthException('Unknown OAuth provider: ' . $providerKey);
		}

		$identity = $this->identityRepository->getByUserAndProvider($user, $providerKey);
		if ($identity === null) {
			throw new OAuthException('No ' . $providerKey . ' connection to remove');
		}

		// Guard against locking the user out: a password-less account must keep at least
		// one provider connection.
		if ($user->passwordHash === null && $this->identityRepository->countForUser($user) <= 1) {
			throw new OAuthException('Cannot remove your only sign-in method. Set a password first.');
		}

		$this->identityRepository->delete($identity);
	}

	/** @throws OAuthException */
	private function link(User $user, string $providerKey, OAuthUserInfo $info): OAuthCallbackResult
	{
		$existing = $this->identityRepository->getByProviderAccount($providerKey, $info->providerUserId);
		if ($existing !== null) {
			if ($existing->user->id === $user->id) {
				return OAuthCallbackResult::linkedTo($user, $providerKey); // already linked — idempotent
			}
			throw new OAuthException('This ' . $providerKey . ' account is already linked to another user');
		}

		if ($this->identityRepository->getByUserAndProvider($user, $providerKey) !== null) {
			throw new OAuthException('Your account already has a ' . $providerKey . ' connection');
		}

		$this->createIdentity($user, $providerKey, $info);

		return OAuthCallbackResult::linkedTo($user, $providerKey);
	}

	/** @throws OAuthException */
	private function loginOrSignup(string $providerKey, OAuthUserInfo $info): OAuthCallbackResult
	{
		$identity = $this->identityRepository->getByProviderAccount($providerKey, $info->providerUserId);
		if ($identity !== null) {
			return OAuthCallbackResult::authenticated(
				$identity->user,
				$this->authService->issueTokensForUser($identity->user),
				$providerKey,
			);
		}

		// No identity yet — matching and account creation both require an email.
		if ($info->email === null) {
			throw new OAuthException(
				ucfirst($providerKey) . ' does not provide an email address, so it cannot be used to sign up. '
				. 'Please sign in with another method first, then link ' . ucfirst($providerKey) . ' from your account settings.',
			);
		}

		$user = $this->userRepository->getByEmail($info->email) ?? $this->createUserFromOAuth($info->email);
		$this->createIdentity($user, $providerKey, $info);

		return OAuthCallbackResult::authenticated(
			$user,
			$this->authService->issueTokensForUser($user),
			$providerKey,
		);
	}

	private function createIdentity(User $user, string $providerKey, OAuthUserInfo $info): OAuthIdentity
	{
		$identity = new OAuthIdentity();
		$identity->provider = $providerKey;
		$identity->providerUserId = $info->providerUserId;
		$identity->user = $user;
		$identity->email = $info->email;
		$identity->createdAt = new DateTimeImmutable();

		$this->identityRepository->save($identity);

		return $identity;
	}

	private function createUserFromOAuth(string $email): User
	{
		$user = new User();
		$user->login = $this->generateUniqueLogin($email);
		$user->email = strtolower($email);
		$user->passwordHash = null;
		$user->createdAt = new DateTimeImmutable();

		$this->userRepository->save($user);

		return $user;
	}

	private function generateUniqueLogin(string $email): string
	{
		$base = (string) preg_replace('/[^a-zA-Z0-9_.-]/', '', explode('@', $email)[0]);
		$base = substr($base, 0, 40);
		if ($base === '') {
			$base = 'user';
		}

		$login = $base;
		$suffix = 0;
		while ($this->userRepository->getByLogin($login) !== null) {
			$suffix++;
			$login = $base . $suffix;
		}

		return $login;
	}

	private function redirectUriFor(string $providerKey): string
	{
		return rtrim($this->callbackBaseUrl, '/') . '/' . $providerKey;
	}

}
