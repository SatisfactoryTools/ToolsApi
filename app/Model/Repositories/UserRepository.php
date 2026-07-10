<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\User;

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

	protected function getEntityClass(): string
	{
		return User::class;
	}

}
