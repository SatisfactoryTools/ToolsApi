<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** Matching an incoming OAuth login to an existing user is done by email; this row only records which external account was used. */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_identity')]
#[ORM\UniqueConstraint(name: 'provider_account', columns: ['provider', 'provider_user_id'])]
class OAuthIdentity
{

	use Identifier;

	/** steam | discord | github | google */
	#[ORM\Column(length: 16)]
	public string $provider;

	#[ORM\Column(name: 'provider_user_id', length: 191)]
	public string $providerUserId;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	/** Informational only (null for Steam). */
	#[ORM\Column(length: 255, nullable: true)]
	public ?string $email = null;

	/** Display only, never used for matching; refreshed on every sign-in through this provider. */
	#[ORM\Column(length: 100, nullable: true)]
	public ?string $nickname = null;

	#[ORM\Column(length: 512, nullable: true)]
	public ?string $avatarUrl = null;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
