<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * The only data we ever read from a third-party provider: the stable account id and,
 * where available, a verified email address used to match against existing users.
 */
class OAuthUserInfo
{

	public function __construct(
		public readonly string $providerUserId,
		public readonly ?string $email = null,
	)
	{
	}

}
