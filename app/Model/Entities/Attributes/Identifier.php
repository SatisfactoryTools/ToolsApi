<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities\Attributes;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidGenerator;
use Ramsey\Uuid\UuidInterface;

trait Identifier
{

	#[ORM\Id]
	#[ORM\Column(nullable: false)]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	public int $id;

	public function __clone(): void
	{
		unset($this->id);
	}

}
