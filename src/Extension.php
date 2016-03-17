<?php
/**
 * Class AccountExtension
 *
 */
namespace Argo22\Modules\Core\Api;

use Nette\DI\CompilerExtension;

class Extension extends CompilerExtension
{
	/**
	 * Load configuration for given module
	 *
	 * TODO: this is common functionality that should be moved to own class,
	 * probably in the Core module
	 *
	 * @return void
	 */
	public function loadConfiguration()
	{
		$config = $this->loadFromFile(__DIR__ . '/config.neon');

		// we have to load parameters here, before we parse services
		// since some service may need parameters already
		$builder = $this->getContainerBuilder();
		if (isset($config['parameters'])) {
			$builder->parameters = \Nette\DI\Config\Helpers::merge(
				$builder->parameters,
				$builder->expand($config['parameters'])
			);
		}

		$this->compiler->parseServices($this->getContainerBuilder(), $config);

		$this->setConfig($config);
	}
}
