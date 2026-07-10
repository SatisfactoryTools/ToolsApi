<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities\Attributes;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid as RamseyUuid;
use Ramsey\Uuid\UuidInterface;

trait Uuid
{

	#[ORM\Column(type: 'uuid', unique: true, nullable: false)]
	public UuidInterface $uuid;

	#[ORM\PrePersist]
	public function generateUuid(): void
	{
		if (!isset($this->uuid)) {
			$this->uuid = RamseyUuid::uuid4();
		}
	}

}
