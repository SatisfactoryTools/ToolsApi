<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use JsonException;

/**
 * Runs the external world-data-generator CLI (see world-data.md) for a given seed and
 * randomization settings, and aggregates its per-node output into resource-node counts
 * grouped by resource type and purity. Locations and node names are discarded — only the
 * counts (used by the frontend to derive resource limits) are returned.
 */
class WorldDataService
{

	public const MODES = ['none', 'random', 'basic-rich', 'advanced-rich', 'fossil-fuel-rich'];

	public const PURITY_SETTINGS = ['no-change', 'all-impure', 'decrease', 'all-normal', 'increase', 'all-pure', 'all-random'];

	public const DEFAULT_MODE = 'none';

	public const DEFAULT_PURITY = 'no-change';

	/** Node purity buckets, in ascending order — every count group is reported with all three. */
	private const PURITIES = ['impure', 'normal', 'pure'];

	public function __construct(
		private readonly string $generatorBin,
		private readonly string $worldFile,
		private readonly int $timeout = 60,
		private readonly string $cacheDir = '',
	)
	{
	}

	/**
	 * Generates world data for the given settings and returns the aggregated node counts.
	 *
	 * @throws WorldDataException on invalid settings, misconfiguration, or generator failure
	 * @return array<string, mixed>
	 */
	public function generate(int $seed, string $mode = self::DEFAULT_MODE, string $purity = self::DEFAULT_PURITY): array
	{
		if (!in_array($mode, self::MODES, true)) {
			throw new WorldDataException('Unknown mode: ' . $mode);
		}
		if (!in_array($purity, self::PURITY_SETTINGS, true)) {
			throw new WorldDataException('Unknown purity setting: ' . $purity);
		}
		if ($this->generatorBin === '' || $this->worldFile === '') {
			throw new WorldDataException('World data generator is not configured');
		}
		if (!is_file($this->generatorBin) || !is_executable($this->generatorBin)) {
			throw new WorldDataException('World data generator binary is missing or not executable');
		}
		if (!is_file($this->worldFile)) {
			throw new WorldDataException('World data file not found');
		}

		// The generator is deterministic for a given (seed, mode, purity), so results are
		// cached on disk. The endpoint is open to anonymous users; the per-key lock also
		// prevents a thundering herd from running the (expensive) binary in parallel.
		if ($this->cacheDir === '') {
			return $this->generateUncached($seed, $mode, $purity);
		}

		$cacheFile = sprintf('%s/%s_%s_%d.json', rtrim($this->cacheDir, '/'), $mode, $purity, $seed);

		$cached = $this->readCache($cacheFile);
		if ($cached !== null) {
			return $cached;
		}

		return $this->withLock($cacheFile . '.lock', function () use ($seed, $mode, $purity, $cacheFile): array {
			// Another request may have produced the result while we waited for the lock.
			$cached = $this->readCache($cacheFile);
			if ($cached !== null) {
				return $cached;
			}

			$result = $this->generateUncached($seed, $mode, $purity);
			$this->writeCache($cacheFile, $result);

			return $result;
		});
	}

	/** @return array<string, mixed> */
	private function generateUncached(int $seed, string $mode, string $purity): array
	{
		$raw = $this->run([
			$this->generatorBin,
			'--seed=' . $seed,
			'--mode=' . $mode,
			'--purity=' . $purity,
			'--world-file=' . $this->worldFile,
		]);

		return $this->aggregate($raw, $seed, $mode, $purity);
	}

	/** @return array<string, mixed>|null */
	private function readCache(string $cacheFile): ?array
	{
		$contents = @file_get_contents($cacheFile);
		if ($contents === false) {
			return null;
		}

		try {
			$decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/** @param array<string, mixed> $result */
	private function writeCache(string $cacheFile, array $result): void
	{
		$dir = dirname($cacheFile);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
			return; // caching is best-effort — the result is still returned
		}

		$tmp = $cacheFile . '.tmp';
		if (@file_put_contents($tmp, json_encode($result)) === false || !@rename($tmp, $cacheFile)) {
			@unlink($tmp);
		}
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	private function withLock(string $lockFile, callable $callback): mixed
	{
		$dir = dirname($lockFile);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
			return $callback(); // no lock dir — run unguarded rather than fail
		}

		$handle = @fopen($lockFile, 'c');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			return $callback();
		}

		try {
			return $callback();
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * Runs the CLI with an argv array (no shell, so arguments cannot be injected) and
	 * returns the decoded JSON, draining both pipes to avoid a full-buffer deadlock and
	 * enforcing a wall-clock timeout.
	 *
	 * @param list<string> $command
	 * @return array<string, mixed>
	 */
	private function run(array $command): array
	{
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			throw new WorldDataException('Could not start the world data generator');
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$exitCode = null;
		$deadline = microtime(true) + $this->timeout;

		while (true) {
			$status = proc_get_status($process);

			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
				foreach ($read as $stream) {
					$chunk = fread($stream, 8192);
					if ($chunk === false || $chunk === '') {
						continue;
					}
					if ($stream === $pipes[1]) {
						$stdout .= $chunk;
					} else {
						$stderr .= $chunk;
					}
				}
			}

			if (!$status['running']) {
				$exitCode = $status['exitcode'];
				break;
			}

			if (microtime(true) >= $deadline) {
				proc_terminate($process, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new WorldDataException('World data generator timed out after ' . $this->timeout . 's');
			}
		}

		// Drain whatever the process buffered right before it exited.
		foreach ([1, 2] as $i) {
			while (($chunk = fread($pipes[$i], 8192)) !== false && $chunk !== '') {
				if ($i === 1) {
					$stdout .= $chunk;
				} else {
					$stderr .= $chunk;
				}
			}
			fclose($pipes[$i]);
		}
		proc_close($process);

		if ($exitCode !== 0) {
			$detail = trim($stderr);
			throw new WorldDataException(
				'World data generator failed (exit ' . $exitCode . ')' . ($detail !== '' ? ': ' . $detail : ''),
			);
		}

		try {
			$decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new WorldDataException('World data generator returned invalid JSON', 0, $e);
		}

		if (!is_array($decoded)) {
			throw new WorldDataException('World data generator returned an unexpected response');
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * Collapses the raw node list into counts by resource type and purity, dropping
	 * locations and names. Geysers have no resource (grouped by purity only); fracking
	 * cores carry a resource and a set of satellites whose purities are what get counted.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function aggregate(array $raw, int $seed, string $mode, string $purity): array
	{
		$resourceNodes = [];
		foreach ($this->list($raw, 'resourceNodes') as $node) {
			$resource = (string) ($node['resource'] ?? '');
			$nodePurity = (string) ($node['purity'] ?? '');
			if ($resource === '' || $nodePurity === '') {
				continue;
			}
			$resourceNodes[$resource][$nodePurity] = ($resourceNodes[$resource][$nodePurity] ?? 0) + 1;
		}

		$geysers = [];
		foreach ($this->list($raw, 'geysers') as $geyser) {
			$nodePurity = (string) ($geyser['purity'] ?? '');
			if ($nodePurity !== '') {
				$geysers[$nodePurity] = ($geysers[$nodePurity] ?? 0) + 1;
			}
		}

		$fracking = [];
		foreach ($this->list($raw, 'frackingCores') as $core) {
			$resource = (string) ($core['resource'] ?? '');
			if ($resource === '') {
				continue;
			}
			$fracking[$resource]['cores'] = ($fracking[$resource]['cores'] ?? 0) + 1;
			foreach ($this->list($core, 'satellites') as $satellite) {
				$nodePurity = (string) ($satellite['purity'] ?? '');
				if ($nodePurity !== '') {
					$fracking[$resource]['satellites'][$nodePurity] = ($fracking[$resource]['satellites'][$nodePurity] ?? 0) + 1;
				}
			}
		}

		return [
			'gameVersion' => isset($raw['gameVersion']) ? (string) $raw['gameVersion'] : null,
			'seed' => $seed,
			'mode' => $mode,
			'purity' => $purity,
			'resourceNodes' => array_map(fn (array $c): array => $this->withPurities($c), $resourceNodes),
			'geysers' => $this->withPurities($geysers),
			'frackingCores' => array_map(
				fn (array $c): array => [
					'cores' => $c['cores'] ?? 0,
					'satellites' => $this->withPurities($c['satellites'] ?? []),
				],
				$fracking,
			),
		];
	}

	/**
	 * Normalizes a purity=>count map so every bucket (impure/normal/pure) is present.
	 *
	 * @param array<string, int> $counts
	 * @return array<string, int>
	 */
	private function withPurities(array $counts): array
	{
		$result = [];
		foreach (self::PURITIES as $purity) {
			$result[$purity] = $counts[$purity] ?? 0;
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<array<string, mixed>>
	 */
	private function list(array $data, string $key): array
	{
		$value = $data[$key] ?? [];
		if (!is_array($value)) {
			return [];
		}
		return array_values(array_filter($value, 'is_array'));
	}

}
