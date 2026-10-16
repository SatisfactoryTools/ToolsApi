<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

use JsonException;

/** Refreshed creator tokens outlive the ones in local.neon, so they are kept in a file the deploy leaves alone (temp/). */
class PatreonTokenStore
{

	public function __construct(
		private readonly string $file,
		private readonly string $configAccessToken,
		private readonly string $configRefreshToken,
	)
	{
	}

	/** @return array{accessToken: string, refreshToken: string} */
	public function load(): array
	{
		$contents = is_file($this->file) ? @file_get_contents($this->file) : false; // @ intentional
		if ($contents !== false) {
			try {
				$stored = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				$stored = null;
			}
			// A token pasted into local.neon after the last refresh wins over the stored one.
			if (is_array($stored) && ($stored['configAccessToken'] ?? null) === $this->configAccessToken
				&& is_string($stored['accessToken'] ?? null) && is_string($stored['refreshToken'] ?? null)) {
				return ['accessToken' => $stored['accessToken'], 'refreshToken' => $stored['refreshToken']];
			}
		}

		return ['accessToken' => $this->configAccessToken, 'refreshToken' => $this->configRefreshToken];
	}

	public function save(string $accessToken, string $refreshToken): void
	{
		$dir = dirname($this->file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true); // @ intentional, the write below reports the failure
		}
		$written = @file_put_contents($this->file, json_encode([ // @ intentional
			'configAccessToken' => $this->configAccessToken,
			'accessToken' => $accessToken,
			'refreshToken' => $refreshToken,
		]), LOCK_EX);
		if ($written === false) {
			throw new SupporterSyncException('Could not store the refreshed Patreon token in ' . $this->file);
		}
	}

}
