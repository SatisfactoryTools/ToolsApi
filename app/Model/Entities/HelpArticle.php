<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

/**
 * A help article (tutorial). The body is Markdown; readers never get it from here but
 * from the static snapshot HelpSnapshotService writes after every change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'help_articles')]
#[ORM\HasLifecycleCallbacks]
class HelpArticle
{

	use Identifier;
	use Uuid;

	/** URL segment, e.g. "first-plan". */
	#[ORM\Column(unique: true, length: 100)]
	public string $slug;

	#[ORM\Column(length: 200)]
	public string $title;

	/** One line shown in article lists and search results. */
	#[ORM\Column(length: 500)]
	public string $summary = '';

	/** Markdown source. */
	#[ORM\Column(type: 'text')]
	public string $body = '';

	/**
	 * Extra search terms that do not appear in the title or summary.
	 *
	 * @var string[]
	 */
	#[ORM\Column(type: 'json')]
	public array $keywords = [];

	/**
	 * Topic id => anchor within this article ('' for the top). Topic ids are what the
	 * question-mark buttons in the app reference, so an article can answer several.
	 *
	 * @var array<string, string>
	 */
	#[ORM\Column(type: 'json')]
	public array $topics = [];

	/**
	 * Slugs of related articles, listed at the end of this one.
	 *
	 * @var string[]
	 */
	#[ORM\Column(type: 'json')]
	public array $seeAlso = [];

	#[ORM\ManyToOne(targetEntity: HelpCategory::class, inversedBy: 'articles')]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?HelpCategory $category = null;

	/** Zero-based position within the category. */
	#[ORM\Column]
	public int $position = 0;

	/** Drafts are served to editors only and stay out of the snapshot. */
	#[ORM\Column]
	public bool $published = false;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	#[ORM\Column]
	public DateTimeImmutable $updatedAt;

	/** Whoever saved the article last; null once that account is gone. */
	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?User $author = null;

}
