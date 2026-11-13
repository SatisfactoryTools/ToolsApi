<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use greeny\SatisfactoryTools\Api\Model\Entities\ModSuggestion;
use greeny\SatisfactoryTools\Api\Model\Entities\ModSuggestionVote;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModSuggestionRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\ModSuggestionVoteRepository;
use greeny\SatisfactoryTools\Api\Model\Services\Ficsit\FicsitAppClient;
use greeny\SatisfactoryTools\Api\Model\Services\Ficsit\FicsitAppException;

class ModSuggestionService
{

	public function __construct(
		private readonly ModSuggestionRepository $suggestionRepository,
		private readonly ModSuggestionVoteRepository $voteRepository,
		private readonly FicsitAppClient $ficsitApp,
	)
	{
	}

	/**
	 * Only mods not seen before are looked up on ficsit.app.
	 * @throws FicsitAppException
	 */
	public function find(string $idOrReference): ?ModSuggestion
	{
		$suggestion = $this->suggestionRepository->getByReference($idOrReference);
		if ($suggestion !== null) {
			return $suggestion;
		}

		$mod = $this->ficsitApp->findMod($idOrReference);
		if ($mod === null) {
			return null;
		}

		$existing = $this->suggestionRepository->getByReference($mod->reference);
		if ($existing !== null) {
			return $existing;
		}

		$suggestion = new ModSuggestion();
		$suggestion->reference = $mod->reference;
		$suggestion->name = $mod->name;
		$suggestion->ficsitId = $mod->id;
		$suggestion->createdAt = new DateTimeImmutable();
		$suggestion->lastSuggestedAt = $suggestion->createdAt;

		try {
			$this->suggestionRepository->save($suggestion);
		} catch (UniqueConstraintViolationException) {
			// Someone suggested the same mod in the meantime.
			return $this->suggestionRepository->getByReference($mod->reference);
		}

		return $suggestion;
	}

	/**
	 * Counts the suggestion unless this IP or account already suggested the mod.
	 * @return bool whether it was counted
	 */
	public function suggest(ModSuggestion $suggestion, string $clientIp, ?User $user): bool
	{
		$voters = [hash('sha256', 'mod-suggestion-ip' . "\0" . $clientIp)];
		if ($user !== null) {
			$voters[] = hash('sha256', 'mod-suggestion-user' . "\0" . $user->id);
		}

		if ($this->voteRepository->hasAnyVoter($suggestion, $voters)) {
			return false;
		}

		$now = new DateTimeImmutable();
		foreach ($voters as $voter) {
			$vote = new ModSuggestionVote();
			$vote->suggestion = $suggestion;
			$vote->voter = $voter;
			$vote->createdAt = $now;
			$this->voteRepository->save($vote, false);
		}
		$suggestion->suggestions++;
		$suggestion->lastSuggestedAt = $now;

		try {
			$this->suggestionRepository->save($suggestion);
		} catch (UniqueConstraintViolationException) {
			return false;
		}

		return true;
	}

}
