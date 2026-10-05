<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/** Explicit join entity because merge order matters (later mods overwrite earlier ones). */
#[ORM\Entity]
#[ORM\Table(name: 'version_mod_version')]
class VersionModVersion
{

	use Identifier;

	#[ORM\ManyToOne(targetEntity: Version::class, inversedBy: 'modVersions')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public Version $version;

	#[ORM\ManyToOne(targetEntity: ModVersion::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public ModVersion $modVersion;

	#[ORM\Column]
	public int $position = 0;

}
