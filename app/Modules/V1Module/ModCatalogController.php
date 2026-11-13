<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Services\Ficsit\FicsitAppClient;
use greeny\SatisfactoryTools\Api\Model\Services\Ficsit\FicsitAppException;
use greeny\SatisfactoryTools\Api\Model\Services\ModCatalog;
use greeny\SatisfactoryTools\Api\Model\Services\ModSuggestionService;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\ClientIp;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimiter;
use greeny\SatisfactoryTools\Api\Model\Services\RateLimit\RateLimitExceeded;
use Nette\Http\IResponse;
use Psr\Log\LoggerInterface;

/** Public list of supported mods and the "suggest a mod" form. */
#[Path('/mod-catalog')]
class ModCatalogController extends BaseV1Controller
{

	public function __construct(
		private readonly ModCatalog $catalog,
		private readonly ModSuggestionService $suggestions,
		private readonly RateLimiter $rateLimiter,
		private readonly LoggerInterface $logger,
	)
	{
	}

	#[Path('/')]
	#[Method('GET')]
	public function list(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		return $response->writeJsonBody($this->catalog->entries());
	}

	#[Path('/suggestions')]
	#[Method('POST')]
	public function suggest(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);
		$link = $body['link'] ?? null;
		$reference = is_string($link) && strlen($link) <= 512 ? FicsitAppClient::parseLink($link) : null;
		if ($reference === null) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Paste the link of a mod page on ficsit.app, like https://ficsit.app/mod/RocketFuelRebalancePatch.']);
		}

		try {
			$this->rateLimiter->hit('modSuggestion', ClientIp::of($request));
		} catch (RateLimitExceeded $e) {
			return $response->withStatus(IResponse::S429_TooManyRequests)
				->withHeader('Retry-After', (string) $e->retryAfter)
				->writeJsonBody(['error' => 'You have suggested a lot of mods. Please try again later.']);
		}

		if ($this->catalog->isPublicReference($reference)) {
			return $response->writeJsonBody(['status' => 'available', 'reference' => $reference]);
		}

		try {
			$suggestion = $this->suggestions->find($reference);
		} catch (FicsitAppException $e) {
			$this->logger->warning('Mod suggestion lookup failed: ' . $e->getMessage());
			return $response->withStatus(IResponse::S502_BadGateway)
				->writeJsonBody(['error' => 'Could not check the mod on ficsit.app. Please try again later.']);
		}
		if ($suggestion === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'ficsit.app has no mod "' . $reference . '". Check the link.']);
		}
		if ($suggestion->reference !== $reference && $this->catalog->isPublicReference($suggestion->reference)) {
			return $response->writeJsonBody(['status' => 'available', 'reference' => $suggestion->reference, 'name' => $suggestion->name]);
		}

		/** @var User|null $user */
		$user = $request->getAttribute('user');
		$counted = $this->suggestions->suggest($suggestion, ClientIp::of($request), $user);

		return $response->writeJsonBody([
			'status' => $counted ? 'counted' : 'alreadySuggested',
			'reference' => $suggestion->reference,
			'name' => $suggestion->name,
		]);
	}

}
