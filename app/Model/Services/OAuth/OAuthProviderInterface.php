<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

interface OAuthProviderInterface
{

	/** Used in URLs and stored on entities, so it must stay stable. */
	public function getKey(): string;

	public function getAuthorizationUrl(string $state, string $redirectUri): string;

	/** @param array<string, mixed> $params */
	public function fetchUserInfo(array $params, string $redirectUri): OAuthUserInfo;

}
