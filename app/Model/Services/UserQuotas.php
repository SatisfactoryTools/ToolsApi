<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

class UserQuotas
{

	public readonly int $plans;
	public readonly int $folders;
	public readonly int $mods;
	public readonly int $modVersions;
	public readonly int $customVersionsPerUser;
	public readonly int $customVersionsPerIp;

	/** @param array{plans: int, folders: int, mods: int, modVersions: int, customVersionsPerUser: int, customVersionsPerIp: int} $quotas */
	public function __construct(array $quotas)
	{
		$this->plans = $quotas['plans'];
		$this->folders = $quotas['folders'];
		$this->mods = $quotas['mods'];
		$this->modVersions = $quotas['modVersions'];
		$this->customVersionsPerUser = $quotas['customVersionsPerUser'];
		$this->customVersionsPerIp = $quotas['customVersionsPerIp'];
	}

}
