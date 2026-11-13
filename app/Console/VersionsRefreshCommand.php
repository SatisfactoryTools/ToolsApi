<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\CustomVersionFileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Custom version files are only regenerated when missing, so an existing file keeps the output of the generator that wrote it.
 * Run after a deploy that changes the generator; mods:import refreshes the versions using a replaced mod version on its own.
 */
#[AsCommand(name: 'versions:refresh', description: 'Regenerates out-of-date data files of custom versions')]
class VersionsRefreshCommand extends Command
{

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly CustomVersionFileService $fileService,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('all', null, InputOption::VALUE_NONE, 'Every custom version, not only the ones with mods');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$versions = $input->getOption('all')
			? array_filter($this->versionRepository->getAll(), static fn ($version): bool => $version->custom)
			: $this->versionRepository->getCustomWithMods();

		$refreshed = 0;
		$failed = 0;
		foreach ($versions as $version) {
			try {
				if ($this->fileService->ensureFile($version)) {
					$this->versionRepository->save($version);
					$refreshed++;
				}
			} catch (Throwable $e) {
				$failed++;
				$io->warning(sprintf('%s (%s): %s', $version->name, $version->uuid->toString(), $e->getMessage()));
			}
		}

		$io->success(sprintf('Checked %d custom version(s), regenerated %d.', count($versions), $refreshed));

		return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
	}

}
