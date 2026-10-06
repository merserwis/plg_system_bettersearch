<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Live preview column of the plugin settings: the live results or the results page rendered by the
 * plugin itself from the unsaved form values, in an iframe at the real width of a desktop, tablet or
 * phone. mode="live", "page" or "both" (switchable).
 */
class BspreviewField extends FormField
{
    protected $type = 'Bspreview';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $mode = (string) ($this->element['mode'] ?? 'live');
        $mode = in_array($mode, ['live', 'page', 'both'], true) ? $mode : 'live';
        $t    = fn (string $k) => htmlspecialchars(Text::_('PLG_SYSTEM_BETTERSEARCH_PREVIEW_' . $k), ENT_QUOTES, 'UTF-8');

        $devices = '';
        foreach (['desktop' => 'DESKTOP', 'tablet' => 'TABLET', 'mobile' => 'MOBILE'] as $device => $key) {
            $devices .= '<button type="button" class="btn btn-sm ' . ($device === 'desktop' ? 'btn-primary' : 'btn-outline-secondary')
                . '" data-bs-device="' . $device . '">' . $t($key) . '</button>';
        }
        $modes = '';
        if ($mode === 'both') {
            foreach (['live' => 'LIVE', 'page' => 'PAGE'] as $value => $key) {
                $modes .= '<button type="button" class="btn btn-sm ' . ($value === 'live' ? 'btn-primary' : 'btn-outline-secondary')
                    . '" data-bs-view="' . $value . '">' . $t($key) . '</button>';
            }
            $modes = '<div class="btn-group" role="group">' . $modes . '</div>';
        }

        return '<div class="bs-preview" data-mode="' . $mode . '">'
            . '<div class="bs-preview-head"><strong>' . $t('TITLE') . '</strong>' . $modes
            . '<div class="btn-group" role="group">' . $devices . '</div></div>'
            . '<div class="bs-preview-query"><input type="search" class="form-control form-control-sm" placeholder="' . $t('QUERY') . '" aria-label="' . $t('QUERY') . '">'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-replay title="' . $t('REPLAY_DESC') . '">↻ ' . $t('REPLAY') . '</button></div>'
            . '<div class="bs-preview-frame"><iframe title="' . $t('TITLE') . '" loading="lazy"></iframe></div>'
            . '<div class="bs-preview-status small text-muted" aria-live="polite"></div>'
            . '<div class="bs-preview-motion alert alert-warning small py-1 px-2 mt-2 mb-0" hidden>' . $t('REDUCED_MOTION') . '</div>'
            . '</div>';
    }
}
