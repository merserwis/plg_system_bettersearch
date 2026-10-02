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
    /**
     * Columns and indexes of earlier versions, added only when missing. (Joomla runs every update
     * script again when the schema record of an extension is missing; ALTER TABLE ADD would then
     * fail on an existing column and stop the whole update.)
     */
    private function upgradeColumns($db): void
    {
        try {
            $items = $db->replacePrefix('#__bettersearch_items');
            $log   = $db->replacePrefix('#__bettersearch_log');
            $have  = array_map('strval', $db->getTableList());
            if (in_array($items, $have, true) && !isset($db->getTableColumns('#__bettersearch_items')['pcrc'])) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName('#__bettersearch_items') . ' ADD COLUMN ' . $db->quoteName('pcrc') . ' int unsigned NOT NULL DEFAULT 0')->execute();
            }
            if (in_array($log, $have, true)) {
                $keys = array_map(fn ($k) => (string) $k->Key_name, $db->setQuery('SHOW INDEX FROM ' . $db->quoteName('#__bettersearch_log'))->loadObjectList() ?: []);
                if (!in_array('idx_last', $keys, true)) {
                    $db->setQuery('ALTER TABLE ' . $db->quoteName('#__bettersearch_log') . ' ADD KEY ' . $db->quoteName('idx_last') . ' (' . $db->quoteName('last_at') . ')')->execute();
                }
            }
        } catch (\Throwable $e) {
        }
    }

    public function postflight(string $type, InstallerAdapter $parent): void
    {
        if ($type === 'uninstall') {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            // every table of the install script, also on updates: a site whose schema record was
            // missing (Joomla then skips the update scripts) still gets the tables of new versions
            $sql = $parent->getParent()->getPath('source') . '/sql/install.mysql.utf8.sql';
            foreach (is_file($sql) ? $db->splitSql((string) file_get_contents($sql)) : [] as $statement) {
                if (stripos(trim($statement), 'CREATE TABLE IF NOT EXISTS') === 0) {
                    try {
                        $db->setQuery($statement)->execute();
                    } catch (\Throwable $e) {
                    }
                }
            }
            $this->upgradeColumns($db);
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
