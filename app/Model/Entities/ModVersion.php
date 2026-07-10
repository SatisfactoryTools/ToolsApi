<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class ModVersion
{

	use Identifier;
	use Uuid;

	#[ORM\ManyToOne(targetEntity: Mod::class, inversedBy: 'versions')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public Mod $mod;

	/** Label of this mod version, e.g. "1.0.0". Need not match any game version. */
	#[ORM\Column(length: 255)]
	public string $name;

	/**
	 * Path (relative to the web root) of this mod version's data file, in the same format
	 * as a game version data file. Null if the author has not uploaded data yet, in which
	 * case the mod version merges nothing.
	 */
	#[ORM\Column(nullable: true)]
	public ?string $dataPath = null;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
