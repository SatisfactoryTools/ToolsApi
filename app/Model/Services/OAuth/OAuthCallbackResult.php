<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use greeny\SatisfactoryTools\Api\Model\Entities\OAuthState;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

class OAuthCallbackResult
{

	/** @param array{accessToken: string, refreshToken: string, expiresIn: int}|null $tokens */
	private function __construct(
		public readonly bool $linked,
		public readonly User $user,
		public readonly string $provider,
		public readonly ?array $tokens,
		public readonly ?OAuthState $desktopState = null,
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

	public function forDesktop(OAuthState $state): self
	{
		return new self($this->linked, $this->user, $this->provider, $this->tokens, $state);
	}

}
