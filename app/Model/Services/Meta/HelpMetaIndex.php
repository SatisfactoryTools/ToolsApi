<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

use greeny\SatisfactoryTools\Api\Model\Repositories\HelpArticleRepository;
use JsonException;

/** Published help articles, from the static snapshot when it exists, else from the database. */
class HelpMetaIndex
{

	private const string SNAPSHOT_INDEX = 'data/help/index.json';

	/** @var array<string, array{title: string, summary: string}>|null */
	private ?array $articles = null;

	public function __construct(
		private readonly HelpArticleRepository $helpArticleRepository,
		private readonly string $wwwDir,
	)
	{
	}

	/** @return array{title: string, summary: string}|null */
	public function get(string $slug): ?array
	{
		return $this->all()[$slug] ?? null;
	}

	/** @return array<string, array{title: string, summary: string}> */
	public function all(): array
	{
		return $this->articles ??= $this->fromSnapshot() ?? $this->fromDatabase();
	}

	/** @return array<string, array{title: string, summary: string}>|null */
	private function fromSnapshot(): ?array
	{
		$file = rtrim($this->wwwDir, '/') . '/' . self::SNAPSHOT_INDEX;
		$contents = is_file($file) ? @file_get_contents($file) : false; // @ intentional
		if ($contents === false) {
			return null;
		}
		try {
			$manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}

		$articles = [];
		foreach (is_array($manifest['articles'] ?? null) ? $manifest['articles'] : [] as $slug => $entry) {
			if (is_array($entry) && is_string($entry['title'] ?? null)) {
				$articles[(string) $slug] = [
					'title' => $entry['title'],
					'summary' => is_string($entry['summary'] ?? null) ? $entry['summary'] : '',
				];
			}
		}

		return $articles;
	}

	/** @return array<string, array{title: string, summary: string}> */
	private function fromDatabase(): array
	{
		$articles = [];
		foreach ($this->helpArticleRepository->getPublished() as $article) {
			$articles[$article->slug] = ['title' => $article->title, 'summary' => $article->summary];
		}

		return $articles;
	}

}
