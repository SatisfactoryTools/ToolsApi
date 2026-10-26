<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use Psr\Http\Message\ServerRequestInterface;

/** "METHOD /v1/path/*" matched against the end of the request path, so it holds wherever the API is mounted; * is one segment. */
class RoutePattern
{

	public static function matches(string $route, ServerRequestInterface $request): bool
	{
		[$method, $path] = explode(' ', $route, 2);
		if ($request->getMethod() !== $method) {
			return false;
		}

		$regex = '~' . str_replace('\*', '[^/]+', preg_quote(rtrim($path, '/'), '~')) . '/?$~D';

		return preg_match($regex, $request->getUri()->getPath()) === 1;
	}

}
