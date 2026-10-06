<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Merserwis\Plugin\System\BetterSearch\Render\Themes;

/**
 * Theme gallery (radio cards with a miniature of each theme) and the theme editor: a visual editor
 * of the theme tokens and a CSS code editor, both writing the hidden setting "theme_custom" (JSON).
 * The editor itself is built by media/js/theme.js from the configuration in data-config.
 */
class BsthemeField extends FormField
{
    protected $type = 'Bstheme';

    private const GROUPS = [
        'COLORS'  => ['accent', 'on_accent', 'bg', 'surface', 'text', 'muted', 'title', 'price', 'hover', 'border', 'img_bg', 'hl_bg', 'hl_color', 'input_bg', 'input_text'],
        'SHAPE'   => ['radius', 'card_radius', 'btn_radius', 'border_width'],
        'EFFECTS' => ['shadow', 'card_shadow', 'card_hover', 'blur'],
        'TYPE'    => ['font', 'title_weight', 'section_case', 'button_style'],
    ];

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        Admin::assets();
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $t     = fn (string $k) => Text::_('PLG_SYSTEM_BETTERSEARCH_' . $k);
        $value = in_array((string) $this->value, Themes::KEYS, true) ? (string) $this->value : 'default';

        // the default theme shows the colours set in the Live results tab
        $form    = fn (string $name, string $default) => (string) ($this->form ? $this->form->getValue($name, 'params', $default) : $default) ?: $default;
        $default = [
            'bg' => $form('live_bg', '#ffffff'), 'surface' => '', 'text' => $form('live_text', '#1f2328'), 'muted' => $form('live_muted', '#6b7280'),
            'accent' => $form('live_accent', '#1a73e8'), 'on_accent' => '#ffffff', 'hover' => $form('live_hover_bg', '#f3f4f6'),
            'border' => $form('live_border', '#e5e7eb'), 'img_bg' => $form('live_image_bg', '#ffffff'),
            'radius' => (int) $form('live_radius', '10'), 'border_width' => 1, 'shadow' => $form('live_shadow', 'strong'), 'font' => 'inherit', 'title_weight' => '600',
        ];

        $cards = '';
        foreach (Themes::KEYS as $key) {
            $id     = $this->id . '_' . $key;
            $name   = $t('THEME_' . strtoupper($key));
            $cards .= '<label class="bs-theme-card" for="' . $id . '" data-theme="' . $key . '">'
                . '<input type="radio" class="bs-theme-radio" name="' . $esc($this->name) . '" id="' . $id . '" value="' . $key . '"' . ($key === $value ? ' checked' : '') . '>'
                . '<span class="bs-theme-mock" aria-hidden="true"><span class="m-surface"><span class="m-input"><span class="m-q"></span></span><span class="m-panel">'
                . '<span class="m-sec"></span>'
                . '<span class="m-row is-active"><i class="m-img"></i><span class="m-lines"><b></b><i></i></span><em></em></span>'
                . '<span class="m-row"><i class="m-img"></i><span class="m-lines"><b></b><i></i></span><em></em></span>'
                . '<span class="m-row"><i class="m-img"></i><span class="m-lines"><b></b><i></i></span><em></em></span>'
                . '<span class="m-all"></span></span></span></span>'
                . '<span class="bs-theme-name">' . $esc($name) . ($key === 'default' ? ' <span class="badge bg-secondary">' . $esc($t('THEME_DEFAULT_BADGE')) . '</span>' : '')
                . '<span class="bs-theme-check" aria-hidden="true">✓</span></span>'
                . '<span class="bs-theme-desc">' . $esc($t('THEME_' . strtoupper($key) . '_DESC')) . '</span></label>';
        }

        $texts = [];
        foreach (['EDITOR_TITLE', 'EDITOR_DEFAULT', 'MODE_VISUAL', 'MODE_CSS', 'RESET', 'RESET_CONFIRM', 'BACK_DEFAULT', 'CHANGED', 'TOKEN_RESET', 'AS_SITE',
            'G_COLORS', 'G_SHAPE', 'G_EFFECTS', 'G_TYPE', 'CSS_HELP', 'CSS_INSERT', 'CSS_INSERT_CONFIRM', 'CSS_CLEAR', 'CSS_CLEAR_CONFIRM', 'CSS_BRACES',
            'CSS_OK', 'CSS_CHARS', 'CSS_ACTIVE', 'CSS_SELECTORS'] as $k) {
            $texts[$k] = $t('TE_' . $k);
        }
        foreach (array_keys(Themes::TOKENS) as $token) {
            $texts['T_' . $token] = $t('TE_T_' . strtoupper($token));
        }
        foreach (Themes::TOKENS as $kind) {
            if (is_array($kind)) {
                foreach ($kind as $option) {
                    $texts['O_' . $option] = $t('TE_O_' . strtoupper($option));
                }
            }
        }
        $names = [];
        foreach (Themes::KEYS as $key) {
            $names[$key] = $t('THEME_' . strtoupper($key));
        }
        $config = ['presets' => Themes::PRESETS + ['default' => $default], 'tokens' => Themes::TOKENS, 'groups' => self::GROUPS,
            'names' => $names, 'texts' => $texts];

        return '<div class="bs-themes" data-config="' . $esc(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
            . '<div class="bs-themes-head"><div><h3>' . $esc($t('THEME_LABEL')) . '</h3><p class="text-muted small mb-0">' . $esc($t('THEME_DESC')) . '</p></div>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary bs-theme-back"' . ($value === 'default' ? ' hidden' : '') . '>↺ ' . $esc($t('TE_BACK_DEFAULT')) . '</button></div>'
            . '<div class="bs-theme-grid" role="radiogroup" aria-label="' . $esc($t('THEME_LABEL')) . '">' . $cards . '</div>'
            . '<div class="bs-theme-editor"></div></div>';
    }
}
