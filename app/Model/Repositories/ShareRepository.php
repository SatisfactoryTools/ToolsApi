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

	protected function getEntityClass(): string
	{
		return Share::class;
	}

}
