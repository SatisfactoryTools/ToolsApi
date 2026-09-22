<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\HelpCategory;

/** @extends BaseRepository<HelpCategory> */
class HelpCategoryRepository extends BaseRepository
{

	/** @return HelpCategory[] */
	public function getAll(): array
	{
		return $this->getRepository()->findBy([], ['position' => 'ASC']);
	}

	public function getByUuid(string $uuid): ?HelpCategory
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	public function getBySlug(string $slug): ?HelpCategory
	{
		return $this->getRepository()->findOneBy(['slug' => $slug]);
	}

	protected function getEntityClass(): string
	{
		return HelpCategory::class;
	}

}
