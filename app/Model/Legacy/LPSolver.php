<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use Nette\Utils\Strings;

class LPSolver
{

	public function __construct(
		private readonly string $lpSolveBin = '/usr/bin/lp_solve',
		private readonly int $timeout = 30,
	)
	{
	}

	/** @return array{variables: array<string, string>, recipes: array<string, string>}|null */
	public function call(string $request): ?array
	{
		$pipeDesc = [
			['pipe', 'r'],
			['pipe', 'w'],
			['pipe', 'w'],
		];

		$process = @proc_open([$this->lpSolveBin, '-s3', '-S3'], $pipeDesc, $pipes); // @ intentional

		if (!is_resource($process)) {
			throw new LegacySolverException('lp_solve not found');
		}

		fwrite($pipes[0], $request);
		fclose($pipes[0]);

		[$result, $error] = $this->read($process, $pipes);

		if ($error) {
			throw new LegacySolverException($error);
		}
		return $this->parseResult($result);
	}

	/**
	 * Both pipes are read together so a full pipe buffer cannot deadlock lp_solve.
	 *
	 * @param resource $process
	 * @param array<int, resource> $pipes
	 * @return array{string, string}
	 */
	private function read($process, array $pipes): array
	{
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = microtime(true) + $this->timeout;

		while (true) {
			$status = proc_get_status($process);

			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, 0, 200_000) > 0) { // @ intentional
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
				break;
			}

			if (microtime(true) >= $deadline) {
				proc_terminate($process, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new LegacySolverException('lp_solve timed out after ' . $this->timeout . 's', timeout: true);
			}
		}

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

		return [$stdout, $stderr];
	}

	/** @return array{variables: array<string, string>, recipes: array<string, string>}|null */
	private function parseResult(string $result): ?array
	{
		$pos = strpos($result, 'Actual values of the variables:');
		if ($pos === null) {
			return null;
		}

		$parts = explode('Actual values of the constraints:', $result, 2);
		if (count($parts) !== 2) {
			return null;
		}

		$recipes = [];
		foreach (Strings::matchAll($parts[0], '/([a-zA-Z0-9\_@#]+)\s+(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+\-]?\d+)?)/') as $match) {
			if ((float) $match[2] !== 0.0 && !in_array($match[1], ['min', 'max'], true)) {
				$recipes[$match[1]] = $match[2];
			}
		}

		$variables = [];
		foreach (Strings::matchAll($parts[1], '/([a-zA-Z0-9\_@#]+)\s+(-?\d+\.?\d*)/') as $match) {
			if ((float) $match[2] !== 0.0 && !in_array($match[1], ['min', 'max'], true)) {
				$variables[$match[1]] = $match[2];
			}
		}

		return [
			'variables' => $this->fixRepeating($variables),
			'recipes' => $this->fixRepeating($recipes),
		];
	}

	/**
	 * @param array<string, string> $numbers
	 * @return array<string, string>
	 */
	private function fixRepeating(array $numbers): array
	{
		$searches = [
			'111' => str_repeat('1', 10),
			'222' => str_repeat('2', 10),
			'333' => str_repeat('3', 10),
			'444' => str_repeat('4', 10),
			'556' => str_repeat('5', 10),
			'667' => str_repeat('6', 10),
			'778' => str_repeat('7', 10),
			'889' => str_repeat('8', 10),
		];

		return array_map(function (string $number) use ($searches): string {
			if (strpos($number, '.') === false) {
				return $number;
			}
			[$whole, $decimal] = explode('.', $number, 2);
			if (strlen($decimal) < 3) {
				return $number;
			}

			foreach ($searches as $repeating => $replace) {
				if (substr($decimal, -3) === (string) $repeating) {
					$decimal = substr($decimal, 0, -3) . $replace;
					break;
				}
			}
			return implode('.', [$whole, $decimal]);
		}, $numbers);
	}

}
