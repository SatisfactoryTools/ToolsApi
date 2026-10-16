<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

use JsonException;

class SupporterHttpClient
{

	private const string UserAgent = 'SatisfactoryTools-API';

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, body: array<string, mixed>|null}
	 */
	public function request(string $method, string $url, ?string $body = null, array $headers = []): array
	{
		$curlHeaders = ['Accept: application/json'];
		foreach ($headers as $name => $value) {
			$curlHeaders[] = $name . ': ' . $value;
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
		curl_setopt($ch, CURLOPT_USERAGENT, self::UserAgent);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($errno !== 0 || !is_string($response)) {
			throw new SupporterSyncException('Could not reach ' . (string) parse_url($url, PHP_URL_HOST) . ': ' . $error);
		}

		$decoded = null;
		if ($response !== '') {
			try {
				$decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				$decoded = null;
			}
		}

		return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
	}

}
