<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Ficsit;

use JsonException;

/** Anonymous GraphQL API of ficsit.app (Satisfactory Mod Repository). */
class FicsitAppClient
{

	private const string API_URL = 'https://api.ficsit.app/v2/query';
	private const string USER_AGENT = 'SatisfactoryTools-API';
	private const string NOT_FOUND_MESSAGE = 'mod not found';

	/** Matches a mod page link and captures its reference (or ficsit.app id), e.g. https://ficsit.app/mod/RocketFuelRebalancePatch. */
	private const string LINK_PATTERN = '~^(?:https?://)?(?:www\.)?ficsit\.app/mod/([A-Za-z0-9_]{1,64})/?(?:[/?#].*)?$~Di';

	public static function parseLink(string $link): ?string
	{
		return preg_match(self::LINK_PATTERN, trim($link), $matches) === 1 ? $matches[1] : null;
	}

	/** @throws FicsitAppException when ficsit.app cannot be reached or answers with something unexpected */
	public function findMod(string $idOrReference): ?FicsitMod
	{
		$json = $this->query(
			'query($ref: String!) { getModByIdOrReference(modIdOrReference: $ref) { id name mod_reference short_description logo } }',
			['ref' => $idOrReference],
		);

		$mod = $json['data']['getModByIdOrReference'] ?? null;
		if (!is_array($mod)) {
			$message = (string) ($json['errors'][0]['message'] ?? '');
			if ($message === '' || str_contains($message, self::NOT_FOUND_MESSAGE)) {
				return null;
			}
			throw new FicsitAppException('ficsit.app: ' . $message);
		}

		return new FicsitMod(
			id: (string) $mod['id'],
			reference: (string) $mod['mod_reference'],
			name: (string) $mod['name'],
			shortDescription: is_string($mod['short_description'] ?? null) && $mod['short_description'] !== '' ? $mod['short_description'] : null,
			logo: is_string($mod['logo'] ?? null) && str_starts_with($mod['logo'], 'https://') ? $mod['logo'] : null,
		);
	}

	/**
	 * @param array<string, mixed> $variables
	 * @return array<string, mixed>
	 */
	private function query(string $query, array $variables): array
	{
		$curl = curl_init(self::API_URL);
		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR),
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT => self::USER_AGENT,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 10,
		]);
		$body = curl_exec($curl);
		if (!is_string($body)) {
			throw new FicsitAppException('Could not reach ficsit.app: ' . curl_error($curl));
		}

		try {
			$json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new FicsitAppException('ficsit.app returned invalid JSON.');
		}
		if (!is_array($json)) {
			throw new FicsitAppException('ficsit.app returned invalid JSON.');
		}

		return $json;
	}

}
