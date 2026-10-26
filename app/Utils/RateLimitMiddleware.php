<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use Contributte\Middlewares\IMiddleware;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\ClientIp;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimiter;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimitExceeded;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RateLimitMiddleware implements IMiddleware
{

	/** @param array<string, string> $routes limit name => RoutePattern */
	public function __construct(
		private readonly RateLimiter $rateLimiter,
		private readonly array $routes,
	)
	{
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
	{
		foreach ($this->routes as $name => $route) {
			if (!RoutePattern::matches($route, $request)) {
				continue;
			}

			try {
				$this->rateLimiter->hit($name, ClientIp::of($request));
			} catch (RateLimitExceeded $e) {
				return JsonResponses::tooManyRequests($response, $e);
			}
		}

		return $next($request, $response);
	}

}
