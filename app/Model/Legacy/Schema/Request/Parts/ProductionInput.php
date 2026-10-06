<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts;

/** Ported from the old API (app/Solver/Schema/Request/Parts/ProductionInput.php). */
class ProductionInput
{

	public ?string $item = null;
	public ?float $amount = null;

}
