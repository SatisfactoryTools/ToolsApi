<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use RuntimeException;

/**
 * Builds the data file for a custom version: it starts from a base version's data file,
 * merges in any mod data files, and applies the custom multipliers (recipe cost, power
 * cost) to the resulting data.
 */
class CustomVersionDataGenerator
{

	private const FLUID_FORMS = ['liquid', 'gas'];

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/**
	 * Reads the base data file, merges the mod data files (in order), applies the
	 * multipliers and writes the result to $targetRelativePath. All paths are
	 * web-root-relative, matching Version::$dataPath / ModVersion::$dataPath.
	 *
	 * @param string[] $modDataPaths mod data files to merge, in application order
	 * @param array<string, mixed>|null $worldData resource-node counts and derived limits to
	 *   record under metadata.world (null to leave the base metadata untouched)
	 */
	public function generate(
		string $baseRelativePath,
		string $targetRelativePath,
		array $modDataPaths,
		float $recipeCostMultiplier,
		float $powerCostMultiplier,
		?array $worldData = null,
	): void
	{
		$data = $this->readJson($baseRelativePath);

		foreach ($modDataPaths as $modDataPath) {
			$this->mergeMod($data, $this->readJson($modDataPath));
		}

		if ($recipeCostMultiplier !== 1.0) {
			$this->applyRecipeCost($data, $recipeCostMultiplier);
		}

		if ($powerCostMultiplier !== 1.0) {
			$this->applyPowerCost($data, $powerCostMultiplier);
		}

		if ($worldData !== null) {
			if (!is_array($data['metadata'] ?? null)) {
				$data['metadata'] = [];
			}
			$data['metadata']['world'] = $worldData;
		}

		$this->writeJson($targetRelativePath, $data);
	}

	/**
	 * Merges a mod's data into the base data. Within each data category (recipes, items,
	 * buildings, …) entries are keyed by class name: a mod entry overwrites the base entry
	 * with the same class name and adds entries with new class names. Metadata is merged
	 * shallowly. The multipliers are applied afterwards, so they also affect merged-in
	 * recipes and buildings.
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $mod
	 */
	private function mergeMod(array &$base, array $mod): void
	{
		if (isset($mod['data']) && is_array($mod['data'])) {
			foreach ($mod['data'] as $category => $entries) {
				if (!is_array($entries)) {
					continue;
				}
				$base['data'][$category] = array_replace($base['data'][$category] ?? [], $entries);
			}
		}

		if (isset($mod['metadata']) && is_array($mod['metadata'])) {
			$base['metadata'] = array_replace(
				is_array($base['metadata'] ?? null) ? $base['metadata'] : [],
				$mod['metadata'],
			);
		}
	}

	/**
	 * Increases the cost (ingredient amounts) of every recipe. Solid items use
	 * max(1, round(abs(amount) * multiplier)); fluids (liquid/gas) are multiplied
	 * directly, since they may carry decimal amounts.
	 *
	 * @param array<string, mixed> $data
	 */
	private function applyRecipeCost(array &$data, float $multiplier): void
	{
		$fluidItems = $this->fluidItemLookup($data);

		foreach ($data['data']['recipes'] as &$recipe) {
			foreach ($recipe['ingredients'] as &$ingredient) {
				$amount = $ingredient['amount'];
				if (isset($fluidItems[$ingredient['item']])) {
					$ingredient['amount'] = $amount * $multiplier;
				} else {
					$ingredient['amount'] = (int) max(1, (int) round(abs($amount) * $multiplier));
				}
			}
			unset($ingredient);
		}
		unset($recipe);
	}

	/**
	 * Multiplies power consumption by the multiplier: every machine's (building's)
	 * base power draw, plus the variable power draw of recipes that use it.
	 *
	 * @param array<string, mixed> $data
	 */
	private function applyPowerCost(array &$data, float $multiplier): void
	{
		foreach ($data['data']['buildings'] as &$building) {
			$building['powerUsage'] *= $multiplier;
		}
		unset($building);

		foreach ($data['data']['recipes'] as &$recipe) {
			if ($recipe['variablePowerDraw'] ?? false) {
				$recipe['variablePowerDrawConstant'] *= $multiplier;
				$recipe['variablePowerDrawFactor'] *= $multiplier;
			}
		}
		unset($recipe);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, true> set of item class names whose form is a fluid
	 */
	private function fluidItemLookup(array $data): array
	{
		$fluids = [];
		foreach ($data['data']['items'] as $className => $item) {
			if (in_array($item['form'] ?? 'solid', self::FLUID_FORMS, true)) {
				$fluids[$className] = true;
			}
		}
		return $fluids;
	}

	/** @return array<string, mixed> */
	private function readJson(string $relativePath): array
	{
		$absolute = $this->absolutePath($relativePath);
		$contents = @file_get_contents($absolute);
		if ($contents === false) {
			throw new RuntimeException(sprintf('Base version data file not found: %s', $relativePath));
		}

		try {
			return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException(sprintf('Base version data file is not valid JSON: %s', $relativePath), 0, $e);
		}
	}

	/** @param array<string, mixed> $data */
	private function writeJson(string $relativePath, array $data): void
	{
		$absolute = $this->absolutePath($relativePath);
		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		try {
			$json = json_encode($data, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('Could not encode generated version data', 0, $e);
		}

		// Write-then-rename so a crash mid-write can never leave a truncated file at the
		// final path — readers (Apache serves these statically) see the old file or the
		// complete new one, never a partial write.
		$tmp = $absolute . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) {
			@unlink($tmp);
			throw new RuntimeException(sprintf('Could not write version data file: %s', $relativePath));
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
