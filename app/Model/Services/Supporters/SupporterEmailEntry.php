<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

class SupporterEmailEntry
{

	public function __construct(
		public readonly string $email,
		public readonly string $source,
		public readonly ?string $externalId = null,
	)
	{
	}

}
