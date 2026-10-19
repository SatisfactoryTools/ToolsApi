<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\OfficialVersionRegistry;
use greeny\SatisfactoryTools\Api\Model\Services\PublishOutcome;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataException;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataService;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'publish', description: 'Imports an extracted Satisfactory build as official game versions')]
class PublishCommand extends Command
{

	private const array BRANCHES = ['stable', 'experimental'];

	private const array VARIANTS = [
		['suffix' => '', 'ficsmas' => false],
		['suffix' => '-ficsmas', 'ficsmas' => true],
	];

	/** Matches the extractor's formatting. */
	private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly OfficialVersionRegistry $registry,
		private readonly WorldDataService $worldDataService,
		private readonly string $wwwDir,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('buildId', null, InputOption::VALUE_REQUIRED, 'Steam build id (unique key for the build)');
		$this->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Logical branch: stable or experimental');
		// Named --game-version (not --version) because Symfony reserves the global --version option.
		$this->addOption('game-version', null, InputOption::VALUE_REQUIRED, 'Human game version, e.g. 1.2.3.1 (optional)');
		$this->addOption('skip-world-data', null, InputOption::VALUE_NONE, 'Do not run the world-data generator; an already stamped metadata.world is kept');
		$this->addOption('attach', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Attach the build to this official version slug even if its planner data differs (one per variant; an unused version the build was previously registered as is merged away)');
		$this->addOption('new-version', null, InputOption::VALUE_OPTIONAL, 'Register the variant (default or ficsmas; both when no value is given) as a new version even if its planner data matches the latest version', false);
		$this->addOption('summary-file', null, InputOption::VALUE_REQUIRED, 'Write a JSON summary of what happened to each variant to this path');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$buildId = trim((string) $input->getOption('buildId'));
		$branch = trim((string) $input->getOption('branch'));
		$versionOption = $input->getOption('game-version');
		$gameVersion = $versionOption !== null && trim((string) $versionOption) !== ''
			? trim((string) $versionOption)
			: null;

		if ($buildId === '') {
			$io->error('Option --buildId is required.');
			return Command::FAILURE;
		}

		if (!in_array($branch, self::BRANCHES, true)) {
			$io->error(sprintf('Option --branch must be one of: %s.', implode(', ', self::BRANCHES)));
			return Command::FAILURE;
		}

		$attachSlugs = $this->resolveAttachSlugs((array) $input->getOption('attach'));
		if (is_string($attachSlugs)) {
			$io->error($attachSlugs);
			return Command::FAILURE;
		}

		$newVersion = $input->getOption('new-version');
		if ($newVersion !== false && $newVersion !== null && !in_array($newVersion, ['default', 'ficsmas'], true)) {
			$io->error('Option --new-version accepts no value, "default" or "ficsmas".');
			return Command::FAILURE;
		}

		// Verify both data files up front, so we never register one variant while the other is missing.
		$variants = [];
		foreach (self::VARIANTS as $variant) {
			$dataPath = sprintf('data/versions/%s-%s%s.json', $branch, $buildId, $variant['suffix']);
			$absolute = $this->absolutePath($dataPath);
			if (!is_file($absolute)) {
				$io->error(sprintf('Data file not found: %s', $dataPath));
				return Command::FAILURE;
			}
			$variants[] = $variant + ['dataPath' => $dataPath, 'absolute' => $absolute];
		}

		// Resource nodes are identical in both variants, so the world is generated once.
		$worldData = null;
		if (!$input->getOption('skip-world-data')) {
			$io->writeln('Generating world data (seed 0, mode none, purity no-change)...');
			try {
				$worldData = $this->worldDataService->generate(0);
			} catch (WorldDataException $e) {
				$io->error('World data generation failed: ' . $e->getMessage());
				$io->note('Fix the world-data generator configuration, or re-run with --skip-world-data.');
				return Command::FAILURE;
			}
		}

		$importedAt = (new DateTimeImmutable())->format('c');

		try {
			$outcomes = [];
			foreach ($variants as $variant) {
				$this->stampMetadata($variant['absolute'], $buildId, $gameVersion, $branch, $variant['ficsmas'], $importedAt, $worldData);
				$outcomes[] = $this->registry->register(
					$variant['dataPath'],
					$buildId,
					$branch,
					$gameVersion,
					$variant['ficsmas'],
					$attachSlugs[(int) $variant['ficsmas']] ?? null,
					$newVersion === null || $newVersion === ($variant['ficsmas'] ? 'ficsmas' : 'default'),
				);
			}
			$this->versionRepository->flush();
		} catch (\RuntimeException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		foreach ($outcomes as $outcome) {
			$this->report($io, $outcome);
		}

		$summaryFile = $input->getOption('summary-file');
		if (is_string($summaryFile) && $summaryFile !== '') {
			$summary = [
				'buildId' => $buildId,
				'branch' => $branch,
				'gameVersion' => $gameVersion,
				'variants' => array_map(static fn (PublishOutcome $outcome): array => $outcome->toArray(), $outcomes),
			];
			if (@file_put_contents($summaryFile, json_encode($summary, self::JSON_FLAGS)) === false) { // @ intentional
				$io->warning(sprintf('Could not write summary file: %s', $summaryFile));
			}
		}

		$io->success(sprintf('Build %s (%s) imported.', $buildId, $branch));
		return Command::SUCCESS;
	}

	/**
	 * Keyed by the variant's ficsmas flag (0/1), or an error message.
	 *
	 * @param string[] $slugs
	 * @return array<int, string>|string
	 */
	private function resolveAttachSlugs(array $slugs): array|string
	{
		$resolved = [];
		foreach ($slugs as $slug) {
			$version = $this->versionRepository->getBySlug((string) $slug);
			if ($version === null || $version->custom) {
				return sprintf('Official version "%s" not found.', $slug);
			}
			$key = (int) $version->ficsmas;
			if (isset($resolved[$key])) {
				return sprintf('Only one --attach per variant; "%s" and "%s" are both %s.', $resolved[$key], $slug, $version->ficsmas ? 'FICSMAS' : 'non-FICSMAS');
			}
			$resolved[$key] = (string) $slug;
		}

		return $resolved;
	}

	private function report(SymfonyStyle $io, PublishOutcome $outcome): void
	{
		$version = $outcome->version;
		$io->writeln(sprintf(
			'<info>%s</info> %s (%s)%s%s',
			ucfirst($outcome->action),
			$version->name,
			$version->slug,
			$outcome->removedSlug !== null ? sprintf(', merged away %s', $outcome->removedSlug) : '',
			$outcome->detachedFrom !== null ? sprintf(', detached from %s', $outcome->detachedFrom) : '',
		));

		if ($outcome->diff !== null && $outcome->comparedWith !== null) {
			$io->writeln(sprintf('  Planner data vs %s: %d change(s)', $outcome->comparedWith, $outcome->diff['count']));
			foreach ($outcome->diff['changes'] as $change) {
				$io->writeln('    ' . $change);
			}
		}
	}

	/** @param array<string, mixed>|null $worldData */
	private function stampMetadata(
		string $absolute,
		string $buildId,
		?string $gameVersion,
		string $branch,
		bool $ficsmas,
		string $importedAt,
		?array $worldData,
	): void
	{
		$contents = @file_get_contents($absolute); // @ intentional
		if ($contents === false) {
			throw new \RuntimeException(sprintf('Could not read data file: %s', $absolute));
		}

		try {
			$data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
			$metadata = [
				'buildId' => $buildId,
				'version' => $gameVersion,
				'branch' => $branch,
				'ficsmas' => $ficsmas,
				'importedAt' => $importedAt,
			];
			$world = $worldData ?? ($data['metadata']['world'] ?? null);
			if ($world !== null) {
				$metadata['world'] = $world;
			}
			$data['metadata'] = $metadata;
			$json = json_encode($data, self::JSON_FLAGS);
		} catch (JsonException $e) {
			throw new \RuntimeException(sprintf('Data file is not valid JSON: %s', $absolute), 0, $e);
		}

		$tmp = $absolute . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) { // @ intentional
			@unlink($tmp); // @ intentional
			throw new \RuntimeException(sprintf('Could not write data file: %s', $absolute));
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
