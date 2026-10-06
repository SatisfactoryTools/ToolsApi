<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use Nette\Utils\Json;

/**
 * Loads a shared production line from the old Satisfactory Tools API while it is still
 * up: GET {sourceUrl}{slug}/ (the old GET /v2/share/{slug}/), which answers the envelope
 * `{"code": 200, "data": {"metadata": ..., "request": ...}}` (its BaseV1Presenter::send
 * merges `code` into the presenter's data) or a 404 envelope for an unknown slug. Only the
 * `data` object is of interest: re-encoded with Nette's Json (the flags the old API stored
 * it with) it is the same document the import command copies into legacy_share.
 */
class LegacyShareFetcher
{

	/** Seconds for the whole request, connection included. */
	private const TIMEOUT = 10;

	public function __construct(private readonly string $sourceUrl)
	{
	}

	/** False when the fallback is switched off (empty `legacyShareSourceUrl`). */
	public function isEnabled(): bool
	{
		return $this->sourceUrl !== '';
	}

	/**
	 * Returns the share document as a JSON string (`{"metadata": ..., "request": ...}`),
	 * or null when the old API does not know the slug.
	 *
	 * @throws LegacyShareFetchException on any other failure
	 */
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

		curl_setopt_array($handle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_HTTPHEADER => ['Accept: application/json'],
		]);

		$body = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);

		if (!is_string($body)) {
			throw new LegacyShareFetchException('Request to the old API failed: ' . ($error !== '' ? $error : 'no response'));
		}

		if ($status === 404) {
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

}
