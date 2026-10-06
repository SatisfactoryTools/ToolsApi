<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use RuntimeException;

/** The old API could not be reached or answered something other than a share or a 404. */
class LegacyShareFetchException extends RuntimeException
{

}
