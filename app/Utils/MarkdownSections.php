<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

/** The anchor algorithm is mirrored by HelpMarkdownRenderer in the frontend - ids must match, or deep links break. */
class MarkdownSections
{

	/**
	 * Level 1 is skipped, it's the article title which the reader renders itself.
	 * @return array<int, array{id: string, title: string, level: int}>
	 */
	public static function extract(string $markdown): array
	{
		$sections = [];
		$used = [];
		$inFence = false;

		foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
			if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
				$inFence = !$inFence;
				continue;
			}
			if ($inFence || preg_match('/^(#{2,3})\s+(.+?)\s*#*\s*$/', $line, $match) !== 1) {
				continue;
			}

			$title = self::plainText($match[2]);
			$id = self::anchor($title);
			if ($id === '') {
				continue;
			}

			// Two headings with the same text get -2, -3… suffixes, like GitHub.
			$used[$id] = ($used[$id] ?? 0) + 1;
			if ($used[$id] > 1) {
				$id .= '-' . $used[$id];
			}

			$sections[] = ['id' => $id, 'title' => $title, 'level' => strlen($match[1])];
		}

		return $sections;
	}

	public static function anchor(string $title): string
	{
		$anchor = strtolower($title);
		$anchor = preg_replace('/[^a-z0-9]+/u', '-', $anchor) ?? '';
		return trim($anchor, '-');
	}

	private static function plainText(string $title): string
	{
		$title = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $title) ?? $title;
		$title = preg_replace('/[*_`]+/', '', $title) ?? $title;
		return trim($title);
	}

}
