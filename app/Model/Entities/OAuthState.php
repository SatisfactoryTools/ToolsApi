<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/**
 * Short-lived, single-use CSRF state for an in-flight OAuth authorization.
 * Created when the frontend starts a flow, consumed when the provider redirects back.
 *
 * Desktop flows (pollToken set) are consumed in two steps instead: the browser-side
 * callback stores its outcome in result and sets completedAt, and the desktop app's
 * poll picks the result up and deletes the row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_state')]
class OAuthState
{

	use Identifier;

	#[ORM\Column(unique: true, length: 64)]
	public string $state;

	/** steam | discord | github | google */
	#[ORM\Column(length: 16)]
	public string $provider;

	/** When set, the flow links the provider to this already-authenticated user instead of logging in / signing up. */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?User $linkUser = null;

	/**
	 * Secret the desktop app polls with for the flow's outcome. Null for flows that
	 * finish in the browser that started them.
	 */
	#[ORM\Column(length: 64, nullable: true)]
	public ?string $pollToken = null;

	/**
	 * Desktop flows only: the response body the callback would have returned (tokens and
	 * provider, the linked payload, or `{"error": ...}`), kept until the desktop app polls.
	 *
	 * @var array<string, mixed>|null
	 */
	#[ORM\Column(type: 'json', nullable: true)]
	public ?array $result = null;

	/** Desktop flows only: when the browser-side callback finished (successfully or not). */
	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $completedAt = null;

	#[ORM\Column]
	public DateTimeImmutable $expiresAt;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
