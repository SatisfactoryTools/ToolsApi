<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use RuntimeException;

/**
 * Raised when a client-sent share tree (POST /v1/shares with a `root`) is malformed or
 * exceeds the caps on it. The message is written for the end user — the frontend shows
 * it as is, so keep it human-readable.
 */
class ShareInputException extends RuntimeException
{
}
