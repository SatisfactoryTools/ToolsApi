<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use greeny\SatisfactoryTools\Api\Model\Services\AuthException;

/**
 * Raised for any failure during an OAuth flow (misconfiguration, provider error,
 * invalid/expired state, unusable identity). Extends AuthException so controllers
 * can catch a single auth-error type.
 */
class OAuthException extends AuthException
{
}
