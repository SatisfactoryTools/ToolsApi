<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\HelpImage;

/** @extends BaseRepository<HelpImage> */
class HelpImageRepository extends BaseRepository
{

	/** @return HelpImage[] */
	public function getAll(): array
	{
		return $this->getRepository()->findBy([], ['uploadedAt' => 'DESC']);
	}

	public function getByUuid(string $uuid): ?HelpImage
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	protected function getEntityClass(): string
	{
		return HelpImage::class;
	}

}
