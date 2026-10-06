<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use greeny\SatisfactoryTools\Api\Model\Entities\Folder;
use greeny\SatisfactoryTools\Api\Model\Entities\Plan;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\FolderRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;

class PlanTreeBuilder
{

	public function __construct(
		private readonly FolderRepository $folderRepository,
		private readonly PlanRepository $planRepository,
	)
	{
	}

	/** @return array{childFolders: array<int, Folder[]>, folderPlans: array<int, Plan[]>, subplans: array<int, Plan[]>} */
	public function index(User $user, Version $version): array
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
	public function folderNode(Folder $folder, array $index): array
	{
		$children = [];
		foreach ($index['childFolders'][$folder->id] ?? [] as $child) {
			$children[] = $this->folderNode($child, $index);
		}

		$plans = [];
		foreach ($index['folderPlans'][$folder->id] ?? [] as $plan) {
			$plans[] = $this->planNode($plan, $index);
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
	public function planNode(Plan $plan, array $index): array
	{
		$subplans = [];
		foreach ($index['subplans'][$plan->id] ?? [] as $subplan) {
			$subplans[] = $this->planNode($subplan, $index);
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

	/** @return array<string, mixed> */
	public function versionSnapshot(Version $version): array
	{
		return [
			'id' => $version->uuid->toString(),
			'name' => $version->name,
			'slug' => $version->slug,
			'experimental' => $version->experimental,
			'custom' => $version->custom,
			'ficsmas' => $version->ficsmas,
		];
	}

}
