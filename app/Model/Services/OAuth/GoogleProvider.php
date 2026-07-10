<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * Google OpenID Connect. Scope: `openid email`. We read the `sub` id and verified email.
 * @see https://developers.google.com/identity/protocols/oauth2/openid-connect
 */
class GoogleProvider extends AbstractOAuthProvider
{

	private const AuthorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TokenUrl = 'https://oauth2.googleapis.com/token';
	private const UserInfoUrl = 'https://openidconnect.googleapis.com/v1/userinfo';

	public function __construct(
		private readonly string $clientId,
		private readonly string $clientSecret,
	)
	{
	}

	public function getKey(): string
	{
		return 'google';
	}

	public function getAuthorizationUrl(string $state, string $redirectUri): string
	{
		return self::AuthorizeUrl . '?' . http_build_query([
			'client_id' => $this->clientId,
			'redirect_uri' => $redirectUri,
			'response_type' => 'code',
			'scope' => 'openid email',
			'state' => $state,
			// keeps the flow non-offline; we never store Google tokens
			'access_type' => 'online',
		]);
	}

	public function fetchUserInfo(array $params, string $redirectUri): OAuthUserInfo
	{
		$code = (string) ($params['code'] ?? '');
		if ($code === '') {
			throw new OAuthException('Google did not return an authorization code');
		}

		$token = $this->postForm(self::TokenUrl, [
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $redirectUri,
		]);

		$accessToken = (string) ($token['access_token'] ?? '');
		if ($accessToken === '') {
			throw new OAuthException('Google did not return an access token');
		}

		$user = $this->getJson(self::UserInfoUrl, ['Authorization' => 'Bearer ' . $accessToken]);

		$id = isset($user['sub']) ? (string) $user['sub'] : '';
		if ($id === '') {
			throw new OAuthException('Google did not return an account id');
		}

		$email = null;
		$verified = $user['email_verified'] ?? false;
		if (!empty($user['email']) && ($verified === true || $verified === 'true')) {
			$email = strtolower((string) $user['email']);
		}

		return new OAuthUserInfo($id, $email);
	}

}
