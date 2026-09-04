<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Folder;
use greeny\SatisfactoryTools\Api\Model\Entities\Plan;
use greeny\SatisfactoryTools\Api\Model\Entities\Share;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\SharedVisitRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\ShareRepository;
use JsonException;
use Ramsey\Uuid\Uuid;

/**
 * Creates and reads point-in-time shares. Sharing a folder or plan freezes the entire
 * subtree beneath it (descendant folders, their plans, and all nested subplans) into a
 * JSON snapshot on the Share row, so subsequent edits to the originals are never visible
 * to anyone who already holds the share link.
 */
class ShareService
{

	/**
	 * Maximum entries in a user's visited-shares list; inserting beyond it evicts the
	 * entry with the oldest visitedAt. The frontend mirrors this value for the anonymous
	 * localStorage list, so keep the two in sync (docs/shared-plans-api.md).
	 */
	public const VISITED_CAP = 20;

	public function __construct(
		private readonly FolderRepository $folderRepository,
		private readonly PlanRepository $planRepository,
		private readonly ShareRepository $shareRepository,
		private readonly SharedVisitRepository $sharedVisitRepository,
	)
	{
	}

	/** Freezes a folder and everything under it into a new share. */
	public function shareFolder(User $user, Version $version, Folder $folder): Share
	{
		$index = $this->indexTree($user, $version);

		return $this->persist($user, 'folder', $version, $this->buildFolderNode($folder, $index));
	}

	/** Freezes a plan (or subplan) and all of its subplans into a new share. */
	public function sharePlan(User $user, Version $version, Plan $plan): Share
	{
		$index = $this->indexTree($user, $version);

		return $this->persist($user, 'plan', $version, $this->buildPlanNode($plan, $index));
	}

	/**
	 * Returns the frozen contents of a share for the read-only viewer, or null if the
	 * UUID is malformed or no such share exists.
	 *
	 * @return array<string, mixed>|null
	 */
	public function present(string $shareUuid): ?array
	{
		if (!Uuid::isValid($shareUuid)) {
			return null;
		}

		$share = $this->shareRepository->getByUuid($shareUuid);
		if ($share === null) {
			return null;
		}

		/** @var array{version: mixed, root: mixed} $snapshot */
		$snapshot = json_decode($share->snapshot, true);

		return [
			'share' => $share->uuid->toString(),
			'type' => $share->type,
			'sharedAt' => $share->createdAt->format('c'),
			'version' => $snapshot['version'] ?? null,
			'root' => $snapshot['root'] ?? null,
		];
	}

	/**
	 * The user's visited shares, most recently visited first, as the response payload of
	 * GET /v1/shares/visited. Everything except visitedAt is resolved from the frozen
	 * share at read time (shares are immutable, so the values are stable).
	 *
	 * @return array{shares: array<int, array<string, mixed>>}
	 */
	public function visitedShares(User $user): array
	{
		$shares = [];
		foreach ($this->sharedVisitRepository->getByUserNewestFirst($user) as $visit) {
			$share = $visit->share;
			/** @var array{version: mixed, root: array<string, mixed>|null} $snapshot */
			$snapshot = json_decode($share->snapshot, true);

			$shares[] = [
				'share' => $share->uuid->toString(),
				'type' => $share->type,
				'name' => $snapshot['root']['name'] ?? null,
				'sharedAt' => $share->createdAt->format('c'),
				'visitedAt' => $visit->visitedAt->format('c'),
				'version' => $snapshot['version'] ?? null,
			];
		}

		return ['shares' => $shares];
	}

	/**
	 * Records a visit of the share by the user (upsert — an existing entry only gets its
	 * visitedAt bumped). Returns false if no such share exists.
	 */
	public function recordVisit(User $user, string $shareUuid): bool
	{
		$share = Uuid::isValid($shareUuid) ? $this->shareRepository->getByUuid($shareUuid) : null;
		if ($share === null) {
			return false;
		}

		$this->sharedVisitRepository->recordVisit($user, $share, self::VISITED_CAP);

		return true;
	}

	/**
	 * Removes the user's visit entry for the share, if any. Idempotent; never touches the
	 * share itself.
	 */
	public function forgetVisit(User $user, string $shareUuid): void
	{
		$share = Uuid::isValid($shareUuid) ? $this->shareRepository->getByUuid($shareUuid) : null;
		if ($share === null) {
			return;
		}

		$visit = $this->sharedVisitRepository->getByUserAndShare($user, $share);
		if ($visit !== null) {
			$this->sharedVisitRepository->delete($visit);
		}
	}

	/** @param array<string, mixed> $root */
	private function persist(User $user, string $type, Version $version, array $root): Share
	{
		$snapshot = [
			'version' => [
				'id' => $version->uuid->toString(),
				'name' => $version->name,
				'slug' => $version->slug,
				'experimental' => $version->experimental,
				'custom' => $version->custom,
				'ficsmas' => $version->ficsmas,
			],
			'root' => $root,
		];

		try {
			$encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new \RuntimeException('Could not encode share snapshot', 0, $e);
		}

		$share = new Share();
		$share->user = $user;
		$share->type = $type;
		$share->snapshot = $encoded;
		$share->createdAt = new DateTimeImmutable();

		$this->shareRepository->save($share);

		return $share;
	}

	/**
	 * Loads every folder and plan the user owns in this version once, and indexes them by
	 * parent so the tree can be walked without further queries.
	 *
	 * @return array{childFolders: array<int, Folder[]>, folderPlans: array<int, Plan[]>, subplans: array<int, Plan[]>}
	 */
	private function indexTree(User $user, Version $version): array
	{
		$childFolders = [];
		foreach ($this->folderRepository->getByUserAndVersion($user, $version) as $folder) {
			if ($folder->parent !== null) {
				$childFolders[$folder->parent->id][] = $folder;
			}
		}

		$folderPlans = [];
		$subplans = [];
		foreach ($this->planRepository->getByUserAndVersion($user, $version) as $plan) {
			if ($plan->parent !== null) {
				$subplans[$plan->parent->id][] = $plan;
			} elseif ($plan->folder !== null) {
				$folderPlans[$plan->folder->id][] = $plan;
			}
		}

		return ['childFolders' => $childFolders, 'folderPlans' => $folderPlans, 'subplans' => $subplans];
	}

	/**
	 * @param array{childFolders: array<int, Folder[]>, folderPlans: array<int, Plan[]>, subplans: array<int, Plan[]>} $index
	 * @return array<string, mixed>
	 */
	private function buildFolderNode(Folder $folder, array $index): array
	{
		$children = [];
		foreach ($index['childFolders'][$folder->id] ?? [] as $child) {
			$children[] = $this->buildFolderNode($child, $index);
		}

		$plans = [];
		foreach ($index['folderPlans'][$folder->id] ?? [] as $plan) {
			$plans[] = $this->buildPlanNode($plan, $index);
		}

		return [
			'id' => $folder->uuid->toString(),
			'name' => $folder->name,
			'data' => $folder->data,
			'createdAt' => $folder->createdAt->format('c'),
			'children' => $children,
			'plans' => $plans,
		];
	}

	/**
	 * @param array{childFolders: array<int, Folder[]>, folderPlans: array<int, Plan[]>, subplans: array<int, Plan[]>} $index
	 * @return array<string, mixed>
	 */
	private function buildPlanNode(Plan $plan, array $index): array
	{
		$subplans = [];
		foreach ($index['subplans'][$plan->id] ?? [] as $subplan) {
			$subplans[] = $this->buildPlanNode($subplan, $index);
		}

		return [
			'id' => $plan->uuid->toString(),
			'name' => $plan->name,
			'description' => $plan->description,
			'data' => $plan->data,
			'createdAt' => $plan->createdAt->format('c'),
			'subplans' => $subplans,
		];
	}

}
