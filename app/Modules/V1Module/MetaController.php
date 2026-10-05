<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Services\Meta\MetaResolver;

/**
 * Link preview metadata for a frontend page: GET /v1/meta?path=/latest-stable/codex/items/Desc_Cable_C.
 * Called by the frontend web layer (sftools-frontend: deploy/web/index.php) on every page
 * request, so preview bots - which run no JavaScript - see a page-specific title,
 * description and image. Public, and always 200 with usable values: anything unknown or
 * broken answers with the site defaults.
 */
#[Path('/meta')]
class MetaController extends BaseV1Controller
{

	private const CACHE_CONTROL = 'public, max-age=300';

	public function __construct(private readonly MetaResolver $resolver)
	{
	}

	#[Path('/')]
	#[Method('GET')]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$path = $request->getQueryParams()['path'] ?? '';
		$meta = is_string($path) ? $this->resolver->resolve($path) : $this->resolver->defaults();

		return $response->writeJsonBody($meta->toArray())
			->withHeader('Cache-Control', self::CACHE_CONTROL);
	}

}
