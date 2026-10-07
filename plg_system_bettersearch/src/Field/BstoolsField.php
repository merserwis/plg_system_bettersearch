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

        if ((string) $this->element['mode'] === 'report') {
            return '<div class="bs-tools"><section class="bs-tools-card"><h3>' . $t('REPORT') . '</h3><p class="small text-muted">' . $t('REPORT_DESC') . '</p>'
                . '<div class="bs-report-status small"></div>'
                . '<fieldset class="bs-report-period"><legend>' . $t('REPORT_RANGE') . '</legend>'
                . '<div class="bs-report-dates"><label>' . $t('REPORT_FROM') . ' <input type="date" class="form-control form-control-sm" data-bs-from></label>'
                . '<label>' . $t('REPORT_DATE_TO') . ' <input type="date" class="form-control form-control-sm" data-bs-to></label>'
                . '<button type="button" class="btn btn-sm btn-link" data-bs-range="auto">↺ ' . $t('REPORT_AUTO') . '</button></div>'
                . '<div class="bs-report-presets">'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-range="7">' . $t('REPORT_LAST7') . '</button>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-range="30">' . $t('REPORT_LAST30') . '</button>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-range="prevmonth">' . $t('REPORT_PREV_MONTH') . '</button>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-range="month">' . $t('REPORT_THIS_MONTH') . '</button>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-range="year">' . $t('REPORT_THIS_YEAR') . '</button></div>'
                . '<p class="small text-muted mb-0 bs-report-hint">' . $t('REPORT_RANGE_HINT') . '</p></fieldset>'
                . '<p class="bs-tools-buttons"><button type="button" class="btn btn-secondary" data-bs-tool="report_preview">' . $t('REPORT_PREVIEW') . '</button> '
                . '<button type="button" class="btn btn-primary" data-bs-tool="report_send">' . $t('REPORT_SEND') . '</button></p>'
                . '<div class="bs-report-out"></div></section></div>';
        }

        if ((string) $this->element['mode'] === 'gsc') {
            return '<div class="bs-tools"><section class="bs-tools-card"><h3>' . $t('GSC') . '</h3><p class="small text-muted">' . $t('GSC_DESC') . '</p>'
                . '<div class="bs-gsc-status small"></div>'
                . '<p class="bs-tools-buttons"><button type="button" class="btn btn-primary" data-bs-tool="gsc_fetch">' . $t('GSC_FETCH') . '</button> '
                . '<label class="btn btn-secondary mb-0">' . $t('GSC_IMPORT') . '<input type="file" accept=".csv,text/csv" class="bs-gsc-file" hidden></label> '
                . '<button type="button" class="btn btn-secondary" data-bs-tool="gsc">' . $t('GSC_SHOW') . '</button> '
                . '<button type="button" class="btn btn-outline-secondary" data-bs-tool="gsc_check">' . $t('GSC_CHECK') . '</button></p>'
                . '<div class="bs-gsc-out"></div></section></div>';
        }

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
            . '<section class="bs-tools-card"><h3>' . $t('SETTINGS') . '</h3><p class="small text-muted">' . $t('SETTINGS_DESC') . '</p><p class="bs-tools-buttons">'
            . '<button type="button" class="btn btn-secondary" data-bs-settings="export">' . $t('SETTINGS_EXPORT') . '</button> '
            . '<label class="btn btn-secondary mb-0">' . $t('SETTINGS_IMPORT') . '<input type="file" accept=".json,application/json" class="bs-settings-file" hidden></label> '
            . '<button type="button" class="btn btn-outline-danger" data-bs-settings="reset">' . $t('SETTINGS_RESET') . '</button></p></section>'
            . '<section class="bs-tools-card"><h3>' . $t('CONV') . '</h3><p class="small text-muted">' . $t('CONV_DESC') . '</p><p class="bs-tools-buttons">'
            . '<select class="form-select bs-conv-days" style="max-width:10rem;display:inline-block"><option value="7">7</option><option value="30" selected>30</option><option value="90">90</option><option value="365">365</option></select> '
            . '<button type="button" class="btn btn-secondary" data-bs-tool="conversions">' . $t('CONV_LOAD') . '</button></p>'
            . '<div class="bs-conv-out"></div></section>'
            . '</div>';
    }
}
