<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\DocsSchema;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts\ProductionInput;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts\ProductionItem;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\ProductionRequest;

/**
 * Turns the old tools' request document (IProductionDataRequest, the `request` of a
 * share) into the ProductionRequest the solver runs, leniently: missing lists and maps are
 * empty, unknown fields are ignored, rows without an item are dropped. On top of the plain
 * mapping it does the two things that used to happen outside the old solver:
 *
 * - folds `blockedMachines` into the recipe lists the way the old client's
 *   ProductionTab.buildApiRequest() did before calling the API (the API never saw
 *   blockedMachines): an allowed alternate recipe made in a blocked machine is dropped,
 *   a base recipe made in a blocked machine is blocked;
 * - fills `resourceMax` / `resourceWeight` for every resource of the game data the
 *   request does not mention with the old client's defaults (LegacyResourceDefaults),
 *   since the solver reads both for every resource.
 */
class LegacyRequestFactory
{

	public function __construct(private readonly LegacyResourceDefaults $resourceDefaults)
	{
	}

	/** @param array<string, mixed> $data */
	public function create(string $gameVersion, array $data, DocsSchema $schema): ProductionRequest
	{
		$request = new ProductionRequest;
		$request->gameVersion = $gameVersion;
		$request->resourceMax = $this->numberMap($data['resourceMax'] ?? null);
		$request->resourceWeight = $this->numberMap($data['resourceWeight'] ?? null);
		$request->blockedResources = $this->stringList($data['blockedResources'] ?? null);
		$request->blockedRecipes = $this->stringList($data['blockedRecipes'] ?? null);
		$request->allowedAlternateRecipes = $this->stringList($data['allowedAlternateRecipes'] ?? null);
		$request->sinkableResources = $this->stringList($data['sinkableResources'] ?? null);
		$request->production = $this->production($data['production'] ?? null);
		$request->input = $this->input($data['input'] ?? null);

		$this->foldBlockedMachines($request, $this->stringList($data['blockedMachines'] ?? null), $schema);
		$this->fillResourceDefaults($request, $schema);

		return $request;
	}

	/**
	 * Ported from the old client (satisfactorytools: src/Tools/Production/ProductionTab.ts,
	 * buildApiRequest). `getBaseItemRecipes()` there meant "not alternate and inMachine",
	 * which in the schema is a non-alternate recipe with a non-empty producedIn.
	 *
	 * @param string[] $blockedMachines
	 */
	private function foldBlockedMachines(ProductionRequest $request, array $blockedMachines, DocsSchema $schema): void
	{
		$allowedAlts = [];
		foreach ($request->allowedAlternateRecipes as $recipeClass) {
			$recipe = $schema->recipes[$recipeClass] ?? null;
			if ($recipe) {
				$allowed = true;
				foreach ($blockedMachines as $machineClass) {
					if (in_array($machineClass, $recipe->producedIn, true)) {
						$allowed = false;
					}
				}
				if ($allowed) {
					$allowedAlts[] = $recipeClass;
				}
			}
		}
		$request->allowedAlternateRecipes = $allowedAlts;

		$blockedRecipes = [];
		foreach ($schema->recipes as $recipe) {
			if ($recipe->alternate || count($recipe->producedIn) === 0) {
				continue;
			}
			$allowed = !in_array($recipe->className, $request->blockedRecipes, true);
			foreach ($blockedMachines as $machineClass) {
				if (in_array($machineClass, $recipe->producedIn, true)) {
					$allowed = false;
				}
			}
			if (!$allowed) {
				$blockedRecipes[] = $recipe->className;
			}
		}
		$request->blockedRecipes = $blockedRecipes;
	}

	private function fillResourceDefaults(ProductionRequest $request, DocsSchema $schema): void
	{
		foreach ($schema->resources as $resource) {
			$request->resourceMax[$resource] ??= $this->resourceDefaults->limit($request->gameVersion, $resource);
			$request->resourceWeight[$resource] ??= $this->resourceDefaults->weight($request->gameVersion, $resource);
		}
	}

	/** @return ProductionItem[] */
	private function production(mixed $rows): array
	{
		$result = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			if (!is_array($row) || !is_string($row['item'] ?? null) || $row['item'] === '') {
				continue;
			}
			$item = new ProductionItem;
			$item->item = $row['item'];
			$item->type = is_string($row['type'] ?? null) ? $row['type'] : 'perMinute';
			$item->amount = $this->number($row['amount'] ?? null);
			$item->ratio = $this->number($row['ratio'] ?? null) ?? 0;
			$result[] = $item;
		}

		return $result;
	}

	/** @return ProductionInput[] */
	private function input(mixed $rows): array
	{
		$result = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			if (!is_array($row) || !is_string($row['item'] ?? null) || $row['item'] === '') {
				continue;
			}
			$input = new ProductionInput;
			$input->item = $row['item'];
			$input->amount = $this->number($row['amount'] ?? null);
			$result[] = $input;
		}

		return $result;
	}

	/** @return string[] */
	private function stringList(mixed $value): array
	{
		$result = [];
		foreach (is_array($value) ? $value : [] as $entry) {
			if (is_string($entry)) {
				$result[] = $entry;
			}
		}

		return $result;
	}

	/**
	 * Numbers keep their JSON type (int stays int) so the model text the solver writes
	 * matches what the old API produced from the same request.
	 *
	 * @return array<string, int|float>
	 */
	private function numberMap(mixed $value): array
	{
		$result = [];
		foreach (is_array($value) ? $value : [] as $key => $entry) {
			if (!is_string($key)) {
				continue;
			}
			$number = $this->number($entry);
			if ($number !== null) {
				$result[$key] = is_int($entry) ? $entry : $number;
			}
		}

		return $result;
	}

	private function number(mixed $value): ?float
	{
		if (is_int($value) || is_float($value)) {
			return (float) $value;
		}
		if (is_string($value) && is_numeric($value)) {
			return (float) $value;
		}

		return null;
	}

}
