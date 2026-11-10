<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;
use greeny\SatisfactoryTools\Api\Model\Repositories\VersionRepository;

/** Search engines index one copy of the version pages: the latest stable release, which every other version's pages point to. */
class CanonicalVersion
{

	private ?Version $version = null;

	private bool $loaded = false;

	public function __construct(private readonly VersionRepository $versionRepository)
	{
	}

	public function get(): ?Version
	{
		if (!$this->loaded) {
			$this->version = $this->versionRepository->getLatestOfficial(false, false);
			$this->loaded = true;
		}

		return $this->version;
	}

	public function isCanonical(Version $version): bool
	{
		$canonical = $this->get();

		return $canonical === null || self::slug($canonical) === self::slug($version);
	}

	public static function slug(Version $version): string
	{
		return $version->slug ?? $version->uuid->toString();
	}

	/** @param string[] $segments */
	public static function path(Version $version, array $segments): string
	{
		return '/' . implode('/', array_map(rawurlencode(...), [self::slug($version), ...$segments]));
	}

}
