<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/** Script, styles and settings of the administrator tools (loaded once per page). */
final class Admin
{
    private static bool $done = false;

    public static function assets(): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $v  = fn (string $file) => '1.3.1-' . (int) @filemtime(JPATH_ROOT . '/' . $file);
        $wa->registerAndUseStyle('plg_system_bettersearch.admin', 'media/plg_system_bettersearch/css/admin.css', ['version' => $v('media/plg_system_bettersearch/css/admin.css')]);
        $wa->registerAndUseScript('plg_system_bettersearch.admin', 'media/plg_system_bettersearch/js/admin.js', ['version' => $v('media/plg_system_bettersearch/js/admin.js')], ['defer' => true]);
        $wa->registerAndUseScript('plg_system_bettersearch.preview', 'media/plg_system_bettersearch/js/preview.js', ['version' => $v('media/plg_system_bettersearch/js/preview.js')], ['defer' => true]);

        $keys = ['PICKER_EMPTY', 'PICKER_NONE', 'PICKER_REMOVE', 'PICKER_UP', 'PICKER_DOWN', 'PICKER_UNPUBLISHED', 'TOOLS_ITEMS', 'TOOLS_PAGES',
            'TOOLS_PENDING', 'TOOLS_CHECKED', 'TOOLS_COMPLETE', 'TOOLS_APPS', 'TOOLS_CONFIG_CHANGED', 'TOOLS_DONE', 'TOOLS_REBUILD_CONFIRM',
            'TOOLS_WORKING', 'TOOLS_RESULTS', 'TOOLS_MODE', 'TOOLS_GROUPS', 'TOOLS_SCORE', 'TOOLS_WHY', 'TOOLS_TOP', 'TOOLS_ZERO', 'TOOLS_RECENT',
            'TOOLS_SEARCHES', 'TOOLS_RESULTS_COL', 'TOOLS_LAST', 'TOOLS_CLEAR_CONFIRM', 'TOOLS_SAVE_FIRST', 'TOOLS_NO_DATA', 'TOOLS_PINNED',
            'TOOLS_CORRECTED', 'TOOLS_THUMBS_REMOVED', 'TOOLS_CACHE_CLEARED', 'PREVIEW_UPDATING', 'PREVIEW_COUNT'];
        $texts = [];
        foreach ($keys as $k) {
            $texts[$k] = Text::_('PLG_SYSTEM_BETTERSEARCH_' . $k);
        }
        Factory::getApplication()->getDocument()->addScriptOptions('plg_system_bettersearch', [
            'ajax'  => Uri::base(true) . '/index.php?option=com_ajax&plugin=bettersearch&group=system&format=json&' . Session::getFormToken() . '=1',
            'texts' => $texts,
        ]);
    }
}
