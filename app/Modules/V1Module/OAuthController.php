<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Services\AuthException;
use greeny\SatisfactoryTools\Api\Model\Services\OAuth\OAuthService;
use greeny\SatisfactoryTools\Api\Schema\Responses\Auth\TokenResponse;
use Nette\Http\IResponse;

#[Path('/auth/oauth')]
class OAuthController extends BaseV1Controller
{

	public function __construct(
		private readonly OAuthService $oauthService,
	)
	{
	}

	#[Path('/providers')]
	#[Method('GET')]
	public function providers(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		return $response->writeJsonBody(['providers' => $this->oauthService->getProviderKeys()]);
	}

	#[Path('/{provider}/start')]
	#[Method('POST')]
	#[RequestParameter(name: 'provider', type: 'string', in: EndpointParameter::IN_PATH)]
	public function start(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$provider = (string) $request->getParameter('provider');
		if (!$this->oauthService->hasProvider($provider)) {
			return $this->notFound($response);
		}

		/** @var User|null $user */
		$user = $request->getAttribute('user');

		$body = $this->parseBody($request);
		$desktop = ($body['desktop'] ?? false) === true;

		try {
			$result = $this->oauthService->start($provider, $user, $desktop);
		} catch (AuthException $e) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => $e->getMessage()]);
		}

		return $response->writeJsonBody($result);
	}

	#[Path('/{provider}/callback')]
	#[Method('POST')]
	#[RequestParameter(name: 'provider', type: 'string', in: EndpointParameter::IN_PATH)]
	public function callback(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$provider = (string) $request->getParameter('provider');
		if (!$this->oauthService->hasProvider($provider)) {
			return $this->notFound($response);
		}

		$body = $this->parseBody($request);
		$params = isset($body['params']) && is_array($body['params']) ? $body['params'] : $body;

		try {
			$result = $this->oauthService->handleCallback($provider, $params);
		} catch (AuthException $e) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => $e->getMessage()]);
		}

		$body = $result->linked
			? [
				'linked' => true,
				'provider' => $result->provider,
				'message' => ucfirst($result->provider) . ' connected successfully',
			]
			: TokenResponse::fromTokens($result->tokens)->toArray() + ['provider' => $result->provider];

		if ($result->desktopState !== null) {
			$this->oauthService->completeDesktop($result->desktopState, $body);

			return $response->writeJsonBody([
				'desktop' => true,
				'provider' => $result->provider,
				'linked' => $result->linked,
			]);
		}

		return $response->writeJsonBody($body);
	}

	#[Path('/desktop/poll')]
	#[Method('POST')]
	public function desktopPoll(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);
		$state = $this->oauthService->findDesktopState(
			is_string($body['state'] ?? null) ? $body['state'] : '',
			is_string($body['pollToken'] ?? null) ? $body['pollToken'] : '',
		);
		if ($state === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Unknown or expired sign-in']);
		}

		if ($state->completedAt === null) {
			return $response->withStatus(IResponse::S202_Accepted)
				->writeJsonBody(['pending' => true]);
		}

		$result = $this->oauthService->consumeDesktopResult($state);
		if (isset($result['error'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody($result);
		}

		return $response->writeJsonBody($result);
	}

	#[Path('/connections')]
	#[Method('GET')]
	public function connections(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $this->unauthorized($response);
		}

		return $response->writeJsonBody([
			'hasPassword' => $user->passwordHash !== null,
			'connections' => $this->oauthService->listConnections($user),
		]);
	}

	#[Path('/{provider}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'provider', type: 'string', in: EndpointParameter::IN_PATH)]
	public function unlink(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $this->unauthorized($response);
		}

		$provider = (string) $request->getParameter('provider');

		try {
			$this->oauthService->unlink($user, $provider);
		} catch (AuthException $e) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => $e->getMessage()]);
		}

		return $response->writeJsonBody(['message' => ucfirst($provider) . ' disconnected successfully']);
	}

	private function notFound(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S404_NotFound)
			->writeJsonBody(['error' => 'Unknown OAuth provider']);
	}

	private function unauthorized(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S401_Unauthorized)
			->writeJsonBody(['error' => 'Unauthorized']);
	}

}
