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
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\UserQuotas;
use greeny\SatisfactoryTools\Api\Schema\Responses\FolderResponse;
use Nette\Http\IResponse;

#[Path('/versions')]
class FoldersController extends BaseV1Controller
{

	public function __construct(
		private readonly FolderRepository $folderRepository,
		private readonly VersionRepository $versionRepository,
		private readonly UserQuotas $quotas,
	)
	{
	}

	#[Path('/{version}/folders')]
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

		$nameError = $this->nameError($body['name'] ?? '', 'name');
		if ($nameError !== null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => $nameError]);
		}

		$id = trim((string) ($body['id'] ?? ''));
		$name = trim((string) ($body['name'] ?? ''));
		$data = (string) ($body['data'] ?? '{}');

		if ($id === '' || $name === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Fields id and name are required']);
		}

		try {
			$uuid = \Ramsey\Uuid\Uuid::fromString($id);
		} catch (\InvalidArgumentException) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field id is not a valid UUID']);
		}

		$parent = null;
		if (isset($body['parent']) && $body['parent'] !== null) {
			$parentUuid = $this->toUuid($body['parent']);
			$parent = $parentUuid !== null ? $this->folderRepository->getByUuidAndUserAndVersion($parentUuid, $user, $version) : null;
			if ($parent === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Parent folder not found']);
			}
		}

		if ($this->folderRepository->countByUser($user) >= $this->quotas->folders) {
			return $response->withStatus(IResponse::S403_Forbidden)
				->writeJsonBody(['error' => 'You have reached the limit of ' . $this->quotas->folders . ' folders. Delete some folders to make room.']);
		}

		$folder = new Folder();
		$folder->uuid = $uuid;
		$folder->name = $name;
		$folder->user = $user;
		$folder->version = $version;
		$folder->parent = $parent;
		$folder->data = $data;
		$folder->createdAt = new DateTimeImmutable();

		try {
			$this->folderRepository->save($folder);
		} catch (UniqueConstraintViolationException) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody(['error' => 'A folder with this id already exists']);
		}

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(FolderResponse::fromEntity($folder)->toArray());
	}

	#[Path('/{version}/folders/{uuid}')]
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

		$folder = $this->folderRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($folder === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Folder not found']);
		}

		$body = $this->parseBody($request);

		if (!isset($body['revision'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field revision is required']);
		}

		$clientRevision = (int) $body['revision'];
		if ($clientRevision !== $folder->revision) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody([
					'error' => 'Conflict',
					'message' => 'Folder was modified by another session. Reload the folder before saving.',
					'currentRevision' => $folder->revision,
				]);
		}

		if (isset($body['name'])) {
			$nameError = $this->nameError($body['name'], 'name');
			if ($nameError !== null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => $nameError]);
			}
			$name = trim($body['name']);
			if ($name === '') {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field name must not be empty']);
			}
			$folder->name = $name;
		}

		if (isset($body['data'])) {
			$folder->data = (string) $body['data'];
		}

		$folder->revision++;
		$this->folderRepository->save($folder);

		return $response->writeJsonBody(FolderResponse::fromEntity($folder)->toArray());
	}

	#[Path('/{version}/folders/{uuid}/move')]
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

		$folder = $this->folderRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($folder === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Folder not found']);
		}

		$body = $this->parseBody($request);
		$parentId = array_key_exists('parent', $body) ? $body['parent'] : false;

		if ($parentId === false) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field parent is required (use null for root)']);
		}

		if ($parentId !== null) {
			$parentUuid = $this->toUuid($parentId);
			$parent = $parentUuid !== null ? $this->folderRepository->getByUuidAndUserAndVersion($parentUuid, $user, $version) : null;
			if ($parent === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Parent folder not found']);
			}
			if ($parent->id === $folder->id || $this->wouldCreateCycle($folder, $parent)) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Cannot move a folder into itself or one of its descendants']);
			}
			$folder->parent = $parent;
		} else {
			$folder->parent = null;
		}

		$this->folderRepository->save($folder);

		return $response->writeJsonBody(FolderResponse::fromEntity($folder)->toArray());
	}

	#[Path('/{version}/folders/{uuid}')]
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

		$folder = $this->folderRepository->getByUuidAndUserAndVersion($this->getUuidParameter($request), $user, $version);

		if ($folder === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Folder not found']);
		}

		$this->folderRepository->delete($folder);

		return $response->withStatus(IResponse::S204_NoContent);
	}

	private function getVersion(ApiRequest $request): ?Version
	{
		// Not scoped to the user on purpose: any version is addressable by UUID.
		return $this->versionRepository->getByUuid(
			(string) $this->getUuidParameter($request, 'version'),
		);
	}

	private function wouldCreateCycle(Folder $folderToMove, Folder $newParent): bool
	{
		$current = $newParent->parent;
		while ($current !== null) {
			if ($current->id === $folderToMove->id) {
				return true;
			}
			$current = $current->parent;
		}
		return false;
	}

}
