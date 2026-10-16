<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

/** Uses the creator's own token (Patreon client portal), which can read every member's email. */
class PatreonClient
{

	private const string ApiUrl = 'https://www.patreon.com/api/oauth2/v2';
	private const string TokenUrl = 'https://www.patreon.com/api/oauth2/token';

	private const array MemberFields = ['email', 'lifetime_support_cents', 'campaign_lifetime_support_cents', 'last_charge_status'];

	public function __construct(
		private readonly SupporterHttpClient $http,
		private readonly PatreonTokenStore $tokens,
		private readonly string $clientId,
		private readonly string $clientSecret,
		private readonly string $campaignId,
		private readonly string $webhookSecret,
	)
	{
	}

	public function isEnabled(): bool
	{
		return $this->tokens->load()['accessToken'] !== '';
	}

	public function isWebhookEnabled(): bool
	{
		return $this->webhookSecret !== '';
	}

	/** @return iterable<SupporterEmailEntry> */
	public function fetchSupporters(): iterable
	{
		$url = sprintf('%s/campaigns/%s/members?%s', self::ApiUrl, rawurlencode($this->resolveCampaignId()), http_build_query([
			'fields[member]' => implode(',', self::MemberFields),
			'page[count]' => 500,
		]));

		while ($url !== null) {
			$page = $this->get($url);
			foreach (is_array($page['data'] ?? null) ? $page['data'] : [] as $member) {
				$entry = is_array($member) ? $this->entryOf($member) : null;
				if ($entry !== null) {
					yield $entry;
				}
			}
			$next = $page['links']['next'] ?? null;
			$url = is_string($next) && str_starts_with($next, 'https://www.patreon.com/') ? $next : null;
		}
	}

	public function verifyWebhook(string $body, string $signature): bool
	{
		return $this->webhookSecret !== '' && $signature !== ''
			&& hash_equals(hash_hmac('md5', $body, $this->webhookSecret), strtolower($signature));
	}

	/** @param array<string, mixed> $payload a members:* webhook body */
	public function entryOfWebhook(array $payload): ?SupporterEmailEntry
	{
		return is_array($payload['data'] ?? null) ? $this->entryOf($payload['data']) : null;
	}

	/**
	 * Anyone who ever paid counts, including former patrons and one-month pledges.
	 * @param array<string, mixed> $member
	 */
	private function entryOf(array $member): ?SupporterEmailEntry
	{
		$attributes = is_array($member['attributes'] ?? null) ? $member['attributes'] : [];
		$email = $attributes['email'] ?? null;
		if (!is_string($email) || $email === '') {
			return null;
		}

		$paid = (int) ($attributes['lifetime_support_cents'] ?? 0) > 0
			|| (int) ($attributes['campaign_lifetime_support_cents'] ?? 0) > 0
			|| ($attributes['last_charge_status'] ?? null) === 'Paid';

		return $paid ? new SupporterEmailEntry($email, 'patreon', is_string($member['id'] ?? null) ? $member['id'] : null) : null;
	}

	private function resolveCampaignId(): string
	{
		if ($this->campaignId !== '') {
			return $this->campaignId;
		}

		$campaigns = $this->get(self::ApiUrl . '/campaigns');
		$id = $campaigns['data'][0]['id'] ?? null;
		if (!is_string($id) || $id === '') {
			throw new SupporterSyncException('The Patreon token sees no campaign; set supporters.patreon.campaignId');
		}

		return $id;
	}

	/** @return array<string, mixed> */
	private function get(string $url, bool $retried = false): array
	{
		$response = $this->http->request('GET', $url, null, ['Authorization' => 'Bearer ' . $this->tokens->load()['accessToken']]);

		if ($response['status'] === 401 && !$retried) {
			$this->refresh();

			return $this->get($url, true);
		}
		if ($response['status'] !== 200 || $response['body'] === null) {
			throw new SupporterSyncException('Patreon answered HTTP ' . $response['status'] . ' for ' . $url);
		}

		return $response['body'];
	}

	private function refresh(): void
	{
		$refreshToken = $this->tokens->load()['refreshToken'];
		if ($refreshToken === '' || $this->clientId === '' || $this->clientSecret === '') {
			throw new SupporterSyncException('The Patreon token expired and cannot be refreshed: set supporters.patreon refreshToken, clientId and clientSecret');
		}

		$response = $this->http->request('POST', self::TokenUrl, http_build_query([
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
		]), ['Content-Type' => 'application/x-www-form-urlencoded']);

		$accessToken = $response['body']['access_token'] ?? null;
		$newRefreshToken = $response['body']['refresh_token'] ?? null;
		if ($response['status'] !== 200 || !is_string($accessToken) || !is_string($newRefreshToken)) {
			throw new SupporterSyncException('Could not refresh the Patreon token: HTTP ' . $response['status']);
		}

		$this->tokens->save($accessToken, $newRefreshToken);
	}

}
