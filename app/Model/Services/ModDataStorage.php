<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;
use stdClass;

class ModDataStorage
{

	/** Keyed by className; materials and resources are lists. */
	private const array KEYED_COLLECTIONS = ['items', 'recipes', 'buildings', 'schematics'];

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/** @param array<string, mixed> $data */
	public function store(UuidInterface $modVersionUuid, array $data): string
	{
		$relativePath = 'data/mods/' . $modVersionUuid->toString() . '.json';
		$absolute = $this->absolutePath($relativePath);

		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) { // @ intentional
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		try {
			$json = json_encode($this->restoreEmptyObjects($data), JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('Could not encode mod data', 0, $e);
		}

		if (@file_put_contents($absolute, $json) === false) { // @ intentional
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
			@unlink($absolute); // @ intentional
		}
	}

	/**
	 * The body is decoded into PHP arrays, so an empty JSON object would be written back as [].
	 * @param array<string, mixed> $file
	 * @return array<string, mixed>
	 */
	private function restoreEmptyObjects(array $file): array
	{
		$wrapped = array_key_exists('data', $file) && is_array($file['data']);
		$data = $wrapped ? $file['data'] : $file;

		foreach (self::KEYED_COLLECTIONS as $collection) {
			if (($data[$collection] ?? null) === []) {
				$data[$collection] = new stdClass();
			}
		}

		if (!$wrapped) {
			return $data;
		}
		$file['data'] = $data === [] ? new stdClass() : $data;
		if (($file['metadata'] ?? null) === []) {
			$file['metadata'] = new stdClass();
		}

		return $file;
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
