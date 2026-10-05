<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\LegacyShare;

/** @extends BaseRepository<LegacyShare> */
class LegacyShareRepository extends BaseRepository
{

	public function getBySlug(string $slug): ?LegacyShare
	{
		return $this->getRepository()->findOneBy(['slug' => $slug]);
	}

	/**
	 * Loads the shares for a set of slugs in one query, keyed by slug. Slugs with no row
	 * are simply absent from the result.
	 *
	 * @param list<string> $slugs
	 * @return array<string, LegacyShare>
	 */
	public function getBySlugs(array $slugs): array
	{
		if ($slugs === []) {
			return [];
		}

		$result = [];
		foreach ($this->getRepository()->findBy(['slug' => $slugs]) as $share) {
			$result[$share->slug] = $share;
		}

		return $result;
	}

	protected function getEntityClass(): string
	{
		return LegacyShare::class;
	}

}
