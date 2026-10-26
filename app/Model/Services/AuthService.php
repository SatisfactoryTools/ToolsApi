<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use greeny\SatisfactoryTools\Api\Model\Entities\PasswordResetToken;
use greeny\SatisfactoryTools\Api\Model\Entities\RefreshToken;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\PasswordResetTokenRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\RefreshTokenRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimiter;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimitExceeded;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use Throwable;

class AuthService
{

	/** Verified against when the login does not exist, so a miss takes as long as a wrong password. */
	private const string TIMING_HASH = '$2y$12$y9PnUzcRqLsZeE/au1oRtOI.E9oIXGiXlvSVGAkriAPB5H2YONJ2W';

	/** Two tabs refreshing with the same token at once is normal; reuse after this long means the token leaked. */
	private const int REFRESH_REUSE_GRACE = 60;

	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly RefreshTokenRepository $refreshTokenRepository,
		private readonly PasswordResetTokenRepository $passwordResetTokenRepository,
		private readonly Passwords $passwords,
		private readonly SupporterService $supporterService,
		private readonly RateLimiter $rateLimiter,
		private readonly string $jwtSecret,
		private readonly int $accessTokenTtl,
		private readonly int $refreshTokenTtl,
	)
	{
	}

	public function register(string $login, string $email, string $password): User
	{
		// OAuth providers hand us lowercased addresses, so normalize to keep accounts unique.
		$email = strtolower($email);
		$passwordHash = $this->passwords->hash($password);

		$user = $this->userRepository->withAccountLock(function () use ($login, $email, $passwordHash): User {
			if ($this->userRepository->getByLogin($login) !== null) {
				throw new AuthException('Login already taken');
			}
			if ($this->userRepository->getByEmail($email) !== null) {
				throw new AuthException('Email already registered');
			}

			$user = new User();
			$user->login = $login;
			$user->email = $email;
			$user->passwordHash = $passwordHash;
			$user->createdAt = new DateTimeImmutable();

			$this->userRepository->save($user);

			return $user;
		});
		$this->supporterService->syncUser($user);

		return $user;
	}

	/**
	 * @return array{accessToken: string, refreshToken: string, expiresIn: int}
	 * @throws RateLimitExceeded
	 */
	public function login(string $login, string $password): array
	{
		$this->rateLimiter->assertLoginAllowed($login);

		$user = $this->userRepository->getByLogin($login);
		$valid = $this->passwords->verify($password, $user?->passwordHash ?? self::TIMING_HASH);
		if ($user === null || $user->passwordHash === null || !$valid) {
			$this->rateLimiter->recordLoginFailure($login);
			throw new AuthException('Invalid credentials');
		}

		$this->rateLimiter->clearLoginFailures($login);
		$this->supporterService->syncUser($user);

		return $this->issueTokens($user);
	}

	/** @return array{accessToken: string, refreshToken: string, expiresIn: int} */
	public function refreshTokens(string $refreshTokenValue): array
	{
		$now = new DateTimeImmutable();
		$refreshToken = $this->refreshTokenRepository->getByValue($refreshTokenValue);
		if ($refreshToken === null || $refreshToken->expiresAt < $now) {
			throw new AuthException('Invalid or expired refresh token');
		}

		$user = $refreshToken->user;
		$rotated = $refreshToken->rotatedAt === null
			? !$this->refreshTokenRepository->markRotated($refreshToken, $now)
			: true;
		if ($rotated) {
			$this->refreshTokenRepository->refresh($refreshToken);
			$rotatedAt = $refreshToken->rotatedAt ?? $now;
			if ($rotatedAt < $now->modify('-' . self::REFRESH_REUSE_GRACE . ' seconds')) {
				$this->refreshTokenRepository->deleteAllForUser($user);
				throw new AuthException('Invalid or expired refresh token');
			}
		}

		return $this->issueTokens($user);
	}

	public function logout(string $refreshTokenValue): void
	{
		$refreshToken = $this->refreshTokenRepository->getByValue($refreshTokenValue);
		if ($refreshToken !== null) {
			$this->refreshTokenRepository->delete($refreshToken);
		}
	}

	public function getUserFromAccessToken(string $token): ?User
	{
		try {
			$decoded = (array) JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
		} catch (Throwable) {
			return null;
		}

		if (($decoded['type'] ?? null) !== 'access') {
			return null;
		}

		return $this->userRepository->getByUuid($decoded['sub']);
	}

	public function forgotPassword(string $email): void
	{
		$user = $this->userRepository->getByEmail(strtolower($email));
		$token = Random::generate(64, '0-9a-zA-Z');
		if ($user === null) {
			return; // Never reveal whether an email is registered
		}

		$this->passwordResetTokenRepository->deleteAllForUser($user);

		$resetToken = new PasswordResetToken();
		$resetToken->token = self::hashToken($token);
		$resetToken->user = $user;
		$resetToken->expiresAt = new DateTimeImmutable('+1 hour');
		$resetToken->createdAt = new DateTimeImmutable();

		$this->passwordResetTokenRepository->save($resetToken);

		// TODO: dispatch email with reset link containing $token
	}

	public function resetPassword(string $tokenValue, string $newPassword): void
	{
		$resetToken = $this->passwordResetTokenRepository->getByToken(self::hashToken($tokenValue));

		if (
			$resetToken === null
			|| $resetToken->usedAt !== null
			|| $resetToken->expiresAt < new DateTimeImmutable()
		) {
			throw new AuthException('Invalid or expired password reset token');
		}

		$resetToken->user->passwordHash = $this->passwords->hash($newPassword);
		$resetToken->usedAt = new DateTimeImmutable();

		$this->userRepository->save($resetToken->user, flush: false);
		$this->passwordResetTokenRepository->save($resetToken);

		$this->refreshTokenRepository->deleteAllForUser($resetToken->user);
	}

	/** @return array{accessToken: string, refreshToken: string, expiresIn: int} */
	public function issueTokensForUser(User $user): array
	{
		return $this->issueTokens($user);
	}

	/** @return array{accessToken: string, refreshToken: string, expiresIn: int} */
	private function issueTokens(User $user): array
	{
		$now = time();
		$accessToken = JWT::encode([
			'sub' => $user->uuid->toString(),
			'iat' => $now,
			'exp' => $now + $this->accessTokenTtl,
			'type' => 'access',
		], $this->jwtSecret, 'HS256');

		$value = Random::generate(64, '0-9a-zA-Z');
		$refreshToken = new RefreshToken();
		$refreshToken->token = self::hashToken($value);
		$refreshToken->user = $user;
		$refreshToken->expiresAt = new DateTimeImmutable('+' . $this->refreshTokenTtl . ' seconds');
		$refreshToken->createdAt = new DateTimeImmutable();

		$this->refreshTokenRepository->save($refreshToken);
		$this->refreshTokenRepository->deleteStale();

		return [
			'accessToken' => $accessToken,
			'refreshToken' => $value,
			'expiresIn' => $this->accessTokenTtl,
		];
	}

	/** Tokens are stored hashed, so a leaked table holds nothing that signs anyone in. */
	public static function hashToken(string $value): string
	{
		return hash('sha256', $value);
	}

}
