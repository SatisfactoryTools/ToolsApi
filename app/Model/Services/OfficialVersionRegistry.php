<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Entities\VersionBuild;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\ShareRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionBuildRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;
use JsonException;
use Ramsey\Uuid\Uuid;

/**
 * Decides which official version an imported build belongs to. Builds whose planner data matches the newest
 * version of their branch are attached to it (its slug, plans and shares stay), anything else starts a new version.
 * Changes are persisted but not flushed, so the caller commits every variant of a build at once.
 */
class OfficialVersionRegistry
{

	public function __construct(
		private readonly VersionRepository $versionRepository,
		private readonly VersionBuildRepository $buildRepository,
		private readonly PlanRepository $planRepository,
		private readonly FolderRepository $folderRepository,
		private readonly ShareRepository $shareRepository,
		private readonly PlannerFingerprint $fingerprint,
		private readonly string $wwwDir,
	)
	{
	}

	/** @param string|null $attachSlug forces the build onto this version; with $forceNew a non-matching auto decision is skipped */
	public function register(
		string $dataPath,
		string $buildId,
		string $branch,
		?string $gameVersion,
		bool $ficsmas,
		?string $attachSlug = null,
		bool $forceNew = false,
	): PublishOutcome
	{
		$experimental = $branch === 'experimental';
		$file = $this->readFile($dataPath);
		if ($file === null) {
			throw new \RuntimeException(sprintf('Data file is missing or not valid JSON: %s', $dataPath));
		}

		$build = $this->findBuild($dataPath) ?? new VersionBuild();
		$build->dataPath = $dataPath;
		$build->buildId = $buildId;
		$build->gameVersion = $gameVersion;
		$build->plannerHash = $this->fingerprint->hash($file);
		if (!isset($build->importedAt)) {
			$build->importedAt = new DateTimeImmutable();
		}
		$current = isset($build->version) ? $build->version : null;

		if ($attachSlug !== null) {
			$target = $this->versionRepository->getBySlug($attachSlug);
			if ($target === null || $target->custom) {
				throw new \RuntimeException(sprintf('Official version "%s" not found.', $attachSlug));
			}
			if ($target->experimental !== $experimental || $target->ficsmas !== $ficsmas) {
				throw new \RuntimeException(sprintf('Version "%s" is for a different branch or FICSMAS setting.', $attachSlug));
			}
			$this->ensureHistory($target);
			$comparedWith = $target;
		} elseif ($current !== null && (!$forceNew || $current->builds->count() === 1)) {
			$target = $current;
			$comparedWith = null;
		} elseif ($forceNew) {
			$target = null;
			$comparedWith = $current ?? $this->versionRepository->getLatestOfficial($experimental, $ficsmas);
		} else {
			$comparedWith = $this->versionRepository->getLatestOfficial($experimental, $ficsmas);
			if ($comparedWith !== null) {
				$this->ensureHistory($comparedWith);
			}
			$target = $comparedWith !== null && $comparedWith->plannerHash === $build->plannerHash ? $comparedWith : null;
		}

		$diff = null;
		if ($comparedWith !== null && $comparedWith !== $current) {
			$previous = $this->readFile($comparedWith->dataPath);
			$diff = $previous !== null ? $this->fingerprint->diff($previous, $file) : null;
		}

		$removedSlug = null;
		$detachedFrom = null;
		if ($current !== null && $current !== $target) {
			$current->builds->removeElement($build);
			if ($current->builds->isEmpty()) {
				$this->assertUnused($current);
				$removedSlug = $current->slug;
				$this->versionRepository->delete($current, flush: false);
			} else {
				$detachedFrom = $current->slug;
				$this->adoptNewestBuild($current);
				$this->versionRepository->save($current, flush: false);
			}
		}

		if ($target === null) {
			$target = $this->createVersion($buildId, $branch, $ficsmas);
			$action = PublishOutcome::CREATED;
		} elseif ($target === $current) {
			$action = PublishOutcome::REFRESHED;
		} else {
			$action = $removedSlug !== null ? PublishOutcome::MERGED : PublishOutcome::ATTACHED;
		}

		$build->version = $target;
		if (!$target->builds->contains($build)) {
			$target->builds->add($build);
		}
		$this->adoptNewestBuild($target);
		$this->versionRepository->save($target, flush: false);

		return new PublishOutcome($action, $target, $comparedWith?->slug, $diff, $removedSlug, $detachedFrom);
	}

	private function findBuild(string $dataPath): ?VersionBuild
	{
		$build = $this->buildRepository->getByDataPath($dataPath);
		if ($build !== null) {
			return $build;
		}

		$version = $this->versionRepository->getByDataPath($dataPath);
		if ($version === null || $version->custom) {
			return null;
		}

		$this->ensureHistory($version);
		foreach ($version->builds as $candidate) {
			if ($candidate->dataPath === $dataPath) {
				return $candidate;
			}
		}

		return null;
	}

	/** Versions imported before builds were tracked get their current file recorded as their only build. */
	private function ensureHistory(Version $version): void
	{
		if (!$version->builds->isEmpty() && $version->plannerHash !== null) {
			return;
		}

		$file = $this->readFile($version->dataPath);
		if ($file === null) {
			return;
		}

		if ($version->builds->isEmpty()) {
			$metadata = is_array($file['metadata'] ?? null) ? $file['metadata'] : [];
			$build = new VersionBuild();
			$build->version = $version;
			$build->dataPath = $version->dataPath;
			$build->buildId = is_scalar($metadata['buildId'] ?? null) ? (string) $metadata['buildId'] : null;
			$build->gameVersion = is_string($metadata['version'] ?? null) ? $metadata['version'] : null;
			$build->plannerHash = $this->fingerprint->hash($file);
			$build->importedAt = $this->importedAt($metadata, $version->dataPath);
			$version->builds->add($build);
		}

		$version->plannerHash ??= $this->fingerprint->hash($file);
		$this->versionRepository->save($version, flush: false);
	}

	private function adoptNewestBuild(Version $version): void
	{
		$newest = null;
		foreach ($version->builds as $build) {
			if ($newest === null || $build->importedAt >= $newest->importedAt) {
				$newest = $build;
			}
		}
		if ($newest === null) {
			return;
		}

		$version->dataPath = $newest->dataPath;
		$version->plannerHash = $newest->plannerHash;
		if ($newest->gameVersion !== null || $newest->buildId !== null) {
			$version->name = $this->buildName($newest->buildId, $newest->gameVersion, $version->experimental, $version->ficsmas);
		}
	}

	private function createVersion(string $buildId, string $branch, bool $ficsmas): Version
	{
		$slug = sprintf('%s-%s%s', $branch, $buildId, $ficsmas ? '-ficsmas' : '');
		if ($this->versionRepository->getBySlug($slug) !== null) {
			throw new \RuntimeException(sprintf('Slug "%s" is already taken by another version.', $slug));
		}

		$version = new Version();
		$version->uuid = Uuid::uuid4();
		$version->slug = $slug;
		$version->experimental = $branch === 'experimental';
		$version->custom = false;
		$version->ficsmas = $ficsmas;

		return $version;
	}

	/** Device-only plans can't be seen from here; a merged version only ever exists between an import and its correction. */
	private function assertUnused(Version $version): void
	{
		$references = array_filter([
			'plans' => $this->planRepository->countByVersion($version),
			'folders' => $this->folderRepository->countByVersion($version),
			'custom versions' => $this->versionRepository->countDerived($version),
			'shares' => $this->shareRepository->countReferencingVersion($version->uuid->toString()),
		]);

		if ($references !== []) {
			$parts = [];
			foreach ($references as $label => $count) {
				$parts[] = $count . ' ' . $label;
			}
			throw new \RuntimeException(sprintf(
				'Version "%s" would be merged away but is still referenced by %s.',
				$version->slug,
				implode(', ', $parts),
			));
		}
	}

	private function buildName(?string $buildId, ?string $gameVersion, bool $experimental, bool $ficsmas): string
	{
		$name = $gameVersion ?? ('Build ' . $buildId);

		$qualifiers = [];
		if ($experimental) {
			$qualifiers[] = 'Experimental';
		}
		if ($ficsmas) {
			$qualifiers[] = 'FICSMAS';
		}

		return $qualifiers === [] ? $name : $name . ' (' . implode(', ', $qualifiers) . ')';
	}

	/** @param array<string, mixed> $metadata */
	private function importedAt(array $metadata, string $dataPath): DateTimeImmutable
	{
		if (is_string($metadata['importedAt'] ?? null)) {
			try {
				return new DateTimeImmutable($metadata['importedAt']);
			} catch (\Exception) {
			}
		}

		$mtime = @filemtime($this->absolutePath($dataPath)); // @ intentional

		return $mtime !== false ? (new DateTimeImmutable())->setTimestamp($mtime) : new DateTimeImmutable();
	}

	/** @return array<string, mixed>|null */
	private function readFile(string $dataPath): ?array
	{
		$contents = @file_get_contents($this->absolutePath($dataPath)); // @ intentional
		if ($contents === false) {
			return null;
		}

		try {
			$data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		return is_array($data) ? $data : null;
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
