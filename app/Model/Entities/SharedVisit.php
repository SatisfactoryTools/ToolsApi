<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/**
 * One entry in a user's "visited shares" list: this user opened this share, most
 * recently at $visitedAt. Only the visit itself is stored — type, name and version are
 * resolved from the (frozen) share at read time. The list is capped per user (see
 * ShareService::VISITED_CAP); the oldest entry is evicted when the cap is exceeded.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shared_visit')]
#[ORM\UniqueConstraint(name: 'user_share', columns: ['user_id', 'share_id'])]
class SharedVisit
{

	use Identifier;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	/** Shares are never deleted (API contract), so the cascade is a safety net only. */
	#[ORM\ManyToOne(targetEntity: Share::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public Share $share;

	#[ORM\Column]
	public DateTimeImmutable $visitedAt;

}
