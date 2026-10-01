<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Merserwis\Plugin\System\BetterSearch\Extension\BetterSearch;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(BetterSearch::class, function (Container $container) {
                $plugin = new BetterSearch((array) PluginHelper::getPlugin('system', 'bettersearch'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
