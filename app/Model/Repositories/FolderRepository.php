<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\Folder;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use Ramsey\Uuid\UuidInterface;

/** @extends BaseRepository<Folder> */
class FolderRepository extends BaseRepository
{

	/** @return Folder[] */
	public function getByUserAndVersion(User $user, Version $version): array
	{
		return $this->getRepository()->findBy(['user' => $user, 'version' => $version]);
	}

	public function getByUuidAndUserAndVersion(UuidInterface $uuid, User $user, Version $version): ?Folder
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid, 'user' => $user, 'version' => $version]);
	}

	protected function getEntityClass(): string
	{
		return Folder::class;
	}

}
