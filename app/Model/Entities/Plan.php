<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Plan
{

	use Identifier;
	use Uuid;

	#[ORM\Column(length: 255)]
	public string $name;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false)]
	public User $user;

	#[ORM\Column(type: 'text', nullable: true)]
	public ?string $description = null;

	#[ORM\ManyToOne(targetEntity: Version::class)]
	#[ORM\JoinColumn(nullable: false)]
	public Version $version;

	#[ORM\Column(type: 'text')]
	public string $data = '{}';

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

	/**
	 * Last content/move change. Null for plans that predate this column — treat null as
	 * createdAt (see getUpdatedAt()).
	 */
	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $updatedAt = null;

	#[ORM\Column]
	public int $revision = 0;

	#[ORM\ManyToOne(targetEntity: Folder::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?Folder $folder = null;

	#[ORM\ManyToOne(targetEntity: Plan::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	public ?Plan $parent = null;

	public function getUpdatedAt(): DateTimeImmutable
	{
		return $this->updatedAt ?? $this->createdAt;
	}

}
