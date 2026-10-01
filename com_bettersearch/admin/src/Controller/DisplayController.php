<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_bettersearch
 */

namespace Merserwis\Component\BetterSearch\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/**
 * The administrator menu entry "Better Search for Gridbox" opens the settings of the system plugin.
 */
class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        if (!$this->app->getIdentity()?->authorise('core.manage', 'com_plugins')) {
            $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php', false));

            return $this;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('bettersearch'));
        $pluginId = (int) $db->setQuery($query)->loadResult();

        $this->setRedirect(Route::_($pluginId > 0
            ? 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $pluginId
            : 'index.php?option=com_plugins&view=plugins&filter[folder]=system', false));

        return $this;
    }
}
