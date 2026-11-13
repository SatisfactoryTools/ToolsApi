<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Console;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Mod;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModVersionRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use greeny\SatisfactoryTools\Api\Model\Services\CustomVersionFileService;
use greeny\SatisfactoryTools\Api\Model\Services\ModDataStorage;
use JsonException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mods:import', description: 'Stores a mod read by the assets extractor (mod:import) as a mod version')]
class ModsImportCommand extends Command
{

	public function __construct(
		private readonly ModRepository $modRepository,
		private readonly ModVersionRepository $modVersionRepository,
		private readonly ModDataStorage $modDataStorage,
		private readonly UserRepository $userRepository,
		private readonly VersionRepository $versionRepository,
		private readonly CustomVersionFileService $fileService,
		private readonly string $ownerUuid,
	)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('data', null, InputOption::VALUE_REQUIRED, 'Mod data file ({data, metadata}, like a version data file)');
		$this->addOption('reference', null, InputOption::VALUE_REQUIRED, 'ficsit.app mod reference; an existing mod of the owner with this reference gets the version');
		$this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Mod name');
		$this->addOption('mod-version', null, InputOption::VALUE_REQUIRED, 'Version name, e.g. 1.4.4; an existing version with this name is replaced');
		$this->addOption('source-version', null, InputOption::VALUE_REQUIRED, 'ficsit.app version id');
		$this->addOption('depends-on', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Id of a mod version this one needs');
		$this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Id of the owning account (default: modImport.ownerUuid)');
		$this->addOption('public', null, InputOption::VALUE_NONE, 'Make the mod and the mods it needs public');
		$this->addOption('summary-file', null, InputOption::VALUE_REQUIRED, 'Write the ids of the mod and version to this JSON file');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		foreach (['data', 'reference', 'name', 'mod-version'] as $required) {
			if (trim((string) $input->getOption($required)) === '') {
				$io->error(sprintf('Option --%s is required.', $required));
				return Command::FAILURE;
			}
		}

		$ownerUuid = trim((string) ($input->getOption('owner') ?? $this->ownerUuid));
		$owner = Uuid::isValid($ownerUuid) ? $this->userRepository->getByUuid($ownerUuid) : null;
		if ($owner === null) {
			$io->error('No owner account. Set modImport.ownerUuid in config/local.neon or pass --owner.');
			return Command::FAILURE;
		}

		try {
			$data = json_decode((string) @file_get_contents((string) $input->getOption('data')), true, flags: JSON_THROW_ON_ERROR); // @ intentional
		} catch (JsonException $e) {
			$io->error('Cannot read the data file: ' . $e->getMessage());
			return Command::FAILURE;
		}
		// Without the {data, metadata} envelope the data silently merges into nothing.
		if (!is_array($data) || !is_array($data['data'] ?? null)) {
			$io->error('The data file must be an object with a "data" object.');
			return Command::FAILURE;
		}

		$dependencies = [];
		foreach ((array) $input->getOption('depends-on') as $dependencyId) {
			$dependency = Uuid::isValid((string) $dependencyId) ? $this->modVersionRepository->getByUuid((string) $dependencyId) : null;
			if ($dependency === null) {
				$io->error('Mod version not found: ' . $dependencyId);
				return Command::FAILURE;
			}
			$dependencies[] = $dependency;
		}

		$public = (bool) $input->getOption('public');
		$reference = trim((string) $input->getOption('reference'));
		$mod = $this->modRepository->getByReferenceAndOwner($reference, $owner);
		$createdMod = $mod === null;
		if ($mod === null) {
			$mod = new Mod();
			$mod->user = $owner;
			$mod->reference = $reference;
			$mod->createdAt = new DateTimeImmutable();
		}
		$mod->name = trim((string) $input->getOption('name'));
		if ($public) {
			$mod->public = true;
		}
		$this->modRepository->save($mod, false);

		$versionName = trim((string) $input->getOption('mod-version'));
		$modVersion = $createdMod ? null : $this->modVersionRepository->getByModAndName($mod, $versionName);
		$createdVersion = $modVersion === null;
		if ($modVersion === null) {
			$modVersion = new ModVersion();
			$modVersion->uuid = Uuid::uuid4();
			$modVersion->mod = $mod;
			$modVersion->name = $versionName;
			$modVersion->createdAt = new DateTimeImmutable();
			$mod->versions->add($modVersion);
		}
		$sourceVersion = $input->getOption('source-version');
		$modVersion->sourceVersionId = $sourceVersion !== null && trim((string) $sourceVersion) !== '' ? trim((string) $sourceVersion) : null;

		$modVersion->dependencies->clear();
		foreach ($dependencies as $dependency) {
			$modVersion->dependencies->add($dependency);
			// A public mod that needs a private one could not be used by anyone else.
			if ($mod->public && !$dependency->mod->public) {
				$dependency->mod->public = true;
				$this->modRepository->save($dependency->mod, false);
			}
		}

		$modVersion->dataPath = $this->modDataStorage->store($modVersion->uuid, $data);
		$this->modVersionRepository->save($modVersion);

		// Their files would otherwise keep the replaced data until pruned.
		$refreshed = 0;
		foreach ($createdVersion ? [] : $this->versionRepository->getUsingModVersion($modVersion) as $version) {
			if ($this->fileService->ensureFile($version)) {
				$this->versionRepository->save($version);
				$refreshed++;
			}
		}

		$summary = [
			'modId' => $mod->uuid->toString(),
			'modVersionId' => $modVersion->uuid->toString(),
			'createdMod' => $createdMod,
			'createdVersion' => $createdVersion,
		];
		$summaryFile = $input->getOption('summary-file');
		if ($summaryFile !== null) {
			file_put_contents((string) $summaryFile, json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		}

		$io->success(sprintf('%s %s %s (%s, version %s).%s', $mod->name, $versionName,
			$createdVersion ? 'imported' : 'replaced', $mod->public ? 'public' : 'private', $modVersion->uuid->toString(),
			$refreshed > 0 ? sprintf(' Regenerated %d custom version(s) using it.', $refreshed) : ''));

		return Command::SUCCESS;
	}

}
