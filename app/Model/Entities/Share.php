<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

/** The whole shared tree is frozen into $snapshot at creation, so later edits or deletion of the original rows never change what a share serves. The UUID alone grants read access. */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'share')]
class Share
{

	use Identifier;
	use Uuid;

	/** Null when created by a signed-out visitor (ShareService::shareTree()); reading a share never depends on it. */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?User $user = null;

	/** 'folder' | 'plan' */
	#[ORM\Column(length: 16)]
	public string $type;

	/** MEDIUMTEXT so large plan trees fit. */
	#[ORM\Column(type: Types::TEXT, length: 16_777_215)]
	public string $snapshot;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
