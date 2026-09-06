<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

interface OAuthProviderInterface
{

	/** Stable provider key used in URLs and stored on entities: steam | discord | github | google. */
	public function getKey(): string;

	/**
	 * Build the URL to which the browser should be redirected to begin authorization.
	 *
	 * @param string $state       opaque CSRF token the provider must echo back
	 * @param string $redirectUri absolute URL of the frontend callback page for this provider
	 */
	public function getAuthorizationUrl(string $state, string $redirectUri): string;

	/**
	 * Validate the provider's callback and return the identifying user info: the account
	 * id, a verified email where available, and (display only) the provider's nickname
	 * and avatar URL where the provider hands them over during sign-in.
	 *
	 * @param array<string, mixed> $params      the query parameters the provider sent to the callback
	 * @param string               $redirectUri the same redirect URI used in getAuthorizationUrl()
	 * @throws OAuthException on any validation or provider failure
	 */
	public function fetchUserInfo(array $params, string $redirectUri): OAuthUserInfo;

}
