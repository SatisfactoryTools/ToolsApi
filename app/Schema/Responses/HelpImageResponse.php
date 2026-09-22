<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\HelpImage;

class HelpImageResponse
{

	public function __construct(
		public readonly string $id,
		public readonly string $fileName,
		/** Web-root-relative path; the frontend resolves it against the API base URL. */
		public readonly string $path,
		public readonly string $uploadedAt,
	)
	{
	}

	public static function fromEntity(HelpImage $image): self
	{
		return new self(
			id: $image->uuid->toString(),
			fileName: $image->fileName,
			path: $image->path,
			uploadedAt: $image->uploadedAt->format('c'),
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

}
