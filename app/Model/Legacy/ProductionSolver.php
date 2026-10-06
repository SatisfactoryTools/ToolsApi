<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Building;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\DocsSchema;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Recipe;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\ProductionRequest;

/**
 * Builds the old tools' LP model for a production request and solves it. Ported from the
 * old API (app/Solver/ProductionSolver.php, the one POST /v2/solver ran) with the algorithm
 * and the generated model text kept as they were — static state became instance state and
 * LPSolver is injected, nothing else changed. Column names in the result are the old ones:
 * `Recipe_X_C@100#Desc_Machine_C`, `Desc_X_C#Mine`, `Desc_X_C#Product`, `Desc_X_C#Byproduct`,
 * `Desc_X_C#Input` and the pseudo items `special__*`.
 */
class ProductionSolver
{

	private const TYPE_PER_MINUTE = 'perMinute';
	private const TYPE_MAX = 'max';

	private const SPECIAL_POWER = 'special__power';
	private const SPECIAL_SINK_POINTS = 'special__points';
	private const SPECIAL_BUILDING_COUNT = 'special__buildings';

	/**
	 * [
	 *      'Desc_IronOre_C' => ['Recipe_IronIngot_C' => 3, ...],
	 *      'Desc_CopperOre_C' => ['Recipe_CopperIngot_C' => 2, ...],
	 *      ...
	 * ]
	 *
	 * @var array<string, array<string, float>>
	 */
	private array $rawItems = [];

	public function __construct(private readonly LPSolver $lpSolver)
	{
	}

	/**
	 * The request must carry a `resourceMax` and `resourceWeight` entry for every resource
	 * of the schema (LegacyRequestFactory fills the gaps).
	 *
	 * @return array{variables: array<string, string>, recipes: array<string, string>}|null null when there is no solution
	 * @throws LegacySolverException
	 */
	public function solveRequest(ProductionRequest $request, DocsSchema $schema): ?array
	{
		$this->rawItems = [];

		$this->addFakeVariable(self::SPECIAL_POWER);
		$this->addFakeVariable(self::SPECIAL_SINK_POINTS);
		$this->addFakeVariable(self::SPECIAL_BUILDING_COUNT);

		foreach ($schema->recipes as $recipe) {
			if (count($recipe->producedIn) === 0) {
				continue;
			}

			if ($recipe->alternate && !in_array($recipe->className, $request->allowedAlternateRecipes, true)) {
				continue;
			}

			if (!$recipe->alternate && in_array($recipe->className, $request->blockedRecipes, true)) {
				continue;
			}

			foreach ($recipe->producedIn as $buildingClass) {
				if (isset($schema->buildings[$buildingClass])) {
					$this->addRecipe($recipe, $schema->buildings[$buildingClass]);
				}
			}
		}

		// The old solver had sinking disabled (its addSinkableResource call was commented
		// out), so sinkableResources is accepted but has no effect — kept that way on purpose.

		foreach ($schema->resources as $resource) {
			if (!in_array($resource, $request->blockedResources, true)) {
				$this->addResource($resource);
			}
		}

		return $this->lpSolver->call($this->toText($request, $schema));
	}

	private function toText(ProductionRequest $request, DocsSchema $schema): string
	{
		$rows = [];

		foreach ($request->input as $input) {
			if ($input->item && isset($schema->items[$input->item]) && $input->amount > 0) {
				$this->addInput($input->item);
				$rows[] = $input->item . '#Input <= ' . $input->amount . ';';
			}
		}

		$type = self::TYPE_PER_MINUTE;
		foreach ($this->rawItems as $itemClass => $recipes) {
			$items = [];
			$items[] = '-1' . $itemClass . '#Byproduct';
			foreach ($recipes as $recipeClass => $amount) {
				$items[] = $amount . $recipeClass;
			}

			$amount = 0;
			$max = false;
			foreach ($request->production as $productionRequestItem) {
				if ($productionRequestItem->item === $itemClass) {
					if ($productionRequestItem->type === self::TYPE_PER_MINUTE && $productionRequestItem->amount > 0) {
						$amount += $productionRequestItem->amount;
					} elseif ($productionRequestItem->type === self::TYPE_MAX) {
						$max = true;
						$type = self::TYPE_MAX;
					}
				}
			}

			$rows[] = implode(' + ', $items) . (($amount > 0 || $max) ? ' + -1' . $itemClass . '#Product' : '') . ' = 0; // ' . $itemClass;

			if ($amount > 0 && $max === false) {
				$rows[] = $itemClass . '#Product = ' . $amount . ';';
			} elseif ($amount > 0 && $max === true) {
				$rows[] = $itemClass . '#Product >= ' . $amount . ';';
			} elseif ($amount === 0 && $max === true) {
				$rows[] = $itemClass . '#Product >= 0;';
			}
		}

		foreach ($schema->resources as $resource) {
			if (!in_array($resource, $request->blockedResources, true)) {
				$rows[] = '0 <= ' . $this->getResourceRecipeName($resource) . ' <= ' . $request->resourceMax[$resource] . ';';
			}
		}

		$optimise = [];
		if ($type === self::TYPE_MAX) {
			foreach ($request->production as $production) {
				if ($production->item && $production->type === self::TYPE_MAX) {
					$rows[] = '1' . $production->item . '#Product - Maximise#Product = 0;';
				}
			}
			$resultType = 'max';
			$optimise['Maximise#Product'] = 1;
		} else {
			$resultType = 'min';
			foreach ($schema->resources as $resource) {
				$optimise[$this->getResourceRecipeName($resource)] = $request->resourceWeight[$resource];
			}
		}

		$weightData = [];
		foreach ($optimise as $item => $amount) {
			if ((float) $amount !== 0.0) {
				$weightData[] = $amount . $item;
			} else {
				$weightData[] = '0.0000000001' . $item;
			}
		}
		$result = $resultType . ': ' . implode(' + ', $weightData);
		$result .= ";\n\n" . implode("\n", $rows);

		return $result;
	}

	private function getResourceRecipeName(string $resourceClass): string
	{
		return $resourceClass . '#Mine';
	}

	private function addRecipe(Recipe $recipe, Building $building): void
	{
		$recipeClass = $recipe->className . '@100#' . $building->className;
		$this->addItem($recipeClass, self::SPECIAL_POWER, $building->powerUsage);

		$cyclesPerMin = 60 / $recipe->time * ($building->manufacturingSpeed ?: 1);

		foreach ($recipe->ingredients as $ingredient) {
			$this->addItem($recipeClass, $ingredient->item, -($ingredient->amount * $cyclesPerMin));
		}
		foreach ($recipe->products as $product) {
			$this->addItem($recipeClass, $product->item, $product->amount * $cyclesPerMin);
		}
	}

	private function addResource(string $resourceClass): void
	{
		$this->addItem($this->getResourceRecipeName($resourceClass), $resourceClass, 1);
	}

	private function addFakeVariable(string $name): void
	{
		$this->addItem($name, $name, -1);
	}

	private function addInput(string $itemClass): void
	{
		$this->addItem($itemClass . '#Input', $itemClass, 1);
	}

	private function addItem(string $recipeClass, string $itemClass, float $value): void
	{
		if (!isset($this->rawItems[$itemClass])) {
			$this->rawItems[$itemClass] = [];
		}
		if (!isset($this->rawItems[$itemClass][$recipeClass])) {
			$this->rawItems[$itemClass][$recipeClass] = 0;
		}
		$this->rawItems[$itemClass][$recipeClass] += $value;
	}

}
