<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

/** The pages MetaResolver marks indexable, as paths; the web layer turns them into sitemap.xml. */
class SitemapBuilder
{

	private const array FIXED_PATHS = ['/', '/about', '/create-version', '/supported-mods', '/mod-editor', '/help'];

	private const array CODEX_SECTIONS = ['items', 'recipes', 'buildings', 'schematics'];

	public function __construct(
		private readonly CanonicalVersion $canonicalVersion,
		private readonly VersionMetaIndex $versionMetaIndex,
		private readonly HelpMetaIndex $helpMetaIndex,
	)
	{
	}

	/** @return list<string> */
	public function paths(): array
	{
		$paths = self::FIXED_PATHS;
		foreach (array_keys($this->helpMetaIndex->all()) as $slug) {
			$paths[] = '/help/' . rawurlencode((string) $slug);
		}

		$version = $this->canonicalVersion->get();
		if ($version === null) {
			return $paths;
		}
		$paths[] = CanonicalVersion::path($version, ['planner']);
		$paths[] = CanonicalVersion::path($version, ['map']);
		foreach (array_keys(MapTopicCatalog::topics()) as $topic) {
			$paths[] = CanonicalVersion::path($version, ['map', $topic]);
		}
		$paths[] = CanonicalVersion::path($version, ['codex']);
		$index = $this->versionMetaIndex->forVersion($version);
		foreach (self::CODEX_SECTIONS as $section) {
			$paths[] = CanonicalVersion::path($version, ['codex', $section]);
			foreach (array_keys($index[$section] ?? []) as $className) {
				$paths[] = CanonicalVersion::path($version, ['codex', $section, (string) $className]);
			}
		}

		return $paths;
	}

}
