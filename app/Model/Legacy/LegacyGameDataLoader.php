<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\DocsSchema;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Schema;
use JsonException;

/**
 * Loads the game data the old tools calculated with. The old API read it from its
 * `version` table by name; here the same documents live as files under data/legacy
 * (`{gameVersion}.json`, copied from the old frontend's data/*.json). A parsed DocsSchema
 * is kept for the lifetime of the service, i.e. for one request — there is no persistent
 * cache, parsing takes well under 100 ms.
 */
class LegacyGameDataLoader
{

	/** The game versions the old tools had, as the old client sent them in `gameVersion`. */
	public const GAME_VERSIONS = ['0.8.0', '1.0.0', '1.0.0-ficsmas'];

	/** @var array<string, DocsSchema> */
	private array $schemas = [];

	public function __construct(private readonly string $dataDir)
	{
	}

	public function hasGameVersion(string $gameVersion): bool
	{
		return in_array($gameVersion, self::GAME_VERSIONS, true);
	}

	/** @throws LegacySolverException when the version is unknown or its data file is missing or broken */
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
		$contents = @file_get_contents($file);
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
