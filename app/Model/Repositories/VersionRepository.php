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
	 * Public (built-in) versions plus the custom versions the user has saved (linked)
	 * to their account.
	 *
	 * @return Version[]
	 */
	public function getVisibleToUser(User $user): array
	{
		return $this->getRepository()->createQueryBuilder('v')
			->where('v.custom = false')
			->orWhere(':user MEMBER OF v.users')
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

	/** An existing custom version with the exact same definition (dedup on create). */
	public function getByDefinitionHash(string $definitionHash): ?Version
	{
		return $this->getRepository()->findOneBy(['definitionHash' => $definitionHash]);
	}

	protected function getEntityClass(): string
	{
		return Version::class;
	}

}
