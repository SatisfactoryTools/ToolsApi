<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class User
{

	use Identifier;
	use Uuid;

	#[ORM\Column(unique: true, length: 64)]
	public string $login;

	#[ORM\Column(unique: true, length: 255)]
	public string $email;

	/** Null for accounts created via a third-party provider that have not set a password. */
	#[ORM\Column(length: 255, nullable: true)]
	public ?string $passwordHash = null;

	/** When unset, the frontend falls back to the login or a provider nickname. */
	#[ORM\Column(length: 50, nullable: true)]
	public ?string $displayName = null;

	/** Set by hand in the database; there is no role system or API to grant it. */
	#[ORM\Column]
	public bool $helpEditor = false;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
