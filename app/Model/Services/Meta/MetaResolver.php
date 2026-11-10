<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\PlanLinkService;
use greeny\SatisfactoryTools\Api\Model\Services\ShareService;
use Psr\Log\LoggerInterface;
use Throwable;

/** Mirrors the frontend routes (sftools-frontend: src/app/RouteList.ts) - keep in sync. */
class MetaResolver
{

	public const int PATH_MAX_LENGTH = 512;

	/** Keep in sync with RouteList.ts. */
	private const array RESERVED_SEGMENTS = [
		'settings', 'about', 'create-version', 'mods', 'mod-editor', 'help', 'auth', 'account', 'shared',
		'0.8', '1.0', '1.0-ficsmas', 'production', 'codex', 'import',
	];

	/** Old-site URL shapes, mirrored from RouteList.ts and LegacyUrlRedirectComponent. */
	private const array LEGACY_VERSIONS = ['0.8', '1.0', '1.0-ficsmas'];
	private const array LEGACY_FIRST_SEGMENTS = [...self::LEGACY_VERSIONS, 'production', 'codex', 'items'];
	private const array LEGACY_CODEX_SECTIONS = ['items', 'buildings', 'schematics'];

	/** Key => [title, description, indexable]. */
	private const array FIXED_PAGES = [
		'about' => ['About', 'Who makes Satisfactory Tools, what it can do, and where to report a bug or ask for a feature.', true],
		'create-version' => ['Create custom version', 'Make your own game version: add mods, change recipe and power costs, and set up a different world.', true],
		'mods' => ['Mods', 'Upload and manage mod data, then use the mods in your custom game versions.', false],
		'mod-editor' => ['Mod editor', 'Write the items, recipes and buildings of a mod and get the data file to upload.', true],
		'help' => ['Help', 'Guides and reference for Satisfactory Tools.', true],
		'settings' => ['Settings', null, false],
		'account' => ['Account', null, false],
		'auth' => ['Sign in', null, false],
		'auth/login' => ['Sign in', null, false],
		'auth/register' => ['Register', null, false],
		'auth/forgot-password' => ['Forgot password', null, false],
		'auth/reset-password' => ['Reset password', null, false],
	];

	private const array CODEX_SECTIONS = [
		'items' => ['Items', 'items', 'Item'],
		'recipes' => ['Recipes', 'recipes', 'Recipe'],
		'buildings' => ['Buildings', 'buildings', 'Building'],
		'schematics' => ['Schematics', 'schematics', 'Schematic'],
	];

	private const string UUID_PATTERN = '~^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$~D';

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly ShareService $shareService,
		private readonly PlanLinkService $planLinkService,
		private readonly HelpMetaIndex $helpMetaIndex,
		private readonly VersionMetaIndex $versionMetaIndex,
		private readonly CanonicalVersion $canonicalVersion,
		private readonly LoggerInterface $logger,
		private readonly string $publicApiUrl,
	)
	{
	}

	public function resolve(string $path): PageMeta
	{
		try {
			// Unknown paths still render the app (its not-found page), so they must not be indexed.
			return $this->resolvePath($path) ?? $this->defaults()->notIndexed();
		} catch (Throwable $e) {
			$this->logger->error(sprintf('Could not resolve page metadata for "%s": %s', $path, $e->getMessage()), ['exception' => $e]);

			return $this->defaults();
		}
	}

	/** No canonical and no noindex: used when the page is not known to be wrong, only not resolved. */
	public function defaults(): PageMeta
	{
		return new PageMeta(MetaText::HOME_TITLE, MetaText::DEFAULT_DESCRIPTION, null, 'default');
	}

	private function resolvePath(string $path): ?PageMeta
	{
		if (strlen($path) > self::PATH_MAX_LENGTH) {
			return null;
		}

		$path = explode('#', $path, 2)[0];
		[$pathPart, $queryPart] = array_pad(explode('?', $path, 2), 2, '');
		parse_str($queryPart, $query);

		$segments = array_map(
			static fn (string $segment): string => rawurldecode($segment),
			array_values(array_filter(explode('/', $pathPart), static fn (string $segment): bool => $segment !== '')),
		);

		if ($segments === []) {
			return $this->home();
		}

		if (in_array($segments[0], self::LEGACY_FIRST_SEGMENTS, true)) {
			// Old share links are imported by the app in the browser, so they keep the client-side redirect.
			return isset($query['share']) ? $this->defaults()->notIndexed() : $this->legacyRedirect($segments);
		}

		return match ($segments[0]) {
			'shared' => isset($segments[1]) ? $this->share($segments[1])?->notIndexed() : null,
			'help' => $this->help(array_slice($segments, 1)),
			'auth' => $this->fixedPage(isset(self::FIXED_PAGES['auth/' . ($segments[1] ?? '')]) ? 'auth/' . $segments[1] : 'auth'),
			default => in_array($segments[0], self::RESERVED_SEGMENTS, true)
				? $this->fixedPage($segments[0])
				: $this->versionPage($segments, is_string($query['codex'] ?? null) ? $query['codex'] : null),
		};
	}

	private function home(): PageMeta
	{
		return (new PageMeta(MetaText::HOME_TITLE, MetaText::DEFAULT_DESCRIPTION, null, 'default', MetaText::HOME_HEADING))
			->indexedAs('/', $this->mainLinks());
	}

	private function fixedPage(string $key): ?PageMeta
	{
		if (!isset(self::FIXED_PAGES[$key])) {
			return null;
		}
		[$title, $description, $indexable] = self::FIXED_PAGES[$key];
		$meta = new PageMeta(MetaText::title($title), $description ?? MetaText::DEFAULT_DESCRIPTION, null, 'page', $title);

		return $indexable ? $meta->indexedAs('/' . $key, $this->mainLinks()) : $meta->notIndexed();
	}

	/** @param string[] $segments the path below /help */
	private function help(array $segments): ?PageMeta
	{
		if ($segments === []) {
			$meta = $this->fixedPage('help');
			$links = [];
			foreach ($this->helpMetaIndex->all() as $slug => $article) {
				$links[] = new PageLink('/help/' . rawurlencode($slug), $article['title']);
			}

			return $meta?->indexedAs('/help', [...$this->mainLinks(), ...$links]);
		}
		if ($segments[0] === 'editor') {
			return $this->fixedPage('help')?->notIndexed();
		}

		return count($segments) === 1 ? $this->helpArticle($segments[0]) : null;
	}

	/** @param string[] $segments */
	private function versionPage(array $segments, ?string $codexQuery): ?PageMeta
	{
		$version = $this->versionRepository->getBySlugOrUuid($segments[0]);
		if ($version === null) {
			return null;
		}

		$page = $segments[1] ?? 'planner'; // the version root redirects to the planner
		if ($page === 'codex') {
			$codexPath = array_slice($segments, 2);

			return $this->indexedVersionPage($version, $this->codex($version, $codexPath), ['codex', ...$codexPath]);
		}
		if ($page === 'map') {
			$mapPath = array_slice($segments, 2);

			return count($mapPath) <= 1 ? $this->indexedVersionPage($version, $this->map($version, $mapPath[0] ?? null), ['map', ...$mapPath]) : null;
		}
		if ($page !== 'planner' || count($segments) > 4) {
			return null;
		}
		if (($segments[2] ?? null) === 'shared') {
			return isset($segments[3]) ? $this->share($segments[3])?->notIndexed() : null;
		}
		if ($codexQuery !== null && $codexQuery !== '') {
			$codexPath = array_values(array_filter(explode('/', $codexQuery), static fn (string $segment): bool => $segment !== ''));

			// The planner with a codex panel is the codex page again: it points to the standalone one.
			return $this->indexedVersionPage($version, $this->codex($version, $codexPath), ['codex', ...$codexPath]);
		}

		$planner = new PageMeta(
			MetaText::title(sprintf(MetaText::PLANNER_TITLE, $version->name)),
			MetaText::DEFAULT_DESCRIPTION,
			null,
			'page',
			MetaText::HOME_HEADING,
		);
		if (isset($segments[2])) {
			// Plans are personal; ones the API does not know (device-only) or with link access off keep the generic title.
			return ($this->planLink($segments[2]) ?? $planner)->notIndexed();
		}

		return $this->indexedVersionPage($version, $planner, ['planner']);
	}

	/** @param string[] $segments the path below the version slug */
	private function indexedVersionPage(Version $version, ?PageMeta $meta, array $segments): ?PageMeta
	{
		if ($meta === null) {
			return null;
		}
		if ($version->custom) {
			return $meta->notIndexed();
		}

		$canonical = $this->canonicalVersion->get() ?? $version;
		if (!$this->canonicalVersion->isCanonical($version) && !$this->codexEntryExists($canonical, $segments)) {
			return $meta->notIndexed(); // e.g. an item that only exists in an older version or in FICSMAS
		}

		return $meta->indexedAs(
			CanonicalVersion::path($canonical, $segments),
			[...$this->mainLinks(), ...$this->codexLinks($canonical, $segments), ...$this->mapLinks($canonical, $segments)],
		);
	}

	private function map(Version $version, ?string $topic): ?PageMeta
	{
		if ($topic === null) {
			return new PageMeta(
				MetaText::title(sprintf('%s (%s)', MapTopicCatalog::MAP_HEADING, $version->name)),
				sprintf(MapTopicCatalog::MAP_DESCRIPTION, $version->name),
				null,
				'page',
				MapTopicCatalog::MAP_HEADING,
			);
		}

		$entry = MapTopicCatalog::topics()[$topic] ?? null;
		if ($entry === null) {
			return null;
		}
		[$heading, $description] = $entry;

		return new PageMeta(
			MetaText::title(sprintf('%s (%s)', $heading, $version->name)),
			sprintf($description, $version->name),
			null,
			'map-topic',
			$heading,
		);
	}

	/**
	 * @param string[] $segments
	 * @return list<PageLink>
	 */
	private function mapLinks(Version $version, array $segments): array
	{
		if (($segments[0] ?? null) !== 'map') {
			return [];
		}
		$links = isset($segments[1]) ? [new PageLink(CanonicalVersion::path($version, ['map']), MapTopicCatalog::MAP_HEADING)] : [];
		foreach (MapTopicCatalog::topics() as $slug => [$heading]) {
			$links[] = new PageLink(CanonicalVersion::path($version, ['map', $slug]), $heading);
		}

		return $links;
	}

	/** @param string[] $segments */
	private function codexEntryExists(Version $version, array $segments): bool
	{
		if (($segments[0] ?? null) !== 'codex' || !isset($segments[2])) {
			return true;
		}

		return isset($this->versionMetaIndex->forVersion($version)[$segments[1]][$segments[2]]);
	}

	/**
	 * Links a crawler that does not run scripts can follow from the page.
	 * @return list<PageLink>
	 */
	private function mainLinks(): array
	{
		$links = [new PageLink('/', MetaText::SITE_NAME)];
		$version = $this->canonicalVersion->get();
		if ($version !== null) {
			$links[] = new PageLink(CanonicalVersion::path($version, ['planner']), 'Production calculator');
			$links[] = new PageLink(CanonicalVersion::path($version, ['codex']), 'Codex');
			$links[] = new PageLink(CanonicalVersion::path($version, ['map']), 'Map');
		}
		$links[] = new PageLink('/help', 'Help');
		$links[] = new PageLink('/about', 'About');

		return $links;
	}

	/**
	 * @param string[] $segments
	 * @return list<PageLink>
	 */
	private function codexLinks(Version $version, array $segments): array
	{
		if (($segments[0] ?? null) !== 'codex' || count($segments) > 3) {
			return [];
		}
		$section = $segments[1] ?? null;
		if ($section === null) {
			$links = [];
			foreach (self::CODEX_SECTIONS as $key => [$title]) {
				$links[] = new PageLink(CanonicalVersion::path($version, ['codex', $key]), $title);
			}

			return $links;
		}
		if (!isset(self::CODEX_SECTIONS[$section])) {
			return [];
		}
		if (isset($segments[2])) {
			return [new PageLink(CanonicalVersion::path($version, ['codex', $section]), self::CODEX_SECTIONS[$section][0])];
		}

		$entries = $this->versionMetaIndex->forVersion($version)[$section] ?? [];
		uasort($entries, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		$links = [];
		foreach ($entries as $className => $entry) {
			$links[] = new PageLink(CanonicalVersion::path($version, ['codex', $section, (string) $className]), $entry['name']);
		}

		return $links;
	}

	/** @param string[] $path */
	private function codex(Version $version, array $path): ?PageMeta
	{
		if ($path === []) {
			return new PageMeta(
				MetaText::title(sprintf('Codex (%s)', $version->name)),
				sprintf('Searchable codex of items, recipes, buildings and schematics in Satisfactory (%s).', $version->name),
				null,
				'page',
				'Codex',
			);
		}

		$section = $path[0];
		if (!isset(self::CODEX_SECTIONS[$section]) || count($path) > 2) {
			return null;
		}
		[$sectionTitle, $sectionNoun, $entityLabel] = self::CODEX_SECTIONS[$section];
		$sectionMeta = new PageMeta(
			MetaText::title(sprintf('%s - Codex (%s)', $sectionTitle, $version->name)),
			sprintf('All %s in Satisfactory (%s).', $sectionNoun, $version->name),
			null,
			'codex-section',
			$sectionTitle,
		);
		if (!isset($path[1])) {
			return $sectionMeta;
		}

		$index = $this->versionMetaIndex->forVersion($version);
		if ($index === null) {
			return $sectionMeta; // pruned custom version file: version-level metadata only
		}

		$entry = $index[$section][$path[1]] ?? null;
		if ($entry === null) {
			return null;
		}

		if ($section === 'recipes') {
			return new PageMeta(
				MetaText::title($entry['name'] . ' (recipe)'),
				MetaText::description(($entry['alternate'] ? 'Alternate recipe: ' : '') . $entry['summary']),
				$this->imageUrl($entry['icon'] ?? null),
				'codex-recipe',
				$entry['name'],
			);
		}

		return new PageMeta(
			MetaText::title($entry['name']),
			$entry['description'] !== '' ? $entry['description'] : sprintf('%s in Satisfactory (%s).', $entityLabel, $version->name),
			$this->imageUrl($entry['icon'] ?? null),
			match ($section) {
				'items' => 'codex-item',
				'buildings' => 'codex-building',
				default => 'codex-schematic',
			},
			$entry['name'],
		);
	}

	/**
	 * Server-side twin of the app's LegacyUrlRedirectComponent, so search engines see a permanent redirect.
	 * @param string[] $segments
	 */
	private function legacyRedirect(array $segments): PageMeta
	{
		$oldVersion = in_array($segments[0], self::LEGACY_VERSIONS, true) ? $segments[0] : null;
		$path = $oldVersion === null ? $segments : array_slice($segments, 1);
		$version = $this->versionRepository->getLatestOfficial(false, $oldVersion === '1.0-ficsmas') ?? $this->canonicalVersion->get();
		if ($version === null) {
			return PageMeta::redirect('/');
		}

		return PageMeta::redirect(match ($path[0] ?? null) {
			null => '/',
			'codex' => $this->legacyCodexTarget($version, $path[1] ?? null, $path[2] ?? null),
			'items' => $this->legacyCodexTarget($version, 'items', $path[1] ?? null),
			default => CanonicalVersion::path($version, ['planner']),
		});
	}

	private function legacyCodexTarget(Version $version, ?string $section, ?string $entrySlug): string
	{
		if ($section === null || !in_array($section, self::LEGACY_CODEX_SECTIONS, true)) {
			return CanonicalVersion::path($version, ['codex']);
		}
		if ($entrySlug !== null) {
			foreach ($this->versionMetaIndex->forVersion($version)[$section] ?? [] as $className => $entry) {
				if (self::webalize($entry['name']) === $entrySlug) {
					return CanonicalVersion::path($version, ['codex', $section, (string) $className]);
				}
			}
		}

		return CanonicalVersion::path($version, ['codex', $section]);
	}

	/** The old site's slug rule, as LegacyUrlRedirectComponent replicates it. */
	private static function webalize(string $name): string
	{
		return mb_strtolower(str_replace(['™', ':'], '', (string) preg_replace('~[\s|.]+~u', '-', $name)));
	}

	private function share(string $uuid): ?PageMeta
	{
		if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
			return null;
		}
		$share = $this->shareService->present($uuid);
		if ($share === null || !is_array($share['root'])) {
			return null;
		}

		$root = $share['root'];
		$snapshotVersion = is_array($share['version']) ? $share['version'] : [];
		$version = is_string($snapshotVersion['id'] ?? null) ? $this->versionRepository->getByUuid($snapshotVersion['id']) : null;
		$versionName = $version?->name ?? (is_string($snapshotVersion['name'] ?? null) ? $snapshotVersion['name'] : '');
		$name = is_string($root['name'] ?? null) ? $root['name'] : '';

		if ($share['type'] === 'folder') {
			$count = $this->countPlans($root);

			return new PageMeta(
				MetaText::title($name),
				MetaText::description(sprintf('Shared folder with %d %s for Satisfactory (%s).', $count, $count === 1 ? 'plan' : 'plans', $versionName)
					. $this->supporterNote($share['sharedBySupporter'] ?? false)),
				null,
				'share-folder',
			);
		}

		$description = is_string($root['description'] ?? null) ? MetaText::description($root['description']) : '';

		return new PageMeta(
			MetaText::title($name),
			$this->withSupporterNote(
				$description !== '' ? $description : sprintf('Shared production plan for Satisfactory (%s).', $versionName),
				$share['sharedBySupporter'] ?? false,
			),
			$version !== null ? $this->planImage($version, $root['data'] ?? null) : null,
			'share-plan',
		);
	}

	private function planLink(string $uuid): ?PageMeta
	{
		if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
			return null;
		}
		$payload = $this->planLinkService->present($uuid);
		if ($payload === null || !is_array($payload['root'])) {
			return null;
		}

		$root = $payload['root'];
		$version = is_string($payload['version']['id'] ?? null) ? $this->versionRepository->getByUuid($payload['version']['id']) : null;
		$versionName = $version?->name ?? (is_string($payload['version']['name'] ?? null) ? $payload['version']['name'] : '');
		$description = is_string($root['description'] ?? null) ? MetaText::description($root['description']) : '';

		return new PageMeta(
			MetaText::title(is_string($root['name'] ?? null) ? $root['name'] : ''),
			$this->withSupporterNote(
				$description !== '' ? $description : sprintf('Production plan for Satisfactory (%s).', $versionName),
				$payload['sharedBySupporter'] ?? false,
			),
			$version !== null ? $this->planImage($version, $root['data'] ?? null) : null,
			'plan',
		);
	}

	private function supporterNote(mixed $sharedBySupporter): string
	{
		return $sharedBySupporter === true ? ' ' . MetaText::SUPPORTER_NOTE : '';
	}

	/** The note goes first when the text is long, so the length cap cuts the description and never the note. */
	private function withSupporterNote(string $description, mixed $sharedBySupporter): string
	{
		if ($sharedBySupporter !== true) {
			return $description;
		}
		$suffixed = $description . ' ' . MetaText::SUPPORTER_NOTE;
		if (mb_strlen($suffixed) <= MetaText::DESCRIPTION_MAX_LENGTH) {
			return $suffixed;
		}

		return MetaText::description(MetaText::SUPPORTER_NOTE . ' ' . $description);
	}

	/** @param array<string, mixed> $folder */
	private function countPlans(array $folder): int
	{
		$count = is_array($folder['plans'] ?? null) ? count($folder['plans']) : 0;
		foreach (is_array($folder['children'] ?? null) ? $folder['children'] : [] as $child) {
			if (is_array($child)) {
				$count += $this->countPlans($child);
			}
		}

		return $count;
	}

	private function planImage(Version $version, mixed $data): ?string
	{
		$className = $this->shareService->resolvePlanIcon($data);
		if ($className === null) {
			return null;
		}
		$index = $this->versionMetaIndex->forVersion($version);

		return $this->imageUrl($index['items'][$className]['icon'] ?? $index['buildings'][$className]['icon'] ?? null);
	}

	private function helpArticle(string $slug): ?PageMeta
	{
		$article = $this->helpMetaIndex->get($slug);
		if ($article === null) {
			return null;
		}

		return (new PageMeta(
			MetaText::title($article['title'] . ' - Help'),
			$article['summary'] !== '' ? MetaText::description($article['summary']) : self::FIXED_PAGES['help'][1],
			null,
			'help-article',
			$article['title'],
		))->indexedAs('/help/' . rawurlencode($slug), $this->mainLinks());
	}

	private function imageUrl(?string $icon): ?string
	{
		return $icon !== null ? sprintf('%s/data/images/256/%s.png', rtrim($this->publicApiUrl, '/'), $icon) : null;
	}

}
