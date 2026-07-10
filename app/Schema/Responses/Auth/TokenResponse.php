<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses\Auth;

class TokenResponse
{

	public function __construct(
		public readonly string $accessToken,
		public readonly string $refreshToken,
		public readonly int $expiresIn,
		public readonly string $tokenType = 'Bearer',
	)
	{
	}

	/** @param array{accessToken: string, refreshToken: string, expiresIn: int} $tokens */
	public static function fromTokens(array $tokens): self
	{
		return new self(
			accessToken: $tokens['accessToken'],
			refreshToken: $tokens['refreshToken'],
			expiresIn: $tokens['expiresIn'],
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'tokenType' => $this->tokenType,
			'accessToken' => $this->accessToken,
			'refreshToken' => $this->refreshToken,
			'expiresIn' => $this->expiresIn,
		];
	}

}
