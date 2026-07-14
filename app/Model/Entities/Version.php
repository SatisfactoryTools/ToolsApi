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

	/**
	 * Web-root-relative path of the data file. For official versions this file is
	 * permanent; for custom versions it is a regenerable cache artifact named
	 * custom/{uuid}-{inputsHash}.json — it may be pruned from disk at any time and is
	 * re-materialized on demand (see CustomVersionFileService). The hash suffix changes
	 * when any generation input changes, so each URL is immutable.
	 */
	#[ORM\Column]
	public string $dataPath;

	/**
	 * Users who saved this version to their account. A custom version has no owner —
	 * it is an immutable shared object (anyone may create one, anonymous browsers keep
	 * references client-side); "deleting" a version only removes the caller's link.
	 *
	 * @var Collection<int, User>
	 */
	#[ORM\ManyToMany(targetEntity: User::class)]
	#[ORM\JoinTable(name: 'user_version')]
	public Collection $users;

	/** The public version a custom version was derived from. Null for public versions. */
	#[ORM\ManyToOne(targetEntity: Version::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?Version $baseVersion = null;

	#[ORM\Column(type: 'float')]
	public float $recipeCostMultiplier = 1.0;

	#[ORM\Column(type: 'float')]
	public float $powerCostMultiplier = 1.0;

	/**
	 * World settings and derived resource limits stored under metadata.world in the
	 * generated file. Persisted here (not only in the file) because the file is a
	 * prunable cache and must be reproducible from the database alone.
	 *
	 * @var array<string, mixed>|null
	 */
	#[ORM\Column(type: 'json', nullable: true)]
	public ?array $worldData = null;

	/**
	 * SHA-256 of the canonical definition (name, base, ordered mods, multipliers, world
	 * data). Unique: creating an already-existing definition returns the existing
	 * version instead of a new one. Null for official versions.
	 */
	#[ORM\Column(length: 64, unique: true, nullable: true)]
	public ?string $definitionHash = null;

	/**
	 * Mod versions merged into this custom version, in application order, at most one
	 * version per mod.
	 *
	 * @var Collection<int, VersionModVersion>
	 */
	#[ORM\OneToMany(mappedBy: 'version', targetEntity: VersionModVersion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
	#[ORM\OrderBy(['position' => 'ASC'])]
	public Collection $modVersions;

	public function __construct()
	{
		$this->users = new ArrayCollection();
		$this->modVersions = new ArrayCollection();
	}

	/** @return ModVersion[] in application order */
	public function getOrderedModVersions(): array
	{
		return array_map(
			static fn (VersionModVersion $link): ModVersion => $link->modVersion,
			$this->modVersions->toArray(),
		);
	}

}
