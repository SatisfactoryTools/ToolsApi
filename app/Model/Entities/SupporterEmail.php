<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** Kept apart from accounts, so donors (and old-tools supporters) without an account are matched once they sign in. */
#[ORM\Entity]
#[ORM\Table(name: 'supporter_email')]
class SupporterEmail
{

	use Identifier;

	/** SHA-256 of the normalised address (SupporterEmailHasher): matching works without keeping donor emails. */
	#[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
	public string $emailHash;

	#[ORM\Column(length: 16)]
	public string $source;

	#[ORM\Column(length: 191, nullable: true)]
	public ?string $externalId = null;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
