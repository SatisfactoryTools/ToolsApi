<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use greeny\SatisfactoryTools\Api\Model\Entities\Version;

class PublishOutcome
{

	public const string CREATED = 'created';
	public const string ATTACHED = 'attached';
	public const string MERGED = 'merged';
	public const string REFRESHED = 'refreshed';

	/** @param array{count: int, changes: string[]}|null $diff */
	public function __construct(
		public readonly string $action,
		public readonly Version $version,
		public readonly ?string $comparedWith,
		public readonly ?array $diff,
		public readonly ?string $removedSlug = null,
		public readonly ?string $detachedFrom = null,
	)
	{
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'action' => $this->action,
			'slug' => $this->version->slug,
			'name' => $this->version->name,
			'gameVersions' => $this->version->getGameVersions(),
			'ficsmas' => $this->version->ficsmas,
			'comparedWith' => $this->comparedWith,
			'diff' => $this->diff,
			'removedSlug' => $this->removedSlug,
			'detachedFrom' => $this->detachedFrom,
		];
	}

}
