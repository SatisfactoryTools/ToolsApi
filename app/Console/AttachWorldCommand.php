<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\VersionDataFile;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataException;
use greeny\SatisfactoryTools\Api\Model\Services\WorldDataService;
use greeny\SatisfactoryTools\Api\Model\Services\WorldFileStorage;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives already-published official versions the world the assets extractor published later (its
 * `world` command prints the references file). Custom versions pick it up on their own: their files
 * are keyed by the base file's contents, so they regenerate on the next request.
 */
#[AsCommand(name: 'world:attach', description: 'Stamps an extracted world (map, nodes, collectibles) into official version data files')]
class AttachWorldCommand extends Command
{

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly WorldDataService $worldDataService,
		private readonly WorldFileStorage $worldFiles,
		private readonly VersionDataFile $dataFiles,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument('slugs', InputArgument::IS_ARRAY, 'Official version slugs to update');
		$this->addOption('references', null, InputOption::VALUE_REQUIRED, 'references.json written by the assets extractor');
		$this->addOption('all', null, InputOption::VALUE_NONE, 'Update every official version');
		$this->addOption('map-only', null, InputOption::VALUE_NONE, 'Attach only the map background, for versions whose world differs from the extracted one');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$references = $this->readReferences((string) $input->getOption('references'));
		if (is_string($references)) {
			$io->error($references);
			return Command::FAILURE;
		}

		$versions = $this->resolveVersions((array) $input->getArgument('slugs'), (bool) $input->getOption('all'));
		if (is_string($versions)) {
			$io->error($versions);
			return Command::FAILURE;
		}

		$stamp = ['map' => $references['map']];
		if (!$input->getOption('map-only')) {
			$worldSource = $this->worldFiles->sourceFile($references['worldSource']);
			if ($worldSource === null) {
				$io->error(sprintf('World source %s is not published.', $references['worldSource']));
				return Command::FAILURE;
			}
			try {
				$worldNodes = $this->worldFiles->storeNodes($this->worldDataService->generateNodes(0, worldFile: $worldSource));
			} catch (WorldDataException $e) {
				$io->error('World data generation failed: ' . $e->getMessage());
				return Command::FAILURE;
			}
			$stamp += [
				'worldSource' => $references['worldSource'],
				'collectibles' => $references['collectibles'],
				'worldNodes' => $worldNodes,
			];
		}

		try {
			foreach ($versions as $version) {
				$paths = [$version->dataPath];
				foreach ($version->builds as $build) {
					$paths[] = $build->dataPath;
				}
				foreach (array_unique($paths) as $path) {
					$data = $this->dataFiles->read($path);
					$data['metadata'] = array_replace(is_array($data['metadata'] ?? null) ? $data['metadata'] : [], $stamp);
					$this->dataFiles->write($path, $data);
					$io->writeln(sprintf('<info>%s</info> %s', $version->slug, $path));
				}
			}
		} catch (\RuntimeException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$io->success(sprintf('Attached map %s to %d version(s).', $references['map'], count($versions)));
		return Command::SUCCESS;
	}

	/** @return array{map: string, worldSource: string, collectibles: string}|string the references, or an error message */
	private function readReferences(string $file): array|string
	{
		if ($file === '') {
			return 'Option --references is required.';
		}
		$contents = @file_get_contents($file); // @ intentional
		if ($contents === false) {
			return sprintf('Could not read %s.', $file);
		}
		try {
			$references = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return sprintf('%s is not valid JSON.', $file);
		}
		foreach (['map', 'worldSource', 'collectibles'] as $key) {
			if (!is_string($references[$key] ?? null) || $references[$key] === '') {
				return sprintf('%s has no "%s".', $file, $key);
			}
		}
		if (preg_match('/^[0-9a-f]+$/', $references['map']) !== 1) {
			return 'The map id must be a hex content hash.';
		}

		/** @var array{map: string, worldSource: string, collectibles: string} $references */
		return $references;
	}

	/**
	 * @param string[] $slugs
	 * @return Version[]|string the versions, or an error message
	 */
	private function resolveVersions(array $slugs, bool $all): array|string
	{
		if ($all) {
			return array_values(array_filter(
				$this->versionRepository->getPublic(),
				static fn (Version $version): bool => $version->slug !== null,
			));
		}
		if ($slugs === []) {
			return 'Pass version slugs or --all.';
		}

		$versions = [];
		foreach ($slugs as $slug) {
			$version = $this->versionRepository->getBySlug((string) $slug);
			if ($version === null || $version->custom) {
				return sprintf('Official version "%s" not found.', $slug);
			}
			$versions[] = $version;
		}

		return $versions;
	}

}
