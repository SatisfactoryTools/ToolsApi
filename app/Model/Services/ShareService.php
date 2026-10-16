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

class ShareService
{

	/** The frontend mirrors this for the anonymous localStorage list; keep in sync. */
	public const int VISITED_CAP = 20;

	/** Bounds the tree for the unauthenticated POST /v1/shares; body size is capped separately by the controller. */
	public const int TREE_MAX_NODES = 500;
	public const int TREE_MAX_DEPTH = 32;

	/** Matches the column length of owned folders/plans. */
	private const int NAME_MAX_LENGTH = 255;

	public function __construct(
		private readonly PlanTreeBuilder $treeBuilder,
		private readonly ShareRepository $shareRepository,
		private readonly SharedVisitRepository $sharedVisitRepository,
	)
	{
	}

	public function shareFolder(User $user, Version $version, Folder $folder): Share
	{
		$index = $this->treeBuilder->index($user, $version);

		return $this->persist($user, 'folder', $version, $this->treeBuilder->folderNode($folder, $index));
	}

	public function sharePlan(User $user, Version $version, Plan $plan): Share
	{
		$index = $this->treeBuilder->index($user, $version);

		return $this->persist($user, 'plan', $version, $this->treeBuilder->planNode($plan, $index));
	}

	public function shareTree(?User $user, Version $version, string $type, mixed $root): Share
	{
		$createdAt = new DateTimeImmutable();
		$nodes = 0;

		$node = $type === 'folder'
			? $this->readFolderNode($root, 'root', 1, $nodes, $createdAt)
			: $this->readPlanNode($root, 'root', 1, $nodes, $createdAt);

		return $this->persist($user, $type, $version, $node, $createdAt);
	}

	/** @return array<string, mixed>|null */
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

	/** @return array{shares: array<int, array<string, mixed>>} */
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

	public function resolvePlanIcon(mixed $data): ?string
	{
		if (!is_string($data)) {
			return null;
		}

		$decoded = json_decode($data, true);
		if (!is_array($decoded)) {
			return null;
		}

		$className = $decoded['iconClassName'] ?? null;

		return is_string($className) && $className !== '' ? $className : null;
	}

	public function recordVisit(User $user, string $shareUuid): bool
	{
		$share = Uuid::isValid($shareUuid) ? $this->shareRepository->getByUuid($shareUuid) : null;
		if ($share === null) {
			return false;
		}

		$this->sharedVisitRepository->recordVisit($user, $share, self::VISITED_CAP);

		return true;
	}

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

	/** @return array{input: array<string, mixed>, id: string, name: string, data: string} */
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

		$data = $input['data'] ?? '{}';
		if (!is_string($data)) {
			throw new ShareInputException(sprintf('Field %s.data must be a JSON string', $path));
		}

		return ['input' => $input, 'id' => $id, 'name' => $name, 'data' => $data !== '' ? $data : '{}'];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<int, mixed>
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

	/** @return array<string, mixed> */
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

	/** @return array<string, mixed> */
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

	private function countNode(int &$nodes): void
	{
		if (++$nodes > self::TREE_MAX_NODES) {
			throw new ShareInputException(sprintf('The shared tree may contain at most %d folders and plans', self::TREE_MAX_NODES));
		}
	}

}
