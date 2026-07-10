<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModVersionRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\CustomVersionDataGenerator;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataException;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataService;
use greeny\SatisfactoryTools\Api\Schema\Responses\VersionResponse;
use Nette\Http\IResponse;
use Nette\Utils\Random;
use Ramsey\Uuid\Uuid;

#[Path('/versions')]
class VersionsController extends BaseV1Controller
{

	/** Allowed recipe cost multipliers. */
	private const RECIPE_COST_OPTIONS = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0];

	/** Allowed power cost multipliers. */
	private const POWER_COST_OPTIONS = [0.25, 0.5, 0.75, 1.0, 2.0, 5.0];

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly ModVersionRepository $modVersionRepository,
		private readonly CustomVersionDataGenerator $dataGenerator,
		private readonly WorldDataService $worldDataService,
	)
	{
	}

	/**
	 * Previews the resource nodes for a set of world settings, so the frontend can show
	 * the resulting resource limits before a custom version is created. Runs the external
	 * world-data-generator and returns node counts by resource type and purity.
	 * Body: { seed?: int = 0, mode?: string = 'none', purity?: string = 'no-change' }
	 */
	#[Path('/world-data')]
	#[Method('POST')]
	public function worldData(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$body = $this->parseBody($request);

		$seed = $body['seed'] ?? 0;
		if (!is_int($seed)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field seed must be an integer']);
		}
		if ($seed < -2147483648 || $seed > 2147483647) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field seed must fit in a 32-bit signed integer']);
		}

		$mode = trim((string) ($body['mode'] ?? WorldDataService::DEFAULT_MODE));
		if (!in_array($mode, WorldDataService::MODES, true)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field mode is invalid', 'allowed' => WorldDataService::MODES]);
		}

		$purity = trim((string) ($body['purity'] ?? WorldDataService::DEFAULT_PURITY));
		if (!in_array($purity, WorldDataService::PURITY_SETTINGS, true)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field purity is invalid', 'allowed' => WorldDataService::PURITY_SETTINGS]);
		}

		try {
			$data = $this->worldDataService->generate($seed, $mode, $purity);
		} catch (WorldDataException $e) {
			// The generator is an upstream dependency; a failure here is a bad gateway.
			return $response->withStatus(IResponse::S502_BadGateway)
				->writeJsonBody(['error' => 'Could not generate world data', 'detail' => $e->getMessage()]);
		}

		return $response->writeJsonBody($data);
	}

	#[Path('/')]
	#[Method('GET')]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');

		// Authenticated users see public versions plus their own custom versions.
		// Guests only see public (built-in) versions.
		$versions = $user !== null
			? $this->versionRepository->getVisibleToUser($user)
			: $this->versionRepository->getPublic();

		return $response->writeJsonBody(VersionResponse::fromArray($versions));
	}

	/**
	 * Returns a single version by UUID, regardless of ownership. Public and unauthenticated
	 * so a shared plan/folder on a custom version can be loaded by anyone holding the share
	 * link (the version UUID acts as an unguessable capability, and the version's data file
	 * is already served statically). Only versions are exposed here — never user content.
	 */
	#[Path('/{uuid}')]
	#[Method('GET')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function detail(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$uuid = (string) $request->getParameter('uuid');

		$version = Uuid::isValid($uuid) ? $this->versionRepository->getByUuid($uuid) : null;
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		return $response->writeJsonBody(VersionResponse::fromEntity($version)->toArray());
	}

	#[Path('/')]
	#[Method('POST')]
	public function create(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$body = $this->parseBody($request);

		$baseId = trim((string) ($body['base'] ?? ''));
		if ($baseId === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field base is required']);
		}

		try {
			$baseUuid = Uuid::fromString($baseId);
		} catch (\InvalidArgumentException) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field base is not a valid UUID']);
		}

		$base = $this->versionRepository->getByUuid($baseUuid->toString());
		if ($base === null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Base version not found']);
		}

		if ($base->custom) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'A custom version must be based on a public version']);
		}

		$recipeCost = $this->normalizeMultiplier($body['recipeCost'] ?? 1);
		$powerCost = $this->normalizeMultiplier($body['powerCost'] ?? 1);

		if ($recipeCost === null || !in_array($recipeCost, self::RECIPE_COST_OPTIONS, true)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody([
					'error' => 'Field recipeCost is invalid',
					'allowed' => self::RECIPE_COST_OPTIONS,
				]);
		}

		if ($powerCost === null || !in_array($powerCost, self::POWER_COST_OPTIONS, true)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody([
					'error' => 'Field powerCost is invalid',
					'allowed' => self::POWER_COST_OPTIONS,
				]);
		}

		$modsInput = $body['mods'] ?? [];
		if (!is_array($modsInput)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field mods must be an array of mod version UUIDs']);
		}

		/** @var ModVersion[] $modVersions */
		$modVersions = [];
		$seenMods = [];
		foreach ($modsInput as $modVersionId) {
			try {
				$modVersionUuid = Uuid::fromString((string) $modVersionId);
			} catch (\InvalidArgumentException) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field mods contains an invalid UUID: ' . (string) $modVersionId]);
			}

			$modVersion = $this->modVersionRepository->getByUuidVisibleToUser($modVersionUuid->toString(), $user);
			if ($modVersion === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Mod version not found: ' . $modVersionUuid->toString()]);
			}

			if (isset($seenMods[$modVersion->mod->id])) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'At most one version per mod may be added']);
			}
			$seenMods[$modVersion->mod->id] = true;
			$modVersions[] = $modVersion;
		}

		[$worldData, $worldDataError] = $this->parseWorldData($body);
		if ($worldDataError !== null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => $worldDataError]);
		}

		if ($recipeCost === 1.0 && $powerCost === 1.0 && $modVersions === [] && $worldData === null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'At least one modifier must differ from the default (1), a mod must be added, or world settings must be provided; otherwise the version would be identical to the base version']);
		}

		$uuid = Uuid::uuid4();
		$dataPath = 'data/versions/custom/' . $uuid->toString() . '.json';

		$modDataPaths = [];
		foreach ($modVersions as $modVersion) {
			if ($modVersion->dataPath !== null) {
				$modDataPaths[] = $modVersion->dataPath;
			}
		}

		$this->dataGenerator->generate($base->dataPath, $dataPath, $modDataPaths, $recipeCost, $powerCost, $worldData);

		$name = trim((string) ($body['name'] ?? ''));
		if ($name === '') {
			$name = $this->defaultName($base, $recipeCost, $powerCost, $modVersions);
		}

		$version = new Version();
		$version->uuid = $uuid;
		$version->name = $name;
		$version->slug = 'custom-' . Random::generate(12);
		$version->experimental = $base->experimental;
		$version->custom = true;
		$version->dataPath = $dataPath;
		$version->user = $user;
		$version->baseVersion = $base;
		$version->recipeCostMultiplier = $recipeCost;
		$version->powerCostMultiplier = $powerCost;
		foreach ($modVersions as $modVersion) {
			$version->modVersions->add($modVersion);
		}

		$this->versionRepository->save($version);

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(VersionResponse::fromEntity($version)->toArray());
	}

	/**
	 * Validates the optional worldData payload sent on version creation: the node counts
	 * and frontend-derived limits (plus the settings they were computed from), which get
	 * stored under metadata.world in the version's data file. Only known keys are kept.
	 *
	 * @param array<string, mixed> $body
	 * @return array{0: array<string, mixed>|null, 1: string|null} [cleaned worldData or null, error or null]
	 */
	private function parseWorldData(array $body): array
	{
		if (!isset($body['worldData'])) {
			return [null, null];
		}

		$input = $body['worldData'];
		if (!is_array($input)) {
			return [null, 'Field worldData must be an object'];
		}

		$clean = [];

		if (isset($input['seed'])) {
			if (!is_int($input['seed']) || $input['seed'] < -2147483648 || $input['seed'] > 2147483647) {
				return [null, 'Field worldData.seed must be a 32-bit signed integer'];
			}
			$clean['seed'] = $input['seed'];
		}

		if (isset($input['mode'])) {
			if (!in_array($input['mode'], WorldDataService::MODES, true)) {
				return [null, 'Field worldData.mode is invalid'];
			}
			$clean['mode'] = $input['mode'];
		}

		if (isset($input['purity'])) {
			if (!in_array($input['purity'], WorldDataService::PURITY_SETTINGS, true)) {
				return [null, 'Field worldData.purity is invalid'];
			}
			$clean['purity'] = $input['purity'];
		}

		if (isset($input['nodes'])) {
			if (!is_array($input['nodes'])) {
				return [null, 'Field worldData.nodes must be an object'];
			}
			$clean['nodes'] = $input['nodes'];
		}

		if (isset($input['limits'])) {
			if (!is_array($input['limits'])) {
				return [null, 'Field worldData.limits must be an object'];
			}
			$clean['limits'] = $input['limits'];
		}

		if ($clean === []) {
			return [null, 'Field worldData must contain at least one of: seed, mode, purity, nodes, limits'];
		}

		return [$clean, null];
	}

	/**
	 * Coerces a JSON number (int or float) to float, or returns null if not numeric.
	 * The allowed multipliers are all exact binary fractions, so strict comparison is safe.
	 */
	private function normalizeMultiplier(mixed $value): ?float
	{
		if (is_int($value) || is_float($value)) {
			return (float) $value;
		}
		return null;
	}

	/** @param ModVersion[] $modVersions */
	private function defaultName(Version $base, float $recipeCost, float $powerCost, array $modVersions): string
	{
		$parts = [];
		if ($recipeCost !== 1.0) {
			$parts[] = 'recipe ×' . $this->formatMultiplier($recipeCost);
		}
		if ($powerCost !== 1.0) {
			$parts[] = 'power ×' . $this->formatMultiplier($powerCost);
		}
		if ($modVersions !== []) {
			$parts[] = count($modVersions) === 1 ? '1 mod' : count($modVersions) . ' mods';
		}

		return $base->name . ' (' . implode(', ', $parts) . ')';
	}

	private function formatMultiplier(float $value): string
	{
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}

}
