<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataException;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataService;
use JsonException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a build published by the extractor pipeline (see docs/api-handoff.md). For each
 * build it registers two official game versions — one without FICSMAS content and one with
 * it — pointing at the two data files already written to disk. Idempotent: re-running for
 * the same build updates the existing versions in place.
 */
#[AsCommand(name: 'publish', description: 'Imports an extracted Satisfactory build as official game versions')]
class PublishCommand extends Command
{

	private const BRANCHES = ['stable', 'experimental'];

	/** The two parser variants delivered per build. */
	private const VARIANTS = [
		['suffix' => '', 'ficsmas' => false],
		['suffix' => '-ficsmas', 'ficsmas' => true],
	];

	/** Matches the extractor's formatting: 4-space pretty-print, raw slashes and unicode. */
	private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	public function __construct(
		private readonly VersionRepository $versionRepository,
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
		$this->addOption('skip-world-data', null, InputOption::VALUE_NONE, 'Do not run the world-data generator / stamp metadata.world');
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

		// Resolve and verify both data files up front, so we never register one variant
		// while the other is missing.
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

		// The resource nodes are the same for both variants (FICSMAS only adds seasonal
		// recipes/items), so generate the vanilla world once and stamp it into both files.
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
			$versions = [];
			foreach ($variants as $variant) {
				$this->stampMetadata($variant['absolute'], $buildId, $gameVersion, $branch, $variant['ficsmas'], $importedAt, $worldData);
				$versions[] = $this->upsertVersion($buildId, $branch, $gameVersion, $variant['ficsmas'], $variant['dataPath']);
			}
		} catch (\RuntimeException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		foreach ($versions as $index => $version) {
			$this->versionRepository->save($version, flush: $index === array_key_last($versions));
			$io->writeln(sprintf('Imported <info>%s</info> (%s)', $version->name, $version->uuid->toString()));
		}

		$io->success(sprintf('Build %s (%s) imported.', $buildId, $branch));
		return Command::SUCCESS;
	}

	private function upsertVersion(
		string $buildId,
		string $branch,
		?string $gameVersion,
		bool $ficsmas,
		string $dataPath,
	): Version
	{
		$version = $this->versionRepository->getByDataPath($dataPath) ?? new Version();
		if (!isset($version->uuid)) {
			$version->uuid = Uuid::uuid4();
		}

		$version->name = $this->buildName($buildId, $branch, $gameVersion, $ficsmas);
		$version->slug = sprintf('%s-%s%s', $branch, $buildId, $ficsmas ? '-ficsmas' : '');
		$version->experimental = $branch === 'experimental';
		$version->custom = false;
		$version->ficsmas = $ficsmas;
		$version->dataPath = $dataPath;
		$version->user = null;
		$version->baseVersion = null;
		$version->recipeCostMultiplier = 1.0;
		$version->powerCostMultiplier = 1.0;

		return $version;
	}

	private function buildName(string $buildId, string $branch, ?string $gameVersion, bool $ficsmas): string
	{
		$name = $gameVersion ?? ('Build ' . $buildId);

		$qualifiers = [];
		if ($branch === 'experimental') {
			$qualifiers[] = 'Experimental';
		}
		if ($ficsmas) {
			$qualifiers[] = 'FICSMAS';
		}

		return $qualifiers === [] ? $name : $name . ' (' . implode(', ', $qualifiers) . ')';
	}

	/**
	 * Fills the data file's reserved `metadata` object with the build info (and the world
	 * node counts under metadata.world, when generated), writing atomically so a crash
	 * cannot corrupt the extractor's file. Output keeps the extractor's pretty formatting.
	 *
	 * @param array<string, mixed>|null $worldData
	 */
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
		$contents = @file_get_contents($absolute);
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
			if ($worldData !== null) {
				$metadata['world'] = $worldData;
			}
			$data['metadata'] = $metadata;
			$json = json_encode($data, self::JSON_FLAGS);
		} catch (JsonException $e) {
			throw new \RuntimeException(sprintf('Data file is not valid JSON: %s', $absolute), 0, $e);
		}

		$tmp = $absolute . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $absolute)) {
			@unlink($tmp);
			throw new \RuntimeException(sprintf('Could not write data file: %s', $absolute));
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
