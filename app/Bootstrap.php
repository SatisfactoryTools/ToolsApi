<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

class Bootstrap
{

	private Configurator $configurator;
	private string $rootDir;

	public function __construct()
	{
		$this->rootDir = dirname(__DIR__);
		$this->configurator = new Configurator;
		$this->configurator->setTempDirectory($this->rootDir . '/temp');
	}

	public function bootWebApplication(): Container
	{
		$this->initializeEnvironment();
		$this->setupContainer();
		return $this->configurator->createContainer();
	}

	private function initializeEnvironment(): void
	{
		$this->configurator->enableTracy($this->rootDir . '/log');

		$this->configurator->createRobotLoader()
			->addDirectory(__DIR__)
			->register();
	}

	private function setupContainer(): void
	{
		$configDir = $this->rootDir . '/config';
		$this->configurator->addConfig($configDir . '/common.neon');
		$this->configurator->addConfig($configDir . '/services.neon');
		$this->configurator->addConfig($configDir . '/local.neon');
	}

}
