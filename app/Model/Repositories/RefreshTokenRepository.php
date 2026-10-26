<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\RefreshToken;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Services\AuthService;

/** @extends BaseRepository<RefreshToken> */
class RefreshTokenRepository extends BaseRepository
{

	/** Tokens issued before hashing are still stored as they are, until they expire. */
	public function getByValue(string $value): ?RefreshToken
	{
		return $this->getRepository()->findOneBy(['token' => AuthService::hashToken($value)])
			?? $this->getRepository()->findOneBy(['token' => $value]);
	}

	/** False when another request rotated the token first. */
	public function markRotated(RefreshToken $token, DateTimeImmutable $at): bool
	{
		$affected = $this->entityManager->createQueryBuilder()
			->update(RefreshToken::class, 't')
			->set('t.rotatedAt', ':at')
			->where('t.id = :id')
			->andWhere('t.rotatedAt IS NULL')
			->setParameter('at', $at)
			->setParameter('id', $token->id)
			->getQuery()
			->execute();

		return $affected === 1;
	}

	public function refresh(RefreshToken $token): void
	{
		$this->entityManager->refresh($token);
	}

	public function deleteStale(): void
	{
		$now = new DateTimeImmutable();
		$this->entityManager->createQueryBuilder()
			->delete(RefreshToken::class, 't')
			->where('t.expiresAt < :now')
			->orWhere('t.rotatedAt < :rotatedBefore')
			->setParameter('now', $now)
			->setParameter('rotatedBefore', $now->modify('-7 days'))
			->getQuery()
			->execute();
	}

	public function deleteAllForUser(User $user): void
	{
		$this->entityManager->createQueryBuilder()
			->delete(RefreshToken::class, 't')
			->where('t.user = :user')
			->setParameter('user', $user)
			->getQuery()
			->execute();
	}

	protected function getEntityClass(): string
	{
		return RefreshToken::class;
	}

}
