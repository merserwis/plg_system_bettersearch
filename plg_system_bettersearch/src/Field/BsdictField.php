<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;

/**
 * Table editor of a dictionary kept in a text setting: synonyms ("a, b, c" / "a => b, c") or
 * redirects ("phrase, phrase => /address | Label"). The text field stays the stored value (and can
 * still be edited as text); the table is written back into it on every change.
 */
class BsdictField extends FormField
{
    protected $type = 'Bsdict';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $mode   = (string) $this->element['mode'] === 'redirects' ? 'redirects' : 'synonyms';
        $target = preg_replace('/[^a-z_]/', '', (string) $this->element['target']);

        return '<div class="bs-dict" data-bs-dict="' . $mode . '" data-target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></div>';
    }
}
