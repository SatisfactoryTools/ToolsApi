<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'version_created_from_ip', columns: ['created_from_ip', 'created_at'])]
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

	#[ORM\Column(length: 64, nullable: true)]
	public ?string $plannerHash = null;

	/** Who created a custom version, for the per-user and per-IP creation caps; unset on versions from before the caps. */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?User $createdBy = null;

	#[ORM\Column(length: 64, nullable: true)]
	public ?string $createdFromIp = null;

	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $createdAt = null;

	/** @var Collection<int, VersionBuild> */
	#[ORM\OneToMany(mappedBy: 'version', targetEntity: VersionBuild::class, cascade: ['persist'])]
	#[ORM\OrderBy(['importedAt' => 'ASC', 'id' => 'ASC'])]
	public Collection $builds;

	public function __construct()
	{
		$this->users = new ArrayCollection();
		$this->modVersions = new ArrayCollection();
		$this->builds = new ArrayCollection();
	}

	/** @return string[] */
	public function getGameVersions(): array
	{
		$gameVersions = [];
		foreach ($this->builds as $build) {
			if ($build->gameVersion !== null && !in_array($build->gameVersion, $gameVersions, true)) {
				$gameVersions[] = $build->gameVersion;
			}
		}

		return $gameVersions;
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
