<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use greeny\SatisfactoryTools\Api\Model\Entities\LegacyShare;
use greeny\SatisfactoryTools\Api\Model\Repositories\LegacyShareRepository;
use JsonException;
use PDO;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies the shared production lines of the old Satisfactory Tools API (table `shared`:
 * slug + JSON document) into `legacy_share`, so the old links keep working through
 * GET /v1/legacy-shares/{slug} once the old API is retired.
 *
 * Idempotent: rows are upserted by slug and a row whose data already matches is left
 * alone, so the command can be re-run (e.g. once more right before the old API goes
 * offline) to pick up shares created in between. Two sources are supported:
 *
 *   --dsn="mysql:host=127.0.0.1;dbname=satisfactorytools" --user=... --password=...
 *       reads the old database directly over PDO, in id-ordered batches.
 *
 *   --file=/path/to/shared.jsonl
 *       reads a dump with one {"slug": "...", "data": "..."} object per line, where data
 *       is the raw JSON string as stored (see the --file option help for how to make it).
 *
 * Either way the rows stream through in batches (default 500) and the EntityManager is
 * cleared after each one, so memory stays flat whatever the table size.
 */
#[AsCommand(name: 'legacy-shares:import', description: 'Imports shared production lines from the old Satisfactory Tools API into legacy_share')]
class ImportLegacySharesCommand extends Command
{

	private const DEFAULT_BATCH_SIZE = 500;

	/** Same rule as LegacySharesController — a slug that can never be served is invalid. */
	private const SLUG_PATTERN = '~^[A-Za-z0-9]{1,64}$~D';

	private int $imported = 0;

	private int $updated = 0;

	private int $unchanged = 0;

	private int $invalid = 0;

	public function __construct(
		private readonly LegacyShareRepository $legacyShareRepository,
		private readonly EntityManagerInterface $entityManager,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PDO DSN of the old database, e.g. "mysql:host=127.0.0.1;dbname=satisfactorytools"');
		$this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Database user for --dsn');
		$this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password for --dsn', '');
		$this->addOption(
			'file',
			null,
			InputOption::VALUE_REQUIRED,
			'JSON Lines dump instead of --dsn: one {"slug": "...", "data": "<raw JSON string>"} per line. Produce it with: '
			. 'mysql -N -B -r -e "SELECT JSON_OBJECT(\'slug\', slug, \'data\', data) FROM shared" satisfactorytools > shared.jsonl',
		);
		$this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch (one flush + EntityManager clear each)', (string) self::DEFAULT_BATCH_SIZE);
		$this->setHelp(<<<'HELP'
			Imports the old API's <comment>shared</comment> table into <comment>legacy_share</comment>. Re-running is safe: rows are
			matched by slug, rows whose data is unchanged are skipped, changed rows are updated.

			Straight from the old database:

			  <info>php bin/console legacy-shares:import --dsn="mysql:host=127.0.0.1;dbname=satisfactorytools" --user=root --password=secret</info>

			From a JSON Lines dump (one {"slug": ..., "data": ...} object per line; data is the raw
			JSON string exactly as stored), which you can produce on the old server with:

			  <info>mysql -N -B -r -e "SELECT JSON_OBJECT('slug', slug, 'data', data) FROM shared" satisfactorytools > shared.jsonl</info>
			  <info>php bin/console legacy-shares:import --file=/path/to/shared.jsonl</info>

			(<comment>-N</comment> drops the header line, <comment>-B</comment> prints one row per line, <comment>-r</comment> writes the JSON without
			the client's tab/newline escaping — JSON_OBJECT already escapes everything.)

			Rows whose slug is not 1-64 alphanumeric characters or whose data is not a JSON object are
			counted as invalid and skipped.
			HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$dsn = trim((string) $input->getOption('dsn'));
		$file = trim((string) $input->getOption('file'));
		$batchSize = (int) $input->getOption('batch-size');

		if (($dsn === '') === ($file === '')) {
			$io->error('Give exactly one source: --dsn (with --user/--password) or --file.');
			return Command::FAILURE;
		}

		if ($batchSize < 1) {
			$io->error('Option --batch-size must be a positive integer.');
			return Command::FAILURE;
		}

		try {
			$rows = $dsn !== ''
				? $this->readDatabase($dsn, (string) $input->getOption('user'), (string) $input->getOption('password'), $batchSize)
				: $this->readFile($file);
			$io->writeln(sprintf('Importing from <info>%s</info> in batches of %d...', $dsn !== '' ? $dsn : $file, $batchSize));

			$batch = [];
			$batchNumber = 0;
			foreach ($rows as $row) {
				$slug = $this->validSlug($row['slug'] ?? null);
				$data = $this->validData($row['data'] ?? null);
				if ($slug === null || $data === null) {
					$this->invalid++;
					continue;
				}

				// Last occurrence of a slug wins, like a sequence of upserts would.
				$batch[$slug] = $data;
				if (count($batch) >= $batchSize) {
					$this->importBatch($batch);
					$this->reportBatch($io, ++$batchNumber);
					$batch = [];
				}
			}

			if ($batch !== []) {
				$this->importBatch($batch);
				$this->reportBatch($io, ++$batchNumber);
			}
		} catch (PDOException $e) {
			$io->error('Old database error: ' . $e->getMessage());
			return Command::FAILURE;
		} catch (\RuntimeException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$io->success(sprintf(
			'Done: %d imported, %d updated, %d unchanged, %d invalid (skipped).',
			$this->imported,
			$this->updated,
			$this->unchanged,
			$this->invalid,
		));

		return Command::SUCCESS;
	}

	/**
	 * Streams `shared` rows out of the old database, ordered by id and fetched a batch at a
	 * time (keyset pagination — no OFFSET, so late pages cost no more than early ones and
	 * the connection never holds an open cursor over the whole table).
	 *
	 * @return iterable<array{slug: mixed, data: mixed}>
	 */
	private function readDatabase(string $dsn, string $user, string $password, int $batchSize): iterable
	{
		if (str_starts_with($dsn, 'mysql:') && !str_contains($dsn, 'charset=')) {
			$dsn .= ';charset=utf8mb4';
		}

		$pdo = new PDO($dsn, $user, $password, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$statement = $pdo->prepare('SELECT id, slug, data FROM shared WHERE id > :after ORDER BY id LIMIT :limit');
		$afterId = 0;
		do {
			$statement->bindValue('after', $afterId, PDO::PARAM_INT);
			$statement->bindValue('limit', $batchSize, PDO::PARAM_INT);
			$statement->execute();

			$count = 0;
			while (($row = $statement->fetch()) !== false) {
				$count++;
				$afterId = (int) $row['id'];
				yield ['slug' => $row['slug'], 'data' => $row['data']];
			}
			$statement->closeCursor();
		} while ($count === $batchSize);
	}

	/**
	 * Streams a JSON Lines dump line by line. Blank lines are ignored; a line that is not
	 * a JSON object yields a row with no slug, which is then counted as invalid.
	 *
	 * @return iterable<array{slug: mixed, data: mixed}>
	 */
	private function readFile(string $file): iterable
	{
		$handle = @fopen($file, 'r');
		if ($handle === false) {
			throw new \RuntimeException(sprintf('Could not open file: %s', $file));
		}

		try {
			while (($line = fgets($handle)) !== false) {
				$line = trim($line);
				if ($line === '') {
					continue;
				}

				try {
					$decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
				} catch (JsonException) {
					$decoded = null;
				}

				yield is_array($decoded)
					? ['slug' => $decoded['slug'] ?? null, 'data' => $decoded['data'] ?? null]
					: ['slug' => null, 'data' => null];
			}
		} finally {
			fclose($handle);
		}
	}

	private function validSlug(mixed $slug): ?string
	{
		return is_string($slug) && preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
	}

	/** The raw string is kept verbatim; it only has to decode to a JSON object. */
	private function validData(mixed $data): ?string
	{
		if (!is_string($data)) {
			return null;
		}

		try {
			$decoded = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		return is_array($decoded) ? $data : null;
	}

	/**
	 * Upserts one batch: a single lookup of the existing rows, then insert / update /
	 * skip per slug, one flush, and a clear so nothing from this batch stays managed.
	 *
	 * @param array<string, string> $batch slug => raw data
	 */
	private function importBatch(array $batch): void
	{
		$existing = $this->legacyShareRepository->getBySlugs(array_keys($batch));
		$now = new DateTimeImmutable();

		foreach ($batch as $slug => $data) {
			$share = $existing[$slug] ?? null;
			if ($share === null) {
				$share = new LegacyShare();
				$share->slug = $slug;
				$share->data = $data;
				$share->createdAt = $now;
				$this->legacyShareRepository->save($share, flush: false);
				$this->imported++;
			} elseif ($share->data !== $data) {
				$share->data = $data;
				$this->updated++;
			} else {
				$this->unchanged++;
			}
		}

		$this->legacyShareRepository->flush();
		$this->entityManager->clear();
	}

	private function reportBatch(SymfonyStyle $io, int $batchNumber): void
	{
		$io->writeln(sprintf(
			'Batch %d: %d imported, %d updated, %d unchanged, %d invalid so far',
			$batchNumber,
			$this->imported,
			$this->updated,
			$this->unchanged,
			$this->invalid,
		));
	}

}
