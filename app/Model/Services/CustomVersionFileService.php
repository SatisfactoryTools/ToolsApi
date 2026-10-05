<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use RuntimeException;

/** The DB row is the source of truth; files are a prunable cache named custom/{uuid}-{inputsHash}.json, so a URL always serves the same bytes and can be cached as immutable. */
class CustomVersionFileService
{

	/** Bump when the generator output or merge/multiplier logic changes, to invalidate previously generated files. */
	private const GENERATOR_VERSION = 2;

	private const CUSTOM_DIR = 'data/versions/custom';

	public function __construct(
		private readonly CustomVersionDataGenerator $dataGenerator,
		private readonly string $wwwDir,
		private readonly string $lockDir,
	)
	{
	}

	/**
	 * Used to deduplicate creation.
	 * @param ModVersion[] $modVersions in application order
	 * @param array<string, mixed>|null $worldData
	 */
	public function definitionHash(
		string $name,
		Version $base,
		array $modVersions,
		float $recipeCostMultiplier,
		float $powerCostMultiplier,
		?array $worldData,
	): string
	{
		return hash('sha256', json_encode([
			'name' => $name,
			'base' => $base->uuid->toString(),
			'mods' => array_map(
				static fn (ModVersion $modVersion): string => $modVersion->uuid->toString(),
				$modVersions,
			),
			'recipeCost' => $recipeCostMultiplier,
			'powerCost' => $powerCostMultiplier,
			'worldData' => $worldData === null ? null : $this->canonicalize($worldData),
		], JSON_THROW_ON_ERROR));
	}

	/** Returns true when dataPath was (re)assigned; the caller must persist the entity then. */
	public function ensureFile(Version $version): bool
	{
		if (!$version->custom) {
			return false;
		}

		$base = $version->baseVersion;
		if ($base === null) {
			throw new RuntimeException(sprintf('Custom version %s has no base version', $version->uuid->toString()));
		}

		$modDataPaths = [];
		foreach ($version->getOrderedModVersions() as $modVersion) {
			if ($modVersion->dataPath !== null) {
				$modDataPaths[] = $modVersion->dataPath;
			}
		}

		$target = $this->targetPath($version, $base, $modDataPaths);

		if (!is_file($this->absolutePath($target))) {
			$this->withLock('version-' . $version->uuid->toString(), function () use ($version, $base, $modDataPaths, $target): void {
				// Another request may have generated the file while we waited for the lock.
				if (is_file($this->absolutePath($target))) {
					return;
				}

				$this->dataGenerator->generate(
					$base->dataPath,
					$target,
					$modDataPaths,
					$version->recipeCostMultiplier,
					$version->powerCostMultiplier,
					$version->worldData,
				);

				$this->removeStaleFiles($version, $target);
			});
		}

		$changed = !isset($version->dataPath) || $version->dataPath !== $target;
		$version->dataPath = $target;

		return $changed;
	}

	/**
	 * Also used by the create endpoint per definition hash, so concurrent identical creations cannot both insert (definitionHash is unique).
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function withLock(string $name, callable $callback): mixed
	{
		if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0o775, true) && !is_dir($this->lockDir)) {
			throw new RuntimeException(sprintf('Could not create lock directory: %s', $this->lockDir));
		}

		// Lock files are never unlinked; removing one while another process holds or waits on it reintroduces the race.
		$handle = @fopen($this->lockDir . '/' . $name . '.lock', 'c');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			throw new RuntimeException(sprintf('Could not acquire lock: %s', $name));
		}

		try {
			return $callback();
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * File contents are hashed, not just identities, because official data files are republished in place and mod data can be re-uploaded.
	 * @param string[] $modDataPaths
	 */
	private function targetPath(Version $version, Version $base, array $modDataPaths): string
	{
		$inputs = [
			'generator' => self::GENERATOR_VERSION,
			'base' => $this->fileHash($base->dataPath),
			'mods' => array_map(fn (string $path): string => $this->fileHash($path), $modDataPaths),
			'recipeCost' => $version->recipeCostMultiplier,
			'powerCost' => $version->powerCostMultiplier,
			'worldData' => $version->worldData === null ? null : $this->canonicalize($version->worldData),
		];

		$hash = substr(hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)), 0, 16);

		return self::CUSTOM_DIR . '/' . $version->uuid->toString() . '-' . $hash . '.json';
	}

	/** Clients holding an old URL either have it cached (immutable) or get a 404 and re-resolve the current URL via the API. */
	private function removeStaleFiles(Version $version, string $currentTarget): void
	{
		$pattern = $this->absolutePath(self::CUSTOM_DIR . '/' . $version->uuid->toString() . '-*.json');
		foreach (glob($pattern) ?: [] as $file) {
			if ($file !== $this->absolutePath($currentTarget)) {
				@unlink($file);
			}
		}
	}

	private function fileHash(string $relativePath): string
	{
		$hash = @hash_file('sha256', $this->absolutePath($relativePath));
		if ($hash === false) {
			throw new RuntimeException(sprintf('Data file not found: %s', $relativePath));
		}
		return $hash;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>
	 */
	private function canonicalize(array $data): array
	{
		if (!array_is_list($data)) {
			ksort($data);
		}
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->canonicalize($value);
			}
		}
		return $data;
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
