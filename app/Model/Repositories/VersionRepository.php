<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use Ramsey\Uuid\Uuid;

/** @extends BaseRepository<Version> */
class VersionRepository extends BaseRepository
{

	/** @return Version[] */
	public function getAll(): array
	{
		return $this->getRepository()->findAll();
	}

	/** @return Version[] */
	public function getPublic(): array
	{
		return $this->getRepository()->findBy(['custom' => false]);
	}

	/** @return Version[] */
	public function getVisibleToUser(User $user): array
	{
		return $this->getRepository()->createQueryBuilder('v')
			->where('v.custom = false')
			->orWhere(':user MEMBER OF v.users')
			->setParameter('user', $user)
			->getQuery()
			->getResult();
	}

	public function getByUuid(string $uuid): ?Version
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	public function getBySlugOrUuid(string $slugOrUuid): ?Version
	{
		return Uuid::isValid($slugOrUuid) ? $this->getByUuid($slugOrUuid) : $this->getBySlug($slugOrUuid);
	}

	public function getBySlug(string $slug): ?Version
	{
		return $this->getRepository()->findOneBy(['slug' => $slug]);
	}

	public function getByDataPath(string $dataPath): ?Version
	{
		return $this->getRepository()->findOneBy(['dataPath' => $dataPath]);
	}

	public function getByDefinitionHash(string $definitionHash): ?Version
	{
		return $this->getRepository()->findOneBy(['definitionHash' => $definitionHash]);
	}

	public function countCreatedBy(User $user): int
	{
		return $this->getRepository()->count(['createdBy' => $user]);
	}

	/** @return list<DateTimeImmutable> oldest first */
	public function getCreationTimesFromIpSince(string $ipHash, DateTimeImmutable $since): array
	{
		$rows = $this->getRepository()->createQueryBuilder('v')
			->select('v.createdAt')
			->where('v.createdFromIp = :ip')
			->andWhere('v.createdAt >= :since')
			->setParameter('ip', $ipHash)
			->setParameter('since', $since)
			->orderBy('v.createdAt', 'ASC')
			->getQuery()
			->getArrayResult();

		return array_map(static fn (array $row): DateTimeImmutable => $row['createdAt'], $rows);
	}

	public function getLatestOfficial(bool $experimental, bool $ficsmas): ?Version
	{
		return $this->getRepository()->findOneBy(
			['custom' => false, 'experimental' => $experimental, 'ficsmas' => $ficsmas],
			['id' => 'DESC'],
		);
	}

	public function countDerived(Version $version): int
	{
		return $this->getRepository()->count(['baseVersion' => $version]);
	}

	protected function getEntityClass(): string
	{
		return Version::class;
	}

}
