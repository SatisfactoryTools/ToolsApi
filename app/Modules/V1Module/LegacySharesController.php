<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use greeny\SatisfactoryTools\Api\Model\Repositories\LegacyShareRepository;
use Nette\Http\IResponse;

/**
 * Shares imported from the old Satisfactory Tools API (see LegacyShare). Public and
 * read-only: the slug is the capability, exactly as it was on the old site. There is no
 * way to create one — the old API is retired and the table is filled by
 * `bin/console legacy-shares:import`.
 */
#[Path('/legacy-shares')]
class LegacySharesController extends BaseV1Controller
{

	/** The old API generated 20-char alphanumeric slugs; anything else cannot exist. */
	private const SLUG_PATTERN = '~^[A-Za-z0-9]{1,64}$~D';

	/** Shares are immutable, so clients and proxies may cache a hit for a day. */
	private const CACHE_CONTROL = 'public, max-age=86400';

	public function __construct(private readonly LegacyShareRepository $legacyShareRepository)
	{
	}

	/**
	 * Returns the stored document — `{ "metadata": {...}, "request": {...} }` — at the top
	 * level, not wrapped in an envelope. The body is the raw JSON as imported, written as
	 * is, so the response is byte-identical to what the old API had stored. 404 for an
	 * unknown or malformed slug.
	 */
	#[Path('/{slug}')]
	#[Method('GET')]
	#[RequestParameter(name: 'slug', type: 'string', in: EndpointParameter::IN_PATH)]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$slug = (string) $request->getParameter('slug');
		$share = preg_match(self::SLUG_PATTERN, $slug) === 1
			? $this->legacyShareRepository->getBySlug($slug)
			: null;

		if ($share === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Share not found']);
		}

		return $response->writeBody($share->data)
			->withHeader('Content-Type', 'application/json')
			->withHeader('Cache-Control', self::CACHE_CONTROL);
	}

}
