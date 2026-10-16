<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthIdentityRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\SupporterRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use greeny\SatisfactoryTools\Api\Schema\Responses\Auth\ProfileResponse;
use Nette\Http\IResponse;

// Outside /v1/auth on purpose, so the frontend's regular auth interceptor applies to it.
#[Path('/account')]
class AccountController extends BaseV1Controller
{

	// MAX matches the column width.
	private const int DISPLAY_NAME_MIN_LENGTH = 1;
	private const int DISPLAY_NAME_MAX_LENGTH = 50;

	private const array SUPPORTER_FLAGS = ['listed', 'shareBadge', 'accent', 'nameHeart'];

	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly OAuthIdentityRepository $identityRepository,
		private readonly SupporterRepository $supporterRepository,
		private readonly SupporterService $supporterService,
	)
	{
	}

	#[Path('/')]
	#[Method('GET')]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $this->unauthorized($response);
		}

		return $response->writeJsonBody($this->profile($user));
	}

	#[Path('/')]
	#[Method('PUT')]
	public function update(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$body = $this->parseBody($request);

		if (!array_key_exists('displayName', $body)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field displayName is required (use null to clear it)']);
		}

		$raw = $body['displayName'];
		if ($raw !== null && !is_string($raw)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field displayName must be a string or null']);
		}

		$displayName = null;
		if ($raw !== null) {
			$displayName = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', '', $raw)));
			if ($displayName === '') {
				$displayName = null;
			} elseif (mb_strlen($displayName) < self::DISPLAY_NAME_MIN_LENGTH || mb_strlen($displayName) > self::DISPLAY_NAME_MAX_LENGTH) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody([
						'error' => 'Field displayName must be between ' . self::DISPLAY_NAME_MIN_LENGTH . ' and ' . self::DISPLAY_NAME_MAX_LENGTH . ' characters long',
					]);
			}
		}

		if ($displayName !== $user->displayName) {
			$user->displayName = $displayName;
			$this->userRepository->save($user);
		}

		return $response->writeJsonBody($this->profile($user));
	}

	/** Partial update: fields left out keep their value. "welcomed": true records that the thank-you dialog was answered. */
	#[Path('/supporter')]
	#[Method('PUT')]
	public function updateSupporter(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$supporter = $this->supporterRepository->getByUser($user);
		if ($supporter === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'This account is not marked as a supporter']);
		}

		$body = $this->parseBody($request);

		foreach (self::SUPPORTER_FLAGS as $flag) {
			if (array_key_exists($flag, $body) && !is_bool($body[$flag])) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field ' . $flag . ' must be a boolean']);
			}
		}

		$listName = $supporter->listName;
		if (array_key_exists('listName', $body)) {
			$listName = $body['listName'] === null ? null : $this->supporterService->normalizeListName($body['listName']);
			if ($body['listName'] !== null && $listName === null) {
				return $response->withStatus(IResponse::S400_BadRequest)
					->writeJsonBody(['error' => 'Field listName must be 1 to ' . SupporterService::LIST_NAME_MAX_LENGTH . ' characters long']);
			}
		}

		$listed = is_bool($body['listed'] ?? null) ? $body['listed'] : $supporter->listed;
		if ($listed && $listName === null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'A name is needed to appear on the supporters list']);
		}

		$supporter->listName = $listName;
		$supporter->listed = $listed;
		$supporter->shareBadge = is_bool($body['shareBadge'] ?? null) ? $body['shareBadge'] : $supporter->shareBadge;
		$supporter->accent = is_bool($body['accent'] ?? null) ? $body['accent'] : $supporter->accent;
		$supporter->nameHeart = is_bool($body['nameHeart'] ?? null) ? $body['nameHeart'] : $supporter->nameHeart;
		if (($body['welcomed'] ?? null) === true) {
			$supporter->welcomedAt ??= new DateTimeImmutable();
		}
		$this->supporterRepository->save($supporter);

		return $response->writeJsonBody($this->profile($user));
	}

	/** @return array<string, mixed> */
	private function profile(User $user): array
	{
		return ProfileResponse::fromUser(
			$user,
			$this->identityRepository->findAllForUser($user),
			$this->supporterRepository->getByUser($user),
		)->toArray();
	}

	private function unauthorized(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S401_Unauthorized)
			->writeJsonBody(['error' => 'Unauthorized']);
	}

}
