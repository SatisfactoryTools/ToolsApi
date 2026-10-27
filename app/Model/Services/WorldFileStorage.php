<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use RuntimeException;

/**
 * Content-addressed world files under www/data/world, shared by every version whose world matches:
 * `source-*.json` (extracted default world, the generator's input) and `collectibles-*.json` come from
 * the assets extractor, `nodes-*.json` (the generator's output for one seed + settings) are written here.
 */
class WorldFileStorage
{

	public const string DIR = 'data/world';

	private const int HASH_LENGTH = 16;

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/**
	 * @param array<string, mixed> $nodes
	 * @return string path relative to the www dir
	 */
	public function storeNodes(array $nodes): string
	{
		$json = json_encode($nodes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$relativePath = sprintf('%s/nodes-%s.json', self::DIR, substr(hash('sha256', $json), 0, self::HASH_LENGTH));
		$absolute = $this->absolutePath($relativePath);
		if (is_file($absolute)) {
			return $relativePath;
		}

		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) { // @ intentional
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		$tmp = $absolute . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) { // @ intentional
			@unlink($tmp); // @ intentional
			throw new RuntimeException(sprintf('Could not write world nodes file: %s', $relativePath));
		}

		return $relativePath;
	}

	/**
	 * The generator input for a data file's `worldSource` reference, or null when the reference is missing or
	 * does not name an existing source file (the generator then falls back to its configured default world).
	 */
	public function sourceFile(mixed $relativePath): ?string
	{
		if (!is_string($relativePath) || preg_match('~^' . preg_quote(self::DIR, '~') . '/source-[0-9a-f]+\.json$~', $relativePath) !== 1) {
			return null;
		}

		$absolute = $this->absolutePath($relativePath);

		return is_file($absolute) ? $absolute : null;
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
