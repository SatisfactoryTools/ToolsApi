<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\ClientError;

/** @extends BaseRepository<ClientError> */
class ClientErrorRepository extends BaseRepository
{

	public function getBySignature(string $signature): ?ClientError
	{
		return $this->getRepository()->findOneBy(['signature' => $signature]);
	}

	public function countFirstSeenSince(DateTimeImmutable $since): int
	{
		return $this->countWhere('firstSeenAt', $since);
	}

	public function countNotifiedSince(DateTimeImmutable $since): int
	{
		return $this->countWhere('notifiedAt', $since);
	}

	public function deleteLastSeenBefore(DateTimeImmutable $before): void
	{
		$this->entityManager->createQueryBuilder()
			->delete(ClientError::class, 'e')
			->where('e.lastSeenAt < :before')
			->setParameter('before', $before)
			->getQuery()
			->execute();
	}

	private function countWhere(string $field, DateTimeImmutable $since): int
	{
		return (int) $this->getRepository()->createQueryBuilder('e')
			->select('COUNT(e.id)')
			->where(sprintf('e.%s >= :since', $field))
			->setParameter('since', $since)
			->getQuery()
			->getSingleScalarResult();
	}

	protected function getEntityClass(): string
	{
		return ClientError::class;
	}

}
