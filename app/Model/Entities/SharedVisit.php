<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

#[ORM\Entity]
#[ORM\Table(name: 'shared_visit')]
#[ORM\UniqueConstraint(name: 'user_share', columns: ['user_id', 'share_id'])]
class SharedVisit
{

	use Identifier;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	#[ORM\ManyToOne(targetEntity: Share::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public Share $share;

	#[ORM\Column]
	public DateTimeImmutable $visitedAt;

}
