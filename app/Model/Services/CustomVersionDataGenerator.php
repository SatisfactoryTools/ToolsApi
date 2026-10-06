<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use RuntimeException;

class CustomVersionDataGenerator
{

	private const array FLUID_FORMS = ['liquid', 'gas'];

	/** Packaging/unpackaging must stay 1:1 or the fluid loop breaks. */
	private const array RECIPE_COST_EXEMPT_BUILDINGS = ['Desc_Packager_C'];

	private const array RECIPE_COST_EXEMPT_RECIPES = ['Recipe_Alternate_DilutedPackagedFuel_C'];

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/**
	 * @param string[] $modDataPaths
	 * @param array<string, mixed>|null $worldData
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
	 * Fluid amounts may be decimal, so they are multiplied without rounding.
	 * @param array<string, mixed> $data
	 */
	private function applyRecipeCost(array &$data, float $multiplier): void
	{
		$fluidItems = $this->fluidItemLookup($data);

		foreach ($data['data']['recipes'] as $className => &$recipe) {
			if ($this->isRecipeCostExempt((string) $className, $recipe)) {
				continue;
			}

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

	/** @param array<string, mixed> $recipe */
	private function isRecipeCostExempt(string $className, array $recipe): bool
	{
		if (in_array($className, self::RECIPE_COST_EXEMPT_RECIPES, true)) {
			return true;
		}

		$producedIn = $recipe['producedIn'] ?? [];
		if (!is_array($producedIn)) {
			return false;
		}

		return array_intersect($producedIn, self::RECIPE_COST_EXEMPT_BUILDINGS) !== [];
	}

	/** @param array<string, mixed> $data */
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
	 * @return array<string, true>
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
		$contents = @file_get_contents($absolute); // @ intentional
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
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) { // @ intentional
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		try {
			$json = json_encode($data, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('Could not encode generated version data', 0, $e);
		}

		// Write-then-rename so readers (Apache serves these statically) never see a truncated file.
		$tmp = $absolute . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) { // @ intentional
			@unlink($tmp); // @ intentional
			throw new RuntimeException(sprintf('Could not write version data file: %s', $relativePath));
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
