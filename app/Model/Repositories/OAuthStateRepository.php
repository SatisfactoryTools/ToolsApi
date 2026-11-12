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
			->setParameter('now', new DateTimeImmutable('-1 day'))
			->getQuery()
			->execute();
	}

	/** One conditional UPDATE, so of two concurrent requests only one redeems the provider's single-use code. */
	public function claimPrefetch(OAuthState $state): bool
	{
		$claimed = $this->entityManager->createQueryBuilder()
			->update(OAuthState::class, 's')
			->set('s.prefetchStartedAt', ':now')
			->where('s.id = :id')
			->andWhere('s.prefetchStartedAt IS NULL')
			->andWhere('s.completedAt IS NULL')
			->setParameter('now', new DateTimeImmutable())
			->setParameter('id', $state->id)
			->getQuery()
			->execute();
		$this->entityManager->refresh($state);

		return $claimed === 1;
	}

	public function reload(OAuthState $state): void
	{
		$this->entityManager->refresh($state);
	}

	protected function getEntityClass(): string
	{
		return OAuthState::class;
	}

}
