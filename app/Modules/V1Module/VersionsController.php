<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Entities\VersionModVersion;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModVersionRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\CustomVersionFileService;
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

	/** Caps on the anonymous create endpoint, to bound stored/served payloads. */
	private const NAME_MAX_LENGTH = 100;
	private const MODS_MAX_COUNT = 16;
	private const WORLD_DATA_MAX_BYTES = 65536;

	/** Cap on one link request (syncing an anonymous browser's locally kept list). */
	private const LINK_MAX_IDS = 200;

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly ModVersionRepository $modVersionRepository,
		private readonly CustomVersionFileService $fileService,
		private readonly WorldDataService $worldDataService,
	)
	{
	}

	/**
	 * Previews the resource nodes for a set of world settings, so the frontend can show
	 * the resulting resource limits before a custom version is created. Runs the external
	 * world-data-generator and returns node counts by resource type and purity. Open to
	 * anonymous users; results are cached by (seed, mode, purity) on disk.
	 * Body: { seed?: int = 0, mode?: string = 'none', purity?: string = 'no-change' }
	 */
	#[Path('/world-data')]
	#[Method('POST')]
	public function worldData(ApiRequest $request, ApiResponse $response): ApiResponse
	{
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

		// Authenticated users see public versions plus the custom versions linked to
		// their account. Guests only see public (built-in) versions — the frontend keeps
		// their custom version IDs client-side and loads them via GET /versions/{uuid}.
		$versions = $user !== null
			? $this->versionRepository->getVisibleToUser($user)
			: $this->versionRepository->getPublic();

		return $response->writeJsonBody(VersionResponse::fromArray($versions));
	}

	/**
	 * Returns a single version by UUID, regardless of any account links. Public and
	 * unauthenticated: the version UUID acts as an unguessable capability, and the
	 * version's data file is served statically anyway. Only versions are exposed here —
	 * never user content. Note the returned dataPath may point to a pruned file; use
	 * POST /versions/{uuid}/data to (re)materialize it.
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

	/**
	 * Makes sure the version's data file exists on disk (regenerating it if it was
	 * pruned) and returns the current dataPath. The frontend calls this before fetching
	 * the static file, and again whenever the static fetch returns 404.
	 */
	#[Path('/{uuid}/data')]
	#[Method('POST')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function ensureData(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$uuid = (string) $request->getParameter('uuid');

		$version = Uuid::isValid($uuid) ? $this->versionRepository->getByUuid($uuid) : null;
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		if ($this->fileService->ensureFile($version)) {
			$this->versionRepository->save($version);
		}

		return $response->writeJsonBody([
			'id' => $version->uuid->toString(),
			'dataPath' => $version->dataPath,
		]);
	}

	/**
	 * Creates a custom version — open to anonymous users. The definition (base version,
	 * ordered mods, multipliers, world data) is stored in the database; the data file is
	 * generated as a cache artifact. An identical definition returns the existing version
	 * (200) instead of creating a duplicate (201). Logged-in callers get the version
	 * linked to their account automatically; anonymous callers must keep the returned id
	 * client-side.
	 */
	#[Path('/')]
	#[Method('POST')]
	public function create(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');

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

		if (count($modsInput) > self::MODS_MAX_COUNT) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field mods may contain at most ' . self::MODS_MAX_COUNT . ' entries']);
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

			// Anonymous callers can only use public mods; logged-in users also their own.
			$modVersion = $user !== null
				? $this->modVersionRepository->getByUuidVisibleToUser($modVersionUuid->toString(), $user)
				: $this->modVersionRepository->getByUuidPublic($modVersionUuid->toString());
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

		$name = trim((string) ($body['name'] ?? ''));
		if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field name may be at most ' . self::NAME_MAX_LENGTH . ' characters long']);
		}
		if ($name === '') {
			$name = $this->defaultName($base, $recipeCost, $powerCost, $modVersions, $worldData);
		}

		$definitionHash = $this->fileService->definitionHash($name, $base, $modVersions, $recipeCost, $powerCost, $worldData);

		// The lock serializes concurrent identical creations — without it, two requests
		// could both miss the lookup and the second insert would hit the unique
		// definitionHash constraint.
		/** @var array{Version, bool} [$version, $created] */
		[$version, $created] = $this->fileService->withLock(
			'definition-' . $definitionHash,
			function () use ($user, $base, $name, $recipeCost, $powerCost, $modVersions, $worldData, $definitionHash): array {
				$existing = $this->versionRepository->getByDefinitionHash($definitionHash);
				if ($existing !== null) {
					$changed = $this->fileService->ensureFile($existing);
					if ($user !== null && !$existing->users->contains($user)) {
						$existing->users->add($user);
						$changed = true;
					}
					if ($changed) {
						$this->versionRepository->save($existing);
					}

					return [$existing, false];
				}

				$version = new Version();
				$version->uuid = Uuid::uuid4();
				$version->name = $name;
				$version->slug = 'custom-' . Random::generate(12);
				$version->experimental = $base->experimental;
				$version->ficsmas = $base->ficsmas;
				$version->custom = true;
				$version->baseVersion = $base;
				$version->recipeCostMultiplier = $recipeCost;
				$version->powerCostMultiplier = $powerCost;
				$version->worldData = $worldData;
				$version->definitionHash = $definitionHash;
				foreach ($modVersions as $position => $modVersion) {
					$link = new VersionModVersion();
					$link->version = $version;
					$link->modVersion = $modVersion;
					$link->position = $position;
					$version->modVersions->add($link);
				}
				if ($user !== null) {
					$version->users->add($user);
				}

				$this->fileService->ensureFile($version);
				$this->versionRepository->save($version);

				return [$version, true];
			},
		);

		return $response->withStatus($created ? IResponse::S201_Created : IResponse::S200_OK)
			->writeJsonBody(VersionResponse::fromEntity($version)->toArray());
	}

	/**
	 * Links custom versions to the caller's account — used after login to adopt the
	 * version IDs an anonymous browser accumulated client-side. Idempotent; unknown,
	 * invalid or non-custom IDs are reported back so the frontend can drop them from
	 * its local list. Body: { versions: string[] }
	 */
	#[Path('/link')]
	#[Method('POST')]
	public function link(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$body = $this->parseBody($request);

		$ids = $body['versions'] ?? null;
		if (!is_array($ids)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field versions must be an array of version UUIDs']);
		}

		if (count($ids) > self::LINK_MAX_IDS) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field versions may contain at most ' . self::LINK_MAX_IDS . ' entries']);
		}

		$linked = [];
		$notFound = [];
		$dirty = false;
		foreach ($ids as $id) {
			$id = (string) $id;
			$version = Uuid::isValid($id) ? $this->versionRepository->getByUuid($id) : null;
			if ($version === null || !$version->custom) {
				$notFound[] = $id;
				continue;
			}

			if (!$version->users->contains($user)) {
				$version->users->add($user);
				$this->versionRepository->save($version, flush: false);
				$dirty = true;
			}
			$linked[] = $version->uuid->toString();
		}

		if ($dirty) {
			$this->versionRepository->flush();
		}

		return $response->writeJsonBody([
			'linked' => $linked,
			'notFound' => $notFound,
		]);
	}

	/**
	 * Removes the caller's link to a custom version ("delete" from their account). The
	 * version itself and its file stay — other users or anonymous browsers may still
	 * reference it, and unused files are reclaimed by the prune job.
	 */
	#[Path('/{uuid}/link')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function unlink(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$uuid = (string) $request->getParameter('uuid');

		$version = Uuid::isValid($uuid) ? $this->versionRepository->getByUuid($uuid) : null;
		if ($version === null || !$version->custom) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		if ($version->users->removeElement($user)) {
			$this->versionRepository->save($version);
		}

		return $response->withStatus(IResponse::S204_NoContent);
	}

	/**
	 * Validates the optional worldData payload sent on version creation: the node counts
	 * and frontend-derived limits (plus the settings they were computed from), which get
	 * stored in the database and stamped under metadata.world in the generated data file.
	 * Only known keys are kept, and the payload size is capped since anyone can submit it.
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

		if (strlen(json_encode($clean)) > self::WORLD_DATA_MAX_BYTES) {
			return [null, 'Field worldData is too large (max ' . self::WORLD_DATA_MAX_BYTES . ' bytes)'];
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

	/**
	 * @param ModVersion[] $modVersions
	 * @param array<string, mixed>|null $worldData
	 */
	private function defaultName(Version $base, float $recipeCost, float $powerCost, array $modVersions, ?array $worldData): string
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
		if ($worldData !== null) {
			$parts[] = isset($worldData['seed']) ? 'seed ' . $worldData['seed'] : 'custom world';
		}

		return $base->name . ' (' . implode(', ', $parts) . ')';
	}

	private function formatMultiplier(float $value): string
	{
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}

}
