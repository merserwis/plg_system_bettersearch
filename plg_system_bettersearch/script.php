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
    protected $minimumJoomla = '6.0.0';

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
            $this->fulltextIndex($db);
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

    /**
     * The full-text index on the descriptions, added apart from the table: a server that cannot
     * create it keeps a working (slower) search instead of no index table at all.
     */
    private function fulltextIndex(DatabaseInterface $db): void
    {
        try {
            $has = $db->setQuery('SHOW INDEX FROM ' . $db->quoteName('#__bettersearch_items') . ' WHERE Key_name = ' . $db->quote('ft_body'))->loadRow();
            if (!$has) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName('#__bettersearch_items') . ' ADD FULLTEXT KEY ' . $db->quoteName('ft_body') . ' (' . $db->quoteName('t_body') . ')')->execute();
            }
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_BETTERSEARCH_INSTALL_NO_FULLTEXT'), 'notice');
        }
    }
}
