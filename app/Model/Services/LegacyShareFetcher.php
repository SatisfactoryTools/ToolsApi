<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use Nette\Utils\Json;

class LegacyShareFetcher
{

	private const int TIMEOUT = 10;
	private const int MAX_BYTES = 1048576;
	private const int MISS_LIFETIME = 3600;

	public function __construct(
		private readonly string $sourceUrl,
		private readonly string $missCacheDir = '',
	)
	{
	}

	/** Remembers slugs the old API did not know for a while, so asking for them again costs no outbound request. */
	public function isKnownMiss(string $slug): bool
	{
		$file = $this->missFile($slug);

		return $file !== null && is_file($file) && (int) @filemtime($file) > time() - self::MISS_LIFETIME; // @ intentional
	}

	public function isEnabled(): bool
	{
		return $this->sourceUrl !== '';
	}

	public function fetch(string $slug): ?string
	{
		if (!$this->isEnabled()) {
			throw new LegacyShareFetchException('Legacy share source is not configured');
		}

		$url = $this->sourceUrl . rawurlencode($slug) . '/';
		$handle = curl_init($url);
		if ($handle === false) {
			throw new LegacyShareFetchException('Could not initialize the request');
		}

		$body = '';
		curl_setopt_array($handle, [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_MAXFILESIZE => self::MAX_BYTES,
			CURLOPT_HTTPHEADER => ['Accept: application/json'],
			// MAXFILESIZE only helps when the server announces a length; this stops an endless body too.
			CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
				if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
					return 0;
				}
				$body .= $chunk;

				return strlen($chunk);
			},
		]);

		$ok = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);

		if ($ok === false) {
			throw new LegacyShareFetchException('Request to the old API failed: ' . ($error !== '' ? $error : 'no response'));
		}

		if ($status === 404) {
			$this->rememberMiss($slug);

			return null;
		}

		if ($status !== 200) {
			throw new LegacyShareFetchException('The old API answered HTTP ' . $status);
		}

		try {
			$envelope = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new LegacyShareFetchException('The old API answered with invalid JSON');
		}

		$data = is_array($envelope) ? ($envelope['data'] ?? null) : null;
		if (!is_array($data) || array_is_list($data)) {
			throw new LegacyShareFetchException('The old API answered without a share document');
		}

		return Json::encode($data);
	}

	private function rememberMiss(string $slug): void
	{
		$file = $this->missFile($slug);
		if ($file === null) {
			return;
		}

		$dir = dirname($file);
		if (is_dir($dir) || @mkdir($dir, 0o775, true) || is_dir($dir)) { // @ intentional
			@touch($file); // @ intentional
		}
		if (random_int(1, 50) === 1) {
			foreach (glob($dir . '/*') ?: [] as $old) {
				if ((int) @filemtime($old) < time() - self::MISS_LIFETIME) { // @ intentional
					@unlink($old); // @ intentional
				}
			}
		}
	}

	private function missFile(string $slug): ?string
	{
		return $this->missCacheDir === '' ? null : rtrim($this->missCacheDir, '/') . '/' . hash('sha256', $slug);
	}

}
