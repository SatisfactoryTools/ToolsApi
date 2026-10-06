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

/**
 * Shares imported from the old Satisfactory Tools API (see LegacyShare). Public and
 * read-only: the slug is the capability, exactly as it was on the old site. Rows come from
 * `bin/console legacy-shares:import` — and, until that has run, from the old API itself:
 * a slug that is not stored locally is fetched from there (LegacyShareFetcher) and kept.
 */
#[Path('/legacy-shares')]
class LegacySharesController extends BaseV1Controller
{

	/** The old API generated 20-char alphanumeric slugs; anything else cannot exist. */
	private const SLUG_PATTERN = '~^[A-Za-z0-9]{1,64}$~D';

	/** Shares are immutable, so clients and proxies may cache a hit for a day. */
	private const CACHE_CONTROL = 'public, max-age=86400';

	public function __construct(
		private readonly LegacyShareRepository $legacyShareRepository,
		private readonly LegacyShareFetcher $legacyShareFetcher,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/**
	 * Returns the stored document — `{ "metadata": {...}, "request": {...} }` — at the top
	 * level, not wrapped in an envelope. The body is the raw JSON as imported, written as
	 * is, so the response is byte-identical to what the old API had stored. 404 for an
	 * unknown or malformed slug.
	 *
	 * When the slug is not stored — or the `legacy_share` table does not exist yet — and a
	 * `legacyShareSourceUrl` is configured, the document is fetched from the old API and
	 * answered in the same shape; a fetched document is also stored (when the table is
	 * there) so the next request is local and the import later finds the row unchanged.
	 * The old API's 404 is a 404 here; any other failure there is a 502.
	 */
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
			// Not imported yet: the table is created by the schema update of the deploy
			// that ships the import, or by orm:schema-tool:update by hand.
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

	/**
	 * Write-through of a fetched share. Best effort: a failure (a concurrent request that
	 * stored the same slug first, a closed connection, ...) is logged and the response is
	 * served from the fetched copy regardless.
	 */
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
