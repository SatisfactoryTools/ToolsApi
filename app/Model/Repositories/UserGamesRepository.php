<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\UserGames;

/** @extends BaseRepository<UserGames> */
class UserGamesRepository extends BaseRepository
{

	public function getByUser(User $user): ?UserGames
	{
		return $this->getRepository()->findOneBy(['user' => $user]);
	}

	protected function getEntityClass(): string
	{
		return UserGames::class;
	}

}
