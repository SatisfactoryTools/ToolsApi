<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Repositories\SupporterRepository;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\PatreonClient;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterService;
use Nette\Http\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

#[Path('/supporters')]
class SupportersController extends BaseV1Controller
{

	public function __construct(
		private readonly SupporterRepository $supporterRepository,
		private readonly SupporterService $supporterService,
		private readonly PatreonClient $patreon,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/** Only accounts that chose to be listed, under the name they chose. */
	#[Path('/')]
	#[Method('GET')]
	public function list(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$supporters = [];
		foreach ($this->supporterRepository->findListed() as $supporter) {
			$supporters[] = ['name' => $supporter->listName];
		}

		return $response->writeJsonBody(['supporters' => $supporters]);
	}

	/** Register for the members:create, members:update and members:pledge:* triggers; the nightly sync catches anything missed. */
	#[Path('/webhooks/patreon')]
	#[Method('POST')]
	public function patreonWebhook(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if (!$this->patreon->isWebhookEnabled()) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Not found']);
		}

		$body = (string) $request->getBody();
		if (!$this->patreon->verifyWebhook($body, $request->getHeaderLine('X-Patreon-Signature'))) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Invalid signature']);
		}

		$entry = $this->patreon->entryOfWebhook($this->parseBodyString($body));
		if ($entry !== null) {
			try {
				$this->supporterService->addEmails([$entry]);
			} catch (Throwable $e) {
				$this->logger->error('Patreon webhook failed: ' . $e->getMessage(), ['exception' => $e]);

				return $response->withStatus(IResponse::S500_InternalServerError)
					->writeJsonBody(['error' => 'Could not record the member']);
			}
		}

		return $response->withStatus(IResponse::S204_NoContent);
	}

}
