<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\DocsSchema;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts\ProductionInput;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts\ProductionItem;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\ProductionRequest;

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

	/** @param string[] $blockedMachines */
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
	 * Numbers keep their JSON type so the solver's model text matches the old API's.
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
