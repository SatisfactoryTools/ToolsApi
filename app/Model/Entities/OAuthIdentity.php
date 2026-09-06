<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/**
 * A third-party login linked to a user account. A user may have several of these
 * (one per provider). Matching between an incoming OAuth login and an existing user
 * is done by email; the identity row records which external account was used.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_identity')]
#[ORM\UniqueConstraint(name: 'provider_account', columns: ['provider', 'provider_user_id'])]
class OAuthIdentity
{

	use Identifier;

	/** Provider key: steam | discord | github | google */
	#[ORM\Column(length: 16)]
	public string $provider;

	/** The stable, opaque account id at the provider (Steam ID64, Discord/Google/GitHub user id). */
	#[ORM\Column(name: 'provider_user_id', length: 191)]
	public string $providerUserId;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	/** Email reported by the provider at link time, if any (null for Steam). Informational only. */
	#[ORM\Column(length: 255, nullable: true)]
	public ?string $email = null;

	/**
	 * Human-readable name at the provider (Discord global name, GitHub login, Google
	 * given name, Steam persona name), captured at link time and refreshed on every
	 * sign-in through this provider. Display only — never used for matching.
	 */
	#[ORM\Column(length: 100, nullable: true)]
	public ?string $nickname = null;

	/** Provider avatar image URL, refreshed alongside the nickname. */
	#[ORM\Column(length: 512, nullable: true)]
	public ?string $avatarUrl = null;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
