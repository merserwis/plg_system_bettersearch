<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Merserwis\Plugin\System\BetterSearch\Engine\Visitor;

/**
 * Under the list of addresses left out of the statistics: the address this administrator connects
 * from (as the site sees it) with a button that adds it to the list, and whether it is left out now.
 */
class BsmyipField extends FormField
{
    protected $type = 'Bsmyip';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $esc    = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $source = $this->form ? (string) $this->form->getValue('stats_ip_source', 'params', 'auto') : 'auto';
        $ip     = Visitor::ip($_SERVER, $source);
        $rules  = Visitor::rules($this->form ? (string) $this->form->getValue('stats_exclude_ips', 'params', '') : '');
        $target = $esc((string) ($this->element['target'] ?? 'stats_exclude_ips'));
        if ($ip === '') {
            return '<div class="bs-myip small text-muted">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_MYIP_UNKNOWN')) . '</div>';
        }
        $in = Visitor::matches($ip, $rules);

        return '<div class="bs-myip" data-target="' . $target . '" data-ip="' . $esc($ip) . '">'
            . '<span>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_MYIP_LABEL')) . ' <code>' . $esc($ip) . '</code></span> '
            . '<span class="badge ' . ($in ? 'bg-success' : 'bg-secondary') . ' bs-myip-state">' . $esc(Text::_($in ? 'PLG_SYSTEM_BETTERSEARCH_MYIP_EXCLUDED' : 'PLG_SYSTEM_BETTERSEARCH_MYIP_COUNTED')) . '</span> '
            . '<button type="button" class="btn btn-sm btn-outline-primary bs-myip-add"' . ($in ? ' hidden' : '') . ' data-done="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_MYIP_EXCLUDED')) . '">+ '
            . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_MYIP_ADD')) . '</button></div>';
    }
}
