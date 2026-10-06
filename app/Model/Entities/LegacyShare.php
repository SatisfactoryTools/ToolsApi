<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

#[ORM\Entity]
#[ORM\Table(name: 'legacy_share')]
class LegacyShare
{

	use Identifier;

	#[ORM\Column(unique: true, length: 64)]
	public string $slug;

	#[ORM\Column(type: Types::TEXT)]
	public string $data;

	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
