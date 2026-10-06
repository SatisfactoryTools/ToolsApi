<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

final class PageMeta
{

	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly ?string $image,
		public readonly string $kind,
	)
	{
	}

	/** @return array{title: string, description: string, image: string|null, kind: string} */
	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'image' => $this->image,
			'kind' => $this->kind,
		];
	}

}
