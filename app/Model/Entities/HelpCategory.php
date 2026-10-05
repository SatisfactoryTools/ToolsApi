<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'help_categories')]
#[ORM\HasLifecycleCallbacks]
class HelpCategory
{

	use Identifier;
	use Uuid;

	#[ORM\Column(unique: true, length: 100)]
	public string $slug;

	#[ORM\Column(length: 200)]
	public string $name;

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
