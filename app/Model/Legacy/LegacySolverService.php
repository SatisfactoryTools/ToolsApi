<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

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
	 * @param array<string, mixed> $request
	 * @return array<string, float>
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
