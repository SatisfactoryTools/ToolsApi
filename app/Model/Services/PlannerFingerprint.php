<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

/**
 * Hashes the part of a version data file the planner depends on, so game builds that only change
 * cosmetics (icons, names, descriptions, stack sizes) can share one version.
 */
class PlannerFingerprint
{

	/** Everything not listed counts as planner data, so a field the parser starts emitting errs on the side of a new version. */
	private const array COSMETIC_FIELDS = [
		'items' => [
			'icon', 'smallIcon', 'bigIcon', 'name', 'description', 'abbr', 'stackSize', 'fluidColor', 'gasColor', 'canBeTrashed',
			'consumable', 'healthGain', 'equipSlot', 'compatibleWeapons', 'compatibleAmmo', 'magazineSize', 'fireRate', 'minShots',
			'maxShots', 'reloadTime',
		],
		'recipes' => ['name', 'manualCraftingMultiplier'],
		'buildings' => ['icon', 'name', 'description', 'allowColoring', 'allowPatterning'],
		'schematics' => ['icon', 'name', 'description', 'unlock.emotes', 'unlock.tapes'],
		'world' => ['gameVersion'],
	];

	private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/** @param array<string, mixed> $file */
	public function hash(array $file): string
	{
		return hash('sha256', json_encode($this->project($file), self::JSON_FLAGS));
	}

	/**
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $new
	 * @return array{count: int, changes: string[]}
	 */
	public function diff(array $old, array $new, int $limit = 20): array
	{
		$before = $this->project($old);
		$after = $this->project($new);

		$changes = [];
		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $section) {
			$a = $before[$section] ?? null;
			$b = $after[$section] ?? null;
			if ($this->encode($a) === $this->encode($b)) {
				continue;
			}

			if ($a === null || $b === null) {
				$changes[] = $section . ($a === null ? ' added' : ' removed');
				continue;
			}
			if (!$this->isMap($a) || !$this->isMap($b)) {
				$changes[] = $section . ' changed';
				continue;
			}

			foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
				$path = $section . '.' . $key;
				if (!array_key_exists($key, $a)) {
					$changes[] = $path . ' added';
				} elseif (!array_key_exists($key, $b)) {
					$changes[] = $path . ' removed';
				} elseif ($this->encode($a[$key]) !== $this->encode($b[$key])) {
					$changes[] = $path . ': ' . $this->describeFields($a[$key], $b[$key]);
				}
			}
		}

		return ['count' => count($changes), 'changes' => array_slice($changes, 0, $limit)];
	}

	/**
	 * @param array<string, mixed> $file
	 * @return array<string, mixed>
	 */
	private function project(array $file): array
	{
		$sections = is_array($file['data'] ?? null) ? $file['data'] : [];
		$world = $file['metadata']['world'] ?? null;
		if (is_array($world)) {
			$sections['world'] = $world;
		}

		$projection = [];
		foreach ($sections as $section => $entries) {
			$cosmetic = self::COSMETIC_FIELDS[$section] ?? [];
			if ($section === 'world') {
				$entries = $this->strip($entries, $cosmetic);
			} elseif ($cosmetic !== [] && $this->isMap($entries)) {
				foreach ($entries as $className => $entry) {
					if (is_array($entry)) {
						$entries[$className] = $this->strip($entry, $cosmetic);
					}
				}
			}
			$projection[$section] = $this->canonicalize($entries);
		}

		return $this->canonicalize($projection);
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param string[] $paths
	 * @return array<string, mixed>
	 */
	private function strip(array $entry, array $paths): array
	{
		foreach ($paths as $path) {
			[$field, $nested] = array_pad(explode('.', $path, 2), 2, null);
			if ($nested === null) {
				unset($entry[$field]);
			} elseif (is_array($entry[$field] ?? null)) {
				unset($entry[$field][$nested]);
			}
		}

		return $entry;
	}

	/** Key and list order carry no meaning for the planner, so neither may change the hash. */
	private function canonicalize(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$value = array_map($this->canonicalize(...), $value);
		if (array_is_list($value)) {
			usort($value, fn (mixed $a, mixed $b): int => strcmp($this->encode($a), $this->encode($b)));
		} else {
			ksort($value, SORT_STRING);
		}

		return $value;
	}

	private function describeFields(mixed $a, mixed $b): string
	{
		if (!$this->isMap($a) || !$this->isMap($b)) {
			return $this->describeValue($a) . ' → ' . $this->describeValue($b);
		}

		$fields = [];
		foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $field) {
			$before = $a[$field] ?? null;
			$after = $b[$field] ?? null;
			if ($this->encode($before) === $this->encode($after)) {
				continue;
			}
			$fields[] = is_array($before) || is_array($after)
				? $field
				: $field . ' ' . $this->describeValue($before) . ' → ' . $this->describeValue($after);
		}

		return implode(', ', $fields);
	}

	private function describeValue(mixed $value): string
	{
		return is_array($value) ? '[…]' : $this->encode($value);
	}

	private function isMap(mixed $value): bool
	{
		return is_array($value) && ($value === [] || !array_is_list($value));
	}

	private function encode(mixed $value): string
	{
		return json_encode($value, self::JSON_FLAGS);
	}

}
