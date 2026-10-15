<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\HelpArticleRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\PlanLinkService;
use greeny\SatisfactoryTools\Api\Model\Services\ShareService;
use JsonException;
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

	private const array FIXED_PAGES = [
		'about' => ['About', 'Who makes Satisfactory Tools, what it can do, and where to report a bug or ask for a feature.'],
		'create-version' => ['Create custom version', 'Make your own game version: add mods, change recipe and power costs, and set up a different world.'],
		'mods' => ['Mods', 'Upload and manage mod data, then use the mods in your custom game versions.'],
		'mod-editor' => ['Mod editor', 'Write the items, recipes and buildings of a mod and get the data file to upload.'],
		'help' => ['Help', 'Guides and reference for Satisfactory Tools.'],
		'settings' => ['Settings', null],
		'account' => ['Account', null],
		'auth' => ['Sign in', null],
		'auth/login' => ['Sign in', null],
		'auth/register' => ['Register', null],
		'auth/forgot-password' => ['Forgot password', null],
		'auth/reset-password' => ['Reset password', null],
	];

	private const array CODEX_SECTIONS = [
		'items' => ['Items', 'items', 'Item'],
		'recipes' => ['Recipes', 'recipes', 'Recipe'],
		'buildings' => ['Buildings', 'buildings', 'Building'],
		'schematics' => ['Schematics', 'schematics', 'Schematic'],
	];

	private const string HELP_INDEX = 'data/help/index.json';

	private const string UUID_PATTERN = '~^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$~D';

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly ShareService $shareService,
		private readonly PlanLinkService $planLinkService,
		private readonly HelpArticleRepository $helpArticleRepository,
		private readonly VersionMetaIndex $versionMetaIndex,
		private readonly LoggerInterface $logger,
		private readonly string $wwwDir,
		private readonly string $publicApiUrl,
	)
	{
	}

	public function resolve(string $path): PageMeta
	{
		try {
			return $this->resolvePath($path) ?? $this->defaults();
		} catch (Throwable $e) {
			$this->logger->error(sprintf('Could not resolve page metadata for "%s": %s', $path, $e->getMessage()), ['exception' => $e]);

			return $this->defaults();
		}
	}

	public function defaults(): PageMeta
	{
		return new PageMeta(MetaText::SITE_NAME, MetaText::DEFAULT_DESCRIPTION, null, 'default');
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
			return null;
		}

		return match ($segments[0]) {
			'shared' => isset($segments[1]) ? $this->share($segments[1]) : null,
			'help' => isset($segments[1]) && $segments[1] !== 'editor' ? $this->helpArticle($segments[1]) : $this->fixedPage('help'),
			'auth' => $this->fixedPage(isset(self::FIXED_PAGES['auth/' . ($segments[1] ?? '')]) ? 'auth/' . $segments[1] : 'auth'),
			default => in_array($segments[0], self::RESERVED_SEGMENTS, true)
				? $this->fixedPage($segments[0])
				: $this->versionPage($segments, is_string($query['codex'] ?? null) ? $query['codex'] : null),
		};
	}

	private function fixedPage(string $key): ?PageMeta
	{
		if (!isset(self::FIXED_PAGES[$key])) {
			return null;
		}
		[$title, $description] = self::FIXED_PAGES[$key];

		return new PageMeta(MetaText::title($title), $description ?? MetaText::DEFAULT_DESCRIPTION, null, 'page');
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
			return $this->codex($version, array_slice($segments, 2));
		}
		if ($page !== 'planner' || count($segments) > 4) {
			return null;
		}
		if (($segments[2] ?? null) === 'shared') {
			return isset($segments[3]) ? $this->share($segments[3]) : null;
		}
		if ($codexQuery !== null && $codexQuery !== '') {
			$codexPath = array_values(array_filter(explode('/', $codexQuery), static fn (string $segment): bool => $segment !== ''));

			return $this->codex($version, $codexPath);
		}

		if (isset($segments[2])) {
			$plan = $this->planLink($segments[2]);
			if ($plan !== null) {
				return $plan;
			}
		}

		// Plans the API does not know (device-only) or with link access off keep the generic title.
		return new PageMeta(
			MetaText::title(sprintf('Planner (%s)', $version->name)),
			MetaText::DEFAULT_DESCRIPTION,
			null,
			'page',
		);
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
		);
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
				MetaText::description(sprintf('Shared folder with %d %s for Satisfactory (%s).', $count, $count === 1 ? 'plan' : 'plans', $versionName)),
				null,
				'share-folder',
			);
		}

		$description = is_string($root['description'] ?? null) ? MetaText::description($root['description']) : '';

		return new PageMeta(
			MetaText::title($name),
			$description !== '' ? $description : sprintf('Shared production plan for Satisfactory (%s).', $versionName),
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
			$description !== '' ? $description : sprintf('Production plan for Satisfactory (%s).', $versionName),
			$version !== null ? $this->planImage($version, $root['data'] ?? null) : null,
			'plan',
		);
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
		$article = $this->helpSummary($slug);
		if ($article === null) {
			return null;
		}

		return new PageMeta(
			MetaText::title($article['title'] . ' - Help'),
			$article['summary'] !== '' ? MetaText::description($article['summary']) : self::FIXED_PAGES['help'][1],
			null,
			'help-article',
		);
	}

	/** @return array{title: string, summary: string}|null */
	private function helpSummary(string $slug): ?array
	{
		$file = rtrim($this->wwwDir, '/') . '/' . self::HELP_INDEX;
		$contents = is_file($file) ? @file_get_contents($file) : false; // @ intentional
		if ($contents !== false) {
			try {
				$manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				$manifest = null;
			}
			$entry = is_array($manifest) ? ($manifest['articles'][$slug] ?? null) : null;
			if (!is_array($entry) || !is_string($entry['title'] ?? null)) {
				return null;
			}

			return ['title' => $entry['title'], 'summary' => is_string($entry['summary'] ?? null) ? $entry['summary'] : ''];
		}

		$article = $this->helpArticleRepository->getBySlug($slug);
		if ($article === null || !$article->published) {
			return null;
		}

		return ['title' => $article->title, 'summary' => $article->summary];
	}

	private function imageUrl(?string $icon): ?string
	{
		return $icon !== null ? sprintf('%s/data/images/256/%s.png', rtrim($this->publicApiUrl, '/'), $icon) : null;
	}

}
