<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

/**
 * Stores and removes the JSON data files of mod versions (files in the same format as a
 * game version data file, later merged into custom versions). Paths are relative to the
 * web root, matching ModVersion::$dataPath.
 */
class ModDataStorage
{

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/**
	 * Writes the mod version data and returns its web-root-relative path.
	 *
	 * @param array<string, mixed> $data
	 */
	public function store(UuidInterface $modVersionUuid, array $data): string
	{
		$relativePath = 'data/mods/' . $modVersionUuid->toString() . '.json';
		$absolute = $this->absolutePath($relativePath);

		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		try {
			$json = json_encode($data, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('Could not encode mod data', 0, $e);
		}

		if (@file_put_contents($absolute, $json) === false) {
			throw new RuntimeException(sprintf('Could not write mod data file: %s', $relativePath));
		}

		return $relativePath;
	}

	public function delete(?string $relativePath): void
	{
		if ($relativePath === null) {
			return;
		}

		$absolute = $this->absolutePath($relativePath);
		if (is_file($absolute)) {
			@unlink($absolute);
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
