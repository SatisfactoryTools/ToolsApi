<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules;

use Apitte\Core\Http\ApiRequest;
use Apitte\Core\UI\Controller\IController;
use JsonException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

abstract class BaseController implements IController
{

	protected function getUuidParameter(ApiRequest $request, string $name = 'uuid'): UuidInterface
	{
		return Uuid::fromString((string) $request->getParameter($name));
	}

	/** Null for anything that is not a valid UUID string, so callers can answer 400 instead of failing with 500. */
	protected function toUuid(mixed $value): ?UuidInterface
	{
		return is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
	}

	/** The error message for a name field, or null when it is fine (a string of at most $maxLength characters). */
	protected function nameError(mixed $value, string $field, int $maxLength = 255): ?string
	{
		if (!is_string($value)) {
			return 'Field ' . $field . ' must be a string';
		}
		if (mb_strlen(trim($value)) > $maxLength) {
			return 'Field ' . $field . ' may be at most ' . $maxLength . ' characters long';
		}

		return null;
	}

	/** @return array<string, mixed> */
	protected function parseBody(ApiRequest $request): array
	{
		return $this->parseBodyString((string) $request->getBody());
	}

	/** @return array<string, mixed> */
	protected function parseBodyString(string $body): array
	{
		if ($body === '') {
			return [];
		}
		try {
			$decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}

		// A valid JSON scalar decodes fine but callers expect a key/value map.
		return is_array($decoded) ? $decoded : [];
	}

}
