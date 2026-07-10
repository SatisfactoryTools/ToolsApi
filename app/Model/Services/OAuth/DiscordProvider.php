<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * Discord OAuth2. Scopes: `identify email`. We read the account id and verified email.
 * @see https://discord.com/developers/docs/topics/oauth2
 */
class DiscordProvider extends AbstractOAuthProvider
{

	private const AuthorizeUrl = 'https://discord.com/oauth2/authorize';
	private const TokenUrl = 'https://discord.com/api/oauth2/token';
	private const UserUrl = 'https://discord.com/api/users/@me';

	public function __construct(
		private readonly string $clientId,
		private readonly string $clientSecret,
	)
	{
	}

	public function getKey(): string
	{
		return 'discord';
	}

	public function getAuthorizationUrl(string $state, string $redirectUri): string
	{
		return self::AuthorizeUrl . '?' . http_build_query([
			'client_id' => $this->clientId,
			'redirect_uri' => $redirectUri,
			'response_type' => 'code',
			'scope' => 'identify email',
			'state' => $state,
			'prompt' => 'none',
		]);
	}

	public function fetchUserInfo(array $params, string $redirectUri): OAuthUserInfo
	{
		$code = (string) ($params['code'] ?? '');
		if ($code === '') {
			throw new OAuthException('Discord did not return an authorization code');
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
			throw new OAuthException('Discord did not return an access token');
		}

		$user = $this->getJson(self::UserUrl, ['Authorization' => 'Bearer ' . $accessToken]);

		$id = isset($user['id']) ? (string) $user['id'] : '';
		if ($id === '') {
			throw new OAuthException('Discord did not return an account id');
		}

		$email = null;
		if (!empty($user['email']) && ($user['verified'] ?? false) === true) {
			$email = strtolower((string) $user['email']);
		}

		return new OAuthUserInfo($id, $email);
	}

}
