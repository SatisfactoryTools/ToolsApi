<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterEmailEntry;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'supporters:add', description: 'Marks an account as supporter, or adds an email to the supporter list')]
class SupportersGrantCommand extends Command
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
		$this->addArgument('who', InputArgument::REQUIRED, 'An email (added to the list), or the login or id of an account (marked directly)');
		$this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Where the support came from: ' . implode(', ', SupporterService::SOURCES), 'manual');
		$this->setHelp(<<<'HELP'
			For someone who paid with an email their account does not use. Mark the account itself by login or id:

			  <info>php bin/console supporters:add some-login</info>

			or put another email on the list (the account is marked once that email is its account email or
			the email of a linked sign-in method):

			  <info>php bin/console supporters:add someone@example.com</info>
			HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$who = trim((string) $input->getArgument('who'));
		$source = (string) $input->getOption('source');
		if (!in_array($source, SupporterService::SOURCES, true)) {
			$io->error('Option --source must be one of: ' . implode(', ', SupporterService::SOURCES));
			return Command::FAILURE;
		}

		if (str_contains($who, '@')) {
			$result = $this->supporterService->addEmails([new SupporterEmailEntry($who, $source)]);
			if ($result->invalid > 0) {
				$io->error('Not a valid email: ' . $who);
				return Command::FAILURE;
			}
			$io->success($result->summary());
			return Command::SUCCESS;
		}

		$user = $this->findUser($who);
		if ($user === null) {
			$io->error('No account with login or id ' . $who);
			return Command::FAILURE;
		}

		$supporter = $this->supporterService->grant($user, $source);
		$io->success(sprintf('%s is a supporter since %s.', $user->login, $supporter->since->format('Y-m-d')));

		return Command::SUCCESS;
	}

	private function findUser(string $who): ?User
	{
		return Uuid::isValid($who) ? $this->userRepository->getByUuid($who) : $this->userRepository->getByLogin($who);
	}

}
