<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthIdentityRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Schema\Responses\Auth\ProfileResponse;
use Nette\Http\IResponse;

// Outside /v1/auth on purpose, so the frontend's regular auth interceptor applies to it.
#[Path('/account')]
class AccountController extends BaseV1Controller
{

	// MAX matches the column width.
	private const int DISPLAY_NAME_MIN_LENGTH = 1;
	private const int DISPLAY_NAME_MAX_LENGTH = 50;

	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly OAuthIdentityRepository $identityRepository,
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

	/** @return array<string, mixed> */
	private function profile(User $user): array
	{
		return ProfileResponse::fromUser($user, $this->identityRepository->findAllForUser($user))->toArray();
	}

	private function unauthorized(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S401_Unauthorized)
			->writeJsonBody(['error' => 'Unauthorized']);
	}

}
