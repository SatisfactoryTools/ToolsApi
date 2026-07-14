<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/**
 * A mod version included in a custom version, with its application order. Merge order
 * matters (later mods overwrite earlier ones), so the plain many-to-many join table is
 * not enough — this entity carries the explicit position.
 */
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

	/** Zero-based application order; mods are merged in ascending position. */
	#[ORM\Column]
	public int $position = 0;

}
