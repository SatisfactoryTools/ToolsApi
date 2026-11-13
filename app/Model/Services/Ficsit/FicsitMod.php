<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Ficsit;

class FicsitMod
{

	public function __construct(
		public readonly string $id,
		public readonly string $reference,
		public readonly string $name,
		public readonly ?string $shortDescription,
		public readonly ?string $logo,
	)
	{
	}

}
