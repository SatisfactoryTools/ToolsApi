<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Share;

/** @extends BaseRepository<Share> */
class ShareRepository extends BaseRepository
{

	public function getByUuid(string $uuid): ?Share
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	/** Shares only keep the version inside their JSON snapshot. */
	public function countReferencingVersion(string $versionUuid): int
	{
		return (int) $this->getRepository()->createQueryBuilder('s')
			->select('COUNT(s.id)')
			->where('s.snapshot LIKE :needle')
			->setParameter('needle', '%"' . $versionUuid . '"%')
			->getQuery()
			->getSingleScalarResult();
	}

	protected function getEntityClass(): string
	{
		return Share::class;
	}

}
