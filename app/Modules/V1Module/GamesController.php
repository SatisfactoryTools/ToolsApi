<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Entities\UserGames;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserGamesRepository;
use Nette\Http\IResponse;

#[Path('/games')]
class GamesController extends BaseV1Controller
{

	public function __construct(
		private readonly UserGamesRepository $gamesRepository,
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

		$games = $this->gamesRepository->getByUser($user);

		if ($games === null) {
			return $response->writeJsonBody(['data' => '{}', 'revision' => 0]);
		}

		return $response->writeJsonBody(['data' => $games->data, 'revision' => $games->revision]);
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
		$games = $this->gamesRepository->getByUser($user);

		if ($games === null) {
			if ($clientRevision !== 0) {
				return $response->withStatus(IResponse::S409_Conflict)
					->writeJsonBody([
						'error' => 'Conflict',
						'message' => 'Games were saved by another session. Reload before saving.',
						'currentRevision' => 0,
					]);
			}

			$games = new UserGames();
			$games->user = $user;
			$games->data = (string) $body['data'];
			$games->revision = 1;

			$this->gamesRepository->save($games);

			return $response->writeJsonBody(['data' => $games->data, 'revision' => $games->revision]);
		}

		if ($clientRevision !== $games->revision) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody([
					'error' => 'Conflict',
					'message' => 'Games were saved by another session. Reload before saving.',
					'currentRevision' => $games->revision,
				]);
		}

		$games->data = (string) $body['data'];
		$games->revision++;

		$this->gamesRepository->save($games);

		return $response->writeJsonBody(['data' => $games->data, 'revision' => $games->revision]);
	}

}
