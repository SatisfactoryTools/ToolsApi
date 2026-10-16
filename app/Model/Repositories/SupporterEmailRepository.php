<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\SupporterEmail;

/** @extends BaseRepository<SupporterEmail> */
class SupporterEmailRepository extends BaseRepository
{

	/**
	 * @param list<string> $hashes
	 * @return array<string, SupporterEmail> keyed by hash
	 */
	public function getByHashes(array $hashes): array
	{
		if ($hashes === []) {
			return [];
		}

		$result = [];
		foreach ($this->getRepository()->findBy(['emailHash' => $hashes]) as $email) {
			$result[$email->emailHash] = $email;
		}

		return $result;
	}

	public function getByHash(string $hash): ?SupporterEmail
	{
		return $this->getRepository()->findOneBy(['emailHash' => $hash]);
	}

	protected function getEntityClass(): string
	{
		return SupporterEmail::class;
	}

}
