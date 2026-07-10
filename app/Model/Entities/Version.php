<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Version
{

	use Identifier;
	use Uuid;

	#[ORM\Column]
	public string $name;

	#[ORM\Column]
	public ?string $slug;

	#[ORM\Column]
	public bool $experimental = false;

	#[ORM\Column]
	public bool $custom = false;

	/** Whether this version's data includes FICSMAS (seasonal event) content. */
	#[ORM\Column]
	public bool $ficsmas = false;

	#[ORM\Column]
	public string $dataPath;

	/**
	 * Owner of a custom version. Null for public (built-in) versions, which are visible
	 * to everyone; a custom version is only visible to its owner.
	 */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?User $user = null;

	/** The public version a custom version was derived from. Null for public versions. */
	#[ORM\ManyToOne(targetEntity: Version::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?Version $baseVersion = null;

	#[ORM\Column(type: 'float')]
	public float $recipeCostMultiplier = 1.0;

	#[ORM\Column(type: 'float')]
	public float $powerCostMultiplier = 1.0;

	/**
	 * Mod versions merged into this custom version, at most one version per mod.
	 *
	 * @var Collection<int, ModVersion>
	 */
	#[ORM\ManyToMany(targetEntity: ModVersion::class)]
	#[ORM\JoinTable(name: 'version_mod_version')]
	public Collection $modVersions;

	public function __construct()
	{
		$this->modVersions = new ArrayCollection();
	}

}
