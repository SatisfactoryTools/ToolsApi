<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use RuntimeException;

/** The legacy solver could not run: lp_solve missing, failing, or over its time limit. */
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
