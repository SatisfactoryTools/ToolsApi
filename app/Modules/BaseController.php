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
