<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use Contributte\Middlewares\IMiddleware;
use Nette\Http\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CorsMiddleware implements IMiddleware
{

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
	{
		if ($request->getMethod() === 'OPTIONS') {
			return $response->withHeader('Access-Control-Allow-Origin', '*')
				->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
				->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
				->withStatus(IResponse::S204_NoContent);
		}

		return $next($request, $response)->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
			->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
	}

}
