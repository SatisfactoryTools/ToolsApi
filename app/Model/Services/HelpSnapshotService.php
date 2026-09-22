<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\HelpArticle;
use greeny\SatisfactoryTools\Api\Model\Repositories\HelpArticleRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\HelpCategoryRepository;
use greeny\SatisfactoryTools\Api\Utils\MarkdownSections;
use JsonException;
use RuntimeException;

/**
 * Writes the static files the help reader loads. The database is the source of truth and
 * only the editor touches it; every reader gets plain files from the web root, the same
 * way version and mod data are served.
 *
 *   data/help/index.json           the manifest: categories, and every published article's
 *                                  metadata (title, summary, keywords, topics, sections)
 *   data/help/articles/{slug}.json one article, body included
 *
 * The whole snapshot is rebuilt after every write - it is a handful of small files, and
 * rebuilding wholesale is the only way to keep cross-article data (see-also, category
 * membership, the topic map) consistent.
 */
class HelpSnapshotService
{

	private const DIR = 'data/help';

	public function __construct(
		private readonly HelpArticleRepository $articleRepository,
		private readonly HelpCategoryRepository $categoryRepository,
		private readonly string $wwwDir,
		private readonly string $lockDir,
	)
	{
	}

	/** Regenerates index.json and every article file, dropping files of gone articles. */
	public function rebuild(): void
	{
		$this->withLock(function (): void {
			$articles = $this->articleRepository->getPublished();
			$generatedAt = new DateTimeImmutable();

			$slugs = [];
			foreach ($articles as $article) {
				$slugs[] = $article->slug;
				$this->write(
					self::DIR . '/articles/' . $article->slug . '.json',
					$this->articleFile($article),
				);
			}

			$this->write(self::DIR . '/index.json', $this->manifest($articles, $generatedAt));
			$this->removeStaleArticles($slugs);
		});
	}

	/**
	 * The manifest, also served by `GET /v1/help` so the frontend can fall back to the
	 * API when the snapshot has not been generated yet.
	 *
	 * @param HelpArticle[] $articles
	 * @return array<string, mixed>
	 */
	public function manifest(array $articles, ?DateTimeImmutable $generatedAt = null): array
	{
		$byCategory = [];
		foreach ($articles as $article) {
			$byCategory[$article->category?->slug ?? ''][] = $article->slug;
		}

		$categories = [];
		foreach ($this->categoryRepository->getAll() as $category) {
			if (($byCategory[$category->slug] ?? []) === []) {
				continue; // an empty section would be a dead end in the menu
			}
			$categories[] = [
				'id' => $category->slug,
				'name' => $category->name,
				'articles' => $byCategory[$category->slug],
			];
		}
		if (($byCategory[''] ?? []) !== []) {
			$categories[] = ['id' => '', 'name' => 'Other', 'articles' => $byCategory['']];
		}

		$entries = [];
		foreach ($articles as $article) {
			$entries[$article->slug] = $this->summary($article);
		}

		return [
			'generatedAt' => ($generatedAt ?? new DateTimeImmutable())->format('c'),
			'categories' => $categories,
			// An object even when empty: the frontend indexes it by slug.
			'articles' => (object) $entries,
		];
	}

	/**
	 * One article as the reader receives it.
	 *
	 * @return array<string, mixed>
	 */
	public function articleFile(HelpArticle $article): array
	{
		return [
			'slug' => $article->slug,
			'title' => $article->title,
			'summary' => $article->summary,
			'body' => $article->body,
			'seeAlso' => array_values($article->seeAlso),
			'sections' => MarkdownSections::extract($article->body),
			'updatedAt' => $article->updatedAt->format('c'),
		];
	}

	/**
	 * An article's manifest entry: everything the browser, the search and the
	 * question-mark buttons need without fetching the body.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(HelpArticle $article): array
	{
		return [
			'title' => $article->title,
			'summary' => $article->summary,
			'keywords' => array_values($article->keywords),
			'topics' => (object) $article->topics,
			'sections' => MarkdownSections::extract($article->body),
			'seeAlso' => array_values($article->seeAlso),
			'category' => $article->category?->slug ?? '',
			'updatedAt' => $article->updatedAt->format('c'),
		];
	}

	/**
	 * Serializes the rebuild: two editors saving at the same moment must not interleave
	 * their writes. Same shape as CustomVersionFileService's lock, including keeping the
	 * lock file around - unlinking it would reintroduce the race it prevents.
	 */
	private function withLock(callable $callback): void
	{
		if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0o775, true) && !is_dir($this->lockDir)) {
			throw new RuntimeException(sprintf('Could not create lock directory: %s', $this->lockDir));
		}

		$handle = @fopen($this->lockDir . '/help-snapshot.lock', 'c');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			throw new RuntimeException('Could not acquire the help snapshot lock');
		}

		try {
			$callback();
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/** @param string[] $keptSlugs */
	private function removeStaleArticles(array $keptSlugs): void
	{
		$kept = array_flip($keptSlugs);
		foreach (glob($this->absolutePath(self::DIR . '/articles') . '/*.json') ?: [] as $file) {
			if (!isset($kept[basename($file, '.json')])) {
				@unlink($file);
			}
		}
	}

	/** @param array<string, mixed> $data */
	private function write(string $relativePath, array $data): void
	{
		$absolute = $this->absolutePath($relativePath);

		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		try {
			$json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException $e) {
			throw new RuntimeException('Could not encode help data', 0, $e);
		}

		// Write-then-rename, so a reader never sees a half-written file.
		$tmp = $absolute . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) {
			@unlink($tmp);
			throw new RuntimeException(sprintf('Could not write help file: %s', $relativePath));
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
