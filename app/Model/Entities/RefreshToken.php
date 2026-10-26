<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

#[ORM\Entity]
#[ORM\Index(name: 'refresh_token_expires', columns: ['expires_at'])]
class RefreshToken
{

	use Identifier;

	#[ORM\Column(unique: true, length: 64)]
	public string $token;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	#[ORM\Column]
	public DateTimeImmutable $expiresAt;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	/** Rotated tokens are kept for a while so that a reuse can be told from an unknown token. */
	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $rotatedAt = null;

}
