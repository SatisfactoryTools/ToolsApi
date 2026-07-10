<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;

/** @extends BaseRepository<Version> */
class VersionRepository extends BaseRepository
{

	/** @return Version[] */
	public function getAll(): array
	{
		return $this->getRepository()->findAll();
	}

	/** @return Version[] */
	public function getPublic(): array
	{
		return $this->getRepository()->findBy(['custom' => false]);
	}

	/**
	 * Public (built-in) versions plus the user's own custom versions.
	 *
	 * @return Version[]
	 */
	public function getVisibleToUser(User $user): array
	{
		return $this->getRepository()->createQueryBuilder('v')
			->where('v.custom = false')
			->orWhere('v.user = :user')
			->setParameter('user', $user)
			->getQuery()
			->getResult();
	}

	public function getByUuid(string $uuid): ?Version
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	/** Looks up a version by its data file path (the idempotency key for imports). */
	public function getByDataPath(string $dataPath): ?Version
	{
		return $this->getRepository()->findOneBy(['dataPath' => $dataPath]);
	}

	/**
	 * A version by UUID, but only if it is visible to the user: a public version, or one
	 * the user owns. Returns null for another user's custom version.
	 */
	public function getByUuidVisibleToUser(string $uuid, User $user): ?Version
	{
		return $this->getRepository()->createQueryBuilder('v')
			->where('v.uuid = :uuid')
			->andWhere('v.custom = false OR v.user = :user')
			->setParameter('uuid', $uuid, 'uuid')
			->setParameter('user', $user)
			->getQuery()
			->getOneOrNullResult();
	}

	protected function getEntityClass(): string
	{
		return Version::class;
	}

}
