<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use greeny\SatisfactoryTools\Api\Model\Services\AuthException;

/** Extends AuthException so controllers can catch a single auth-error type. */
class OAuthException extends AuthException
{
}
