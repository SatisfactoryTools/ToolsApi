<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\OAuthState;

/** @extends BaseRepository<OAuthState> */
class OAuthStateRepository extends BaseRepository
{

	public function getByState(string $state): ?OAuthState
	{
		return $this->getRepository()->findOneBy(['state' => $state]);
	}

	public function deleteExpired(): void
	{
		$this->entityManager->createQueryBuilder()
			->delete(OAuthState::class, 's')
			->where('s.expiresAt < :now')
			->setParameter('now', new DateTimeImmutable())
			->getQuery()
			->execute();
	}

	protected function getEntityClass(): string
	{
		return OAuthState::class;
	}

}
