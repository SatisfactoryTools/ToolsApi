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
class ModVersion
{

	use Identifier;
	use Uuid;

	#[ORM\ManyToOne(targetEntity: Mod::class, inversedBy: 'versions')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public Mod $mod;

	#[ORM\Column(length: 255)]
	public string $name;

	#[ORM\Column(nullable: true)]
	public ?string $dataPath = null;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	/** ficsit.app version id of an imported version. */
	#[ORM\Column(length: 64, nullable: true)]
	public ?string $sourceVersionId = null;

	/**
	 * The versions of other mods this one was imported with and needs.
	 * @var Collection<int, ModVersion>
	 */
	#[ORM\ManyToMany(targetEntity: ModVersion::class)]
	#[ORM\JoinTable(name: 'mod_version_dependency')]
	#[ORM\JoinColumn(name: 'mod_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
	#[ORM\InverseJoinColumn(name: 'dependency_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
	public Collection $dependencies;

	public function __construct()
	{
		$this->dependencies = new ArrayCollection();
	}

}
