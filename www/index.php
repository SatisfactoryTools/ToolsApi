<?php declare(strict_types = 1);

use Contributte\Middlewares\Application\IApplication;
use greeny\SatisfactoryTools\Api\Bootstrap;

// Deploys create maintenance.flag in the project root while the code, the cache and the database
// schema are being swapped, and remove it when the new version is ready. Checked before the
// autoloader, so it holds even while vendor/ is half-written. Mirrors the error shape of
// Apitte's SimpleErrorHandler and the headers of CorsMiddleware, so callers can read the reason.
if (is_file(__DIR__ . '/../maintenance.flag')) {
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, Authorization');
	header('Cache-Control: no-store');

	// Let preflights through, otherwise the browser hides the 503 behind a CORS error.
	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
		http_response_code(204);
		exit;
	}

	http_response_code(503);
	header('Content-Type: application/json');
	header('Retry-After: 30');
	echo json_encode([
		'status' => 'error',
		'code' => 503,
		'message' => 'The API is temporarily unavailable, a deployment is in progress. Please try again in a moment.',
	]);
	exit;
}

require __DIR__ . '/../vendor/autoload.php';

$bootstrap = new Bootstrap;
$bootstrap->bootWebApplication()
	->getByType(IApplication::class)
	->run();
