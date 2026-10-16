<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** Never removed by a sync: one payment marks the account for good (only supporters:revoke deletes it). */
#[ORM\Entity]
#[ORM\Table(name: 'supporter')]
class Supporter
{

	use Identifier;

	#[ORM\OneToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
	public User $user;

	#[ORM\Column(length: 16)]
	public string $source;

	#[ORM\Column]
	public DateTimeImmutable $since;

	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $welcomedAt = null;

	#[ORM\Column]
	public bool $listed = false;

	#[ORM\Column(length: 50, nullable: true)]
	public ?string $listName = null;

	#[ORM\Column]
	public bool $shareBadge = true;

	#[ORM\Column]
	public bool $accent = true;

	#[ORM\Column]
	public bool $nameHeart = true;

	/** The Discord account the role went to, so a re-linked account gets it again. */
	#[ORM\Column(length: 191, nullable: true)]
	public ?string $discordRoleUserId = null;

}
