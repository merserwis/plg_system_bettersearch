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
 * Switch between the basic and the advanced settings. Fields marked parentclass="bs-adv" are shown
 * only in the advanced mode; tabs left without any field are hidden. The script moves the switch
 * above the tabs. The choice is saved with the settings.
 */
class BsmodeField extends FormField
{
    protected $type = 'Bsmode';

    protected function getInput()
    {
        Admin::assets();
        $value = $this->value === 'advanced' ? 'advanced' : 'basic';
        $esc   = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html  = '<div class="bs-mode" role="radiogroup" aria-label="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_MODE_LABEL')) . '">'
            . '<span class="bs-mode-label">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_MODE_LABEL')) . '</span><span class="bs-mode-switch">';
        foreach (['basic' => 'BASIC', 'advanced' => 'ADVANCED'] as $mode => $key) {
            $id    = $this->id . '_' . $mode;
            $html .= '<input type="radio" class="btn-check" name="' . $esc($this->name) . '" id="' . $id . '" value="' . $mode . '"' . ($mode === $value ? ' checked' : '') . '>'
                . '<label class="bs-mode-btn" for="' . $id . '">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_MODE_' . $key)) . '</label>';
        }

        return $html . '</span><span class="bs-mode-hint">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_MODE_HINT')) . '</span></div>';
    }
}
