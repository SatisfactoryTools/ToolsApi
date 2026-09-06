<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * Steam sign-in via OpenID 2.0. Steam never exposes an email address — the only thing we
 * learn is the 64-bit Steam ID. Because matching is email-based, an unlinked Steam login
 * cannot create or match an account; Steam can only be linked to an already-existing user.
 *
 * OpenID 2.0 has no `state` parameter, so we carry our CSRF state inside the `return_to`
 * URL, which Steam signs and echoes back verbatim.
 *
 * The persona name and avatar are not part of OpenID; they come from the Steam Web API
 * and require an API key (`oauthSteamApiKey`). Without a key, sign-in still works and
 * simply records no nickname.
 * @see https://partner.steamgames.com/doc/features/auth#website
 * @see https://developer.valvesoftware.com/wiki/Steam_Web_API#GetPlayerSummaries_.28v0002.29
 */
class SteamProvider extends AbstractOAuthProvider
{

	private const OpenIdEndpoint = 'https://steamcommunity.com/openid/login';
	private const ClaimedIdPrefix = 'https://steamcommunity.com/openid/id/';
	private const PlayerSummariesUrl = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/';

	public function __construct(
		private readonly string $apiKey = '',
	)
	{
	}

	public function getKey(): string
	{
		return 'steam';
	}

	public function getAuthorizationUrl(string $state, string $redirectUri): string
	{
		$returnTo = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query(['state' => $state]);

		return self::OpenIdEndpoint . '?' . http_build_query([
			'openid.ns' => 'http://specs.openid.net/auth/2.0',
			'openid.mode' => 'checkid_setup',
			'openid.return_to' => $returnTo,
			'openid.realm' => $this->realmFor($redirectUri),
			'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
			'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
		]);
	}

	public function fetchUserInfo(array $params, string $redirectUri): OAuthUserInfo
	{
		$claimedId = (string) ($params['openid_claimed_id'] ?? $params['openid.claimed_id'] ?? '');
		if (!str_starts_with($claimedId, self::ClaimedIdPrefix)) {
			throw new OAuthException('Steam did not return a valid claimed id');
		}

		$steamId = substr($claimedId, strlen(self::ClaimedIdPrefix));
		if (!ctype_digit($steamId)) {
			throw new OAuthException('Steam returned a malformed Steam ID');
		}

		if (!$this->verifyAssertion($params)) {
			throw new OAuthException('Steam authentication could not be verified');
		}

		[$nickname, $avatarUrl] = $this->fetchProfile($steamId);

		return new OAuthUserInfo($steamId, null, $nickname, $avatarUrl);
	}

	/**
	 * Best-effort lookup of the public persona name and avatar. A missing key or a Web API
	 * hiccup must never break sign-in, so failures degrade to "no nickname".
	 *
	 * @return array{0: string|null, 1: string|null} [personaName, avatarUrl]
	 */
	private function fetchProfile(string $steamId): array
	{
		if ($this->apiKey === '') {
			return [null, null];
		}

		try {
			$data = $this->getJson(self::PlayerSummariesUrl . '?' . http_build_query([
				'key' => $this->apiKey,
				'steamids' => $steamId,
			]));
		} catch (OAuthException) {
			return [null, null];
		}

		$player = $data['response']['players'][0] ?? null;
		if (!is_array($player)) {
			return [null, null];
		}

		return [
			isset($player['personaname']) ? (string) $player['personaname'] : null,
			isset($player['avatarfull']) ? (string) $player['avatarfull'] : null,
		];
	}

	/**
	 * Re-post the received OpenID fields with mode=check_authentication so Steam confirms
	 * the signature is genuine (guards against a forged callback).
	 *
	 * @param array<string, mixed> $params
	 */
	private function verifyAssertion(array $params): bool
	{
		$form = ['openid.mode' => 'check_authentication'];

		foreach ($params as $key => $value) {
			// Frontends often send `openid.ns` as `openid_ns`; accept both forms.
			$normalized = str_starts_with($key, 'openid_') ? 'openid.' . substr($key, 7) : (string) $key;
			if (str_starts_with($normalized, 'openid.') && $normalized !== 'openid.mode') {
				$form[$normalized] = (string) $value;
			}
		}

		$response = $this->postFormRaw(self::OpenIdEndpoint, $form);

		return preg_match('/is_valid\s*:\s*true/i', $response) === 1;
	}

	private function realmFor(string $redirectUri): string
	{
		$parts = parse_url($redirectUri);
		if (!isset($parts['scheme'], $parts['host'])) {
			throw new OAuthException('Invalid Steam redirect URI');
		}

		$realm = $parts['scheme'] . '://' . $parts['host'];
		if (isset($parts['port'])) {
			$realm .= ':' . $parts['port'];
		}

		return $realm;
	}

}
