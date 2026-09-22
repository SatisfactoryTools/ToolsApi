<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

/** A section of the help browser, grouping articles in an explicit order. */
#[ORM\Entity]
#[ORM\Table(name: 'help_categories')]
#[ORM\HasLifecycleCallbacks]
class HelpCategory
{

	use Identifier;
	use Uuid;

	/** Stable id used in the manifest and in help URLs. */
	#[ORM\Column(unique: true, length: 100)]
	public string $slug;

	#[ORM\Column(length: 200)]
	public string $name;

	/** Zero-based position in the section menu. */
	#[ORM\Column]
	public int $position = 0;

	/** @var Collection<int, HelpArticle> */
	#[ORM\OneToMany(mappedBy: 'category', targetEntity: HelpArticle::class)]
	#[ORM\OrderBy(['position' => 'ASC'])]
	public Collection $articles;

	public function __construct()
	{
		$this->articles = new ArrayCollection();
	}

}
