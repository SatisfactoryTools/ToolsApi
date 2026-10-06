<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'versions:prune', description: 'Deletes generated custom version data files older than the retention period')]
class PruneVersionFilesCommand extends Command
{

	private const string CUSTOM_DIR = 'data/versions/custom';

	private const int TMP_MAX_AGE = 3600;

	public function __construct(
		private readonly string $wwwDir,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete files last generated more than this many days ago', '30');
		$this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$days = (int) $input->getOption('days');
		if ($days < 1) {
			$io->error('Option --days must be a positive integer.');
			return Command::FAILURE;
		}

		$dryRun = (bool) $input->getOption('dry-run');
		$dir = rtrim($this->wwwDir, '/') . '/' . self::CUSTOM_DIR;
		$now = time();

		$deleted = 0;
		$kept = 0;
		foreach (glob($dir . '/*.json') ?: [] as $file) {
			$mtime = @filemtime($file); // @ intentional
			if ($mtime === false || $mtime > $now - $days * 86400) {
				$kept++;
				continue;
			}

			if ($dryRun) {
				$io->writeln('Would delete ' . basename($file));
				$deleted++;
			} elseif (@unlink($file)) { // @ intentional
				$deleted++;
			} else {
				$io->warning('Could not delete ' . basename($file));
			}
		}

		// Crashed generations leave *.tmp files behind; any older than an hour are dead.
		foreach (glob($dir . '/*.tmp') ?: [] as $file) {
			$mtime = @filemtime($file); // @ intentional
			if ($mtime !== false && $mtime <= $now - self::TMP_MAX_AGE && !$dryRun) {
				@unlink($file); // @ intentional
			}
		}

		$io->success(sprintf(
			'%s %d file(s) older than %d day(s), kept %d.',
			$dryRun ? 'Would delete' : 'Deleted',
			$deleted,
			$days,
			$kept,
		));

		return Command::SUCCESS;
	}

}
