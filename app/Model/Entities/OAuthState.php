<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_state')]
class OAuthState
{

	use Identifier;

	#[ORM\Column(unique: true, length: 64)]
	public string $state;

	#[ORM\Column(length: 16)]
	public string $provider;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?User $linkUser = null;

	#[ORM\Column(length: 64, nullable: true)]
	public ?string $pollToken = null;

	#[ORM\Column(length: 16, nullable: true)]
	public ?string $confirmCode = null;

	#[ORM\Column(options: ['default' => 0])]
	public int $confirmAttempts = 0;

	/** @var array<string, mixed>|null */
	#[ORM\Column(type: 'json', nullable: true)]
	public ?array $result = null;

	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $completedAt = null;

	#[ORM\Column]
	public DateTimeImmutable $expiresAt;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
