<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Utils;

use Apitte\Core\Exception\Runtime\InvalidArgumentTypeException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class UuidTypeMapper
{

	public function normalize(mixed $value): UuidInterface
	{
		if (is_string($value) && Uuid::isValid($value)) {
			return Uuid::fromString($value);
		}
		throw new InvalidArgumentTypeException('uuid', 'Pass a valid UUID string.');
	}

}
