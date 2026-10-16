<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use DateTimeImmutable;
use Exception;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\PatreonClient;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\PaypalClient;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterSyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'supporters:sync', description: 'Fetches Patreon members and PayPal payments into the supporter email list and hands out the Discord role')]
class SupportersSyncCommand extends Command
{

	private const string DEFAULT_SINCE = '-3 days';

	public function __construct(
		private readonly SupporterService $supporterService,
		private readonly PatreonClient $patreon,
		private readonly PaypalClient $paypal,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Oldest PayPal payment to read (anything strtotime() takes)', self::DEFAULT_SINCE);
		$this->addOption('skip-patreon', null, InputOption::VALUE_NONE, 'Do not read Patreon');
		$this->addOption('skip-paypal', null, InputOption::VALUE_NONE, 'Do not read PayPal');
		$this->setHelp(<<<'HELP'
			Meant for a daily cron job. Patreon always lists every member, so each run reads all of them; PayPal
			is read from <comment>--since</comment> (default: the last 3 days). Run it once with an older date to read the
			payment history (PayPal keeps 3 years):

			  <info>php bin/console supporters:sync --skip-patreon --since=2023-11-01</info>

			Sources without credentials in local.neon are skipped. Accounts are never unmarked.
			HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$failed = false;

		if (!$input->getOption('skip-patreon')) {
			if (!$this->patreon->isEnabled()) {
				$io->note('Patreon: no token in local.neon, skipped.');
			} else {
				try {
					$io->writeln('Patreon: ' . $this->supporterService->addEmails($this->patreon->fetchSupporters())->summary());
				} catch (SupporterSyncException $e) {
					$io->error('Patreon: ' . $e->getMessage());
					$failed = true;
				}
			}
		}

		if (!$input->getOption('skip-paypal')) {
			if (!$this->paypal->isEnabled()) {
				$io->note('PayPal: no credentials in local.neon, skipped.');
			} else {
				try {
					$since = new DateTimeImmutable((string) $input->getOption('since'));
				} catch (Exception) {
					$io->error('Option --since is not a date: ' . $input->getOption('since'));
					return Command::FAILURE;
				}
				try {
					$io->writeln('PayPal: ' . $this->supporterService->addEmails($this->paypal->fetchSupporters($since, new DateTimeImmutable()))->summary());
				} catch (SupporterSyncException $e) {
					$io->error('PayPal: ' . $e->getMessage());
					$failed = true;
				}
			}
		}

		$io->writeln(sprintf('Discord: %d supporter %s handed out.', $granted = $this->supporterService->syncAllDiscordRoles(), $granted === 1 ? 'role' : 'roles'));

		return $failed ? Command::FAILURE : Command::SUCCESS;
	}

}
