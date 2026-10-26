<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimitExceeded;
use Nette\Http\IResponse;
use Psr\Http\Message\ResponseInterface;

class JsonResponses
{

	public static function tooManyRequests(ResponseInterface $response, RateLimitExceeded $exceeded): ResponseInterface
	{
		return self::error($response, IResponse::S429_TooManyRequests, $exceeded->getMessage())
			->withHeader('Retry-After', (string) $exceeded->retryAfter);
	}

	public static function error(ResponseInterface $response, int $status, string $message): ResponseInterface
	{
		$body = $response->getBody();
		$body->write(json_encode(['error' => $message, 'code' => $status], JSON_THROW_ON_ERROR));

		return $response
			->withStatus($status)
			->withHeader('Content-Type', 'application/json')
			->withBody($body);
	}

}
