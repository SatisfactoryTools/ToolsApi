<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\OAuthIdentity;
use greeny\SatisfactoryTools\Api\Model\Entities\OAuthState;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthIdentityRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthStateRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Services\AuthException;
use greeny\SatisfactoryTools\Api\Model\Services\AuthService;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use Nette\Utils\Random;

class OAuthService
{

	private const int DESKTOP_RESULT_GRACE = 60;

	public function __construct(
		private readonly OAuthProviderRegistry $registry,
		private readonly OAuthStateRepository $stateRepository,
		private readonly OAuthIdentityRepository $identityRepository,
		private readonly UserRepository $userRepository,
		private readonly AuthService $authService,
		private readonly SupporterService $supporterService,
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

	/** @return array{authorizationUrl: string, state: string, pollToken?: string} */
	public function start(string $providerKey, ?User $linkUser = null, bool $desktop = false): array
	{
		$provider = $this->registry->get($providerKey);
		$this->stateRepository->deleteExpired();

		$state = new OAuthState();
		$state->state = Random::generate(48, '0-9a-zA-Z');
		$state->provider = $providerKey;
		$state->linkUser = $linkUser;
		$state->pollToken = $desktop ? Random::generate(48, '0-9a-zA-Z') : null;
		$state->expiresAt = new DateTimeImmutable('+' . $this->stateTtl . ' seconds');
		$state->createdAt = new DateTimeImmutable();
		$this->stateRepository->save($state);

		$result = [
			'authorizationUrl' => $provider->getAuthorizationUrl($state->state, $this->redirectUriFor($providerKey)),
			'state' => $state->state,
		];
		if ($state->pollToken !== null) {
			$result['pollToken'] = $state->pollToken;
		}

		return $result;
	}

	/** @param array<string, mixed> $params */
	public function handleCallback(string $providerKey, array $params): OAuthCallbackResult
	{
		$provider = $this->registry->get($providerKey);

		$stateValue = (string) ($params['state'] ?? '');
		if ($stateValue === '') {
			throw new OAuthException('Missing OAuth state');
		}

		$state = $this->stateRepository->getByState($stateValue);
		if ($state !== null && $state->completedAt !== null) {
			// A finished desktop flow: refuse a second callback, but keep the stored result for the poll.
			throw new OAuthException('Invalid or expired OAuth state');
		}
		if ($state === null || $state->provider !== $providerKey || $state->expiresAt < new DateTimeImmutable()) {
			if ($state !== null) {
				$this->stateRepository->delete($state);
			}
			throw new OAuthException('Invalid or expired OAuth state');
		}

		$linkUser = $state->linkUser;

		if ($state->pollToken === null) {
			// State is single-use: consume it before doing anything else.
			$this->stateRepository->delete($state);

			return $this->exchange($provider, $providerKey, $params, $linkUser);
		}

		try {
			return $this->exchange($provider, $providerKey, $params, $linkUser)->forDesktop($state);
		} catch (AuthException $e) {
			$this->completeDesktop($state, ['error' => $e->getMessage()]);
			throw $e;
		}
	}

	/** @param array<string, mixed> $result */
	public function completeDesktop(OAuthState $state, array $result): void
	{
		$now = new DateTimeImmutable();
		$state->result = $result;
		$state->completedAt = $now;
		// Leave the app a moment to collect a result that lands just before the state expires.
		$minimumExpiry = $now->modify('+' . self::DESKTOP_RESULT_GRACE . ' seconds');
		if ($state->expiresAt < $minimumExpiry) {
			$state->expiresAt = $minimumExpiry;
		}
		$this->stateRepository->save($state);
	}

	public function findDesktopState(string $stateValue, string $pollToken): ?OAuthState
	{
		if ($stateValue === '' || $pollToken === '') {
			return null;
		}

		$state = $this->stateRepository->getByState($stateValue);
		if ($state === null || $state->pollToken === null || !hash_equals($state->pollToken, $pollToken)) {
			return null;
		}
		if ($state->expiresAt < new DateTimeImmutable()) {
			$this->stateRepository->delete($state);

			return null;
		}

		return $state;
	}

	/** @return array<string, mixed> */
	public function consumeDesktopResult(OAuthState $state): array
	{
		$result = $state->result ?? ['error' => 'Sign-in failed'];
		$this->stateRepository->delete($state);

		return $result;
	}

	/** @return list<array{provider: string, email: string|null, nickname: string|null, avatarUrl: string|null, connectedAt: string, canDisconnect: bool}> */
	public function listConnections(User $user): array
	{
		$identities = $this->identityRepository->findAllForUser($user);

		// A password-less account must keep at least one connection, so its single remaining connection is not removable.
		$removable = $user->passwordHash !== null || count($identities) > 1;

		$result = [];
		foreach ($identities as $identity) {
			$result[] = [
				'provider' => $identity->provider,
				'email' => $identity->email,
				'nickname' => $identity->nickname,
				'avatarUrl' => $identity->avatarUrl,
				'connectedAt' => $identity->createdAt->format(DATE_ATOM),
				'canDisconnect' => $removable,
			];
		}

		return $result;
	}

	public function unlink(User $user, string $providerKey): void
	{
		if (!$this->registry->has($providerKey)) {
			throw new OAuthException('Unknown OAuth provider: ' . $providerKey);
		}

		$identity = $this->identityRepository->getByUserAndProvider($user, $providerKey);
		if ($identity === null) {
			throw new OAuthException('No ' . $providerKey . ' connection to remove');
		}

		if ($user->passwordHash === null && $this->identityRepository->countForUser($user) <= 1) {
			throw new OAuthException('Cannot remove your only sign-in method. Set a password first.');
		}

		$this->identityRepository->delete($identity);
	}

	/** @param array<string, mixed> $params */
	private function exchange(OAuthProviderInterface $provider, string $providerKey, array $params, ?User $linkUser): OAuthCallbackResult
	{
		$info = $provider->fetchUserInfo($params, $this->redirectUriFor($providerKey));

		$result = $linkUser !== null
			? $this->link($linkUser, $providerKey, $info)
			: $this->loginOrSignup($providerKey, $info);

		// Every sign-in and every newly linked provider may bring an email that is on the supporter list.
		$this->supporterService->syncUser($result->user);

		return $result;
	}

	private function link(User $user, string $providerKey, OAuthUserInfo $info): OAuthCallbackResult
	{
		$existing = $this->identityRepository->getByProviderAccount($providerKey, $info->providerUserId);
		if ($existing !== null) {
			if ($existing->user->id === $user->id) {
				$this->refreshProfile($existing, $info);

				return OAuthCallbackResult::linkedTo($user, $providerKey); // already linked - idempotent
			}
			throw new OAuthException('This ' . $providerKey . ' account is already linked to another user');
		}

		if ($this->identityRepository->getByUserAndProvider($user, $providerKey) !== null) {
			throw new OAuthException('Your account already has a ' . $providerKey . ' connection');
		}

		$this->createIdentity($user, $providerKey, $info);

		return OAuthCallbackResult::linkedTo($user, $providerKey);
	}

	private function loginOrSignup(string $providerKey, OAuthUserInfo $info): OAuthCallbackResult
	{
		$identity = $this->identityRepository->getByProviderAccount($providerKey, $info->providerUserId);
		if ($identity !== null) {
			$this->refreshProfile($identity, $info);

			return OAuthCallbackResult::authenticated(
				$identity->user,
				$this->authService->issueTokensForUser($identity->user),
				$providerKey,
			);
		}

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
		$identity->nickname = $info->nickname;
		$identity->avatarUrl = $info->avatarUrl;
		$identity->createdAt = new DateTimeImmutable();

		$this->identityRepository->save($identity);

		return $identity;
	}

	/** A provider reporting nothing this time (e.g. Steam without an API key) keeps the stored nickname/avatar rather than wiping it. */
	private function refreshProfile(OAuthIdentity $identity, OAuthUserInfo $info): void
	{
		$changed = false;
		if ($info->nickname !== null && $info->nickname !== $identity->nickname) {
			$identity->nickname = $info->nickname;
			$changed = true;
		}
		if ($info->avatarUrl !== null && $info->avatarUrl !== $identity->avatarUrl) {
			$identity->avatarUrl = $info->avatarUrl;
			$changed = true;
		}
		if ($changed) {
			$this->identityRepository->save($identity);
		}
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
