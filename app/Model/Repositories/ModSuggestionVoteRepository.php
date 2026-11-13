<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Repositories;

use greeny\SatisfactoryTools\Api\Model\Entities\ModSuggestion;
use greeny\SatisfactoryTools\Api\Model\Entities\ModSuggestionVote;

/** @extends BaseRepository<ModSuggestionVote> */
class ModSuggestionVoteRepository extends BaseRepository
{

	/** @param string[] $voters */
	public function hasAnyVoter(ModSuggestion $suggestion, array $voters): bool
	{
		return $this->getRepository()->count(['suggestion' => $suggestion, 'voter' => $voters]) > 0;
	}

	protected function getEntityClass(): string
	{
		return ModSuggestionVote::class;
	}

}
