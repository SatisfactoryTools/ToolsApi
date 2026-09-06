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

/**
 * The signed-in user's own account profile. Lives outside /v1/auth on purpose: it is
 * not part of the sign-in flow, so the frontend's regular auth interceptor (Bearer
 * header, token refresh) applies to it like to any other resource.
 */
#[Path('/account')]
class AccountController extends BaseV1Controller
{

	/** Bounds for a user-chosen display name (also the column width). */
	private const DISPLAY_NAME_MIN_LENGTH = 1;
	private const DISPLAY_NAME_MAX_LENGTH = 50;

	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly OAuthIdentityRepository $identityRepository,
	)
	{
	}

	/** Returns the profile of the user identified by the access token. */
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

	/**
	 * Sets or clears the user's display name. Body: { displayName: string|null } — a
	 * string is trimmed and must be 1–50 characters (uniqueness is not required); null
	 * or an empty string clears it so the resolution falls back to login / provider
	 * nickname. Returns the updated profile.
	 */
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
			// Collapse inner whitespace runs and drop control characters so the name
			// renders the same everywhere it is shown.
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
