<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses\Auth;

use greeny\SatisfactoryTools\Api\Model\Entities\OAuthIdentity;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

class ProfileResponse
{

	/** @param list<array{provider: string, nickname: string|null, avatarUrl: string|null}> $connections */
	public function __construct(
		public readonly string $id,
		public readonly string $login,
		public readonly string $email,
		public readonly ?string $displayName,
		public readonly string $name,
		public readonly ?string $avatarUrl,
		public readonly bool $hasPassword,
		public readonly bool $helpEditor,
		public readonly string $createdAt,
		public readonly array $connections,
	)
	{
	}

	/** @param list<OAuthIdentity> $identities */
	public static function fromUser(User $user, array $identities): self
	{
		$connections = [];
		$providerNickname = null;
		$avatarUrl = null;
		foreach ($identities as $identity) {
			$connections[] = [
				'provider' => $identity->provider,
				'nickname' => $identity->nickname,
				'avatarUrl' => $identity->avatarUrl,
			];
			$providerNickname ??= $identity->nickname;
			$avatarUrl ??= $identity->avatarUrl;
		}

		$hasPassword = $user->passwordHash !== null;

		return new self(
			id: $user->uuid->toString(),
			login: $user->login,
			email: $user->email,
			displayName: $user->displayName,
			name: $user->displayName ?? ($hasPassword ? $user->login : ($providerNickname ?? $user->login)),
			avatarUrl: $avatarUrl,
			hasPassword: $hasPassword,
			helpEditor: $user->helpEditor,
			createdAt: $user->createdAt->format('c'),
			connections: $connections,
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'login' => $this->login,
			'email' => $this->email,
			'displayName' => $this->displayName,
			'name' => $this->name,
			'avatarUrl' => $this->avatarUrl,
			'hasPassword' => $this->hasPassword,
			'helpEditor' => $this->helpEditor,
			'createdAt' => $this->createdAt,
			'connections' => $this->connections,
		];
	}

}
