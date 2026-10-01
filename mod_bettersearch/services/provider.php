<?php

/**
 * @package     Merserwis.Module
 * @subpackage  mod_bettersearch
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ModuleDispatcherFactory('\\Merserwis\\Module\\BetterSearch'));
        $container->registerServiceProvider(new Module());
    }
};
