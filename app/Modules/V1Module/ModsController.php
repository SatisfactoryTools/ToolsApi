<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Mod;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModVersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\ModDataStorage;
use greeny\SatisfactoryTools\Api\Schema\Responses\ModResponse;
use greeny\SatisfactoryTools\Api\Schema\Responses\ModVersionResponse;
use Nette\Http\IResponse;
use Ramsey\Uuid\Uuid;

#[Path('/mods')]
class ModsController extends BaseV1Controller
{

	public function __construct(
		private readonly ModRepository $modRepository,
		private readonly ModVersionRepository $modVersionRepository,
		private readonly ModDataStorage $modDataStorage,
	)
	{
	}

	#[Path('/')]
	#[Method('GET')]
	public function list(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');

		// Authenticated users see public mods plus their own; guests see public mods only.
		$mods = $user !== null
			? $this->modRepository->getVisibleToUser($user)
			: $this->modRepository->getPublic();

		return $response->writeJsonBody(array_map(
			static fn (Mod $mod): array => ModResponse::fromEntity($mod, $user)->toArray(),
			$mods,
		));
	}

	#[Path('/{uuid}')]
	#[Method('GET')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');

		$mod = $user !== null
			? $this->modRepository->getByUuidVisibleToUser((string) $this->getUuidParameter($request), $user)
			: $this->publicMod((string) $this->getUuidParameter($request));

		if ($mod === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod not found']);
		}

		return $response->writeJsonBody(ModResponse::fromEntity($mod, $user)->toArray());
	}

	#[Path('/')]
	#[Method('POST')]
	public function create(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireUser($request);
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$body = $this->parseBody($request);
		$name = trim((string) ($body['name'] ?? ''));
		if ($name === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field name is required']);
		}

		$mod = new Mod();
		$mod->name = $name;
		$mod->public = (bool) ($body['public'] ?? false);
		$mod->user = $user;
		$mod->createdAt = new DateTimeImmutable();

		$this->modRepository->save($mod);

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(ModResponse::fromEntity($mod, $user)->toArray());
	}

	#[Path('/{uuid}')]
	#[Method('PUT')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function update(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireUser($request);
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$mod = $this->modRepository->getByUuidAndOwner((string) $this->getUuidParameter($request), $user);
		if ($mod === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod not found']);
		}

		$body = $this->parseBody($request);

		if (isset($body['name'])) {
			$name = trim((string) $body['name']);
			if ($name === '') {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field name must not be empty']);
			}
			$mod->name = $name;
		}

		if (array_key_exists('public', $body)) {
			$mod->public = (bool) $body['public'];
		}

		$this->modRepository->save($mod);

		return $response->writeJsonBody(ModResponse::fromEntity($mod, $user)->toArray());
	}

	#[Path('/{uuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function delete(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireUser($request);
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$mod = $this->modRepository->getByUuidAndOwner((string) $this->getUuidParameter($request), $user);
		if ($mod === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod not found']);
		}

		// Remove the data files; the DB rows cascade when the mod is deleted.
		foreach ($mod->versions as $version) {
			$this->modDataStorage->delete($version->dataPath);
		}

		$this->modRepository->delete($mod);

		return $response->withStatus(IResponse::S204_NoContent);
	}

	#[Path('/{uuid}/versions')]
	#[Method('POST')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function createVersion(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireUser($request);
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$mod = $this->modRepository->getByUuidAndOwner((string) $this->getUuidParameter($request), $user);
		if ($mod === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod not found']);
		}

		$body = $this->parseBody($request);
		$name = trim((string) ($body['name'] ?? ''));
		if ($name === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field name is required']);
		}

		if (array_key_exists('data', $body) && $body['data'] !== null && !is_array($body['data'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field data must be a JSON object or null']);
		}

		$modVersion = new ModVersion();
		$modVersion->uuid = Uuid::uuid4();
		$modVersion->mod = $mod;
		$modVersion->name = $name;
		$modVersion->createdAt = new DateTimeImmutable();

		if (isset($body['data']) && is_array($body['data'])) {
			$modVersion->dataPath = $this->modDataStorage->store($modVersion->uuid, $body['data']);
		}

		$this->modVersionRepository->save($modVersion);

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(ModVersionResponse::fromEntity($modVersion)->toArray());
	}

	#[Path('/{uuid}/versions/{versionUuid}')]
	#[Method('PUT')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	#[RequestParameter(name: 'versionUuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function updateVersion(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireUser($request);
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$mod = $this->modRepository->getByUuidAndOwner((string) $this->getUuidParameter($request), $user);
		if ($mod === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod not found']);
		}

		$modVersion = $this->modVersionRepository->getByUuidAndMod($this->getUuidParameter($request, 'versionUuid'), $mod);
		if ($modVersion === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod version not found']);
		}

		$body = $this->parseBody($request);

		if (isset($body['name'])) {
			$name = trim((string) $body['name']);
			if ($name === '') {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field name must not be empty']);
			}
			$modVersion->name = $name;
		}

		if (array_key_exists('data', $body)) {
			if ($body['data'] === null) {
				$this->modDataStorage->delete($modVersion->dataPath);
				$modVersion->dataPath = null;
			} elseif (is_array($body['data'])) {
				$modVersion->dataPath = $this->modDataStorage->store($modVersion->uuid, $body['data']);
			} else {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field data must be a JSON object or null']);
			}
		}

		$this->modVersionRepository->save($modVersion);

		return $response->writeJsonBody(ModVersionResponse::fromEntity($modVersion)->toArray());
	}

	#[Path('/{uuid}/versions/{versionUuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	#[RequestParameter(name: 'versionUuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function deleteVersion(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireUser($request);
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$mod = $this->modRepository->getByUuidAndOwner((string) $this->getUuidParameter($request), $user);
		if ($mod === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod not found']);
		}

		$modVersion = $this->modVersionRepository->getByUuidAndMod($this->getUuidParameter($request, 'versionUuid'), $mod);
		if ($modVersion === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Mod version not found']);
		}

		$this->modDataStorage->delete($modVersion->dataPath);
		$this->modVersionRepository->delete($modVersion);

		return $response->withStatus(IResponse::S204_NoContent);
	}

	private function publicMod(string $uuid): ?Mod
	{
		$mod = $this->modRepository->getByUuid($uuid);
		return $mod !== null && $mod->public ? $mod : null;
	}

	private function requireUser(ApiRequest $request): ?User
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		return $user;
	}

	private function unauthorized(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S401_Unauthorized)
			->writeJsonBody(['error' => 'Unauthorized']);
	}

}
