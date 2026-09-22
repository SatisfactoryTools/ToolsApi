<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\HelpCategory;

class HelpCategoryResponse
{

	public function __construct(
		public readonly string $id,
		public readonly string $slug,
		public readonly string $name,
		public readonly int $position,
		public readonly int $articleCount,
	)
	{
	}

	public static function fromEntity(HelpCategory $category): self
	{
		return new self(
			id: $category->uuid->toString(),
			slug: $category->slug,
			name: $category->name,
			position: $category->position,
			articleCount: $category->articles->count(),
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

}
