<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

use DateInterval;
use DateTimeImmutable;

/**
 * Reads incoming payments through the Transaction Search API ("Transaction search" must be ticked
 * on the REST app). PayPal lists a payment there up to 3 hours after it happens.
 */
class PaypalClient
{

	private const string LiveUrl = 'https://api-m.paypal.com';
	private const string SandboxUrl = 'https://api-m.sandbox.paypal.com';

	private const int MaxRangeDays = 31;
	private const int PageSize = 500;

	public function __construct(
		private readonly SupporterHttpClient $http,
		private readonly string $clientId,
		private readonly string $clientSecret,
		private readonly bool $sandbox,
	)
	{
	}

	public function isEnabled(): bool
	{
		return $this->clientId !== '' && $this->clientSecret !== '';
	}

	/** @return iterable<SupporterEmailEntry> */
	public function fetchSupporters(DateTimeImmutable $since, DateTimeImmutable $until): iterable
	{
		$token = $this->accessToken();
		$from = $since;
		while ($from < $until) {
			$to = min($from->add(new DateInterval('P' . self::MaxRangeDays . 'D')), $until);
			yield from $this->fetchRange($token, $from, $to);
			$from = $to;
		}
	}

	/** @return iterable<SupporterEmailEntry> */
	private function fetchRange(string $token, DateTimeImmutable $from, DateTimeImmutable $to): iterable
	{
		$page = 1;
		do {
			$response = $this->http->request('GET', $this->baseUrl() . '/v1/reporting/transactions?' . http_build_query([
				'start_date' => $from->format('Y-m-d\TH:i:sP'),
				'end_date' => $to->format('Y-m-d\TH:i:sP'),
				'transaction_status' => 'S',
				'fields' => 'transaction_info,payer_info',
				'page_size' => self::PageSize,
				'page' => $page,
			]), null, ['Authorization' => 'Bearer ' . $token]);

			if ($response['status'] !== 200 || $response['body'] === null) {
				throw new SupporterSyncException('PayPal answered HTTP ' . $response['status'] . ' for transactions ' . $from->format('Y-m-d') . ' - ' . $to->format('Y-m-d')
					. (isset($response['body']['message']) ? ': ' . (string) $response['body']['message'] : ''));
			}

			foreach (is_array($response['body']['transaction_details'] ?? null) ? $response['body']['transaction_details'] : [] as $detail) {
				$entry = is_array($detail) ? $this->entryOf($detail) : null;
				if ($entry !== null) {
					yield $entry;
				}
			}

			$totalPages = (int) ($response['body']['total_pages'] ?? 1);
			$page++;
		} while ($page <= $totalPages);
	}

	/**
	 * Outgoing payments and refunds carry a negative amount; only money received counts.
	 * @param array<string, mixed> $detail
	 */
	private function entryOf(array $detail): ?SupporterEmailEntry
	{
		$info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
		$amount = (float) ($info['transaction_amount']['value'] ?? 0);
		$email = $detail['payer_info']['email_address'] ?? null;
		if ($amount <= 0 || !is_string($email) || $email === '') {
			return null;
		}

		return new SupporterEmailEntry($email, 'paypal', is_string($info['transaction_id'] ?? null) ? $info['transaction_id'] : null);
	}

	private function accessToken(): string
	{
		$response = $this->http->request('POST', $this->baseUrl() . '/v1/oauth2/token', 'grant_type=client_credentials', [
			'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
			'Content-Type' => 'application/x-www-form-urlencoded',
		]);
		$token = $response['body']['access_token'] ?? null;
		if ($response['status'] !== 200 || !is_string($token)) {
			throw new SupporterSyncException('Could not sign in to PayPal: HTTP ' . $response['status']);
		}

		return $token;
	}

	private function baseUrl(): string
	{
		return $this->sandbox ? self::SandboxUrl : self::LiveUrl;
	}

}
