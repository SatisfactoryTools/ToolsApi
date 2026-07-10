<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\RefreshToken;
use greeny\SatisfactoryTools\Api\Model\Entities\User;

/** @extends BaseRepository<RefreshToken> */
class RefreshTokenRepository extends BaseRepository
{

	public function getByToken(string $token): ?RefreshToken
	{
		return $this->getRepository()->findOneBy(['token' => $token]);
	}

	public function deleteAllForUser(User $user): void
	{
		$tokens = $this->getRepository()->findBy(['user' => $user]);
		foreach ($tokens as $token) {
			$this->entityManager->remove($token);
		}
		$this->entityManager->flush();
	}

	protected function getEntityClass(): string
	{
		return RefreshToken::class;
	}

}
