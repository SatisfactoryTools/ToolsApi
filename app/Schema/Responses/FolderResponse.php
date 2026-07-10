<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\Folder;

class FolderResponse
{

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $version,
		public readonly ?string $parent,
		public readonly string $data,
		public readonly string $createdAt,
		public readonly int $revision,
	)
	{
	}

	public static function fromEntity(Folder $folder): static
	{
		return new static(
			id: $folder->uuid->toString(),
			name: $folder->name,
			version: $folder->version->uuid->toString(),
			parent: $folder->parent?->uuid->toString(),
			data: $folder->data,
			createdAt: $folder->createdAt->format('c'),
			revision: $folder->revision,
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

}
