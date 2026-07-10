<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

/**
 * A read-only, point-in-time share of a folder or plan subtree. The whole shared tree
 * (version info + folders/plans/subplans) is frozen into $snapshot at creation time, so
 * later edits to — or deletion of — the original rows never change what a share serves.
 * The share UUID is the only capability needed to view it (anyone with the link can read).
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'share')]
class Share
{

	use Identifier;
	use Uuid;

	/** The creator of the share. */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	/** What was shared at the root of the tree: 'folder' | 'plan'. */
	#[ORM\Column(length: 16)]
	public string $type;

	/**
	 * Self-contained JSON snapshot of the shared subtree, captured when the share was
	 * created (see ShareService). MEDIUMTEXT so large plan trees fit comfortably.
	 */
	#[ORM\Column(type: Types::TEXT, length: 16_777_215)]
	public string $snapshot;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
