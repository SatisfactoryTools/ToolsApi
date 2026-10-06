<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema;

use BackedEnum;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Building;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\DocsSchema;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Item;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\BuildingMaterial;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\Event;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\Fuel;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\ItemAmount;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\ItemForm;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Recipe;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Elements\Type;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema as NetteSchema;
use ReflectionNamedType;
use ReflectionObject;
use stdClass;

/**
 * Maps a decoded game data file onto DocsSchema. Ported from the old API
 * (app/Solver/Schema/Schema.php): the same nette/schema structures built from the entity
 * classes by reflection, and the same conversion of the old export format (the one the
 * legacy data files under data/legacy use — items with `energyValue`/`liquid`, recipes with
 * `inMachine`, buildings with `metadata`, resources keyed by item) into the schema shape.
 * The schematic and material mappers were dropped: the old format never carried them and
 * the solver does not read them. The production request is built by LegacyRequestFactory
 * instead of the old `productionRequest()` mapper, which required every field.
 */
class Schema
{

	private static ?Processor $processor = null;

	/** @var Structure[] */
	private static array $schemas = [];

	public static function schema(stdClass $data): DocsSchema
	{
		if (isset($data->data) && !isset($data->items)) {
			// raw export file, data is wrapped together with build metadata
			$data = $data->data;
		}

		if (!isset($data->materials)) {
			$data = self::convertLegacyData($data);
		}

		$schema = new DocsSchema;
		foreach ($data->items as $key => $item) {
			$schema->items[$key] = self::item($item);
		}
		foreach ($data->recipes as $key => $recipe) {
			$schema->recipes[$key] = self::recipe($recipe);
		}
		foreach ($data->buildings as $key => $building) {
			$schema->buildings[$key] = self::building($building);
		}
		foreach ($data->resources as $resource) {
			$schema->resources[] = $resource;
		}
		return $schema;
	}

	public static function item(mixed $data): Item
	{
		self::$schemas['item'] ??= self::entity(new Item, [
			'compatibleWeapons' => self::arrayOf('string'),
			'compatibleAmmo' => self::arrayOf('string'),
		]);
		return self::processor()->process(self::$schemas['item'], $data);
	}

	public static function recipe(mixed $data): Recipe
	{
		self::$schemas['recipe'] ??= self::entity(new Recipe, [
			'ingredients' => self::arrayOf(self::entity(new ItemAmount)),
			'products' => self::arrayOf(self::entity(new ItemAmount)),
			'producedIn' => self::arrayOf('string'),
			'events' => self::arrayOf(self::enum(Event::class)),
		]);
		return self::processor()->process(self::$schemas['recipe'], $data);
	}

	public static function building(mixed $data): Building
	{
		self::$schemas['building'] ??= self::entity(new Building, [
			'materials' => self::arrayOf(self::entity(new BuildingMaterial)),
			'fuel' => self::arrayOf(self::entity(new Fuel)),
			'acceptedFuel' => self::arrayOf('string'),
			'allowedResources' => self::arrayOf('string'),
			'allowedResourceForms' => self::arrayOf(self::enum(ItemForm::class)),
		]);
		return self::processor()->process(self::$schemas['building'], $data);
	}

	/**
	 * Builds a structure schema from entity class properties. Float properties accept ints,
	 * backed enum properties accept their backing values, object properties are resolved recursively.
	 * Array properties (other than plain string lists) must be passed in $items explicitly.
	 *
	 * @param NetteSchema[] $items
	 */
	private static function entity(object $entity, array $items = []): Structure
	{
		$reflection = new ReflectionObject($entity);
		foreach ($reflection->getProperties() as $property) {
			$name = $property->getName();
			if (isset($items[$name])) {
				continue;
			}

			$type = $property->getType();
			assert($type instanceof ReflectionNamedType);
			$typeName = $type->getName();
			$default = $property->isInitialized($entity) ? $property->getValue($entity) : null;

			if (is_subclass_of($typeName, BackedEnum::class)) {
				$item = self::enum($typeName, $type->allowsNull());
			} elseif ($typeName === 'float') {
				$item = Expect::type('int|float' . ($type->allowsNull() ? '|null' : ''));
			} elseif (class_exists($typeName)) {
				$items[$name] = self::entity($default ?? new $typeName);
				continue;
			} else {
				$item = Expect::type($typeName . ($type->allowsNull() ? '|null' : ''));
			}

			if ($default !== null || $type->allowsNull()) {
				$item->default($default);
			} else {
				$item->required();
			}

			$items[$name] = $item;
		}

		return Expect::structure($items)
			->otherItems()
			->castTo($reflection->getName());
	}

	private static function enum(string $class, bool $nullable = false): Type
	{
		return Expect::type($class . ($nullable ? '|null' : ''))
			->before(static fn ($value) => $value === null || $value instanceof $class ? $value : $class::from($value));
	}

	private static function arrayOf(NetteSchema|string $type): Type
	{
		return Expect::arrayOf($type)
			->before(static fn ($value) => $value instanceof stdClass ? (array) $value : $value);
	}

	/**
	 * Converts data stored in the old export format into the new schema shape,
	 * so that versions imported before the data-utils rewrite keep working.
	 * Only fields present in the old format are mapped.
	 */
	private static function convertLegacyData(stdClass $data): stdClass
	{
		$converted = new stdClass;
		$converted->items = [];
		$converted->recipes = [];
		$converted->buildings = [];
		$converted->materials = [];
		$converted->resources = [];

		foreach ($data->items ?? [] as $key => $item) {
			$converted->items[$key] = [
				'className' => $item->className,
				'name' => $item->name,
				'description' => $item->description,
				'energy' => $item->energyValue,
				'radioactiveDecay' => $item->radioactiveDecay,
				'form' => ($item->liquid ? ItemForm::Liquid : ItemForm::Solid)->value,
				'fluidColor' => $item->fluidColor,
				'sinkPoints' => (int) ($item->sinkPoints ?? 0),
				'stackSize' => (int) $item->stackSize,
			];
		}

		foreach ($data->recipes ?? [] as $key => $recipe) {
			$converted->recipes[$key] = [
				'className' => $recipe->className,
				'name' => $recipe->name,
				'ingredients' => $recipe->ingredients,
				'products' => $recipe->products,
				'producedIn' => $recipe->inMachine ? $recipe->producedIn : [],
				'time' => $recipe->time,
				'manualCraftingMultiplier' => $recipe->manualTimeMultiplier ?? 1,
				'alternate' => $recipe->alternate,
				'inBuildGun' => $recipe->forBuilding ?? $recipe->forBuildGun ?? false,
				'inCraftBench' => $recipe->inHand ?? false,
				'inEquipmentWorkshop' => $recipe->inWorkshop ?? false,
				'variablePowerDraw' => $recipe->isVariablePower ?? false,
				'variablePowerDrawConstant' => (int) ($recipe->minPower ?? 0),
				'variablePowerDrawFactor' => (int) (($recipe->maxPower ?? 1) - ($recipe->minPower ?? 0)),
			];
		}

		foreach ($data->buildings ?? [] as $key => $building) {
			$converted->buildings[$key] = [
				'className' => $building->className,
				'name' => $building->name,
				'description' => $building->description,
				'manufacturingSpeed' => (int) ($building->metadata->manufacturingSpeed ?? 0),
				'powerUsage' => (int) ($building->metadata->powerConsumption ?? 0),
				'powerUsageExponent' => $building->metadata->powerConsumptionExponent ?? 0,
				'width' => $building->size->width ?? 0,
				'height' => $building->size->height ?? 0,
			];
		}

		foreach ($data->resources ?? [] as $resource) {
			$converted->resources[] = $resource->item;
		}

		return $converted;
	}

	private static function processor(): Processor
	{
		if (!self::$processor) {
			self::$processor = new Processor;
		}
		return self::$processor;
	}

}
