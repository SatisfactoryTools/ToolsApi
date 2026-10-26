<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use Contributte\Middlewares\IMiddleware;
use Nette\Http\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** post_max_size only covers POST, so PUT bodies would otherwise be bounded by memory_limit alone. */
class BodySizeMiddleware implements IMiddleware
{

	/** @param array<string, int> $overrides RoutePattern => bytes */
	public function __construct(
		private readonly int $maxBytes,
		private readonly array $overrides = [],
	)
	{
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
	{
		$limit = $this->limitFor($request);
		$size = max((int) $request->getHeaderLine('Content-Length'), $request->getBody()->getSize() ?? 0);
		if ($size > $limit) {
			return JsonResponses::error(
				$response,
				IResponse::S413_RequestEntityTooLarge,
				'The request is too large (at most ' . intdiv($limit, 1024 * 1024) . ' MB)',
			);
		}

		return $next($request, $response);
	}

	private function limitFor(ServerRequestInterface $request): int
	{
		foreach ($this->overrides as $route => $bytes) {
			if (RoutePattern::matches($route, $request)) {
				return $bytes;
			}
		}

		return $this->maxBytes;
	}

}
