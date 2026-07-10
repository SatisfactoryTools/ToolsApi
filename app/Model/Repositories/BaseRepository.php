<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use InvalidArgumentException;

/** @template T of object */
abstract class BaseRepository
{

	protected EntityManagerInterface $entityManager;

	public function __construct(EntityManagerInterface $entityManager)
	{
		$this->entityManager = $entityManager;
	}

	/** @param T $entity */
	public function save(object $entity, bool $flush = true): void
	{
		$this->checkOwnership($entity);

		$this->entityManager->persist($entity);
		if ($flush) {
			$this->entityManager->flush();
		}
	}

	/** @param T $entity */
	public function delete(object $entity, bool $flush = true): void
	{
		$this->checkOwnership($entity);

		$this->entityManager->remove($entity);
		if ($flush) {
			$this->entityManager->flush();
		}
	}

	/** @return EntityRepository<T> */
	protected function getRepository(): EntityRepository
	{
		return $this->entityManager->getRepository($this->getEntityClass());
	}

	protected function checkOwnership(object $entity): void
	{
		$className = $this->getEntityClass();

		if (!($entity instanceof $className)) {
			throw new InvalidArgumentException('Entity ' . get_class($entity) . ' does not belong to ' . get_class($this));
		}
	}

	/** @return class-string<T> */
	abstract protected function getEntityClass(): string;

}
