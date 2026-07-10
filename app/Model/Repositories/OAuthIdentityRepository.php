<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\OAuthIdentity;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

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

	public function countForUser(User $user): int
	{
		return $this->getRepository()->count(['user' => $user]);
	}

	protected function getEntityClass(): string
	{
		return OAuthIdentity::class;
	}

}
