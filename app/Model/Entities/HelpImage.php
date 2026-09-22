<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

/**
 * A screenshot uploaded for use in help articles. Articles reference it by uuid
 * (`img:{uuid}` in Markdown), so the file can be re-uploaded without touching bodies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'help_images')]
#[ORM\HasLifecycleCallbacks]
class HelpImage
{

	use Identifier;
	use Uuid;

	/** Original file name, shown in the editor's media list. */
	#[ORM\Column(length: 255)]
	public string $fileName;

	/** Path relative to the web root, e.g. data/help/images/{uuid}.png. */
	#[ORM\Column(length: 255)]
	public string $path;

	#[ORM\Column]
	public DateTimeImmutable $uploadedAt;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?User $user = null;

}
