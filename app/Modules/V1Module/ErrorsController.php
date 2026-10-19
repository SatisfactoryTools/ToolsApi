<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Services\Errors\ClientErrorService;
use Nette\Http\IResponse;

/** Errors the frontend caught (AppErrorHandler), so they are seen without waiting for someone to report them. */
#[Path('/errors')]
class ErrorsController extends BaseV1Controller
{

	private const int MessageLength = 1000;

	private const int DetailsLength = 30000;

	public function __construct(
		private readonly ClientErrorService $clientErrorService,
	)
	{
	}

	#[Path('/')]
	#[Method('POST')]
	public function create(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);
		$message = $this->text($body['message'] ?? null, self::MessageLength);
		if ($message === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Missing message']);
		}

		$this->clientErrorService->record(
			$message,
			$this->text($body['details'] ?? null, self::DetailsLength),
			$this->text($body['app'] ?? null, 100),
			$this->text($body['build'] ?? null, 100),
		);

		return $response->withStatus(IResponse::S204_NoContent);
	}

	private function text(mixed $value, int $length): string
	{
		return is_string($value) ? mb_substr(trim($value), 0, $length) : '';
	}

}
