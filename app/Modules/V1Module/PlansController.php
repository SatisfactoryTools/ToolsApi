<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use greeny\SatisfactoryTools\Api\Model\Entities\Folder;
use greeny\SatisfactoryTools\Api\Model\Entities\Plan;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Schema\Responses\PlanResponse;
use Nette\Http\IResponse;
use Ramsey\Uuid\Uuid;

#[Path('/versions')]
class PlansController extends BaseV1Controller
{

	public function __construct(
		private readonly PlanRepository $planRepository,
		private readonly VersionRepository $versionRepository,
		private readonly FolderRepository $folderRepository,
	)
	{
	}

	#[Path('/{version}/plans')]
	#[Method('GET')]
	#[RequestParameter(name: 'version', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function list(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$version = $this->getVersion($request);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		$folders = $this->folderRepository->getByUserAndVersion($user, $version);
		$plans = $this->planRepository->getByUserAndVersion($user, $version);

		return $response->writeJsonBody($this->buildTree($folders, $plans));
	}

	#[Path('/{version}/plans')]
	#[Method('POST')]
	#[RequestParameter(name: 'version', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function create(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$version = $this->getVersion($request);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		$body = $this->parseBody($request);

		$id = trim((string) ($body['id'] ?? ''));
		$name = trim((string) ($body['name'] ?? ''));
		$data = (string) ($body['data'] ?? '{}');
		$description = isset($body['description']) ? (string) $body['description'] : null;

		if ($id === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field id is required']);
		}

		try {
			$uuid = Uuid::fromString($id);
		} catch (\InvalidArgumentException) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field id is not a valid UUID']);
		}

		if (isset($body['folder']) && isset($body['parent'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'A plan cannot have both a folder and a parent plan']);
		}

		$folder = null;
		if (isset($body['folder']) && $body['folder'] !== null) {
			$folder = $this->folderRepository->getByUuidAndUserAndVersion(
				Uuid::fromString((string) $body['folder']),
				$user,
				$version,
			);
			if ($folder === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Folder not found']);
			}
		}

		$parent = null;
		if (isset($body['parent']) && $body['parent'] !== null) {
			$parent = $this->planRepository->getByUuidAndUserAndVersion(
				Uuid::fromString((string) $body['parent']),
				$user,
				$version,
			);
			if ($parent === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Parent plan not found']);
			}
		}

		$plan = new Plan();
		$plan->uuid = $uuid;
		$plan->name = $name;
		$plan->user = $user;
		$plan->description = $description;
		$plan->version = $version;
		$plan->folder = $folder;
		$plan->parent = $parent;
		$plan->data = $data;
		$plan->createdAt = new DateTimeImmutable();
		$plan->updatedAt = $plan->createdAt;

		try {
			$this->planRepository->save($plan);
		} catch (UniqueConstraintViolationException) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody(['error' => 'A plan with this id already exists']);
		}

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(PlanResponse::fromEntity($plan)->toArray());
	}

	#[Path('/{version}/plans/{uuid}')]
	#[Method('GET')]
	#[RequestParameter(name: 'version', type: 'uuid', in: EndpointParameter::IN_PATH)]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$version = $this->getVersion($request);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		$plan = $this->planRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($plan === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Plan not found']);
		}

		return $response->writeJsonBody(PlanResponse::fromEntity($plan)->toArray());
	}

	#[Path('/{version}/plans/{uuid}')]
	#[Method('PUT')]
	#[RequestParameter(name: 'version', type: 'uuid', in: EndpointParameter::IN_PATH)]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function update(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$version = $this->getVersion($request);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		$plan = $this->planRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($plan === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Plan not found']);
		}

		$body = $this->parseBody($request);

		if (!isset($body['revision'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field revision is required']);
		}

		$clientRevision = (int) $body['revision'];
		if ($clientRevision !== $plan->revision) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody([
					'error' => 'Conflict',
					'message' => 'Plan was modified by another session. Reload the plan before saving.',
					'currentRevision' => $plan->revision,
				]);
		}

		if (isset($body['version'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Plan version cannot be changed']);
		}

		if (isset($body['name'])) {
			$plan->name = $body['name'];
		}

		if (array_key_exists('description', $body)) {
			$plan->description = $body['description'] !== null ? (string) $body['description'] : null;
		}

		if (isset($body['data'])) {
			$plan->data = (string) $body['data'];
		}

		$plan->revision++;
		$plan->updatedAt = new DateTimeImmutable();
		$this->planRepository->save($plan);

		return $response->writeJsonBody(PlanResponse::fromEntity($plan)->toArray());
	}

	#[Path('/{version}/plans/{uuid}/move')]
	#[Method('POST')]
	#[RequestParameter(name: 'version', type: 'uuid', in: EndpointParameter::IN_PATH)]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function move(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$version = $this->getVersion($request);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		$plan = $this->planRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($plan === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Plan not found']);
		}

		$body = $this->parseBody($request);
		$hasFolder = array_key_exists('folder', $body);
		$hasParent = array_key_exists('parent', $body);

		if ($hasFolder && $hasParent) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Provide either folder or parent, not both']);
		}

		if (!$hasFolder && !$hasParent) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Provide either folder (use null for root) or parent']);
		}

		if ($hasParent) {
			if ($body['parent'] === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field parent must not be null (use folder: null to move to root)']);
			}
			$parent = $this->planRepository->getByUuidAndUserAndVersion(
				Uuid::fromString((string) $body['parent']),
				$user,
				$version,
			);
			if ($parent === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Parent plan not found']);
			}
			if ($parent->id === $plan->id || $this->wouldCreateCycle($plan, $parent)) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Plan cannot be moved into its own subtree']);
			}
			$plan->parent = $parent;
			$plan->folder = null;
		} elseif ($body['folder'] !== null) {
			$folder = $this->folderRepository->getByUuidAndUserAndVersion(
				Uuid::fromString((string) $body['folder']),
				$user,
				$version,
			);
			if ($folder === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Folder not found']);
			}
			$plan->folder = $folder;
			$plan->parent = null;
		} else {
			$plan->folder = null;
			$plan->parent = null;
		}

		$plan->updatedAt = new DateTimeImmutable();
		$this->planRepository->save($plan);

		return $response->writeJsonBody(PlanResponse::fromEntity($plan)->toArray());
	}

	private function wouldCreateCycle(Plan $planToMove, Plan $newParent): bool
	{
		$current = $newParent->parent;
		while ($current !== null) {
			if ($current->id === $planToMove->id) {
				return true;
			}
			$current = $current->parent;
		}
		return false;
	}

	#[Path('/{version}/plans/{uuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'version', type: 'uuid', in: EndpointParameter::IN_PATH)]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function delete(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$version = $this->getVersion($request);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		$plan = $this->planRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($plan === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Plan not found']);
		}

		$this->planRepository->delete($plan);

		return $response->withStatus(IResponse::S204_NoContent);
	}

	private function getVersion(ApiRequest $request): ?Version
	{
		// Any version is addressable by UUID (the UUID acts as a capability); the plans
		// and folders themselves are still scoped to the user.
		return $this->versionRepository->getByUuid(
			(string) $this->getUuidParameter($request, 'version'),
		);
	}

	/**
	 * @param Folder[] $folders
	 * @param Plan[] $plans
	 * @return array<string, mixed>
	 */
	private function buildTree(array $folders, array $plans): array
	{
		$nodes = [];
		foreach ($folders as $folder) {
			$nodes[$folder->id] = [
				'id' => $folder->uuid->toString(),
				'name' => $folder->name,
				'version' => $folder->version->uuid->toString(),
				'data' => $folder->data,
				'createdAt' => $folder->createdAt->format('c'),
				'revision' => $folder->revision,
				'children' => [],
				'plans' => [],
			];
		}

		$plansByParent = [];
		$topLevel = [];
		foreach ($plans as $plan) {
			if ($plan->parent !== null) {
				$plansByParent[$plan->parent->id][] = $plan;
			} else {
				$topLevel[] = $plan;
			}
		}

		$rootPlans = [];
		foreach ($topLevel as $plan) {
			$planData = $this->buildPlanNode($plan, $plansByParent);
			if ($plan->folder !== null) {
				$nodes[$plan->folder->id]['plans'][] = $planData;
			} else {
				$rootPlans[] = $planData;
			}
		}

		return [
			'folders' => $this->collectChildren($folders, $nodes, null),
			'plans' => $rootPlans,
		];
	}

	/**
	 * @param array<int, Plan[]> $plansByParent
	 * @return array<string, mixed>
	 */
	private function buildPlanNode(Plan $plan, array $plansByParent): array
	{
		$node = PlanResponse::fromEntity($plan)->toArray();
		$node['subplans'] = array_map(
			fn (Plan $subplan): array => $this->buildPlanNode($subplan, $plansByParent),
			$plansByParent[$plan->id] ?? [],
		);
		return $node;
	}

	/**
	 * @param Folder[] $folders
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private function collectChildren(array $folders, array $nodes, ?int $parentId): array
	{
		$result = [];
		foreach ($folders as $folder) {
			if (($folder->parent?->id ?? null) === $parentId) {
				$node = $nodes[$folder->id];
				$node['children'] = $this->collectChildren($folders, $nodes, $folder->id);
				$result[] = $node;
			}
		}
		return $result;
	}

}
