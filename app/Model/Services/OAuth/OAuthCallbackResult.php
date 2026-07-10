<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use greeny\SatisfactoryTools\Api\Model\Entities\User;

/**
 * Outcome of a callback: either the user was authenticated (login/signup, tokens issued)
 * or an already-authenticated user linked a new provider (no tokens).
 */
class OAuthCallbackResult
{

	/** @param array{accessToken: string, refreshToken: string, expiresIn: int}|null $tokens */
	private function __construct(
		public readonly bool $linked,
		public readonly User $user,
		public readonly string $provider,
		public readonly ?array $tokens,
	)
	{
	}

	/** @param array{accessToken: string, refreshToken: string, expiresIn: int} $tokens */
	public static function authenticated(User $user, array $tokens, string $provider): self
	{
		return new self(false, $user, $provider, $tokens);
	}

	public static function linkedTo(User $user, string $provider): self
	{
		return new self(true, $user, $provider, null);
	}

}
