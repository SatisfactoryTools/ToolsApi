<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

use JsonException;
use Psr\Log\LoggerInterface;

abstract class AbstractOAuthProvider implements OAuthProviderInterface
{

	private const UserAgent = 'SatisfactoryTools-API';

	private ?LoggerInterface $logger = null;

	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @param array<string, string> $form
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	protected function postForm(string $url, array $form, array $headers = []): array
	{
		$headers['Content-Type'] = 'application/x-www-form-urlencoded';

		return $this->request('POST', $url, http_build_query($form), $headers);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	protected function getJson(string $url, array $headers = []): array
	{
		return $this->request('GET', $url, null, $headers);
	}

	/** @param array<string, string> $form */
	protected function postFormRaw(string $url, array $form): string
	{
		return $this->rawRequest('POST', $url, http_build_query($form), [
			'Content-Type' => 'application/x-www-form-urlencoded',
		]);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private function request(string $method, string $url, ?string $body, array $headers): array
	{
		$raw = $this->rawRequest($method, $url, $body, $headers + ['Accept' => 'application/json']);

		try {
			$decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new OAuthException('Provider ' . $this->getKey() . ' returned a non-JSON response');
		}

		if (!is_array($decoded)) {
			throw new OAuthException('Provider ' . $this->getKey() . ' returned an unexpected response');
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/** @param array<string, string> $headers */
	private function rawRequest(string $method, string $url, ?string $body, array $headers): string
	{
		$curlHeaders = [];
		foreach ($headers as $name => $value) {
			$curlHeaders[] = $name . ': ' . $value;
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
		curl_setopt($ch, CURLOPT_USERAGENT, self::UserAgent);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		// No curl_close(): CurlHandle frees itself, and the call is deprecated since PHP 8.5.

		// The details go to the log only: they describe our server's network, not anything the user can act on.
		if ($errno !== 0 || !is_string($response)) {
			$this->logger?->warning(sprintf('Could not reach provider %s: %s', $this->getKey(), $error));
			throw new OAuthException('Could not reach ' . ucfirst($this->getKey()) . '. Please try again later.');
		}

		if ($status >= 400) {
			$this->logger?->warning(sprintf('Provider %s answered HTTP %d for %s', $this->getKey(), $status, strtok($url, '?')));
			throw new OAuthException(ucfirst($this->getKey()) . ' refused the sign-in. Please try again.');
		}

		return $response;
	}

}
