<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\ShareInputException;
use greeny\SatisfactoryTools\Api\Model\Services\ShareService;
use Nette\Http\IResponse;
use Ramsey\Uuid\Uuid;

#[Path('/shares')]
class SharesController extends BaseV1Controller
{

	private const TYPES = ['folder', 'plan'];

	/** This is the only unauthenticated write storing caller-sent data, so it must be bounded; ShareService also caps the tree shape. */
	private const BODY_MAX_BYTES = 1048576;

	public function __construct(
		private readonly ShareService $shareService,
		private readonly VersionRepository $versionRepository,
		private readonly FolderRepository $folderRepository,
		private readonly PlanRepository $planRepository,
	)
	{
	}

	/** Sharing a client-sent `root` tree needs no account because a signed-out user's plans live only in their browser. */
	#[Path('/')]
	#[Method('POST')]
	public function create(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');

		$raw = (string) $request->getBody();
		if (strlen($raw) > self::BODY_MAX_BYTES) {
			return $response->withStatus(IResponse::S413_RequestEntityTooLarge)
				->writeJsonBody(['error' => 'The shared tree is too large (at most ' . intdiv(self::BODY_MAX_BYTES, 1024) . ' kB)']);
		}

		$body = $this->parseBodyString($raw);
		$type = trim((string) ($body['type'] ?? ''));
		$versionId = trim((string) ($body['version'] ?? ''));
		$hasRoot = array_key_exists('root', $body);
		$id = trim((string) ($body['id'] ?? ''));

		if (!in_array($type, self::TYPES, true)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => "Field type must be one of: " . implode(', ', self::TYPES)]);
		}

		if ($versionId === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field version is required']);
		}

		if (!Uuid::isValid($versionId)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field version must be a valid UUID']);
		}

		if ($hasRoot && $id !== '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Provide either id (a tree stored on the server) or root (a tree sent with the request), not both']);
		}

		if (!$hasRoot && $id === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field id or root is required']);
		}

		$version = $this->versionRepository->getByUuid($versionId);
		if ($version === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Version not found']);
		}

		if ($hasRoot) {
			try {
				$share = $this->shareService->shareTree($user, $version, $type, $body['root']);
			} catch (ShareInputException $e) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => $e->getMessage()]);
			}
		} else {
			if ($user === null) {
				return $response->withStatus(IResponse::S401_Unauthorized)
					->writeJsonBody(['error' => 'Unauthorized']);
			}

			if (!Uuid::isValid($id)) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field id must be a valid UUID']);
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
		}

		return $response->withStatus(IResponse::S201_Created)->writeJsonBody([
			'share' => $share->uuid->toString(),
			'type' => $share->type,
			'createdAt' => $share->createdAt->format('c'),
		]);
	}

	// The /visited endpoints must stay declared above get(): the router matches in declaration order and /{uuid} would otherwise swallow them.
	#[Path('/visited')]
	#[Method('GET')]
	public function visitedList(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		return $response->writeJsonBody($this->shareService->visitedShares($user));
	}

	#[Path('/visited/{uuid}')]
	#[Method('PUT')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function visitedPut(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		try {
			$recorded = $this->shareService->recordVisit($user, (string) $request->getParameter('uuid'));
		} catch (UniqueConstraintViolationException) {
			// A concurrent request inserted the same entry first; the visit is recorded either way.
			$recorded = true;
		}

		if (!$recorded) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Share not found']);
		}

		return $response->withStatus(IResponse::S204_NoContent);
	}

	#[Path('/visited/{uuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function visitedDelete(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$this->shareService->forgetVisit($user, (string) $request->getParameter('uuid'));

		return $response->withStatus(IResponse::S204_NoContent);
	}

	/** Public by design: anyone with the link can view the share. */
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
