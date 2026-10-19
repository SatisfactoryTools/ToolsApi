<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\VersionBuild;

/** @extends BaseRepository<VersionBuild> */
class VersionBuildRepository extends BaseRepository
{

	public function getByDataPath(string $dataPath): ?VersionBuild
	{
		return $this->getRepository()->findOneBy(['dataPath' => $dataPath]);
	}

	protected function getEntityClass(): string
	{
		return VersionBuild::class;
	}

}
