<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use greeny\SatisfactoryTools\Api\Model\Entities\LegacyShare;
use greeny\SatisfactoryTools\Api\Model\Repositories\LegacyShareRepository;
use greeny\SatisfactoryTools\Api\Model\Services\LegacyShareFetcher;
use greeny\SatisfactoryTools\Api\Model\Services\LegacyShareFetchException;
use Nette\Http\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

#[Path('/legacy-shares')]
class LegacySharesController extends BaseV1Controller
{

	private const string SLUG_PATTERN = '~^[A-Za-z0-9]{1,64}$~D';

	private const string CACHE_CONTROL = 'private, max-age=86400';

	public function __construct(
		private readonly LegacyShareRepository $legacyShareRepository,
		private readonly LegacyShareFetcher $legacyShareFetcher,
		private readonly LoggerInterface $logger,
	)
	{
	}

	#[Path('/{slug}')]
	#[Method('GET')]
	#[RequestParameter(name: 'slug', type: 'string', in: EndpointParameter::IN_PATH)]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$slug = (string) $request->getParameter('slug');
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return $this->notFound($response);
		}

		$tableExists = true;
		try {
			$share = $this->legacyShareRepository->getBySlug($slug);
		} catch (TableNotFoundException) {
			// The table may not exist until the schema update that ships the import has run.
			$share = null;
			$tableExists = false;
		} catch (DbalException $e) {
			$this->logger->error('Could not read legacy_share: ' . $e->getMessage(), ['exception' => $e]);
			$share = null;
			$tableExists = false;
		}

		if ($share !== null) {
			return $this->document($response, $share->data);
		}

		if (!$this->legacyShareFetcher->isEnabled()) {
			return $this->notFound($response);
		}

		try {
			$data = $this->legacyShareFetcher->fetch($slug);
		} catch (LegacyShareFetchException $e) {
			return $response->withStatus(IResponse::S502_BadGateway)
				->writeJsonBody(['error' => 'Could not load the share from the old Satisfactory Tools', 'detail' => $e->getMessage()]);
		}

		if ($data === null) {
			return $this->notFound($response);
		}

		if ($tableExists) {
			$this->store($slug, $data);
		}

		return $this->document($response, $data);
	}

	// Best effort: on failure the response is still served from the fetched copy.
	private function store(string $slug, string $data): void
	{
		try {
			$share = new LegacyShare();
			$share->slug = $slug;
			$share->data = $data;
			$share->createdAt = new DateTimeImmutable();
			$this->legacyShareRepository->save($share);
		} catch (Throwable $e) {
			$this->logger->warning('Could not store fetched legacy share ' . $slug . ': ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function document(ApiResponse $response, string $data): ApiResponse
	{
		return $response->writeBody($data)
			->withHeader('Content-Type', 'application/json')
			->withHeader('Cache-Control', self::CACHE_CONTROL);
	}

	private function notFound(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S404_NotFound)
			->writeJsonBody(['error' => 'Share not found']);
	}

}
