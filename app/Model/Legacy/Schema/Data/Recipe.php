<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\Event;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\ItemAmount;

/** Ported from the old API (app/Solver/Schema/Data/Recipe.php); the property list drives the schema. */
class Recipe
{

	use ClassName;

	public string $name = '';

	/** @var ItemAmount[] */
	public array $ingredients = [];

	/** @var ItemAmount[] */
	public array $products = [];

	/** @var string[] */
	public array $producedIn = [];

	/** @var Event[] */
	public array $events = [];

	public float $time = 0;
	public float $manualCraftingMultiplier = 1;
	public bool $alternate = false;
	public bool $inBuildGun = false;
	public bool $inCraftBench = false;
	public bool $inEquipmentWorkshop = false;
	public bool $variablePowerDraw = false;
	public int $variablePowerDrawConstant = 0;
	public int $variablePowerDrawFactor = 1;

}
