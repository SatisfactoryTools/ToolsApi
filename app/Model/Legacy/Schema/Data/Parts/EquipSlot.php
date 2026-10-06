<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts;

/** Ported from the old API (app/Solver/Schema/Data/Parts/EquipSlot.php). */
enum EquipSlot: string
{

	case Arms = 'arms';
	case Body = 'body';
	case Legs = 'legs';
	case Back = 'back';
	case None = '';

}
