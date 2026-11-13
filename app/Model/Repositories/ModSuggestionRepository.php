<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\ModSuggestion;

/** @extends BaseRepository<ModSuggestion> */
class ModSuggestionRepository extends BaseRepository
{

	public function getByReference(string $reference): ?ModSuggestion
	{
		return $this->getRepository()->findOneBy(['reference' => $reference]);
	}

	protected function getEntityClass(): string
	{
		return ModSuggestion::class;
	}

}
