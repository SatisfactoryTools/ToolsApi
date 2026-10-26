<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use RuntimeException;

/** A desktop flow only completes once the browser confirms the code the app shows. */
class OAuthConfirmationRequired extends RuntimeException
{
}
