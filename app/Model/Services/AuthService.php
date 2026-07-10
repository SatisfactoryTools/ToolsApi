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
use Nette\Security\Passwords;
use Nette\Utils\Random;
use Throwable;

class AuthService
{

	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly RefreshTokenRepository $refreshTokenRepository,
		private readonly PasswordResetTokenRepository $passwordResetTokenRepository,
		private readonly Passwords $passwords,
		private readonly string $jwtSecret,
		private readonly int $accessTokenTtl,
		private readonly int $refreshTokenTtl,
	)
	{
	}

	public function register(string $login, string $email, string $password): User
	{
		// Emails are matched case-insensitively (and OAuth providers hand us lowercased
		// addresses), so normalize before storing and comparing to keep accounts unique.
		$email = strtolower($email);

		if ($this->userRepository->getByLogin($login) !== null) {
			throw new AuthException('Login already taken');
		}
		if ($this->userRepository->getByEmail($email) !== null) {
			throw new AuthException('Email already registered');
		}

		$user = new User();
		$user->login = $login;
		$user->email = $email;
		$user->passwordHash = $this->passwords->hash($password);
		$user->createdAt = new DateTimeImmutable();

		$this->userRepository->save($user);

		return $user;
	}

	/** @return array{accessToken: string, refreshToken: string, expiresIn: int} */
	public function login(string $login, string $password): array
	{
		$user = $this->userRepository->getByLogin($login);
		if ($user === null || $user->passwordHash === null || !$this->passwords->verify($password, $user->passwordHash)) {
			throw new AuthException('Invalid credentials');
		}

		return $this->issueTokens($user);
	}

	/** @return array{accessToken: string, refreshToken: string, expiresIn: int} */
	public function refreshTokens(string $refreshTokenValue): array
	{
		$refreshToken = $this->refreshTokenRepository->getByToken($refreshTokenValue);
		if ($refreshToken === null || $refreshToken->expiresAt < new DateTimeImmutable()) {
			throw new AuthException('Invalid or expired refresh token');
		}

		$user = $refreshToken->user;

		// Rotate: invalidate old refresh token before issuing new one
		$this->refreshTokenRepository->delete($refreshToken);

		return $this->issueTokens($user);
	}

	public function logout(string $refreshTokenValue): void
	{
		$refreshToken = $this->refreshTokenRepository->getByToken($refreshTokenValue);
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
		if ($user === null) {
			return; // Never reveal whether an email is registered
		}

		$this->passwordResetTokenRepository->deleteAllForUser($user);

		$resetToken = new PasswordResetToken();
		$resetToken->token = Random::generate(64, '0-9a-zA-Z');
		$resetToken->user = $user;
		$resetToken->expiresAt = new DateTimeImmutable('+1 hour');
		$resetToken->createdAt = new DateTimeImmutable();

		$this->passwordResetTokenRepository->save($resetToken);

		// TODO: dispatch email with reset link containing $resetToken->token
	}

	/**
	 * @throws AuthException
	 */
	public function resetPassword(string $tokenValue, string $newPassword): void
	{
		$resetToken = $this->passwordResetTokenRepository->getByToken($tokenValue);

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

		// Resetting the password invalidates every existing session: if the account was
		// compromised, this logs the attacker out everywhere.
		$this->refreshTokenRepository->deleteAllForUser($resetToken->user);
	}

	/**
	 * Issue a fresh access/refresh token pair for a user. Used by the OAuth flow after a
	 * successful third-party login or signup.
	 *
	 * @return array{accessToken: string, refreshToken: string, expiresIn: int}
	 */
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

		$refreshToken = new RefreshToken();
		$refreshToken->token = Random::generate(64, '0-9a-zA-Z');
		$refreshToken->user = $user;
		$refreshToken->expiresAt = new DateTimeImmutable('+' . $this->refreshTokenTtl . ' seconds');
		$refreshToken->createdAt = new DateTimeImmutable();

		$this->refreshTokenRepository->save($refreshToken);

		return [
			'accessToken' => $accessToken,
			'refreshToken' => $refreshToken->token,
			'expiresIn' => $this->accessTokenTtl,
		];
	}

}
