<?php declare(strict_types = 1);

use Contributte\Middlewares\Application\IApplication;
use greeny\SatisfactoryTools\Api\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

$bootstrap = new Bootstrap;
$bootstrap->bootWebApplication()
	->getByType(IApplication::class)
	->run();
