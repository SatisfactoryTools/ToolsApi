<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\UserSettings;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserSettingsRepository;
use Nette\Http\IResponse;

#[Path('/settings')]
class SettingsController extends BaseV1Controller
{

	public function __construct(
		private readonly UserSettingsRepository $settingsRepository,
	)
	{
	}

	#[Path('/')]
	#[Method('GET')]
	public function get(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$settings = $this->settingsRepository->getByUser($user);

		if ($settings === null) {
			return $response->writeJsonBody(['data' => '{}', 'revision' => 0]);
		}

		return $response->writeJsonBody(['data' => $settings->data, 'revision' => $settings->revision]);
	}

	#[Path('/')]
	#[Method('PUT')]
	public function update(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		if ($user === null) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Unauthorized']);
		}

		$body = $this->parseBody($request);

		if (!isset($body['revision'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field revision is required']);
		}

		if (!isset($body['data'])) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field data is required']);
		}

		$clientRevision = (int) $body['revision'];
		$settings = $this->settingsRepository->getByUser($user);

		if ($settings === null) {
			if ($clientRevision !== 0) {
				return $response->withStatus(IResponse::S409_Conflict)
					->writeJsonBody([
						'error' => 'Conflict',
						'message' => 'Settings were saved by another session. Reload before saving.',
						'currentRevision' => 0,
					]);
			}

			$settings = new UserSettings();
			$settings->user = $user;
			$settings->data = (string) $body['data'];
			$settings->revision = 1;

			$this->settingsRepository->save($settings);

			return $response->writeJsonBody(['data' => $settings->data, 'revision' => $settings->revision]);
		}

		if ($clientRevision !== $settings->revision) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody([
					'error' => 'Conflict',
					'message' => 'Settings were saved by another session. Reload before saving.',
					'currentRevision' => $settings->revision,
				]);
		}

		$settings->data = (string) $body['data'];
		$settings->revision++;

		$this->settingsRepository->save($settings);

		return $response->writeJsonBody(['data' => $settings->data, 'revision' => $settings->revision]);
	}

}
