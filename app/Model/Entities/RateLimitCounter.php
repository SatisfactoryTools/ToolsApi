<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Written with raw upserts by RateLimiter; mapped only so the deploy's schema update creates the table. */
#[ORM\Entity]
#[ORM\Table(name: 'rate_limit')]
#[ORM\Index(name: 'rate_limit_window_ends', columns: ['window_ends_at'])]
class RateLimitCounter
{

	#[ORM\Id]
	#[ORM\Column(length: 64)]
	public string $id;

	#[ORM\Column]
	public int $hits = 0;

	#[ORM\Column]
	public DateTimeImmutable $windowEndsAt;

	#[ORM\Column]
	public DateTimeImmutable $lastHitAt;

}
