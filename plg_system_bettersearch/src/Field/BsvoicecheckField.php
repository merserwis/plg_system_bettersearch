<?php

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Whether the site lets its pages use the microphone: the script asks for the home page as a guest
 * and reads its Permissions-Policy header (the one visitors get, also through Cloudflare).
 */
class BsvoicecheckField extends FormField
{
    protected $type = 'Bsvoicecheck';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $texts = [];
        foreach (['CHECKING', 'OK', 'BLOCKED', 'BLOCKED_HINT', 'FAILED'] as $key) {
            $texts[strtolower($key)] = Text::_('PLG_SYSTEM_BETTERSEARCH_VOICE_CHECK_' . $key);
        }

        return '<div class="bs-voicecheck" data-url="' . $esc(Uri::root()) . '" data-texts="' . $esc(json_encode($texts, JSON_UNESCAPED_UNICODE)) . '">'
            . '<span class="text-muted small">' . $esc($texts['checking']) . '</span></div>';
    }
}
