<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

use greeny\SatisfactoryTools\Api\Model\Entities\HelpArticle;

/**
 * An article as the editor sees it - drafts included, with everything needed to edit it.
 * Readers do not use this; they get the static snapshot files instead.
 */
class HelpArticleResponse
{

	/**
	 * @param string[] $keywords
	 * @param array<string, string> $topics
	 * @param string[] $seeAlso
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $slug,
		public readonly string $title,
		public readonly string $summary,
		/** Null in list responses, where bodies would only be dead weight. */
		public readonly ?string $body,
		public readonly array $keywords,
		public readonly object $topics,
		public readonly array $seeAlso,
		public readonly ?string $category,
		public readonly int $position,
		public readonly bool $published,
		public readonly ?string $author,
		public readonly string $createdAt,
		public readonly string $updatedAt,
	)
	{
	}

	public static function fromEntity(HelpArticle $article, bool $includeBody = true): self
	{
		return new self(
			id: $article->uuid->toString(),
			slug: $article->slug,
			title: $article->title,
			summary: $article->summary,
			body: $includeBody ? $article->body : null,
			keywords: array_values($article->keywords),
			topics: (object) $article->topics,
			seeAlso: array_values($article->seeAlso),
			category: $article->category?->uuid->toString(),
			position: $article->position,
			published: $article->published,
			author: $article->author?->displayName ?? $article->author?->login,
			createdAt: $article->createdAt->format('c'),
			updatedAt: $article->updatedAt->format('c'),
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

}
