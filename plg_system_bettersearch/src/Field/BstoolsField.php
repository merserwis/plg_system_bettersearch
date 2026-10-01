<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/** Index status and rebuild, test console with ranking explanation, search statistics. */
class BstoolsField extends FormField
{
    protected $type = 'Bstools';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $t = fn ($k) => htmlspecialchars(Text::_('PLG_SYSTEM_BETTERSEARCH_TOOLS_' . $k), ENT_QUOTES, 'UTF-8');

        return '<div class="bs-tools">'
            . '<section class="bs-tools-card"><h3>' . $t('INDEX') . '</h3><div class="bs-status">…</div>'
            . '<div class="bs-progress" hidden><div class="bs-progress-bar"></div></div>'
            . '<p class="bs-tools-buttons"><button type="button" class="btn btn-primary" data-bs-tool="sync">' . $t('SYNC') . '</button> '
            . '<button type="button" class="btn btn-warning" data-bs-tool="rebuild">' . $t('REBUILD') . '</button> '
            . '<button type="button" class="btn btn-secondary" data-bs-tool="clearcache">' . $t('CLEAR_CACHE') . '</button> '
            . '<button type="button" class="btn btn-secondary" data-bs-tool="clearthumbs">' . $t('CLEAR_THUMBS') . '</button></p>'
            . '<p class="small text-muted">' . $t('INDEX_DESC') . '</p></section>'
            . '<section class="bs-tools-card"><h3>' . $t('TEST') . '</h3><p class="small text-muted">' . $t('TEST_DESC') . '</p>'
            . '<div class="input-group"><input type="search" class="form-control bs-test-q" placeholder="MI 3155">'
            . '<select class="form-select bs-test-sort" style="max-width:12rem"><option value="relevance">relevance</option><option value="title">title</option>'
            . '<option value="price">price</option><option value="price_desc">price desc</option><option value="newest">newest</option><option value="popular">popular</option></select>'
            . '<button type="button" class="btn btn-primary" data-bs-tool="test">' . $t('RUN') . '</button></div>'
            . '<div class="bs-test-out"></div></section>'
            . '<section class="bs-tools-card"><h3>' . $t('STATS') . '</h3><p class="bs-tools-buttons">'
            . '<button type="button" class="btn btn-secondary" data-bs-tool="stats">' . $t('STATS_LOAD') . '</button> '
            . '<button type="button" class="btn btn-outline-danger" data-bs-tool="clearlog">' . $t('STATS_CLEAR') . '</button></p>'
            . '<div class="bs-stats-out"></div></section>'
            . '</div>';
    }
}
