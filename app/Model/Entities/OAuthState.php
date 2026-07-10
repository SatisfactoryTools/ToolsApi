<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/**
 * Short-lived, single-use CSRF state for an in-flight OAuth authorization.
 * Created when the frontend starts a flow, consumed when the provider redirects back.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_state')]
class OAuthState
{

	use Identifier;

	#[ORM\Column(unique: true, length: 64)]
	public string $state;

	/** Provider key: steam | discord | github | google */
	#[ORM\Column(length: 16)]
	public string $provider;

	/**
	 * When set, the flow links the provider to this already-authenticated user
	 * instead of logging in / signing up.
	 */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?User $linkUser = null;

	#[ORM\Column]
	public DateTimeImmutable $expiresAt;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
