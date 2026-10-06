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

	#[ORM\Column]
	public bool $ficsmas = false;

	#[ORM\Column]
	public string $dataPath;

	/** @var Collection<int, User> */
	#[ORM\ManyToMany(targetEntity: User::class)]
	#[ORM\JoinTable(name: 'user_version')]
	public Collection $users;

	#[ORM\ManyToOne(targetEntity: Version::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?Version $baseVersion = null;

	#[ORM\Column(type: 'float')]
	public float $recipeCostMultiplier = 1.0;

	#[ORM\Column(type: 'float')]
	public float $powerCostMultiplier = 1.0;

	/** @var array<string, mixed>|null */
	#[ORM\Column(type: 'json', nullable: true)]
	public ?array $worldData = null;

	#[ORM\Column(length: 64, unique: true, nullable: true)]
	public ?string $definitionHash = null;

	/** @var Collection<int, VersionModVersion> */
	#[ORM\OneToMany(mappedBy: 'version', targetEntity: VersionModVersion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
	#[ORM\OrderBy(['position' => 'ASC'])]
	public Collection $modVersions;

	public function __construct()
	{
		$this->users = new ArrayCollection();
		$this->modVersions = new ArrayCollection();
	}

	/** @return ModVersion[] */
	public function getOrderedModVersions(): array
	{
		return array_map(
			static fn (VersionModVersion $link): ModVersion => $link->modVersion,
			$this->modVersions->toArray(),
		);
	}

}
