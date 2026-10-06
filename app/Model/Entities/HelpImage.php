<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'help_images')]
#[ORM\HasLifecycleCallbacks]
class HelpImage
{

	use Identifier;
	use Uuid;

	#[ORM\Column(length: 255)]
	public string $fileName;

	#[ORM\Column(length: 255)]
	public string $path;

	#[ORM\Column]
	public DateTimeImmutable $uploadedAt;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	public ?User $user = null;

}
