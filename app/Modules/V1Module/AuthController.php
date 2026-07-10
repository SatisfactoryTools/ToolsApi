<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use greeny\SatisfactoryTools\Api\Model\Services\AuthException;
use greeny\SatisfactoryTools\Api\Model\Services\AuthService;
use greeny\SatisfactoryTools\Api\Schema\Responses\Auth\TokenResponse;
use Nette\Http\IResponse;

#[Path('/auth')]
class AuthController extends BaseV1Controller
{

	public function __construct(
		private readonly AuthService $authService,
	)
	{
	}

	#[Path('/register')]
	#[Method('POST')]
	public function register(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);

		$login = trim((string) ($body['login'] ?? ''));
		$email = trim((string) ($body['email'] ?? ''));
		$password = (string) ($body['password'] ?? '');

		if ($login === '' || $email === '' || $password === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Fields login, email and password are required']);
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Invalid email address']);
		}

		if (strlen($password) < 8) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Password must be at least 8 characters']);
		}

		try {
			$this->authService->register($login, $email, $password);
		} catch (AuthException $e) {
			return $response->withStatus(IResponse::S409_Conflict)
				->writeJsonBody(['error' => $e->getMessage()]);
		}

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(['message' => 'Account created successfully']);
	}

	#[Path('/login')]
	#[Method('POST')]
	public function login(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);

		$login = trim((string) ($body['login'] ?? ''));
		$password = (string) ($body['password'] ?? '');

		if ($login === '' || $password === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Fields login and password are required']);
		}

		try {
			$tokens = $this->authService->login($login, $password);
		} catch (AuthException) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Invalid credentials']);
		}

		return $response->writeJsonBody(TokenResponse::fromTokens($tokens)->toArray());
	}

	#[Path('/refresh')]
	#[Method('POST')]
	public function refresh(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);

		$refreshToken = trim((string) ($body['refreshToken'] ?? ''));

		if ($refreshToken === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field refreshToken is required']);
		}

		try {
			$tokens = $this->authService->refreshTokens($refreshToken);
		} catch (AuthException) {
			return $response->withStatus(IResponse::S401_Unauthorized)
				->writeJsonBody(['error' => 'Invalid or expired refresh token']);
		}

		return $response->writeJsonBody(TokenResponse::fromTokens($tokens)->toArray());
	}

	#[Path('/logout')]
	#[Method('POST')]
	public function logout(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);

		$refreshToken = trim((string) ($body['refreshToken'] ?? ''));

		if ($refreshToken !== '') {
			$this->authService->logout($refreshToken);
		}

		return $response->writeJsonBody(['message' => 'Logged out successfully']);
	}

	#[Path('/forgot-password')]
	#[Method('POST')]
	public function forgotPassword(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);

		$email = trim((string) ($body['email'] ?? ''));

		if ($email === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Field email is required']);
		}

		// Always return 200 to prevent email enumeration
		$this->authService->forgotPassword($email);

		return $response->writeJsonBody(['message' => 'If that email is registered, a reset link has been sent']);
	}

	#[Path('/reset-password')]
	#[Method('POST')]
	public function resetPassword(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$body = $this->parseBody($request);

		$token = trim((string) ($body['token'] ?? ''));
		$password = (string) ($body['password'] ?? '');

		if ($token === '' || $password === '') {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Fields token and password are required']);
		}

		if (strlen($password) < 8) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Password must be at least 8 characters']);
		}

		try {
			$this->authService->resetPassword($token, $password);
		} catch (AuthException) {
			return $response->withStatus(IResponse::S400_BadRequest)
				->writeJsonBody(['error' => 'Invalid or expired password reset token']);
		}

		return $response->writeJsonBody(['message' => 'Password updated successfully']);
	}

}
