<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mods')]
#[ORM\HasLifecycleCallbacks]
class Mod
{

	use Identifier;
	use Uuid;

	#[ORM\Column(length: 255)]
	public string $name;

	/**
	 * Public mods are visible to everyone and can be added to anyone's custom versions,
	 * but are managed only by their author. Private mods are visible to and usable by
	 * their author only.
	 */
	#[ORM\Column]
	public bool $public = false;

	/** The author / owner of the mod. */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public User $user;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	/** @var Collection<int, ModVersion> */
	#[ORM\OneToMany(mappedBy: 'mod', targetEntity: ModVersion::class)]
	#[ORM\OrderBy(['createdAt' => 'ASC'])]
	public Collection $versions;

	public function __construct()
	{
		$this->versions = new ArrayCollection();
	}

}
