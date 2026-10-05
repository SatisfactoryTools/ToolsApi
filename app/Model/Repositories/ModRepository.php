<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Mod;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

/** @extends BaseRepository<Mod> */
class ModRepository extends BaseRepository
{

	/** @return Mod[] */
	public function getPublic(): array
	{
		return $this->getRepository()->findBy(['public' => true]);
	}

	/** @return Mod[] */
	public function getVisibleToUser(User $user): array
	{
		return $this->getRepository()->createQueryBuilder('m')
			->where('m.public = true')
			->orWhere('m.user = :user')
			->setParameter('user', $user)
			->getQuery()
			->getResult();
	}

	public function getByUuidVisibleToUser(string $uuid, User $user): ?Mod
	{
		return $this->getRepository()->createQueryBuilder('m')
			->where('m.uuid = :uuid')
			->andWhere('m.public = true OR m.user = :user')
			->setParameter('uuid', $uuid, 'uuid')
			->setParameter('user', $user)
			->getQuery()
			->getOneOrNullResult();
	}

	public function getByUuidAndOwner(string $uuid, User $user): ?Mod
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid, 'user' => $user]);
	}

	public function getByUuid(string $uuid): ?Mod
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	protected function getEntityClass(): string
	{
		return Mod::class;
	}

}
