<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts;

/** Ported from the old API (app/Solver/Schema/Data/Parts/ItemForm.php). */
enum ItemForm: string
{

	case Solid = 'solid';
	case Liquid = 'liquid';
	case Gas = 'gas';

	public function isFluid(): bool
	{
		return $this !== self::Solid;
	}

}
