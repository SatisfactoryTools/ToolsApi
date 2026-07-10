<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

#[ORM\Entity]
class UserSettings
{

	use Identifier;

	#[ORM\OneToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
	public User $user;

	#[ORM\Column(type: 'text')]
	public string $data = '{}';

	#[ORM\Column]
	public int $revision = 0;

}
