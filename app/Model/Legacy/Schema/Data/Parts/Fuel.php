<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts;

class Fuel
{

	public string $item;
	public ?string $supplementalItem = null;
	public ?string $byproduct = null;
	public int $byproductAmount = 0;
	public bool $acceptsAnySolidFuel = false;

}
