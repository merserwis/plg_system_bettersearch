<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

class PlgSystemBettersearchInstallerScript extends InstallerScript
{
    protected $minimumPhp    = '8.2.0';
    protected $minimumJoomla = '5.0.0';

    /**
     * A fresh install enables the plugin; an update keeps whatever the administrator chose.
     */
    public function postflight(string $type, InstallerAdapter $parent): void
    {
        if ($type === 'uninstall') {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            Factory::getApplication()->getLanguage()->load('plg_system_bettersearch', JPATH_ADMINISTRATOR)
                || Factory::getApplication()->getLanguage()->load('plg_system_bettersearch', JPATH_PLUGINS . '/system/bettersearch');

            $gridbox = (int) $db->setQuery(
                $db->createQuery()
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_gridbox'))
            )->loadResult();

            if ($type === 'install' || $type === 'discover_install') {
                $db->setQuery(
                    $db->createQuery()
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('enabled') . ' = 1')
                        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                        ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('bettersearch'))
                )->execute();
                if ($gridbox) {
                    Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_BETTERSEARCH_INSTALL_BUILD_INDEX'), 'info');
                }
            }

            if (!$gridbox) {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_BETTERSEARCH_INSTALL_NO_GRIDBOX'), 'warning');
            }
        } catch (\Throwable $e) {
        }
    }
}
