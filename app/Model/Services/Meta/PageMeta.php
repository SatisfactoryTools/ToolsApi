<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

/**
 * Ready-to-print link preview metadata for one frontend page (GET /v1/meta). Every value
 * is plain text; the caller escapes it for HTML.
 */
final class PageMeta
{

	public function __construct(
		public readonly string $title,
		public readonly string $description,
		/** Absolute URL, or null for the default logo (the injector fills it in). */
		public readonly ?string $image,
		/** default, page, codex-section, codex-item, … - for debugging and og:type. */
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
