<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * Steam sign-in via OpenID 2.0. Steam never exposes an email address — the only thing we
 * learn is the 64-bit Steam ID. Because matching is email-based, an unlinked Steam login
 * cannot create or match an account; Steam can only be linked to an already-existing user.
 *
 * OpenID 2.0 has no `state` parameter, so we carry our CSRF state inside the `return_to`
 * URL, which Steam signs and echoes back verbatim.
 * @see https://partner.steamgames.com/doc/features/auth#website
 */
class SteamProvider extends AbstractOAuthProvider
{

	private const OpenIdEndpoint = 'https://steamcommunity.com/openid/login';
	private const ClaimedIdPrefix = 'https://steamcommunity.com/openid/id/';

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

		return new OAuthUserInfo($steamId, null);
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
