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
        $v  = fn (string $file) => \Merserwis\Plugin\System\BetterSearch\Extension\BetterSearch::VERSION . '-' . (int) @filemtime(JPATH_ROOT . '/' . $file);
        $wa->registerAndUseStyle('plg_system_bettersearch.admin', 'media/plg_system_bettersearch/css/admin.css', ['version' => $v('media/plg_system_bettersearch/css/admin.css')]);
        $wa->registerAndUseScript('plg_system_bettersearch.admin', 'media/plg_system_bettersearch/js/admin.js', ['version' => $v('media/plg_system_bettersearch/js/admin.js')], ['defer' => true]);
        $wa->registerAndUseScript('plg_system_bettersearch.preview', 'media/plg_system_bettersearch/js/preview.js', ['version' => $v('media/plg_system_bettersearch/js/preview.js')], ['defer' => true]);
        $wa->registerAndUseScript('plg_system_bettersearch.theme', 'media/plg_system_bettersearch/js/theme.js', ['version' => $v('media/plg_system_bettersearch/js/theme.js')], ['defer' => true]);

        $keys = ['PICKER_EMPTY', 'PICKER_NONE', 'PICKER_REMOVE', 'PICKER_UP', 'PICKER_DOWN', 'PICKER_UNPUBLISHED', 'TOOLS_ITEMS', 'TOOLS_PAGES',
            'TOOLS_PENDING', 'TOOLS_CHECKED', 'TOOLS_COMPLETE', 'TOOLS_APPS', 'TOOLS_CONFIG_CHANGED', 'TOOLS_DONE', 'TOOLS_REBUILD_CONFIRM',
            'TOOLS_WORKING', 'TOOLS_RESULTS', 'TOOLS_MODE', 'TOOLS_GROUPS', 'TOOLS_SCORE', 'TOOLS_WHY', 'TOOLS_TOP', 'TOOLS_ZERO', 'TOOLS_RECENT',
            'TOOLS_SEARCHES', 'TOOLS_RESULTS_COL', 'TOOLS_LAST', 'TOOLS_CLEAR_CONFIRM', 'TOOLS_SAVE_FIRST', 'TOOLS_NO_DATA', 'TOOLS_PINNED',
            'TOOLS_CORRECTED', 'TOOLS_THUMBS_REMOVED', 'TOOLS_CACHE_CLEARED', 'PREVIEW_UPDATING', 'PREVIEW_COUNT',
            'TOOLS_REDIRECTED', 'TOOLS_ZERO_HELP', 'TOOLS_CONV_TOTAL', 'TOOLS_CONV_QUERY', 'TOOLS_CONV_CLICKS', 'TOOLS_CONV_CTR', 'TOOLS_CONV_CARTS', 'TOOLS_CONV_CART_RATE', 'TOOLS_CONV_PRODUCTS', 'TOOLS_ADD_SYNONYM', 'TOOLS_ADD_REDIRECT', 'TOOLS_SYN_PROMPT', 'TOOLS_RED_PROMPT', 'HELP', 'TOOLS_SETTINGS_EXPORTED', 'TOOLS_SETTINGS_RESET_CONFIRM', 'TOOLS_SETTINGS_TOO_BIG', 'TOOLS_SETTINGS_IMPORT_CONFIRM', 'TOOLS_SETTINGS_IMPORTED', 'TOOLS_DICT_ADDED', 'TOOLS_DICT_WORDS', 'TOOLS_DICT_ONEWAY', 'TOOLS_DICT_ALSO', 'TOOLS_DICT_PHRASES', 'TOOLS_DICT_URL', 'TOOLS_DICT_LABEL', 'TOOLS_DICT_FILTER', 'TOOLS_DICT_ADD', 'TOOLS_DICT_TEXT', 'TOOLS_DICT_TABLE', 'TOOLS_DICT_COUNT', 'TOOLS_GSC_LAST', 'TOOLS_GSC_NONE', 'TOOLS_GSC_QUERY', 'TOOLS_GSC_IMPR', 'TOOLS_GSC_POS', 'TOOLS_GSC_FOUND',
            'TOOLS_GSC_CTR', 'TOOLS_GSC_FILTER', 'TOOLS_GSC_SHOW_ROWS', 'TOOLS_GSC_COUNT', 'TOOLS_PARAMS', 'TOOLS_FEATURED', 'TOOLS_REPORT_TO', 'TOOLS_REPORT_NO_TO',
            'TOOLS_REPORT_PERIOD', 'TOOLS_REPORT_CONFIRM', 'TOOLS_ERRORS', 'TOOLS_G_APP', 'TOOLS_G_TOTAL', 'TOOLS_G_UNPUBLISHED', 'TOOLS_G_DATES', 'TOOLS_G_LANGUAGE', 'TOOLS_G_ACCESS', 'TOOLS_G_INDEXED'];
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
