<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * GitHub OAuth2. Scopes: `read:user user:email`. We read the numeric account id and the
 * primary verified email (GitHub may hide the email on the profile, so we query the
 * dedicated emails endpoint).
 * @see https://docs.github.com/en/apps/oauth-apps/building-oauth-apps
 */
class GithubProvider extends AbstractOAuthProvider
{

	private const AuthorizeUrl = 'https://github.com/login/oauth/authorize';
	private const TokenUrl = 'https://github.com/login/oauth/access_token';
	private const UserUrl = 'https://api.github.com/user';
	private const EmailsUrl = 'https://api.github.com/user/emails';

	public function __construct(
		private readonly string $clientId,
		private readonly string $clientSecret,
	)
	{
	}

	public function getKey(): string
	{
		return 'github';
	}

	public function getAuthorizationUrl(string $state, string $redirectUri): string
	{
		return self::AuthorizeUrl . '?' . http_build_query([
			'client_id' => $this->clientId,
			'redirect_uri' => $redirectUri,
			'scope' => 'read:user user:email',
			'state' => $state,
			'allow_signup' => 'true',
		]);
	}

	public function fetchUserInfo(array $params, string $redirectUri): OAuthUserInfo
	{
		$code = (string) ($params['code'] ?? '');
		if ($code === '') {
			throw new OAuthException('GitHub did not return an authorization code');
		}

		$token = $this->postForm(self::TokenUrl, [
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
			'code' => $code,
			'redirect_uri' => $redirectUri,
		]);

		$accessToken = (string) ($token['access_token'] ?? '');
		if ($accessToken === '') {
			throw new OAuthException('GitHub did not return an access token');
		}

		$authHeader = ['Authorization' => 'Bearer ' . $accessToken];

		$user = $this->getJson(self::UserUrl, $authHeader);
		$id = isset($user['id']) ? (string) $user['id'] : '';
		if ($id === '') {
			throw new OAuthException('GitHub did not return an account id');
		}

		return new OAuthUserInfo($id, $this->resolveEmail($authHeader));
	}

	/** @param array<string, string> $authHeader */
	private function resolveEmail(array $authHeader): ?string
	{
		$emails = $this->getJson(self::EmailsUrl, $authHeader);

		$primary = null;
		$fallback = null;
		foreach ($emails as $entry) {
			if (!is_array($entry) || empty($entry['email']) || ($entry['verified'] ?? false) !== true) {
				continue;
			}
			$address = strtolower((string) $entry['email']);
			if (($entry['primary'] ?? false) === true) {
				$primary = $address;
			}
			$fallback ??= $address;
		}

		return $primary ?? $fallback;
	}

}
