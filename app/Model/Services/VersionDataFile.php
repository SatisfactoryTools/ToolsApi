<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use RuntimeException;

/** Reads and atomically rewrites official version data files (paths relative to the www dir). */
class VersionDataFile
{

	/** Data-file metadata keys that point at the version's world: map tiles, extracted world + collectibles, positioned nodes. */
	public const array WORLD_REFERENCE_KEYS = ['map', 'worldSource', 'collectibles', 'worldNodes'];

	/** Matches the extractor's formatting. */
	private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/** @return array<string, mixed> */
	public function read(string $relativePath): array
	{
		$absolute = $this->absolutePath($relativePath);
		$contents = @file_get_contents($absolute); // @ intentional
		if ($contents === false) {
			throw new RuntimeException(sprintf('Could not read data file: %s', $absolute));
		}

		try {
			$data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException(sprintf('Data file is not valid JSON: %s', $absolute), 0, $e);
		}
		if (!is_array($data)) {
			throw new RuntimeException(sprintf('Data file is not a JSON object: %s', $absolute));
		}

		return $data;
	}

	/** @param array<string, mixed> $data */
	public function write(string $relativePath, array $data): void
	{
		$absolute = $this->absolutePath($relativePath);
		try {
			$json = json_encode($data, self::JSON_FLAGS);
		} catch (JsonException $e) {
			throw new RuntimeException(sprintf('Could not encode data file: %s', $absolute), 0, $e);
		}

		$tmp = $absolute . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) { // @ intentional
			@unlink($tmp); // @ intentional
			throw new RuntimeException(sprintf('Could not write data file: %s', $absolute));
		}
	}

	public function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
