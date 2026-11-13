<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use greeny\SatisfactoryTools\Api\Model\Entities\Mod;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModRepository;
use greeny\SatisfactoryTools\Api\Model\Services\Ficsit\FicsitAppClient;
use greeny\SatisfactoryTools\Api\Model\Services\Ficsit\FicsitAppException;
use JsonException;
use Nette\Caching\Cache;
use Nette\Caching\Storage;

/** Public mods for the public mod list. Owners are never exposed. */
class ModCatalog
{

	private const array COUNTED_COLLECTIONS = ['items', 'recipes', 'buildings', 'schematics'];

	/** Each lookup can take seconds, so one request fills only a few and later requests fill the rest. */
	private const int FICSIT_LOOKUPS_PER_REQUEST = 5;

	private readonly Cache $cache;

	private int $ficsitLookups = 0;

	public function __construct(
		Storage $storage,
		private readonly ModRepository $modRepository,
		private readonly FicsitAppClient $ficsitApp,
		private readonly string $wwwDir,
	)
	{
		$this->cache = new Cache($storage, 'mod-catalog');
	}

	/** @return array<int, array<string, mixed>> */
	public function entries(): array
	{
		$entries = [];
		foreach ($this->modRepository->getPublic() as $mod) {
			$latest = $this->latestVersionWithData($mod);
			if ($latest === null) {
				continue;
			}
			$entries[] = $this->entry($mod, $latest);
		}
		usort($entries, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		return $entries;
	}

	public function isPublicReference(string $reference): bool
	{
		foreach ($this->modRepository->getPublic() as $mod) {
			if ($mod->reference !== null && strcasecmp($mod->reference, $reference) === 0 && $this->latestVersionWithData($mod) !== null) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string, mixed> */
	private function entry(Mod $mod, ModVersion $latest): array
	{
		$ficsit = $mod->reference === null ? null : $this->ficsitInfo($mod->reference);

		return [
			'id' => $mod->uuid->toString(),
			'name' => $mod->name,
			'reference' => $mod->reference,
			'summary' => $ficsit['shortDescription'] ?? null,
			'logo' => $ficsit['logo'] ?? null,
			'version' => $latest->name,
			'updatedAt' => $latest->createdAt->format('c'),
			'needs' => array_values(array_unique(array_map(
				static fn (ModVersion $dependency): string => $dependency->mod->name,
				$latest->dependencies->toArray(),
			))),
			'counts' => $this->counts((string) $latest->dataPath),
		];
	}

	private function latestVersionWithData(Mod $mod): ?ModVersion
	{
		$latest = null;
		foreach ($mod->versions as $version) {
			if ($version->dataPath !== null) {
				$latest = $version;
			}
		}

		return $latest;
	}

	/** @return array{shortDescription: ?string, logo: ?string}|null */
	private function ficsitInfo(string $reference): ?array
	{
		$key = 'ficsit/' . strtolower($reference);
		$cached = $this->cache->load($key);
		if (is_array($cached)) {
			return $cached['info'];
		}
		if ($this->ficsitLookups >= self::FICSIT_LOOKUPS_PER_REQUEST) {
			return null;
		}
		$this->ficsitLookups++;

		try {
			$mod = $this->ficsitApp->findMod($reference);
		} catch (FicsitAppException) {
			$this->cache->save($key, ['info' => null], [Cache::Expire => '1 hour']);
			return null;
		}

		$info = $mod === null ? null : ['shortDescription' => $mod->shortDescription, 'logo' => $mod->logo];
		$this->cache->save($key, ['info' => $info], [Cache::Expire => '1 day']);

		return $info;
	}

	/** @return array<string, int> */
	private function counts(string $dataPath): array
	{
		$file = rtrim($this->wwwDir, '/') . '/' . ltrim($dataPath, '/');
		$key = 'counts/' . sha1($dataPath);
		$cached = $this->cache->load($key);
		if (is_array($cached)) {
			return $cached;
		}

		$counts = array_fill_keys(self::COUNTED_COLLECTIONS, 0);
		$contents = is_file($file) ? @file_get_contents($file) : false; // @ intentional
		if ($contents === false) {
			return $counts;
		}
		try {
			$json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return $counts;
		}
		$data = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);
		foreach (self::COUNTED_COLLECTIONS as $collection) {
			$counts[$collection] = is_array($data[$collection] ?? null) ? count($data[$collection]) : 0;
		}

		$this->cache->save($key, $counts, [Cache::Files => $file]);

		return $counts;
	}

}
