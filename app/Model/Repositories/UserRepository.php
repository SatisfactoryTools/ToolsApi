<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterEmailHasher;

/** @extends BaseRepository<User> */
class UserRepository extends BaseRepository
{

	public function getById(int $id): ?User
	{
		return $this->getRepository()->find($id);
	}

	public function getByLogin(string $login): ?User
	{
		return $this->getRepository()->findOneBy(['login' => $login]);
	}

	public function getByEmail(string $email): ?User
	{
		return $this->getRepository()->findOneBy(['email' => $email]);
	}

	public function getByUuid(string $uuid): ?User
	{
		return $this->getRepository()->findOneBy(['uuid' => $uuid]);
	}

	/**
	 * Hashed in PHP for the same reason as OAuthIdentityRepository::findByEmailHashes().
	 * @param list<string> $hashes
	 * @return list<User>
	 */
	public function findByEmailHashes(array $hashes, SupporterEmailHasher $hasher): array
	{
		if ($hashes === []) {
			return [];
		}

		$wanted = array_flip($hashes);
		$ids = [];
		$rows = $this->getRepository()->createQueryBuilder('u')
			->select('u.id, u.email')
			->getQuery()
			->toIterable();
		foreach ($rows as $row) {
			if (isset($wanted[$hasher->hash((string) $row['email'])])) {
				$ids[] = (int) $row['id'];
			}
		}

		return $ids === [] ? [] : $this->getRepository()->findBy(['id' => $ids]);
	}

	protected function getEntityClass(): string
	{
		return User::class;
	}

}
