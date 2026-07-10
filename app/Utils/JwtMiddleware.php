<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use Contributte\Middlewares\IMiddleware;
use greeny\SatisfactoryTools\Api\Model\Services\AuthService;
use Nette\Http\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class JwtMiddleware implements IMiddleware
{

	public function __construct(
		private readonly AuthService $authService,
	)
	{
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
	{
		$authHeader = $request->getHeaderLine('Authorization');

		if ($authHeader === '') {
			return $next($request->withAttribute('user', null), $response);
		}

		if (!str_starts_with($authHeader, 'Bearer ')) {
			return $this->unauthorized($response);
		}

		$token = substr($authHeader, 7);
		$user = $this->authService->getUserFromAccessToken($token);

		if ($user === null) {
			return $this->unauthorized($response);
		}

		return $next($request->withAttribute('user', $user), $response);
	}

	private function unauthorized(ResponseInterface $response): ResponseInterface
	{
		$body = $response->getBody();
		$body->write(json_encode(['error' => 'Unauthorized', 'code' => IResponse::S401_Unauthorized]));

		return $response
			->withStatus(IResponse::S401_Unauthorized)
			->withHeader('Content-Type', 'application/json')
			->withBody($body);
	}

}
