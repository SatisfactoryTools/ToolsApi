<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Services\Meta\MetaResolver;

#[Path('/meta')]
class MetaController extends BaseV1Controller
{

	private const string CACHE_CONTROL = 'private, max-age=300';

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
