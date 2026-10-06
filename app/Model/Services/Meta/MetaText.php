<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

/** Keep in sync with sftools-frontend: src/app/Model/Meta/MetaText.ts (tests/meta-text-fixtures.json). */
final class MetaText
{

	public const string SITE_NAME = 'Satisfactory Tools';
	public const string TITLE_SUFFIX = ' – ' . self::SITE_NAME;
	public const int DESCRIPTION_MAX_LENGTH = 200;

	public const string DEFAULT_DESCRIPTION = 'Plan factories that actually work. An optimising production and logistics planner for Satisfactory, supporting every factory - from the first smelter to a 1 TW nuclear grid. Includes a searchable codex, custom versions and mods.';

	/** Tag-like markup only (`<b>`, `</color>`, `<img src=…/>`), so a bare "<" in text survives. */
	private const string TAG_PATTERN = '~</?[A-Za-z][^<>]*>~u';

	public static function title(string $title): string
	{
		$title = self::collapse($title);

		return $title === '' ? self::SITE_NAME : $title . self::TITLE_SUFFIX;
	}

	public static function description(string $text): string
	{
		$text = self::collapse((string) preg_replace(self::TAG_PATTERN, ' ', $text));
		if (mb_strlen($text) <= self::DESCRIPTION_MAX_LENGTH) {
			return $text;
		}

		$cut = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 1);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false && $space > 0) {
			$cut = mb_substr($cut, 0, $space);
		}

		return (string) preg_replace('~[\s,.;:–-]+$~u', '', $cut) . '…';
	}

	private static function collapse(string $text): string
	{
		return trim((string) preg_replace('~\s+~u', ' ', $text));
	}

}
