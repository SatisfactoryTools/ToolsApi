<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\OAuthIdentity;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterEmailHasher;

/** @extends BaseRepository<OAuthIdentity> */
class OAuthIdentityRepository extends BaseRepository
{

	public function getByProviderAccount(string $provider, string $providerUserId): ?OAuthIdentity
	{
		return $this->getRepository()->findOneBy([
			'provider' => $provider,
			'providerUserId' => $providerUserId,
		]);
	}

	public function getByUserAndProvider(User $user, string $provider): ?OAuthIdentity
	{
		return $this->getRepository()->findOneBy([
			'user' => $user,
			'provider' => $provider,
		]);
	}

	/** @return list<OAuthIdentity> */
	public function findAllForUser(User $user): array
	{
		return $this->getRepository()->findBy(['user' => $user], ['createdAt' => 'ASC']);
	}

	/**
	 * Hashed in PHP rather than with SQL SHA2(): MySQL's LOWER() folds case differently from mb_strtolower().
	 * @param list<string> $hashes
	 * @return list<OAuthIdentity>
	 */
	public function findByEmailHashes(array $hashes, SupporterEmailHasher $hasher): array
	{
		if ($hashes === []) {
			return [];
		}

		$wanted = array_flip($hashes);
		$ids = [];
		$rows = $this->getRepository()->createQueryBuilder('i')
			->select('i.id, i.email')
			->where('i.email IS NOT NULL')
			->getQuery()
			->toIterable();
		foreach ($rows as $row) {
			if (isset($wanted[$hasher->hash((string) $row['email'])])) {
				$ids[] = (int) $row['id'];
			}
		}

		return $ids === [] ? [] : $this->getRepository()->findBy(['id' => $ids]);
	}

	/** @return list<OAuthIdentity> */
	public function findByProvider(string $provider): array
	{
		return $this->getRepository()->findBy(['provider' => $provider]);
	}

	public function countForUser(User $user): int
	{
		return $this->getRepository()->count(['user' => $user]);
	}

	protected function getEntityClass(): string
	{
		return OAuthIdentity::class;
	}

}
