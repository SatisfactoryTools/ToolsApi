<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Mod;
use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use Ramsey\Uuid\UuidInterface;

/** @extends BaseRepository<ModVersion> */
class ModVersionRepository extends BaseRepository
{

	public function getByUuidAndMod(UuidInterface $uuid, Mod $mod): ?ModVersion
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid, 'mod' => $mod]);
	}

	public function getByUuidPublic(string $uuid): ?ModVersion
	{
		return $this->getRepository()->createQueryBuilder('mv')
			->join('mv.mod', 'm')
			->where('mv.uuid = :uuid')
			->andWhere('m.public = true')
			->setParameter('uuid', $uuid, 'uuid')
			->getQuery()
			->getOneOrNullResult();
	}

	public function getByUuidVisibleToUser(string $uuid, User $user): ?ModVersion
	{
		return $this->getRepository()->createQueryBuilder('mv')
			->join('mv.mod', 'm')
			->where('mv.uuid = :uuid')
			->andWhere('m.public = true OR m.user = :user')
			->setParameter('uuid', $uuid, 'uuid')
			->setParameter('user', $user)
			->getQuery()
			->getOneOrNullResult();
	}

	protected function getEntityClass(): string
	{
		return ModVersion::class;
	}

}
