<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Schema\Responses;

/** @template T */
abstract class ArrayableResponse
{

	/** @return array<T> */
	public static function fromArray(array $array): array
	{
		return array_map(
			static fn($entity) => static::fromEntity($entity),
			$array
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return json_decode(json_encode($this) ?: '[]', true);
	}

	/** @param T $entity */
	abstract public static function fromEntity(object $entity): static;

}
