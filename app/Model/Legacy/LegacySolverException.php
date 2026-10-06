<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use RuntimeException;

class LegacySolverException extends RuntimeException
{

	public function __construct(string $message, private readonly bool $timeout = false)
	{
		parent::__construct($message);
	}

	public function isTimeout(): bool
	{
		return $this->timeout;
	}

}
