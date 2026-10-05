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

	/** Need not match any game version. */
	#[ORM\Column(length: 255)]
	public string $name;

	/** Same format as a game version data file; null until the author uploads data, in which case nothing is merged. */
	#[ORM\Column(nullable: true)]
	public ?string $dataPath = null;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
