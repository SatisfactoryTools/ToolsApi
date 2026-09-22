<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use Doctrine\ORM\QueryBuilder;
use greeny\SatisfactoryTools\Api\Model\Entities\HelpArticle;

/** @extends BaseRepository<HelpArticle> */
class HelpArticleRepository extends BaseRepository
{

	/**
	 * Every article, category order first, then position within it. Uncategorised
	 * articles sort last.
	 *
	 * @return HelpArticle[]
	 */
	public function getAll(): array
	{
		return $this->ordered()->getQuery()->getResult();
	}

	/** @return HelpArticle[] */
	public function getPublished(): array
	{
		return $this->ordered()
			->where('a.published = true')
			->getQuery()
			->getResult();
	}

	public function getByUuid(string $uuid): ?HelpArticle
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	public function getBySlug(string $slug): ?HelpArticle
	{
		return $this->getRepository()->findOneBy(['slug' => $slug]);
	}

	protected function getEntityClass(): string
	{
		return HelpArticle::class;
	}

	private function ordered(): QueryBuilder
	{
		return $this->getRepository()->createQueryBuilder('a')
			->leftJoin('a.category', 'c')
			->addSelect('c')
			// Uncategorised articles last, whatever the database does with NULLs.
			->addSelect('CASE WHEN c.id IS NULL THEN 1 ELSE 0 END AS HIDDEN uncategorised')
			->orderBy('uncategorised', 'ASC')
			->addOrderBy('c.position', 'ASC')
			->addOrderBy('a.position', 'ASC')
			->addOrderBy('a.title', 'ASC');
	}

}
