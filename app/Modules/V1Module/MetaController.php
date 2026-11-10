<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Services\Meta\MetaResolver;
use greeny\SatisfactoryTools\Api\Model\Services\Meta\SitemapBuilder;

#[Path('/meta')]
class MetaController extends BaseV1Controller
{

	private const string CACHE_CONTROL = 'private, max-age=300';

	private const string SITEMAP_CACHE_CONTROL = 'public, max-age=3600';

	public function __construct(
		private readonly MetaResolver $resolver,
		private readonly SitemapBuilder $sitemapBuilder,
	)
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

	#[Path('/sitemap')]
	#[Method('GET')]
	public function sitemap(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		return $response->writeJsonBody(['paths' => $this->sitemapBuilder->paths()])
			->withHeader('Cache-Control', self::SITEMAP_CACHE_CONTROL);
	}

}
