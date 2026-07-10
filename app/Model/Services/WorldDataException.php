<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use RuntimeException;

/**
 * Raised when the world-data generator cannot be run or its output cannot be understood
 * (misconfiguration, invalid settings, non-zero exit, timeout, malformed JSON).
 */
class WorldDataException extends RuntimeException
{
}
