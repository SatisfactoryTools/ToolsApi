<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Plan;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use Ramsey\Uuid\UuidInterface;

/** @extends BaseRepository<Plan> */
class PlanRepository extends BaseRepository
{

	/** @return Plan[] */
	public function getByUserAndVersion(User $user, Version $version): array
	{
		return $this->getRepository()->findBy(['user' => $user, 'version' => $version]);
	}

	public function getByUuidAndUserAndVersion(UuidInterface $uuid, User $user, Version $version): ?Plan
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid, 'user' => $user, 'version' => $version]);
	}

	/**
	 * Per-version plan statistics for one user, in a single grouped query — cheap enough
	 * for every home page visit. Only versions in which the user has at least one plan
	 * appear. `planCount` counts top-level plans (subplans are excluded); `lastUpdatedAt`
	 * spans every plan of the version, subplans included, since editing a subplan is
	 * still work done in that version.
	 *
	 * @return array<string, array{planCount: int, lastUpdatedAt: DateTimeImmutable}> keyed by version UUID
	 */
	public function getSummaryByVersionForUser(User $user): array
	{
		/** @var list<array{versionUuid: UuidInterface|string, planCount: int|string, lastUpdatedAt: string}> $rows */
		$rows = $this->getRepository()->createQueryBuilder('p')
			->select('v.uuid AS versionUuid')
			->addSelect('SUM(CASE WHEN p.parent IS NULL THEN 1 ELSE 0 END) AS planCount')
			->addSelect('MAX(COALESCE(p.updatedAt, p.createdAt)) AS lastUpdatedAt')
			->join('p.version', 'v')
			->where('p.user = :user')
			->setParameter('user', $user)
			->groupBy('v.id')
			->getQuery()
			->getArrayResult();

		$summary = [];
		foreach ($rows as $row) {
			$summary[(string) $row['versionUuid']] = [
				'planCount' => (int) $row['planCount'],
				'lastUpdatedAt' => new DateTimeImmutable((string) $row['lastUpdatedAt']),
			];
		}

		return $summary;
	}

	protected function getEntityClass(): string
	{
		return Plan::class;
	}

}
