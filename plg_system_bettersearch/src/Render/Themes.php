<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Look themes of the live results and of the results page. "default" is the plugin's own look,
 * driven by the colour and shape settings of the Live results and Results page tabs (unchanged
 * from earlier versions). Every other theme is a set of design tokens (colours, corners, shadows,
 * type, buttons, effects) that replaces those settings; the administrator may adjust each token in
 * the visual editor and add own CSS. Adjustments are kept per theme, so trying another theme and
 * coming back loses nothing.
 *
 * Stored in two settings: "theme" (the key) and "theme_custom" (JSON
 * {"<theme>": {"vars": {token: value}, "css": "…"}}).
 */

namespace Merserwis\Plugin\System\BetterSearch\Render;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Themes
{
    public const KEYS = ['default', 'clean', 'soft', 'glass', 'dark', 'bold'];

    /** Token => kind (color | int:min:max | enum list). Order = order of the editor. */
    public const TOKENS = [
        'bg'           => 'color',
        'surface'      => 'color',
        'text'         => 'color',
        'muted'        => 'color',
        'accent'       => 'color',
        'on_accent'    => 'color',
        'hover'        => 'color',
        'border'       => 'color',
        'title'        => 'color',
        'price'        => 'color',
        'img_bg'       => 'color',
        'hl_bg'        => 'color',
        'hl_color'     => 'color',
        'input_bg'     => 'color',
        'input_text'   => 'color',
        'radius'       => 'int:0:40',
        'card_radius'  => 'int:0:40',
        'btn_radius'   => 'int:0:40',
        'border_width' => 'int:0:4',
        'shadow'       => ['none', 'soft', 'strong', 'float', 'hard', 'glow'],
        'card_shadow'  => ['none', 'soft', 'strong', 'float', 'hard', 'glow'],
        'card_hover'   => ['none', 'lift', 'shadow', 'zoom', 'border', 'glow', 'shift'],
        'font'         => ['inherit', 'system', 'rounded', 'geometric', 'serif', 'mono'],
        'title_weight' => ['400', '500', '600', '700', '800'],
        'section_case' => ['upper', 'normal'],
        'button_style' => ['solid', 'outline', 'tint'],
        'blur'         => 'int:0:40',
    ];

    public const FONTS = [
        'inherit'   => 'inherit',
        'system'    => 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
        'rounded'   => 'ui-rounded,"SF Pro Rounded",Nunito,"Varela Round","Segoe UI",system-ui,sans-serif',
        'geometric' => '"Avenir Next",Avenir,Montserrat,"Century Gothic",Futura,system-ui,sans-serif',
        'serif'     => 'Georgia,"Iowan Old Style","Palatino Linotype","Times New Roman",serif',
        'mono'      => 'ui-monospace,"SF Mono","Cascadia Code",Menlo,Consolas,monospace',
    ];

    /** Presets. Empty colour = the default of the plugin ("as the site"). */
    public const PRESETS = [
        // clean lines, hairline borders, dark accent, outlined buttons
        'clean' => [
            'bg' => '#ffffff', 'surface' => '', 'text' => '#111827', 'muted' => '#6b7280', 'accent' => '#111827', 'on_accent' => '#ffffff',
            'hover' => '#f6f7f9', 'border' => '#e5e7eb', 'title' => '#111827', 'price' => '#111827', 'img_bg' => '#ffffff',
            'hl_bg' => '', 'hl_color' => '#111827', 'input_bg' => '#ffffff', 'input_text' => '#111827',
            'radius' => 8, 'card_radius' => 6, 'btn_radius' => 4, 'border_width' => 1,
            'shadow' => 'soft', 'card_shadow' => 'none', 'card_hover' => 'border',
            'font' => 'inherit', 'title_weight' => '500', 'section_case' => 'upper', 'button_style' => 'outline', 'blur' => 0,
        ],
        // large rounded corners, airy shadows, pill buttons, indigo
        'soft' => [
            'bg' => '#ffffff', 'surface' => '', 'text' => '#1e293b', 'muted' => '#64748b', 'accent' => '#6366f1', 'on_accent' => '#ffffff',
            'hover' => '#f1f5f9', 'border' => '#eef2f7', 'title' => '#1e293b', 'price' => '#4338ca', 'img_bg' => '#f8fafc',
            'hl_bg' => '#eef2ff', 'hl_color' => '#4338ca', 'input_bg' => '#f8fafc', 'input_text' => '#1e293b',
            'radius' => 18, 'card_radius' => 18, 'btn_radius' => 40, 'border_width' => 1,
            'shadow' => 'float', 'card_shadow' => 'soft', 'card_hover' => 'lift',
            'font' => 'rounded', 'title_weight' => '600', 'section_case' => 'normal', 'button_style' => 'tint', 'blur' => 0,
        ],
        // frosted translucent panel over the page
        'glass' => [
            'bg' => 'rgba(255,255,255,.72)', 'surface' => '#f4f6ff', 'text' => '#0f172a', 'muted' => '#475569', 'accent' => '#2563eb', 'on_accent' => '#ffffff',
            'hover' => 'rgba(37,99,235,.08)', 'border' => 'rgba(148,163,184,.35)', 'title' => '#0f172a', 'price' => '#1d4ed8',
            'img_bg' => 'rgba(255,255,255,.65)', 'hl_bg' => 'rgba(37,99,235,.12)', 'hl_color' => '#1d4ed8',
            'input_bg' => 'rgba(255,255,255,.85)', 'input_text' => '#0f172a',
            'radius' => 20, 'card_radius' => 20, 'btn_radius' => 12, 'border_width' => 1,
            'shadow' => 'float', 'card_shadow' => 'float', 'card_hover' => 'lift',
            'font' => 'system', 'title_weight' => '600', 'section_case' => 'upper', 'button_style' => 'solid', 'blur' => 18,
        ],
        // dark surfaces, sky-blue accent
        'dark' => [
            'bg' => '#0f172a', 'surface' => '#020617', 'text' => '#e2e8f0', 'muted' => '#94a3b8', 'accent' => '#38bdf8', 'on_accent' => '#0f172a',
            'hover' => '#1e293b', 'border' => '#1e293b', 'title' => '#f8fafc', 'price' => '#7dd3fc', 'img_bg' => '#f8fafc',
            'hl_bg' => 'rgba(56,189,248,.18)', 'hl_color' => '#7dd3fc', 'input_bg' => '#1e293b', 'input_text' => '#f1f5f9',
            'radius' => 14, 'card_radius' => 14, 'btn_radius' => 10, 'border_width' => 1,
            'shadow' => 'strong', 'card_shadow' => 'soft', 'card_hover' => 'glow',
            'font' => 'system', 'title_weight' => '600', 'section_case' => 'upper', 'button_style' => 'solid', 'blur' => 0,
        ],
        // thick black outlines, hard offset shadows, bright orange (neo-brutalism)
        'bold' => [
            'bg' => '#ffffff', 'surface' => '', 'text' => '#111111', 'muted' => '#525252', 'accent' => '#ff4f00', 'on_accent' => '#ffffff',
            'hover' => '#fff1e8', 'border' => '#111111', 'title' => '#111111', 'price' => '#111111', 'img_bg' => '#ffffff',
            'hl_bg' => '#ffe14d', 'hl_color' => '#111111', 'input_bg' => '#ffffff', 'input_text' => '#111111',
            'radius' => 0, 'card_radius' => 0, 'btn_radius' => 0, 'border_width' => 2,
            'shadow' => 'hard', 'card_shadow' => 'hard', 'card_hover' => 'shift',
            'font' => 'geometric', 'title_weight' => '800', 'section_case' => 'upper', 'button_style' => 'solid', 'blur' => 0,
        ],
    ];

    /** The theme key in the settings (unknown = default). */
    public static function key(Registry $params): string
    {
        $key = (string) $params->get('theme', 'default');

        return in_array($key, self::KEYS, true) ? $key : 'default';
    }

    /** @return array<string, array{vars?: array, css?: string}> */
    private static function custom(Registry $params): array
    {
        $raw = $params->get('theme_custom', '');
        if (is_object($raw)) {
            $raw = json_decode(json_encode($raw), true);
        } elseif (is_string($raw)) {
            $raw = $raw !== '' ? json_decode($raw, true) : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /** Tokens of the active theme (preset + the administrator's changes, validated); null for default. */
    public static function tokens(Registry $params): ?array
    {
        $key = self::key($params);
        if ($key === 'default') {
            return null;
        }
        $vars   = (array) (self::custom($params)[$key]['vars'] ?? []);
        $tokens = self::PRESETS[$key];
        foreach (self::TOKENS as $token => $kind) {
            if (array_key_exists($token, $vars)) {
                $value = self::clean($kind, $vars[$token]);
                if ($value !== null) {
                    $tokens[$token] = $value;
                }
            }
        }

        return $tokens;
    }

    /** Own CSS of the active theme (also of default). Cannot close the <style> element. */
    public static function css(Registry $params): string
    {
        $css = (string) (self::custom($params)[self::key($params)]['css'] ?? '');
        $css = trim(str_replace("\0", '', $css));

        return $css === '' ? '' : str_ireplace('</', '<\/', mb_substr($css, 0, 100000));
    }

    /** A token value, or null when it is not valid. */
    public static function clean($kind, $value)
    {
        if (is_array($kind)) {
            return in_array((string) $value, $kind, true) ? (string) $value : null;
        }
        if ($kind === 'color') {
            $value = trim((string) $value);

            return $value === '' || self::isColor($value) ? $value : null;
        }
        [, $min, $max] = explode(':', $kind);
        if (!is_numeric($value)) {
            return null;
        }

        return max((int) $min, min((int) $max, (int) $value));
    }

    public static function isColor(string $value): bool
    {
        return (bool) preg_match('/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%a-z]+\)|transparent|var\(--[a-z0-9-]+\))$/i', $value);
    }

    /** box-shadow of a level; $p = variable prefix ("bs" or "bsr"). */
    private static function shadow(string $level, string $p): string
    {
        return match ($level) {
            'soft'   => '0 4px 14px rgba(0,0,0,.08)',
            'strong' => '0 18px 40px rgba(0,0,0,.18)',
            'float'  => '0 24px 60px -16px rgba(15,23,42,.28),0 2px 8px rgba(15,23,42,.06)',
            'hard'   => '5px 5px 0 var(--' . $p . '-border)',
            'glow'   => '0 0 0 1px color-mix(in srgb,var(--' . $p . '-accent) 30%,transparent),0 14px 36px color-mix(in srgb,var(--' . $p . '-accent) 20%,transparent)',
            default  => 'none',
        };
    }

    /** CSS custom properties of the live panel for a theme. */
    public static function liveVars(array $t): array
    {
        return [
            '--bs-bg'         => $t['bg'] ?: '#ffffff',
            '--bs-text'       => $t['text'] ?: '#1f2328',
            '--bs-muted'      => $t['muted'] ?: '#6b7280',
            '--bs-accent'     => $t['accent'] ?: 'var(--primary, #1a73e8)',
            '--bs-hover'      => $t['hover'] ?: '#f3f4f6',
            '--bs-border'     => $t['border'] ?: '#e5e7eb',
            '--bs-img-bg'     => $t['img_bg'] ?: '#ffffff',
            '--bs-hl-bg'      => $t['hl_bg'] ?: 'transparent',
            '--bs-hl-color'   => $t['hl_color'] ?: 'inherit',
            '--bs-radius'     => $t['radius'] . 'px',
            '--bs-shadow'     => self::shadow($t['shadow'], 'bs'),
            '--bs-on-accent'  => $t['on_accent'] ?: '#ffffff',
            '--bs-btn-radius' => $t['btn_radius'] . 'px',
            '--bs-bw'         => $t['border_width'] . 'px',
            '--bs-ff'         => self::FONTS[$t['font']],
            '--bs-tw'         => $t['title_weight'],
            '--bs-input-bg'   => $t['input_bg'] ?: '#ffffff',
            '--bs-input-text' => $t['input_text'] ?: '#111111',
        ];
    }

    /** CSS custom properties of the results page for a theme. */
    public static function pageVars(array $t): array
    {
        return [
            '--bsr-card-bg'    => $t['bg'] ?: '#ffffff',
            '--bsr-text'       => $t['muted'] ?: '#4b5563',
            '--bsr-body'       => $t['text'] ?: 'inherit',
            '--bsr-surface'    => $t['surface'] ?: 'transparent',
            '--bsr-title'      => $t['title'] ?: ($t['text'] ?: 'inherit'),
            '--bsr-price'      => $t['price'] ?: 'inherit',
            '--bsr-accent'     => $t['accent'] ?: 'var(--primary, #1a73e8)',
            '--bsr-border'     => $t['border'] ?: '#e5e7eb',
            '--bsr-img-bg'     => $t['img_bg'] ?: '#ffffff',
            '--bsr-hl-bg'      => $t['hl_bg'] ?: 'transparent',
            '--bsr-hl-color'   => $t['hl_color'] ?: 'inherit',
            '--bsr-radius'     => $t['card_radius'] . 'px',
            '--bsr-shadow'     => self::shadow($t['card_shadow'], 'bsr'),
            '--bsr-on-accent'  => $t['on_accent'] ?: '#ffffff',
            '--bsr-btn-radius' => $t['btn_radius'] . 'px',
            '--bsr-bw'         => $t['border_width'] . 'px',
            '--bsr-ff'         => self::FONTS[$t['font']],
            '--bsr-tw'         => $t['title_weight'],
            '--bsr-input-bg'   => $t['input_bg'] ?: '#ffffff',
            '--bsr-input-text' => $t['input_text'] ?: '#111111',
            '--bsr-hover-bg'   => $t['hover'] ?: '#f3f4f6',
        ];
    }

    /** Rules a theme adds to the live panel (type, button style, section titles, glass). */
    public static function liveRules(array $t): string
    {
        $css = $t['font'] !== 'inherit' ? '.bs-live{font-family:var(--bs-ff)}' : '';
        if ($t['section_case'] === 'normal') {
            $css .= '.bs-live .bs-section-title,.bs-live .bs-recent-head span{text-transform:none;letter-spacing:normal;font-size:.86em}';
        }
        $css .= self::buttons($t['button_style'], '.bs-live .bs-cart', '.bs-live .bs-quote', '--bs');
        if ($t['blur'] > 0) {
            $css .= '.bs-live{-webkit-backdrop-filter:blur(' . $t['blur'] . 'px) saturate(1.6);backdrop-filter:blur(' . $t['blur'] . 'px) saturate(1.6)}';
        }

        return $css;
    }

    /** Rules a theme adds to the results page block #id. */
    public static function pageRules(array $t, string $s): string
    {
        $css = $t['font'] !== 'inherit' ? "$s{font-family:var(--bsr-ff)}" : '';
        $css .= "$s .bsr-sort select{background:var(--bsr-input-bg);color:var(--bsr-input-text);border-radius:var(--bsr-btn-radius)}";
        $css .= self::buttons($t['button_style'], "$s .bsr-btn:not(.bsr-btn-alt),$s .bs-cart", "$s .bs-quote,$s .bsr-btn-alt,$s .bsr-more", '--bsr');
        if ($t['surface'] !== '') {
            // the whole block on its own background (dark and glass themes on a light site)
            $css .= "$s{background:var(--bsr-surface);color:var(--bsr-body);padding:clamp(14px,3vw,28px);border-radius:calc(var(--bsr-radius) + 6px)}"
                . "$s .bsr-count,$s .bsr-sort,$s .bsr-note,$s .bsr-empty,$s .bsr-heading,$s .bsr-page,$s .bsr-gap{color:var(--bsr-body)}";
            if ($t['blur'] > 0) {
                $css .= "$s{background:radial-gradient(circle at 12% 18%,color-mix(in srgb,var(--bsr-accent) 22%,transparent),transparent 42%),"
                    . "radial-gradient(circle at 88% 72%,color-mix(in srgb,#ec4899 16%,transparent),transparent 45%),"
                    . "radial-gradient(circle at 60% 10%,color-mix(in srgb,#14b8a6 14%,transparent),transparent 40%),var(--bsr-surface)}";
            }
        }
        if ($t['blur'] > 0) {
            $css .= "$s .bsr-card,$s .bsr-facets{-webkit-backdrop-filter:blur({$t['blur']}px) saturate(1.6);backdrop-filter:blur({$t['blur']}px) saturate(1.6)}";
        }
        $css .= "$s .bsr-chip:hover,$s .bsr-page:hover{background:var(--bsr-hover-bg)}$s .bsr-chip.is-active:hover,$s .bsr-page.is-active:hover{background:var(--bsr-accent)}";
        $css .= match ($t['card_hover']) {
            'lift'   => "$s .bsr-card:hover{transform:translateY(-4px);box-shadow:0 16px 36px -10px rgba(15,23,42,.28)}",
            'shadow' => "$s .bsr-card:hover{box-shadow:0 12px 28px rgba(0,0,0,.14)}",
            'zoom'   => "$s .bsr-card:hover .bsr-img img{transform:scale(1.06)}",
            'border' => "$s .bsr-card:hover{border-color:var(--bsr-accent)}",
            'glow'   => "$s .bsr-card:hover{border-color:color-mix(in srgb,var(--bsr-accent) 60%,transparent);box-shadow:0 0 0 1px var(--bsr-accent),0 16px 40px color-mix(in srgb,var(--bsr-accent) 25%,transparent)}",
            'shift'  => "$s .bsr-card:hover{transform:translate(-3px,-3px);box-shadow:8px 8px 0 var(--bsr-border)}",
            default  => '',
        };

        return $css;
    }

    /** Solid (default), outlined or tinted buttons. */
    private static function buttons(string $style, string $primary, string $secondary, string $p): string
    {
        $hover = implode(',', array_map(fn ($sel) => trim($sel) . ':hover', explode(',', $primary)));
        if ($style === 'outline') {
            return "$primary{background:transparent;color:var($p-accent);border:var($p-bw,1px) solid var($p-accent)}"
                . "$hover{background:var($p-accent);color:var($p-on-accent);filter:none}";
        }
        if ($style === 'tint') {
            return "$primary{background:color-mix(in srgb,var($p-accent) 14%,transparent);color:var($p-accent);border-color:transparent}"
                . "$hover{background:var($p-accent);color:var($p-on-accent);filter:none}"
                . "$secondary{border-color:color-mix(in srgb,var($p-accent) 35%,transparent)}";
        }

        return '';
    }

    /**
     * The own CSS for the results page: selectors written for ".bettersearch-results" get the id of
     * the block so they win over the plugin's own rules (#id .x).
     */
    public static function scopePage(string $css, string $id): string
    {
        return preg_replace('/(?<![\w-])\.bettersearch-results(?![\w-])/', '#' . $id . '.bettersearch-results', $css) ?? $css;
    }

    /**
     * The active theme as readable CSS for the code editor: its custom properties and rules. Pasted
     * into the own CSS it can be changed freely (the own CSS comes after the theme).
     */
    public static function export(Registry $params, string $name): string
    {
        $tokens = self::tokens($params);
        $pretty = function (string $css): string {
            $out = '';
            foreach (array_filter(array_map('trim', explode('}', $css))) as $rule) {
                [$selector, $body] = array_pad(explode('{', $rule, 2), 2, '');
                $out .= str_replace(',', ",\n", trim($selector)) . " {\n  " . implode(";\n  ", array_filter(array_map('trim', explode(';', $body)))) . ";\n}\n";
            }

            return rtrim($out);
        };
        $block  = function (string $selector, array $vars): string {
            $out = $selector . " {\n";
            foreach ($vars as $k => $v) {
                $out .= '  ' . $k . ': ' . $v . ";\n";
            }

            return $out . "}\n";
        };
        $out = '/* Better Search for Gridbox: ' . $name . " */\n\n";
        if ($tokens === null) {
            return $out . "/* .bs-live = live results, .bettersearch-results = results page */\n"
                . ".bs-live {\n  /* --bs-accent: #1a73e8; */\n}\n.bettersearch-results {\n  /* --bsr-accent: #1a73e8; */\n}\n";
        }
        $out .= "/* Live results */\n" . $block('.bs-live', self::liveVars($tokens));
        $rules = self::liveRules($tokens);
        $out  .= $rules !== '' ? $pretty($rules) . "\n" : '';
        $out  .= "\n/* Results page */\n" . $block('.bettersearch-results', self::pageVars($tokens));
        $out  .= $pretty(self::pageRules($tokens, '.bettersearch-results')) . "\n";

        return $out;
    }
}
