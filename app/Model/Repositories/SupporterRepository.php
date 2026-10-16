<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Supporter;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

/** @extends BaseRepository<Supporter> */
class SupporterRepository extends BaseRepository
{

	public function getByUser(User $user): ?Supporter
	{
		return $this->getRepository()->findOneBy(['user' => $user]);
	}

	public function showsShareBadge(?User $user): bool
	{
		if ($user === null) {
			return false;
		}

		return $this->getByUser($user)?->shareBadge ?? false;
	}

	/** @return list<Supporter> */
	public function findListed(): array
	{
		return $this->getRepository()->createQueryBuilder('s')
			->where('s.listed = true')
			->andWhere('s.listName IS NOT NULL')
			->orderBy('s.since', 'ASC')
			->addOrderBy('s.id', 'ASC')
			->getQuery()
			->getResult();
	}

	/** @return list<Supporter> */
	public function findAll(): array
	{
		return $this->getRepository()->findBy([], ['id' => 'ASC']);
	}

	protected function getEntityClass(): string
	{
		return Supporter::class;
	}

}
