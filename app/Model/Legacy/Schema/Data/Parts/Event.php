<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts;

/** Ported from the old API (app/Solver/Schema/Data/Parts/Event.php). */
enum Event: string
{

	case Ficsmas = 'ficsmas';
	case Unknown = '?';

}
