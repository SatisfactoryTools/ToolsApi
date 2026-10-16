<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'supporters:revoke', description: 'Takes the supporter mark off an account (refunds, chargebacks)')]
class SupportersRevokeCommand extends Command
{

	public function __construct(
		private readonly SupporterService $supporterService,
		private readonly UserRepository $userRepository,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument('who', InputArgument::REQUIRED, 'Login or id of the account');
		$this->addOption('forget-emails', null, InputOption::VALUE_NONE, 'Also drop the account\'s sign-in emails from the supporter list; without it the next sign-in marks the account again');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$who = trim((string) $input->getArgument('who'));
		$user = Uuid::isValid($who) ? $this->userRepository->getByUuid($who) : $this->userRepository->getByLogin($who);
		if ($user === null) {
			$io->error('No account with login or id ' . $who);
			return Command::FAILURE;
		}

		$removed = $this->supporterService->revoke($user, (bool) $input->getOption('forget-emails'));
		$io->success($removed ? $user->login . ' is no longer a supporter. A Discord role it got stays; remove it by hand.' : $user->login . ' was not a supporter.');

		return Command::SUCCESS;
	}

}
