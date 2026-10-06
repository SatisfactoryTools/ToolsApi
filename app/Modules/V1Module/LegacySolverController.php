<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Legacy\LegacyGameDataLoader;
use greeny\SatisfactoryTools\Api\Model\Legacy\LegacySolverException;
use greeny\SatisfactoryTools\Api\Model\Legacy\LegacySolverService;
use JsonException;
use Nette\Http\IResponse;

/**
 * The old Satisfactory Tools' production solver (POST /v2/solver of api.satisfactorytools.com),
 * ported so production lines imported from the old tools calculate exactly as they did
 * there after that API is retired. Public, no auth — the old endpoint was too.
 */
#[Path('/legacy-solver')]
class LegacySolverController extends BaseV1Controller
{

	public function __construct(
		private readonly LegacySolverService $legacySolverService,
		private readonly LegacyGameDataLoader $gameDataLoader,
	)
	{
	}

	/**
	 * Body: { gameVersion: "0.8.0" | "1.0.0" | "1.0.0-ficsmas", request: {...} } where
	 * `request` is the old IProductionDataRequest (resourceMax, resourceWeight,
	 * blockedResources, blockedRecipes, blockedMachines, allowedAlternateRecipes,
	 * sinkableResources, production, input), read leniently — see LegacyRequestFactory.
	 * Answers { result: { column: value, ... } } with the old solver's column names; an
	 * infeasible or empty solve is { result: {} } with 200, as the old API answered it.
	 */
	#[Path('/')]
	#[Method('POST')]
	public function solve(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->decodeObject((string) $request->getBody());
		if ($body === null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Request body must be a JSON object']);
		}

		$gameVersion = $body['gameVersion'] ?? null;
		if (!is_string($gameVersion) || !$this->gameDataLoader->hasGameVersion($gameVersion)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field gameVersion must be one of: ' . implode(', ', LegacyGameDataLoader::GAME_VERSIONS)]);
		}

		$productionRequest = $body['request'] ?? [];
		if (!is_array($productionRequest)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field request must be an object']);
		}

		try {
			$result = $this->legacySolverService->solve($gameVersion, $productionRequest);
		} catch (LegacySolverException $e) {
			return $response->withStatus($e->isTimeout() ? IResponse::S504_GatewayTimeout : IResponse::S500_InternalServerError)
				->writeJsonBody(['error' => $e->isTimeout() ? 'The calculation took too long' : 'The calculation failed', 'detail' => $e->getMessage()]);
		}

		// (object) so an empty result is {} and not [], which is what the client expects.
		return $response->writeJsonBody(['result' => (object) $result]);
	}

	/**
	 * Unlike parseBodyString(), tells a JSON object apart from a missing, malformed or
	 * non-object body — those are a client error here, not an empty request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeObject(string $body): ?array
	{
		try {
			$decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
			return null;
		}

		return $decoded;
	}

}
