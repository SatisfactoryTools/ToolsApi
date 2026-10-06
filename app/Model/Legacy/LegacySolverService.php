<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

/**
 * What POST /v2/solver of the old API did (SolverBeta::solve): load the game data for the
 * requested version, run ProductionSolver, hand back the recipe columns — here with the
 * values as floats rather than the strings the old API returned.
 */
class LegacySolverService
{

	public function __construct(
		private readonly LegacyGameDataLoader $gameDataLoader,
		private readonly LegacyRequestFactory $requestFactory,
		private readonly ProductionSolver $productionSolver,
	)
	{
	}

	/**
	 * @param array<string, mixed> $request the old tools' request document
	 * @return array<string, float> column => value; empty when there is no solution
	 * @throws LegacySolverException
	 */
	public function solve(string $gameVersion, array $request): array
	{
		$schema = $this->gameDataLoader->load($gameVersion);
		$productionRequest = $this->requestFactory->create($gameVersion, $request, $schema);

		$result = $this->productionSolver->solveRequest($productionRequest, $schema);
		if ($result === null) {
			return [];
		}

		return array_map(static fn (string $value): float => (float) $value, $result['recipes']);
	}

}
