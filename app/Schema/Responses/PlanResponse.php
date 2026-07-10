<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\Plan;

/** @extends ArrayableResponse<Plan> */
class PlanResponse extends ArrayableResponse
{

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly ?string $description,
		public readonly string $version,
		public readonly ?string $folder,
		public readonly ?string $parent,
		public readonly string $data,
		public readonly string $createdAt,
		public readonly int $revision,
	)
	{
	}

	public static function fromEntity(object $entity): static
	{
		return new static(
			id: $entity->uuid->toString(),
			name: $entity->name,
			description: $entity->description,
			version: $entity->version->uuid->toString(),
			folder: $entity->folder?->uuid->toString(),
			parent: $entity->parent?->uuid->toString(),
			data: $entity->data,
			createdAt: $entity->createdAt->format('c'),
			revision: $entity->revision,
		);
	}

}
