<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'share')]
class Share
{

	use Identifier;
	use Uuid;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?User $user = null;

	#[ORM\Column(length: 16)]
	public string $type;

	#[ORM\Column(type: Types::TEXT, length: 16_777_215)]
	public string $snapshot;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
