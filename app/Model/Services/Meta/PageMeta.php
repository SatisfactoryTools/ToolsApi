<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

final class PageMeta
{

	/** @param list<PageLink> $links */
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly ?string $image,
		public readonly string $kind,
		public readonly ?string $heading = null,
		public readonly ?string $canonicalPath = null,
		public readonly bool $indexable = true,
		public readonly array $links = [],
		public readonly ?string $redirect = null,
	)
	{
	}

	public static function redirect(string $path): self
	{
		return new self('', '', null, 'redirect', redirect: $path);
	}

	/** @param list<PageLink> $links */
	public function indexedAs(string $canonicalPath, array $links): self
	{
		return new self($this->title, $this->description, $this->image, $this->kind, $this->heading, $canonicalPath, true, $links);
	}

	public function notIndexed(): self
	{
		return new self($this->title, $this->description, $this->image, $this->kind, $this->heading, null, false);
	}

	/**
	 * @return array{
	 *     title: string, description: string, image: string|null, kind: string, heading: string|null,
	 *     canonicalPath: string|null, indexable: bool, links: list<array{href: string, label: string}>, redirect: string|null
	 * }
	 */
	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'image' => $this->image,
			'kind' => $this->kind,
			'heading' => $this->heading,
			'canonicalPath' => $this->canonicalPath,
			'indexable' => $this->indexable,
			'links' => array_map(static fn (PageLink $link): array => $link->toArray(), $this->links),
			'redirect' => $this->redirect,
		];
	}

}
