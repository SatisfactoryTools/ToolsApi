<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts;

/** Ported from the old API (app/Solver/Schema/Request/Parts/ProductionItem.php). */
class ProductionItem
{

	public ?string $item = null;
	public string $type = '';
	public ?float $amount = null;
	public float $ratio = 0;

}
