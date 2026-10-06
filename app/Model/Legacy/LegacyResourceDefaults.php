<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

/**
 * What the old client put into a request when the user had not touched the resource
 * settings (satisfactorytools: src/Data/Data.ts, `resourceAmounts` / `resourceWeights`).
 * The old API crashed on a request missing a resource key; the legacy solver endpoint
 * fills the gaps with these instead — a resource the old client did not know gets no
 * limit and no weight. The limits are not just cosmetic: with every resource unbounded
 * (MAX_SAFE_INTEGER on all of them) lp_solve loses accuracy on a `max` request and
 * reports no solution, with the old client's finite limits it solves.
 */
class LegacyResourceDefaults
{

	/** The old client's "no limit" (JavaScript's Number.MAX_SAFE_INTEGER, sent for water). */
	public const UNLIMITED = 9007199254740991;

	private const LIMITS_0_8 = [
		'Desc_OreIron_C' => 70380,
		'Desc_OreCopper_C' => 28860,
		'Desc_Stone_C' => 52860,
		'Desc_Coal_C' => 30120,
		'Desc_OreGold_C' => 11040,
		'Desc_LiquidOil_C' => 11700,
		'Desc_RawQuartz_C' => 10500,
		'Desc_Sulfur_C' => 6840,
		'Desc_OreBauxite_C' => 9780,
		'Desc_OreUranium_C' => 2100,
		'Desc_NitrogenGas_C' => 12000,
		'Desc_SAM_C' => 0,
		'Desc_Water_C' => self::UNLIMITED,
	];

	private const LIMITS_1_0 = [
		'Desc_OreIron_C' => 92100,
		'Desc_OreCopper_C' => 36900,
		'Desc_Stone_C' => 69900,
		'Desc_Coal_C' => 42300,
		'Desc_OreGold_C' => 15000,
		'Desc_LiquidOil_C' => 12600,
		'Desc_RawQuartz_C' => 13500,
		'Desc_Sulfur_C' => 10800,
		'Desc_OreBauxite_C' => 12300,
		'Desc_OreUranium_C' => 2100,
		'Desc_NitrogenGas_C' => 12000,
		'Desc_SAM_C' => 10200,
		'Desc_Water_C' => self::UNLIMITED,
	];

	private const WEIGHTS_0_8 = [
		'Desc_OreIron_C' => 1,
		'Desc_OreCopper_C' => 2.438669438669439,
		'Desc_Stone_C' => 1.3314415437003406,
		'Desc_Coal_C' => 2.277669902912621,
		'Desc_OreGold_C' => 6.375,
		'Desc_LiquidOil_C' => 6.015384615384615,
		'Desc_RawQuartz_C' => 6.702857142857143,
		'Desc_Sulfur_C' => 10.289473684210526,
		'Desc_OreBauxite_C' => 7.196319018404908,
		'Desc_OreUranium_C' => 33.51428571428572,
		'Desc_NitrogenGas_C' => 5.865,
		'Desc_SAM_C' => 10000,
		'Desc_Water_C' => 0,
	];

	private const WEIGHTS_1_0 = [
		'Desc_OreIron_C' => 1,
		'Desc_OreCopper_C' => 2.4959349593495936,
		'Desc_Stone_C' => 1.3175965665236051,
		'Desc_Coal_C' => 2.1773049645390072,
		'Desc_OreGold_C' => 6.140000000000001,
		'Desc_LiquidOil_C' => 7.30952380952381,
		'Desc_RawQuartz_C' => 6.822222222222222,
		'Desc_Sulfur_C' => 8.527777777777779,
		'Desc_OreBauxite_C' => 7.487804878048781,
		'Desc_OreUranium_C' => 43.85714285714286,
		'Desc_NitrogenGas_C' => 7.675000000000001,
		'Desc_SAM_C' => 9.029411764705882,
		'Desc_Water_C' => 0,
	];

	public function limit(string $gameVersion, string $resource): int|float
	{
		$limits = $gameVersion === '0.8.0' ? self::LIMITS_0_8 : self::LIMITS_1_0;

		return $limits[$resource] ?? self::UNLIMITED;
	}

	public function weight(string $gameVersion, string $resource): int|float
	{
		$weights = $gameVersion === '0.8.0' ? self::WEIGHTS_0_8 : self::WEIGHTS_1_0;

		return $weights[$resource] ?? 0;
	}

}
