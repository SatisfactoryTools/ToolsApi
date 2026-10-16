<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterEmailEntry;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'supporters:import', description: 'Adds supporter emails from a CSV export or a plain list of emails')]
class SupportersImportCommand extends Command
{

	/** Lowercased header names, PayPal activity export and Patreon members export first. */
	private const array EMAIL_COLUMNS = ['from email address', 'email', 'e-mail', 'email address', 'e-mail address'];

	private const array AMOUNT_COLUMNS = ['gross', 'lifetime amount', 'amount'];

	private const array ID_COLUMNS = ['transaction id', 'user id', 'patreon user id'];

	private int $skippedUnpaid = 0;

	public function __construct(private readonly SupporterService $supporterService)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('file', null, InputOption::VALUE_REQUIRED, 'CSV export with a header row, or a text file with one email per line');
		$this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Where the emails come from: ' . implode(', ', SupporterService::SOURCES));
		$this->addOption('column', null, InputOption::VALUE_REQUIRED, 'Header of the email column, when it is not one of the usual names');
		$this->setHelp(<<<'HELP'
			Re-running is safe: emails already on the list are skipped. Accounts whose sign-in email matches are
			marked as supporters straight away; everyone else is marked when they sign in.

			  <info>php bin/console supporters:import --source=legacy --file=old-supporters.txt</info>
			  <info>php bin/console supporters:import --source=paypal --file=Download.CSV</info>
			  <info>php bin/console supporters:import --source=patreon --file=members.csv</info>

			A CSV is recognised by its header row. The email column is found by name ("From Email Address",
			"Email", ...; or give <comment>--column</comment>). When there is an amount column ("Gross", "Lifetime Amount"),
			rows with no money received - your own payments, refunds, Patreon followers - are skipped.
			HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$file = trim((string) $input->getOption('file'));
		$source = trim((string) $input->getOption('source'));

		if ($file === '' || !in_array($source, SupporterService::SOURCES, true)) {
			$io->error('Give --file and --source (' . implode(', ', SupporterService::SOURCES) . ').');
			return Command::FAILURE;
		}

		try {
			$entries = $this->read($file, $source, $input->getOption('column'));
			$result = $this->supporterService->addEmails($entries);
		} catch (RuntimeException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$io->success($result->summary() . ($this->skippedUnpaid > 0 ? sprintf(' %d %s without a payment skipped.', $this->skippedUnpaid, $this->skippedUnpaid === 1 ? 'row' : 'rows') : ''));

		return Command::SUCCESS;
	}

	/** @return iterable<SupporterEmailEntry> */
	private function read(string $file, string $source, mixed $column): iterable
	{
		$handle = @fopen($file, 'r'); // @ intentional
		if ($handle === false) {
			throw new RuntimeException(sprintf('Could not open file: %s', $file));
		}

		try {
			$first = fgets($handle);
			if ($first === false) {
				return;
			}
			$first = (string) preg_replace('~^\xEF\xBB\xBF~', '', $first);

			if (str_contains($first, '@') && !is_string($column)) {
				yield new SupporterEmailEntry(trim($first, " \t\n\r\"',;"), $source);
				while (($line = fgets($handle)) !== false) {
					$line = trim($line, " \t\n\r\"',;");
					if ($line !== '') {
						yield new SupporterEmailEntry($line, $source);
					}
				}
				return;
			}

			$delimiter = $this->delimiterOf($first);
			$header = array_map(static fn (?string $name): string => mb_strtolower(trim((string) $name)), str_getcsv($first, $delimiter, escape: ''));
			$emailIndex = $this->findColumn($header, is_string($column) ? [mb_strtolower($column)] : self::EMAIL_COLUMNS);
			if ($emailIndex === null) {
				throw new RuntimeException('No email column found in the header: ' . implode(', ', $header) . ' (use --column)');
			}
			$amountIndex = $this->findColumn($header, self::AMOUNT_COLUMNS);
			$idIndex = $this->findColumn($header, self::ID_COLUMNS);

			while (($row = fgetcsv($handle, null, $delimiter, escape: '')) !== false) {
				$email = trim((string) ($row[$emailIndex] ?? ''));
				if ($email === '') {
					continue;
				}
				if ($amountIndex !== null && $this->amountOf((string) ($row[$amountIndex] ?? '')) <= 0) {
					$this->skippedUnpaid++;
					continue;
				}
				$id = $idIndex !== null ? trim((string) ($row[$idIndex] ?? '')) : '';
				yield new SupporterEmailEntry($email, $source, $id !== '' ? $id : null);
			}
		} finally {
			fclose($handle);
		}
	}

	private function delimiterOf(string $line): string
	{
		$counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
		arsort($counts);

		return (string) array_key_first($counts);
	}

	/**
	 * @param list<string> $header
	 * @param list<string> $names
	 */
	private function findColumn(array $header, array $names): ?int
	{
		foreach ($names as $name) {
			$index = array_search($name, $header, true);
			if ($index !== false) {
				return (int) $index;
			}
		}

		return null;
	}

	/** Handles "1,234.50", "1.234,50", "$5.00" and "-5,00". */
	private function amountOf(string $raw): float
	{
		$clean = (string) preg_replace('~[^\d,.\-]~', '', $raw);
		if (str_contains($clean, ',') && (!str_contains($clean, '.') || strrpos($clean, ',') > strrpos($clean, '.'))) {
			$clean = str_replace(['.', ','], ['', '.'], $clean);
		} else {
			$clean = str_replace(',', '', $clean);
		}

		return (float) $clean;
	}

}
