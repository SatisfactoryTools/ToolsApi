<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Folder;
use greeny\SatisfactoryTools\Api\Model\Entities\Plan;
use greeny\SatisfactoryTools\Api\Model\Entities\Share;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\SharedVisitRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\ShareRepository;
use JsonException;
use Ramsey\Uuid\Uuid;

/**
 * Creates and reads point-in-time shares. Sharing a folder or plan freezes the entire
 * subtree beneath it (descendant folders, their plans, and all nested subplans) into a
 * JSON snapshot on the Share row, so subsequent edits to the originals are never visible
 * to anyone who already holds the share link.
 *
 * The tree is either read from the creator's own rows (shareFolder()/sharePlan()) or
 * taken from the request (shareTree(), for plans that only exist in the browser). Both
 * produce the same snapshot; only the latter needs validating, since it is reachable
 * without an account.
 */
class ShareService
{

	/**
	 * Maximum entries in a user's visited-shares list; inserting beyond it evicts the
	 * entry with the oldest visitedAt. The frontend mirrors this value for the anonymous
	 * localStorage list, so keep the two in sync (docs/shared-plans-api.md).
	 */
	public const VISITED_CAP = 20;

	/**
	 * Caps on a tree sent with the request (shareTree(), reached by the unauthenticated
	 * POST /v1/shares). The raw body is capped separately by the controller; these bound
	 * the shape of the tree, so a body that fits cannot still expand into an expensive
	 * structure or an unreasonable number of rows in the viewer.
	 */
	public const TREE_MAX_NODES = 500;
	public const TREE_MAX_DEPTH = 32;

	/** Cap on a node name, matching the column owned folders/plans are stored in. */
	private const NAME_MAX_LENGTH = 255;

	public function __construct(
		private readonly PlanTreeBuilder $treeBuilder,
		private readonly ShareRepository $shareRepository,
		private readonly SharedVisitRepository $sharedVisitRepository,
	)
	{
	}

	/** Freezes a folder and everything under it into a new share. */
	public function shareFolder(User $user, Version $version, Folder $folder): Share
	{
		$index = $this->treeBuilder->index($user, $version);

		return $this->persist($user, 'folder', $version, $this->treeBuilder->folderNode($folder, $index));
	}

	/** Freezes a plan (or subplan) and all of its subplans into a new share. */
	public function sharePlan(User $user, Version $version, Plan $plan): Share
	{
		$index = $this->treeBuilder->index($user, $version);

		return $this->persist($user, 'plan', $version, $this->treeBuilder->planNode($plan, $index));
	}

	/**
	 * Freezes a tree the client sent with the request, reading nothing from the folder and
	 * plan tables. This is how a signed-out visitor shares — their plans live only in the
	 * browser, so there is no row to point at — and how a signed-in one shares the plans
	 * they still keep on the device. $user is the creator when the request carried a token
	 * and null otherwise; the resulting share is identical either way.
	 *
	 * The node shape mirrors what GET /v1/shares/{uuid} returns: a plan is
	 * {id?, name, description?, data, subplans[]}, a folder {id?, name, data, children[],
	 * plans[]}. Node ids are optional — they are only internal references, and a copy of a
	 * share mints fresh ones anyway — so missing ones are generated here. createdAt is
	 * server-assigned.
	 *
	 * @throws ShareInputException when the tree is malformed or busts one of the caps
	 */
	public function shareTree(?User $user, Version $version, string $type, mixed $root): Share
	{
		$createdAt = new DateTimeImmutable();
		$nodes = 0;

		$node = $type === 'folder'
			? $this->readFolderNode($root, 'root', 1, $nodes, $createdAt)
			: $this->readPlanNode($root, 'root', 1, $nodes, $createdAt);

		return $this->persist($user, $type, $version, $node, $createdAt);
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

			$entry = [
				'share' => $share->uuid->toString(),
				'type' => $share->type,
				'name' => $snapshot['root']['name'] ?? null,
				'sharedAt' => $share->createdAt->format('c'),
				'visitedAt' => $visit->visitedAt->format('c'),
				'version' => $snapshot['version'] ?? null,
			];
			if ($share->type === 'plan') {
				$entry['iconClassName'] = $this->resolvePlanIcon($snapshot['root']['data'] ?? null);
			}

			$shares[] = $entry;
		}

		return ['shares' => $shares];
	}

	/**
	 * The icon the planner shows for a plan, read from the plan's frozen `data` JSON so
	 * the visited list can carry it without the frontend fetching every share. Mirrors
	 * the frontend's own rule: an explicit `iconClassName` key wins (a null there means
	 * "no icon" and stays null), otherwise the item of the first request, otherwise null.
	 */
	private function resolvePlanIcon(mixed $data): ?string
	{
		if (!is_string($data)) {
			return null;
		}

		$decoded = json_decode($data, true);
		if (!is_array($decoded)) {
			return null;
		}

		if (array_key_exists('iconClassName', $decoded)) {
			return is_string($decoded['iconClassName']) && $decoded['iconClassName'] !== '' ? $decoded['iconClassName'] : null;
		}

		$first = $decoded['requests'][0] ?? null;
		if (is_array($first) && is_string($first['itemClassName'] ?? null) && $first['itemClassName'] !== '') {
			return $first['itemClassName'];
		}

		return null;
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
	private function persist(?User $user, string $type, Version $version, array $root, ?DateTimeImmutable $createdAt = null): Share
	{
		$snapshot = [
			'version' => $this->treeBuilder->versionSnapshot($version),
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
		$share->createdAt = $createdAt ?? new DateTimeImmutable();

		$this->shareRepository->save($share);

		return $share;
	}

	/**
	 * Validates one node of a client-sent tree and returns the fields every node shares
	 * (plus the node itself, for the caller to read its children from). $path — e.g.
	 * "root.plans[2].subplans[0]" — makes a rejection point at the offending node.
	 *
	 * @return array{input: array<string, mixed>, id: string, name: string, data: string}
	 * @throws ShareInputException
	 */
	private function readNode(mixed $input, string $path, int $depth): array
	{
		if (!is_array($input) || (array_is_list($input) && $input !== [])) {
			throw new ShareInputException(sprintf('Field %s must be an object', $path));
		}

		if ($depth > self::TREE_MAX_DEPTH) {
			throw new ShareInputException(sprintf('The shared tree may be at most %d levels deep', self::TREE_MAX_DEPTH));
		}

		$id = $input['id'] ?? null;
		if ($id === null || $id === '') {
			$id = Uuid::uuid4()->toString();
		} elseif (!is_string($id) || !Uuid::isValid($id)) {
			throw new ShareInputException(sprintf('Field %s.id must be a UUID or be left out', $path));
		} else {
			// Normalizes the spelling (case, braces) the client used.
			$id = Uuid::fromString($id)->toString();
		}

		$name = $input['name'] ?? '';
		if (!is_string($name)) {
			throw new ShareInputException(sprintf('Field %s.name must be a string', $path));
		}
		$name = trim($name);
		if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
			throw new ShareInputException(sprintf('Field %s.name may be at most %d characters long', $path, self::NAME_MAX_LENGTH));
		}

		// The same opaque JSON string the frontend PUTs for an owned plan/folder; stored
		// verbatim, exactly like the owned path stores $plan->data.
		$data = $input['data'] ?? '{}';
		if (!is_string($data)) {
			throw new ShareInputException(sprintf('Field %s.data must be a JSON string', $path));
		}

		return ['input' => $input, 'id' => $id, 'name' => $name, 'data' => $data !== '' ? $data : '{}'];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<int, mixed>
	 * @throws ShareInputException
	 */
	private function readChildList(array $input, string $key, string $path): array
	{
		$list = $input[$key] ?? [];
		if ($list === null) {
			return [];
		}

		if (!is_array($list) || !array_is_list($list)) {
			throw new ShareInputException(sprintf('Field %s.%s must be an array', $path, $key));
		}

		return $list;
	}

	/**
	 * @param int $nodes running total of nodes read so far
	 * @return array<string, mixed>
	 * @throws ShareInputException
	 */
	private function readPlanNode(mixed $input, string $path, int $depth, int &$nodes, DateTimeImmutable $createdAt): array
	{
		$node = $this->readNode($input, $path, $depth);
		$this->countNode($nodes);

		$description = $node['input']['description'] ?? null;
		if ($description !== null && !is_string($description)) {
			throw new ShareInputException(sprintf('Field %s.description must be a string or null', $path));
		}

		$subplans = [];
		foreach ($this->readChildList($node['input'], 'subplans', $path) as $i => $subplan) {
			$subplans[] = $this->readPlanNode($subplan, sprintf('%s.subplans[%d]', $path, $i), $depth + 1, $nodes, $createdAt);
		}

		return [
			'id' => $node['id'],
			'name' => $node['name'],
			'description' => $description,
			'data' => $node['data'],
			'createdAt' => $createdAt->format('c'),
			'subplans' => $subplans,
		];
	}

	/**
	 * @param int $nodes running total of nodes read so far
	 * @return array<string, mixed>
	 * @throws ShareInputException
	 */
	private function readFolderNode(mixed $input, string $path, int $depth, int &$nodes, DateTimeImmutable $createdAt): array
	{
		$node = $this->readNode($input, $path, $depth);
		$this->countNode($nodes);

		$children = [];
		foreach ($this->readChildList($node['input'], 'children', $path) as $i => $child) {
			$children[] = $this->readFolderNode($child, sprintf('%s.children[%d]', $path, $i), $depth + 1, $nodes, $createdAt);
		}

		$plans = [];
		foreach ($this->readChildList($node['input'], 'plans', $path) as $i => $plan) {
			$plans[] = $this->readPlanNode($plan, sprintf('%s.plans[%d]', $path, $i), $depth + 1, $nodes, $createdAt);
		}

		return [
			'id' => $node['id'],
			'name' => $node['name'],
			'data' => $node['data'],
			'createdAt' => $createdAt->format('c'),
			'children' => $children,
			'plans' => $plans,
		];
	}

	/** @throws ShareInputException */
	private function countNode(int &$nodes): void
	{
		if (++$nodes > self::TREE_MAX_NODES) {
			throw new ShareInputException(sprintf('The shared tree may contain at most %d folders and plans', self::TREE_MAX_NODES));
		}
	}

}
