<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** One imported game build; an official version covers every build whose planner data matched it. */
#[ORM\Entity]
#[ORM\Table(name: 'version_build')]
class VersionBuild
{

	use Identifier;

	#[ORM\ManyToOne(targetEntity: Version::class, inversedBy: 'builds')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public Version $version;

	#[ORM\Column(length: 32, nullable: true)]
	public ?string $buildId = null;

	#[ORM\Column(length: 64, nullable: true)]
	public ?string $gameVersion = null;

	#[ORM\Column(unique: true)]
	public string $dataPath;

	#[ORM\Column(length: 64)]
	public string $plannerHash;

	#[ORM\Column]
	public DateTimeImmutable $importedAt;

}
