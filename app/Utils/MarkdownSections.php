<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

/**
 * Pulls the headings out of an article's Markdown so the manifest can carry a table of
 * contents (and so the editor can offer anchors for the question-mark buttons) without
 * anyone parsing article bodies up front.
 *
 * The anchor algorithm is mirrored by HelpMarkdownRenderer in the frontend - the ids it
 * puts on rendered headings must match the ones listed here, or deep links break.
 */
class MarkdownSections
{

	/**
	 * Level 2 and 3 headings, in document order. Level 1 is the article title, which the
	 * reader renders itself.
	 *
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

	/** Lowercased, spaces to dashes, everything else dropped. */
	public static function anchor(string $title): string
	{
		$anchor = strtolower($title);
		$anchor = preg_replace('/[^a-z0-9]+/u', '-', $anchor) ?? '';
		return trim($anchor, '-');
	}

	/** Strips the inline Markdown a heading may contain, leaving the visible text. */
	private static function plainText(string $title): string
	{
		$title = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $title) ?? $title; // links, images
		$title = preg_replace('/[*_`]+/', '', $title) ?? $title; // emphasis, code
		return trim($title);
	}

}
