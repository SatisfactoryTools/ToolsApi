<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts;

/** Ported from the old API (app/Solver/Schema/Data/Parts/Color.php). */
class Color
{

	public int $r = 0;
	public int $g = 0;
	public int $b = 0;
	public int $a = 0;

	public function toHex(): string
	{
		return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
	}

}
