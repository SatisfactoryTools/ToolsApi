<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use JsonException;
use Nette\Caching\Cache;
use Nette\Caching\Storage;

class VersionMetaIndex
{

	/** Mirrors the frontend's IconUrlService. */
	private const string ICON_PATTERN = '~^[A-Za-z0-9_-]+$~D';

	private readonly Cache $cache;

	public function __construct(
		Storage $storage,
		private readonly string $wwwDir,
	)
	{
		$this->cache = new Cache($storage, 'meta-index');
	}

	/**
	 * @return array{
	 *     items: array<string, array{name: string, description: string, icon?: string}>,
	 *     buildings: array<string, array{name: string, description: string, icon?: string}>,
	 *     schematics: array<string, array{name: string, description: string, icon?: string}>,
	 *     recipes: array<string, array{name: string, alternate: bool, summary: string, icon?: string}>
	 * }|null
	 */
	public function forVersion(Version $version): ?array
	{
		$key = sha1($version->dataPath);
		$index = $this->cache->load($key);
		if (is_array($index)) {
			return $index;
		}

		$file = rtrim($this->wwwDir, '/') . '/' . ltrim($version->dataPath, '/');
		if (!is_file($file)) {
			return null;
		}

		$index = $this->build($file);
		if ($index === null) {
			return null;
		}

		// No expiry: the key changes when the file does; the file dependency covers in-place rewrites.
		$this->cache->save($key, $index, [Cache::Files => $file]);

		return $index;
	}

	/** @return array<string, mixed>|null */
	private function build(string $file): ?array
	{
		$contents = @file_get_contents($file); // @ intentional
		if ($contents === false) {
			return null;
		}

		try {
			$json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}
		if (!is_array($json)) {
			return null;
		}
		$data = is_array($json['data'] ?? null) ? $json['data'] : $json;

		$items = $this->entities($data['items'] ?? []);
		$buildings = $this->entities($data['buildings'] ?? []);

		return [
			'items' => $items,
			'buildings' => $buildings,
			'schematics' => $this->entities($data['schematics'] ?? []),
			'recipes' => $this->recipes($data['recipes'] ?? [], $items, $buildings),
		];
	}

	/** @return array<string, array{name: string, description: string, icon?: string}> */
	private function entities(mixed $entities): array
	{
		$index = [];
		foreach (is_array($entities) ? $entities : [] as $className => $entity) {
			if (!is_array($entity) || !is_string($entity['name'] ?? null)) {
				continue;
			}
			$entry = [
				'name' => $entity['name'],
				'description' => MetaText::description(is_string($entity['description'] ?? null) ? $entity['description'] : ''),
			];
			$icon = $entity['icon'] ?? null;
			if (is_string($icon) && preg_match(self::ICON_PATTERN, $icon) === 1) {
				$entry['icon'] = $icon;
			}
			$index[(string) $className] = $entry;
		}

		return $index;
	}

	/**
	 * @param array<string, array{name: string, description: string, icon?: string}> $items
	 * @param array<string, array{name: string, description: string, icon?: string}> $buildings
	 * @return array<string, array{name: string, alternate: bool, summary: string, icon?: string}>
	 */
	private function recipes(mixed $recipes, array $items, array $buildings): array
	{
		$index = [];
		foreach (is_array($recipes) ? $recipes : [] as $className => $recipe) {
			if (!is_array($recipe) || !is_string($recipe['name'] ?? null)) {
				continue;
			}

			$summary = sprintf(
				'%s → %s',
				$this->amounts($recipe['ingredients'] ?? [], $items, $buildings),
				$this->amounts($recipe['products'] ?? [], $items, $buildings),
			);
			$place = $this->place($recipe, $buildings);
			$time = is_numeric($recipe['time'] ?? null) ? (float) $recipe['time'] : null;
			if ($place !== null) {
				$summary .= ' in ' . $place;
			}
			if ($time !== null) {
				$summary .= ', ' . self::duration($time);
			}

			$entry = [
				'name' => $recipe['name'],
				'alternate' => ($recipe['alternate'] ?? false) === true,
				'summary' => $summary,
			];
			$firstProduct = $recipe['products'][0]['item'] ?? null;
			if (is_string($firstProduct)) {
				$icon = $items[$firstProduct]['icon'] ?? $buildings[$firstProduct]['icon'] ?? null;
				if ($icon !== null) {
					$entry['icon'] = $icon;
				}
			}
			$index[(string) $className] = $entry;
		}

		return $index;
	}

	/**
	 * @param array<string, array{name: string}> $items
	 * @param array<string, array{name: string}> $buildings
	 */
	private function amounts(mixed $amounts, array $items, array $buildings): string
	{
		$parts = [];
		foreach (is_array($amounts) ? $amounts : [] as $amount) {
			$className = $amount['item'] ?? null;
			if (!is_string($className)) {
				continue;
			}
			$name = $items[$className]['name'] ?? $buildings[$className]['name'] ?? $className;
			$parts[] = sprintf('%s× %s', self::number((float) ($amount['amount'] ?? 0)), $name);
		}

		return $parts === [] ? 'nothing' : implode(' + ', $parts);
	}

	/**
	 * @param array<string, mixed> $recipe
	 * @param array<string, array{name: string}> $buildings
	 */
	private function place(array $recipe, array $buildings): ?string
	{
		$machine = $recipe['producedIn'][0] ?? null;
		if (is_string($machine) && isset($buildings[$machine])) {
			return $buildings[$machine]['name'];
		}
		if (($recipe['inCraftBench'] ?? false) === true) {
			return 'Craft Bench';
		}
		if (($recipe['inEquipmentWorkshop'] ?? false) === true) {
			return 'Equipment Workshop';
		}

		return null;
	}

	/** Mirrors the frontend's RateFormatter.duration(). */
	private static function duration(float $seconds): string
	{
		if ($seconds < 60) {
			return self::number($seconds) . ' s';
		}
		$minutes = (int) floor($seconds / 60);
		$rest = $seconds - $minutes * 60;

		return $rest > 0 ? sprintf('%d min %s s', $minutes, self::number($rest)) : sprintf('%d min', $minutes);
	}

	private static function number(float $value): string
	{
		$formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

		return $formatted === '-0' ? '0' : $formatted;
	}

}
