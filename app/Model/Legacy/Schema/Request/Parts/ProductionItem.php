<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts;

class ProductionItem
{

	public ?string $item = null;
	public string $type = '';
	public ?float $amount = null;
	public float $ratio = 0;

}
