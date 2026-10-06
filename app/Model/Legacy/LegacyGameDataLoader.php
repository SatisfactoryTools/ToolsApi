<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\DocsSchema;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Schema;
use JsonException;

class LegacyGameDataLoader
{

	public const array GAME_VERSIONS = ['0.8.0', '1.0.0', '1.0.0-ficsmas'];

	/** @var array<string, DocsSchema> */
	private array $schemas = [];

	public function __construct(private readonly string $dataDir)
	{
	}

	public function hasGameVersion(string $gameVersion): bool
	{
		return in_array($gameVersion, self::GAME_VERSIONS, true);
	}

	public function load(string $gameVersion): DocsSchema
	{
		if (!$this->hasGameVersion($gameVersion)) {
			throw new LegacySolverException('Unknown game version: ' . $gameVersion);
		}

		return $this->schemas[$gameVersion] ??= $this->parse($gameVersion);
	}

	private function parse(string $gameVersion): DocsSchema
	{
		$file = rtrim($this->dataDir, '/') . '/' . $gameVersion . '.json';
		$contents = @file_get_contents($file); // @ intentional
		if ($contents === false) {
			throw new LegacySolverException('Legacy game data file is missing: ' . $file);
		}

		try {
			$decoded = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new LegacySolverException('Legacy game data file is not valid JSON: ' . $file . ' (' . $e->getMessage() . ')');
		}

		if (!is_object($decoded)) {
			throw new LegacySolverException('Legacy game data file is not a JSON object: ' . $file);
		}

		return Schema::schema($decoded);
	}

}
