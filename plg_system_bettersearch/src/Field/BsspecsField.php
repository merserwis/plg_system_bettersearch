<?php

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/** The preview of the technical-data filters: what they read from the fields, before they are switched on. */
class BsspecsField extends FormField
{
    protected $type = 'Bsspecs';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $texts = [];
        foreach (['RUN', 'WORKING', 'SUMMARY', 'PARTIAL', 'PRODUCTS', 'OFF', 'NONE', 'THRESHOLD', 'COUNT', 'SAMPLES', 'PRODUCT', 'VALUE', 'SOURCE'] as $key) {
            $texts[strtolower($key)] = Text::_('PLG_SYSTEM_BETTERSEARCH_SPEC_PREVIEW_' . $key);
        }

        return '<div class="bs-specs" data-texts="' . $esc(json_encode($texts, JSON_UNESCAPED_UNICODE)) . '">'
            . '<p class="small text-muted mb-2">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_SPEC_PREVIEW_HINT')) . '</p>'
            . '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-specs-run>' . $esc($texts['run']) . '</button>'
            . '<div class="bs-specs-status small text-muted mt-2" aria-live="polite"></div><div class="bs-specs-out"></div></div>';
    }
}
