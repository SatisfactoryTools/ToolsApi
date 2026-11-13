<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** A ficsit.app mod people asked to have added. Only read straight from the database, there is no public view. */
#[ORM\Entity]
#[ORM\Table(name: 'mod_suggestion')]
#[ORM\UniqueConstraint(name: 'mod_suggestion_reference', columns: ['reference'])]
class ModSuggestion
{

	use Identifier;

	/** ficsit.app mod reference as ficsit.app spells it. */
	#[ORM\Column(length: 64)]
	public string $reference;

	#[ORM\Column(length: 255)]
	public string $name;

	#[ORM\Column(length: 32)]
	public string $ficsitId;

	#[ORM\Column]
	public int $suggestions = 0;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	#[ORM\Column]
	public DateTimeImmutable $lastSuggestedAt;

}
