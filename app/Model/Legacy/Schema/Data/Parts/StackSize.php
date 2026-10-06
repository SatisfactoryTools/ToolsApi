<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts;

enum StackSize: int
{

	case None = 0;
	case One = 1;
	case Small = 50;
	case Medium = 100;
	case Big = 200;
	case Huge = 500;
	case Fluid = 50000;

}
