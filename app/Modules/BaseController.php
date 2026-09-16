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

	/**
	 * parseBody() for callers that already hold the raw body (e.g. to measure it before
	 * decoding) — the request stream is only worth reading once.
	 *
	 * @return array<string, mixed>
	 */
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

		// A valid JSON scalar (e.g. `5`, `true`, `"x"`) decodes fine but is not an object;
		// callers expect a key/value map, so treat anything but an array as an empty body.
		return is_array($decoded) ? $decoded : [];
	}

}
