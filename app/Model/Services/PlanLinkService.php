<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use greeny\SatisfactoryTools\Api\Model\Repositories\PlanRepository;
use Ramsey\Uuid\Uuid;

/**
 * The read-only view of a live plan behind its own URL. People hand each other the
 * address bar link of a plan far more readily than a share link, so a plan whose owner
 * left link access on can be opened by anyone holding that link.
 *
 * Unlike a share this is NOT frozen: every read returns the plan as it is right now.
 * The payload shape is the share one (PlanTreeBuilder), so the viewer needs no second
 * code path.
 */
class PlanLinkService
{

	public function __construct(
		private readonly PlanRepository $planRepository,
		private readonly PlanTreeBuilder $treeBuilder,
	)
	{
	}

	/**
	 * The plan and all of its subplans, or null when the UUID is malformed, no such plan
	 * exists, or its owner turned link access off. Only the requested plan's own flag is
	 * consulted: the frontend turns a whole subtree off together, and a subplan UUID is
	 * as unguessable as its parent's.
	 *
	 * @return array<string, mixed>|null
	 */
	public function present(string $planUuid): ?array
	{
		if (!Uuid::isValid($planUuid)) {
			return null;
		}

		$plan = $this->planRepository->getByUuid(Uuid::fromString($planUuid));
		if ($plan === null || !$plan->linkAccess) {
			return null;
		}

		$index = $this->treeBuilder->index($plan->user, $plan->version);

		return [
			'plan' => $plan->uuid->toString(),
			'type' => 'plan',
			'updatedAt' => $plan->getUpdatedAt()->format('c'),
			'version' => $this->treeBuilder->versionSnapshot($plan->version),
			'root' => $this->treeBuilder->planNode($plan, $index),
		];
	}

}
