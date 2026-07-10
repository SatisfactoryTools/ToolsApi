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

	/**
	 * Lists the third-party providers this deployment has enabled, so the frontend can
	 * render only the buttons that will actually work.
	 */
	#[Path('/providers')]
	#[Method('GET')]
	public function providers(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		return $response->writeJsonBody(['providers' => $this->oauthService->getProviderKeys()]);
	}

	/**
	 * Begins an authorization flow and returns the URL the browser should be sent to.
	 * If a valid Bearer token is present, the flow links the provider to that account
	 * instead of logging in / signing up.
	 */
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

		try {
			$result = $this->oauthService->start($provider, $user);
		} catch (AuthException $e) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => $e->getMessage()]);
		}

		return $response->writeJsonBody($result);
	}

	/**
	 * Completes an authorization flow. The frontend forwards the query parameters the
	 * provider redirected back with (for Steam, all `openid.*` fields plus `state`).
	 */
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

		if ($result->linked) {
			return $response->writeJsonBody([
				'linked' => true,
				'provider' => $result->provider,
				'message' => ucfirst($result->provider) . ' connected successfully',
			]);
		}

		return $response->writeJsonBody(
			TokenResponse::fromTokens($result->tokens)->toArray() + ['provider' => $result->provider],
		);
	}

	/** Lists the current user's linked third-party connections. */
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

	/** Removes a linked provider from the current user's account. */
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
