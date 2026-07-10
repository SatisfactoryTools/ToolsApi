<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\UserSettings;

/** @extends BaseRepository<UserSettings> */
class UserSettingsRepository extends BaseRepository
{

	public function getByUser(User $user): ?UserSettings
	{
		return $this->getRepository()->findOneBy(['user' => $user]);
	}

	protected function getEntityClass(): string
	{
		return UserSettings::class;
	}

}
