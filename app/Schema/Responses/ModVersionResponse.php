<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;

class ModVersionResponse
{

	public function __construct(
		public readonly string $id,
		public readonly string $mod,
		public readonly string $name,
		public readonly bool $hasData,
		public readonly string $createdAt,
	)
	{
	}

	public static function fromEntity(ModVersion $modVersion): self
	{
		return new self(
			id: $modVersion->uuid->toString(),
			mod: $modVersion->mod->uuid->toString(),
			name: $modVersion->name,
			hasData: $modVersion->dataPath !== null,
			createdAt: $modVersion->createdAt->format('c'),
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

}
