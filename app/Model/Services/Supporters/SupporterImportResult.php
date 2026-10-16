<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

class SupporterImportResult
{

	public int $added = 0;

	public int $known = 0;

	public int $invalid = 0;

	public int $accountsMarked = 0;

	public function merge(self $other): void
	{
		$this->added += $other->added;
		$this->known += $other->known;
		$this->invalid += $other->invalid;
		$this->accountsMarked += $other->accountsMarked;
	}

	public function summary(): string
	{
		return sprintf(
			'%d new %s, %d already known, %d invalid; %d %s newly marked as supporter.',
			$this->added,
			$this->added === 1 ? 'email' : 'emails',
			$this->known,
			$this->invalid,
			$this->accountsMarked,
			$this->accountsMarked === 1 ? 'account' : 'accounts',
		);
	}

}
