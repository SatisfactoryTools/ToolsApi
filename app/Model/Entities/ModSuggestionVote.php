<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** One row per voter key (hashed IP, hashed account), so the same person counts once per mod. */
#[ORM\Entity]
#[ORM\Table(name: 'mod_suggestion_vote')]
#[ORM\UniqueConstraint(name: 'mod_suggestion_voter', columns: ['suggestion_id', 'voter'])]
class ModSuggestionVote
{

	use Identifier;

	#[ORM\ManyToOne(targetEntity: ModSuggestion::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public ModSuggestion $suggestion;

	#[ORM\Column(length: 64)]
	public string $voter;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
