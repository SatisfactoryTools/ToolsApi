<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Entities\VersionModVersion;
use Ramsey\Uuid\UuidInterface;

/** @extends ArrayableResponse<Version> */
class VersionResponse extends ArrayableResponse
{

	/**
	 * @param string[] $mods mod version UUIDs in application order
	 * @param array<string, mixed>|null $worldData
	 */
	public function __construct(
		public UuidInterface $id,
		public readonly string $name,
		public readonly ?string $slug,
		public readonly bool $experimental,
		public readonly bool $custom,
		public readonly bool $official,
		public readonly bool $ficsmas,
		public readonly string $dataPath,
		public readonly ?string $baseVersion,
		public readonly float $recipeCost,
		public readonly float $powerCost,
		public readonly array $mods,
		public readonly ?array $worldData,
	)
	{
	}

	public static function fromEntity(object $entity): static
	{
		return new static(
			id: $entity->uuid,
			name: $entity->name,
			slug: $entity->slug,
			experimental: $entity->experimental,
			custom: $entity->custom,
			official: !$entity->custom,
			ficsmas: $entity->ficsmas,
			dataPath: $entity->dataPath,
			baseVersion: $entity->baseVersion?->uuid->toString(),
			recipeCost: $entity->recipeCostMultiplier,
			powerCost: $entity->powerCostMultiplier,
			mods: array_map(
				static fn (VersionModVersion $link): string => $link->modVersion->uuid->toString(),
				$entity->modVersions->toArray(),
			),
			worldData: $entity->worldData,
		);
	}

}
