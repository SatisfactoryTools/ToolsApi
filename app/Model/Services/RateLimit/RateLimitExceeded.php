<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\RateLimit;

use RuntimeException;

class RateLimitExceeded extends RuntimeException
{

	public function __construct(
		public readonly int $retryAfter,
		string $message = 'Too many requests. Please try again later.',
	)
	{
		parent::__construct($message);
	}

}
