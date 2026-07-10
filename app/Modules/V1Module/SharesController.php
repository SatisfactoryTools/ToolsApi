<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\ShareService;
use Nette\Http\IResponse;
use Ramsey\Uuid\Uuid;

#[Path('/shares')]
class SharesController extends BaseV1Controller
{

	private const TYPES = ['folder', 'plan'];

	public function __construct(
		private readonly ShareService $shareService,
		private readonly VersionRepository $versionRepository,
		private readonly FolderRepository $folderRepository,
		private readonly PlanRepository $planRepository,
	)
	{
	}

	/**
	 * Creates a point-in-time share of a folder or plan (and everything under it) and
	 * returns the share UUID. Requires ownership of the target.
	 * Body: { version: uuid, type: 'folder'|'plan', id: uuid }
	 */
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
		$type = trim((string) ($body['type'] ?? ''));
		$versionId = trim((string) ($body['version'] ?? ''));
		$id = trim((string) ($body['id'] ?? ''));

		if (!in_array($type, self::TYPES, true)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => "Field type must be one of: " . implode(', ', self::TYPES)]);
		}

		if ($versionId === '' || $id === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Fields version and id are required']);
		}

		if (!Uuid::isValid($versionId) || !Uuid::isValid($id)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Fields version and id must be valid UUIDs']);
		}

		$version = $this->versionRepository->getByUuidVisibleToUser($versionId, $user);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		if ($type === 'folder') {
			$folder = $this->folderRepository->getByUuidAndUserAndVersion(Uuid::fromString($id), $user, $version);
			if ($folder === null) {
				return $response->withStatus(IResponse::S404_NotFound)
					->writeJsonBody(['error' => 'Folder not found']);
			}
			$share = $this->shareService->shareFolder($user, $version, $folder);
		} else {
			$plan = $this->planRepository->getByUuidAndUserAndVersion(Uuid::fromString($id), $user, $version);
			if ($plan === null) {
				return $response->withStatus(IResponse::S404_NotFound)
					->writeJsonBody(['error' => 'Plan not found']);
			}
			$share = $this->shareService->sharePlan($user, $version, $plan);
		}

		return $response->withStatus(IResponse::S201_Created)->writeJsonBody([
			'share' => $share->uuid->toString(),
			'type' => $share->type,
			'createdAt' => $share->createdAt->format('c'),
		]);
	}

	/**
	 * Loads a share by its UUID. Public and read-only: no authentication required, so
	 * anyone with the link can view the frozen folder/plan tree.
	 */
	#[Path('/{uuid}')]
	#[Method('GET')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$snapshot = $this->shareService->present((string) $request->getParameter('uuid'));
		if ($snapshot === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Share not found']);
		}

		return $response->writeJsonBody($snapshot);
	}

}
