<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Plan;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
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

	protected function getEntityClass(): string
	{
		return Plan::class;
	}

}
