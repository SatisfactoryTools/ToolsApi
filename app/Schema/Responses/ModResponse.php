<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\Mod;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

class ModResponse
{

	/** @param array<int, array<string, mixed>> $versions */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly bool $public,
		public readonly bool $owned,
		public readonly string $createdAt,
		public readonly array $versions,
	)
	{
	}

	public static function fromEntity(Mod $mod, ?User $viewer): self
	{
		return new self(
			id: $mod->uuid->toString(),
			name: $mod->name,
			public: $mod->public,
			owned: $viewer !== null && $mod->user->id === $viewer->id,
			createdAt: $mod->createdAt->format('c'),
			versions: array_map(
				static fn (ModVersion $version): array => ModVersionResponse::fromEntity($version)->toArray(),
				$mod->versions->toArray(),
			),
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

}
