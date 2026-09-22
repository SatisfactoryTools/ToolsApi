<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use greeny\SatisfactoryTools\Api\Model\Services\PlanLinkService;
use Nette\Http\IResponse;

/**
 * The plan behind a plan URL, for anyone holding that URL. Separate from the
 * version-scoped, owner-only PlansController: this one is public and read-only, and the
 * plan UUID alone addresses it — the frontend's planner URL carries nothing else.
 */
#[Path('/plans')]
class PublicPlansController extends BaseV1Controller
{

	public function __construct(private readonly PlanLinkService $planLinkService)
	{
	}

	/**
	 * Loads a plan (and its subplans) live for read-only viewing. No authentication: the
	 * UUID is the capability. 404 covers both "no such plan" and "the owner turned link
	 * access off", so the endpoint never confirms that a private plan exists.
	 */
	#[Path('/{uuid}')]
	#[Method('GET')]
	#[RequestParameter(name: 'uuid', type: 'string', in: EndpointParameter::IN_PATH)]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$plan = $this->planLinkService->present((string) $request->getParameter('uuid'));
		if ($plan === null) {
			return $response->withStatus(IResponse::S404_NotFound)
				->writeJsonBody(['error' => 'Plan not found']);
		}

		return $response->writeJsonBody($plan);
	}

}
