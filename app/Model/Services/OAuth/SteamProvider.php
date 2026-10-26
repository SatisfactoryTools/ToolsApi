<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/** OpenID 2.0 has no `state`, so our CSRF state rides inside the signed `return_to` URL. */
class SteamProvider extends AbstractOAuthProvider
{

	private const OpenIdEndpoint = 'https://steamcommunity.com/openid/login';
	private const ClaimedIdPattern = '~^https://steamcommunity\.com/openid/id/(\d{17})$~D';
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
		$openId = $this->openIdParams($params);

		if (($openId['openid.op_endpoint'] ?? '') !== self::OpenIdEndpoint) {
			throw new OAuthException('Steam authentication could not be verified');
		}

		$signed = explode(',', $openId['openid.signed'] ?? '');
		foreach (['claimed_id', 'identity', 'return_to'] as $field) {
			if (!in_array($field, $signed, true)) {
				throw new OAuthException('Steam authentication could not be verified');
			}
		}

		$claimedId = $openId['openid.claimed_id'] ?? '';
		if (($openId['openid.identity'] ?? '') !== $claimedId || preg_match(self::ClaimedIdPattern, $claimedId, $match) !== 1) {
			throw new OAuthException('Steam did not return a valid claimed id');
		}

		// The state travels outside the signature, so only an assertion Steam signed for this very callback and state counts.
		if (!$this->returnsTo($openId['openid.return_to'] ?? '', $redirectUri, (string) ($params['state'] ?? ''))) {
			throw new OAuthException('Steam authentication could not be verified');
		}

		if (!$this->verifyAssertion($openId)) {
			throw new OAuthException('Steam authentication could not be verified');
		}

		$steamId = $match[1];
		[$nickname, $avatarUrl] = $this->fetchProfile($steamId);

		return new OAuthUserInfo($steamId, null, $nickname, $avatarUrl);
	}

	/**
	 * Frontends may send `openid.ns` as `openid_ns`; both spellings are accepted, but one key given twice
	 * must not let the identity and the verified assertion come from different values.
	 * @param array<string, mixed> $params
	 * @return array<string, string>
	 */
	private function openIdParams(array $params): array
	{
		$openId = [];
		foreach ($params as $key => $value) {
			$key = (string) $key;
			$normalized = str_starts_with($key, 'openid_') ? 'openid.' . substr($key, 7) : $key;
			if (!str_starts_with($normalized, 'openid.')) {
				continue;
			}
			if (!is_string($value) || array_key_exists($normalized, $openId)) {
				throw new OAuthException('Steam authentication could not be verified');
			}
			$openId[$normalized] = $value;
		}

		return $openId;
	}

	private function returnsTo(string $returnTo, string $redirectUri, string $state): bool
	{
		$separator = str_contains($redirectUri, '?') ? '&' : '?';
		if ($state === '' || !str_starts_with($returnTo, $redirectUri . $separator)) {
			return false;
		}

		parse_str((string) parse_url($returnTo, PHP_URL_QUERY), $query);

		return is_string($query['state'] ?? null) && hash_equals($query['state'], $state);
	}

	/**
	 * Best-effort: a missing key or Web API hiccup must never break sign-in.
	 * @return array{0: string|null, 1: string|null}
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

	/** @param array<string, string> $openId */
	private function verifyAssertion(array $openId): bool
	{
		unset($openId['openid.mode']);
		$form = ['openid.mode' => 'check_authentication'] + $openId;

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
