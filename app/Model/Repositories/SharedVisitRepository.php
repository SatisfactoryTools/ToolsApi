<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Share;
use greeny\SatisfactoryTools\Api\Model\Entities\SharedVisit;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

/** @extends BaseRepository<SharedVisit> */
class SharedVisitRepository extends BaseRepository
{

	/**
	 * The user's visit entries, most recently visited first, with the share join-fetched
	 * (the list response needs the frozen snapshot of every entry anyway).
	 *
	 * @return SharedVisit[]
	 */
	public function getByUserNewestFirst(User $user): array
	{
		return $this->getRepository()->createQueryBuilder('v')
			->addSelect('s')
			->innerJoin('v.share', 's')
			->where('v.user = :user')
			->setParameter('user', $user)
			->orderBy('v.visitedAt', 'DESC')
			->addOrderBy('v.id', 'DESC')
			->getQuery()
			->getResult();
	}

	public function getByUserAndShare(User $user, Share $share): ?SharedVisit
	{
		return $this->getRepository()->findOneBy(['user' => $user, 'share' => $share]);
	}

	/**
	 * Upserts the visit entry keyed on (user, share): an existing entry only gets its
	 * visitedAt refreshed, a new one is inserted. Inserting also trims the list back to
	 * $cap entries by evicting the oldest, all in one transaction.
	 */
	public function recordVisit(User $user, Share $share, int $cap): void
	{
		$this->entityManager->wrapInTransaction(function () use ($user, $share, $cap): void {
			$existing = $this->getByUserAndShare($user, $share);
			if ($existing !== null) {
				$existing->visitedAt = new DateTimeImmutable();
				$this->entityManager->persist($existing);

				return;
			}

			$visit = new SharedVisit();
			$visit->user = $user;
			$visit->share = $share;
			$visit->visitedAt = new DateTimeImmutable();
			$this->entityManager->persist($visit);
			$this->entityManager->flush();

			// Trim everything beyond the cap (not just one row), so the list self-heals
			// even if concurrent inserts ever left it oversized.
			foreach (array_slice($this->getByUserNewestFirst($user), $cap) as $stale) {
				$this->entityManager->remove($stale);
			}
		});
	}

	protected function getEntityClass(): string
	{
		return SharedVisit::class;
	}

}
