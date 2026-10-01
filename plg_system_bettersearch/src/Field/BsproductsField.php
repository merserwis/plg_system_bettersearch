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
 * Product picker: search by name, code or id; the chosen products are listed in their order and
 * can be dragged (or moved with the arrows) — that order is the manual order of pinned results.
 * Stored as a comma-separated list of ids.
 */
class BsproductsField extends FormField
{
    protected $type = 'Bsproducts';

    protected function getInput()
    {
        Admin::assets();
        $multiple = (string) $this->element['multiple'] !== 'false';
        $value    = is_array($this->value) ? implode(',', $this->value) : (string) $this->value;
        $value    = implode(',', array_filter(array_map('intval', explode(',', $value))));
        $esc      = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return '<div class="bs-picker" data-multiple="' . ($multiple ? '1' : '0') . '">'
            . '<input type="hidden" name="' . $esc($this->name) . '" id="' . $esc($this->id) . '" value="' . $esc($value) . '" class="bs-picker-value">'
            . '<ol class="bs-picker-list"></ol>'
            . '<div class="bs-picker-search"><input type="search" class="form-control bs-picker-input" autocomplete="off" placeholder="'
            . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_PICKER_PLACEHOLDER')) . '"><ul class="bs-picker-found" hidden></ul></div>'
            . '</div>';
    }
}
