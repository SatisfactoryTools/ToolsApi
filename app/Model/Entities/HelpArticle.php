<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

/** Readers never get articles from here but from the static snapshot HelpSnapshotService writes after every change. */
#[ORM\Entity]
#[ORM\Table(name: 'help_articles')]
#[ORM\HasLifecycleCallbacks]
class HelpArticle
{

	use Identifier;
	use Uuid;

	#[ORM\Column(unique: true, length: 100)]
	public string $slug;

	#[ORM\Column(length: 200)]
	public string $title;

	#[ORM\Column(length: 500)]
	public string $summary = '';

	#[ORM\Column(type: 'text')]
	public string $body = '';

	/** @var string[] */
	#[ORM\Column(type: 'json')]
	public array $keywords = [];

	/** @var array<string, string> topic id (referenced by the app's question-mark buttons) => anchor ('' for the top) */
	#[ORM\Column(type: 'json')]
	public array $topics = [];

	/** @var string[] slugs */
	#[ORM\Column(type: 'json')]
	public array $seeAlso = [];

	#[ORM\ManyToOne(targetEntity: HelpCategory::class, inversedBy: 'articles')]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?HelpCategory $category = null;

	#[ORM\Column]
	public int $position = 0;

	/** Drafts are served to editors only and stay out of the snapshot. */
	#[ORM\Column]
	public bool $published = false;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	#[ORM\Column]
	public DateTimeImmutable $updatedAt;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?User $author = null;

}
