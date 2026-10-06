<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * HTML and CSS of the live results (dropdown under the search field) and of the results page.
 * Every visual setting becomes a CSS custom property on the block, so one stylesheet serves all
 * variants. Device-specific values are set for the visitor's device from the User-Agent and with
 * @media rules for the other widths: some sites strip @media rules from the HTML for phones.
 */

namespace Merserwis\Plugin\System\BetterSearch\Render;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Merserwis\Plugin\System\BetterSearch\Engine\Normalizer;
use Joomla\Registry\Registry;

final class Renderer
{
    private const RATIOS  = ['1-1' => '1 / 1', '4-3' => '4 / 3', '3-2' => '3 / 2', '16-9' => '16 / 9', '3-4' => '3 / 4', 'auto' => 'auto'];
    private const SHADOWS = ['none' => 'none', 'soft' => '0 4px 14px rgba(0,0,0,.08)', 'strong' => '0 18px 40px rgba(0,0,0,.18)'];

    private Registry $params;

    private Store $store;

    private Thumbs $thumbs;

    private string $device;

    private ?Normalizer $norm = null;

    /** @var array<int, array{name: string, url: string}> the result cards rendered (structured data) */
    private array $listed = [];

    /** @return array<int, array{name: string, url: string}> */
    public function listed(): array
    {
        return $this->listed;
    }

    /** @var array<string, string> folded form of single characters (mark()) */
    private array $charMap = [];

    /** @var string[] compact query groups for highlighting */
    private array $highlight = [];

    /** The query of the rendered results ({query} in the address of "Ask for a quote"). */
    private string $query = '';

    /** Address of "Ask for a quote" when none is set: an e-mail to the site. */
    private string $quoteFallback = '';

    public function setQuoteFallback(string $url): void
    {
        $this->quoteFallback = $url;
    }

    public function __construct(Registry $params, Store $store, Thumbs $thumbs, string $device)
    {
        $this->params = $params;
        $this->store  = $store;
        $this->thumbs = $thumbs;
        $this->device = $device;
    }

    /** @param string[] $terms query groups (compact) to mark in titles */
    public function setHighlight(array $terms): void
    {
        $this->highlight = array_values(array_filter($terms, fn ($t) => strlen($t) >= 2));
    }

    // ================================================================ settings

    private function str(string $key, string $default, array $allowed = []): string
    {
        $value = trim((string) $this->params->get($key, $default));

        return $allowed && !in_array($value, $allowed, true) ? $default : ($value === '' && !$allowed ? $default : $value);
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->params->get($key, $default);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        return (bool) (int) $this->params->get($key, $default ? 1 : 0);
    }

    private function color(string $key, string $default): string
    {
        $value = trim((string) $this->params->get($key, ''));

        return preg_match('/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%a-z]+\)|transparent|var\(--[a-z0-9-]+\))$/i', $value) ? $value : $default;
    }

    /**
     * Fills %s / %d in an administrator text. Not sprintf(): a stray "%" in a text typed in the
     * settings would throw and take the whole search down.
     */
    private function fmt(string $text, array $values): string
    {
        return strtr($text, array_map('strval', $values));
    }

    /** rel="nofollow" for links that only re-sort or filter one search (SEO setting). */
    private function nofollow(): string
    {
        return $this->bool('seo_nofollow', true) ? ' rel="nofollow"' : '';
    }

    /** A text setting, else the language string. */
    private function text(string $key, string $languageKey): string
    {
        $value = trim((string) $this->params->get($key, ''));

        return $value !== '' ? $value : Text::_($languageKey);
    }

    // ================================================================ live results

    /**
     * Inner HTML of the live results panel.
     *
     * @param array      $result      Searcher::search() result (items already limited per app)
     * @param array      $categories  matching categories [{id, score}]
     * @param string     $allUrl      link to the full results page
     */
    public function live(array $result, array $categories, string $allUrl): string
    {
        $q     = $result['query'];
        $this->query = (string) $q;
        $items = $result['items'];
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html  = '';

        if ($result['mode'] === 'typo' && $result['corrected'] !== '') {
            $html .= '<div class="bs-note">' . $this->fmt($esc($this->text('text_corrected', 'PLG_SYSTEM_BETTERSEARCH_T_CORRECTED')), ['%s' => '<b>' . $esc($result['corrected']) . '</b>']) . '</div>';
        } elseif ($result['mode'] === 'partial') {
            $html .= '<div class="bs-note">' . $esc($this->text('text_partial', 'PLG_SYSTEM_BETTERSEARCH_T_PARTIAL')) . '</div>';
        }

        $opt = 0;
        // a redirect set for this phrase: the first option of the panel
        if (!empty($result['redirect'])) {
            $r     = $result['redirect'];
            $html .= '<div class="bs-section bs-section-go"><ul class="bs-cats" role="presentation"><li role="presentation"><a class="bs-opt bs-go" role="option" id="bs-opt-' . $opt
                . '" style="--i:' . $opt++ . '" href="' . $esc($r['url']) . '"><span class="bs-cat-name">' . $esc($r['label']) . '</span><span class="bs-go-arrow" aria-hidden="true">→</span></a></li></ul></div>';
        }
        // popular searches that start like the typed text
        if (!empty($result['popular'])) {
            $html .= '<div class="bs-section bs-section-pop">';
            if ($this->bool('live_section_titles', true)) {
                $html .= '<div class="bs-section-title">' . $esc($this->text('text_popular', 'PLG_SYSTEM_BETTERSEARCH_T_POPULAR')) . '</div>';
            }
            $html .= '<ul class="bs-pop" role="presentation">';
            foreach ($result['popular'] as $phrase) {
                $html .= '<li role="presentation"><a class="bs-opt bs-sugg" role="option" id="bs-opt-' . $opt . '" style="--i:' . $opt++ . '" href="#" data-bs-q="' . $esc($phrase) . '">'
                    . '<span class="bs-sugg-icon" aria-hidden="true"></span><span class="bs-sugg-text">' . $this->mark($phrase) . '</span></a></li>';
            }
            $html .= '</ul></div>';
        }
        if ($categories && $this->bool('live_categories', true)) {
            $all   = $this->store->categories();
            $html .= '<div class="bs-section bs-section-cats">';
            if ($this->bool('live_section_titles', true)) {
                $html .= '<div class="bs-section-title">' . $esc($this->text('text_categories', 'PLG_SYSTEM_BETTERSEARCH_T_CATEGORIES')) . '</div>';
            }
            $html .= '<ul class="bs-cats" role="presentation">';
            foreach ($categories as $c) {
                $cat = $all[$c['id']] ?? null;
                if (!$cat) {
                    continue;
                }
                $path  = $this->store->categoryPath($cat->parent);
                $html .= '<li role="presentation"><a class="bs-opt bs-cat" role="option" id="bs-opt-' . $opt . '" style="--i:' . $opt++ . '" href="'
                    . $esc($this->store->route($this->store->categoryLink($cat->app_id, $cat->id))) . '">'
                    . '<span class="bs-cat-name">' . $this->mark($cat->title) . '</span>'
                    . ($path ? '<span class="bs-cat-path">' . $esc(implode(' › ', $path)) . '</span>' : '') . '</a></li>';
            }
            $html .= '</ul></div>';
        }

        if ($items) {
            $data   = $this->store->items(array_column($items, 'id'));
            $byApp  = [];
            foreach ($items as $item) {
                if (isset($data[$item['id']])) {
                    $data[$item['id']]->featured = !empty($item['featured']);
                    // -1: one list of everything; 0 is the group of the pages
                    $byApp[$this->bool('live_group_apps', true) ? $item['app_id'] : -1][] = $data[$item['id']];
                }
            }
            $counts = $result['app_counts'] ?? [];
            foreach ($byApp as $appId => $rows) {
                $html .= '<div class="bs-section' . ($appId === 0 ? ' bs-section-pages' : '') . '">';
                if ($this->bool('live_section_titles', true) && (count($byApp) > 1 || $categories)) {
                    $title = $appId >= 0 ? $this->store->appTitle($appId) : $this->text('text_products', 'PLG_SYSTEM_BETTERSEARCH_T_PRODUCTS');
                    $count = $appId >= 0 ? ($counts[$appId] ?? count($rows)) : $result['total'];
                    $html .= '<div class="bs-section-title">' . $esc($title) . ' <span class="bs-section-count">' . (int) $count . '</span></div>';
                }
                $html .= '<ul class="bs-list" role="presentation">';
                foreach ($rows as $row) {
                    $html .= $this->liveItem($row, $opt++);
                }
                $html .= '</ul></div>';
            }
        }

        if (!$items && !$categories) {
            $html .= '<div class="bs-empty">' . $this->fmt($esc($this->text('text_no_results', 'PLG_SYSTEM_BETTERSEARCH_T_NO_RESULTS')), ['%s' => '<b>' . $esc($q) . '</b>']) . '</div>';
            if (!empty($result['suggest'])) {
                $links = array_map(fn ($phrase) => '<a href="#" class="bs-opt bs-sugg-inline" role="option" id="bs-opt-' . $opt . '" data-bs-q="' . $esc($phrase) . '">'
                    . $esc($phrase) . '</a>', $result['suggest']);
                $opt  += count($links);
                $html .= '<div class="bs-note bs-didyoumean">' . $esc($this->text('text_did_you_mean', 'PLG_SYSTEM_BETTERSEARCH_T_DID_YOU_MEAN')) . ' ' . implode(', ', $links) . '</div>';
            }
        }

        $out = '<div class="bs-live-body">' . $html . '</div>';
        if ($items && $this->bool('live_show_all', true)) {
            $label = $this->fmt($this->text('text_show_all', 'PLG_SYSTEM_BETTERSEARCH_T_SHOW_ALL'), ['%d' => (int) $result['total']]);
            $out  .= '<a class="bs-all bs-opt" role="option" id="bs-opt-' . $opt . '" href="' . $esc($allUrl) . '">' . $esc($label) . '</a>';
        }

        return $out;
    }

    private function liveItem(object $row, int $n): string
    {
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $image = '';
        if ($this->bool('live_image', true) && $row->image !== '') {
            $size = $this->int('live_image_size', 64, 24, 400);
            // tiles (grid, image on top) are as wide as a column: a larger copy
            if ($this->str('live_layout', 'list', ['list', 'grid']) === 'grid' || $this->str('live_image_position', 'left', ['left', 'right', 'top']) === 'top') {
                $size = max($size, 240);
            }
            $image = '<span class="bs-img">' . $this->img($row, $size, 'lazy') . '</span>';
        }

        $meta = [];
        if ($this->bool('live_show_category', true) && $row->category !== '') {
            $meta[] = '<span class="bs-cat-label">' . $esc($row->category) . '</span>';
        }
        if ($this->bool('live_show_sku', false) && trim((string) $row->sku) !== '') {
            $meta[] = '<span class="bs-sku">' . $esc($this->text('text_sku', 'PLG_SYSTEM_BETTERSEARCH_T_SKU')) . ' ' . $this->mark((string) $row->sku) . '</span>';
        }
        if ($this->showAvailability('live') && $row->isProduct) {
            $meta[] = $this->availability($row);
        } elseif ($this->bool('live_show_stock', false) && $row->isProduct) {
            $meta[] = $this->stock($row);
        }

        $excerpt = '';
        if ($this->bool('live_show_excerpt', false) && trim((string) $row->excerpt) !== '') {
            $excerpt = '<span class="bs-excerpt">' . $this->mark($this->cut((string) $row->excerpt, $this->int('live_excerpt_length', 90, 20, 400))) . '</span>';
        }

        $price   = $this->bool('live_show_price', true) ? $this->price($row) : '';
        $badge   = $row->featured && $this->bool('featured_badge', true)
            ? '<span class="bs-badge">' . $esc($this->text('text_featured', 'PLG_SYSTEM_BETTERSEARCH_T_FEATURED')) . '</span>' : '';
        $badge   = $this->productBadges($row, 'live', 'bs-badges', 'bs-pbadge', $badge);
        // buttons beside the link of the result (a button inside a link is not allowed)
        $actions = $this->actions($row, 'live');

        $liClass = trim(($actions !== '' ? 'bs-has-actions' : '') . ($row->featured ? ' is-featured' : ''));

        return '<li role="presentation"' . ($liClass !== '' ? ' class="' . $liClass . '"' : '') . '><a class="bs-opt bs-item' . ($row->featured ? ' is-featured' : '') . '" role="option" id="bs-opt-' . $n
            . '" style="--i:' . $n . '" href="' . $esc($row->link) . '" data-bs-id="' . (int) $row->id . '">' . $image
            . '<span class="bs-info">' . $badge . '<span class="bs-title">' . $this->mark($row->title) . '</span>'
            . ($meta ? '<span class="bs-meta">' . implode('<span class="bs-dot">·</span>', $meta) . '</span>' : '')
            . $excerpt . '</span>' . $price . '</a>' . ($actions !== '' ? '<span class="bs-actions">' . $actions . '</span>' : '') . '</li>';
    }

    /**
     * The badges of a result: "featured" first, then the product's Gridbox badges ("New",
     * "Recommended", "- 15%"…) in their colours — on the results page, in the live results or both (setting).
     */
    private function productBadges(object $row, string $where, string $wrapClass, string $badgeClass, string $first): string
    {
        $mode  = $this->str('product_badges', 'both', ['both', 'page', 'live', 'none']);
        $items = $first;
        if (($mode === 'both' || $mode === $where) && !empty($row->badges)) {
            $max = $this->int('product_badges_max', 3, 1, 10);
            foreach (array_slice($row->badges, 0, $max) as $badge) {
                $items .= '<span class="' . $badgeClass . '"' . ($badge['color'] !== '' ? ' style="--bs-badge:' . htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
                    . htmlspecialchars($badge['title'], ENT_QUOTES, 'UTF-8') . '</span>';
            }
        }

        return $items !== '' ? '<span class="' . $wrapClass . '">' . $items . '</span>' : '';
    }

    /** CSS of the live panel (scoped to .bs-live). */
    public function liveCss(): string
    {
        $pos    = $this->str('live_image_position', 'left', ['left', 'right', 'top']);
        $shape  = $this->str('live_image_shape', 'rounded', ['rect', 'rounded', 'circle']);
        $size   = $this->int('live_image_size', 64, 24, 400);
        $layout = $this->str('live_layout', 'list', ['list', 'grid']);
        $cols   = $this->int('live_columns', 3, 1, 6);

        $vars = [
            '--bs-bg'        => $this->color('live_bg', '#ffffff'),
            '--bs-text'      => $this->color('live_text', '#1f2328'),
            '--bs-muted'     => $this->color('live_muted', '#6b7280'),
            '--bs-accent'    => $this->color('live_accent', 'var(--primary, #1a73e8)'),
            '--bs-hover'     => $this->color('live_hover_bg', '#f3f4f6'),
            '--bs-border'    => $this->color('live_border', '#e5e7eb'),
            '--bs-hl-bg'     => $this->color('highlight_bg', 'transparent'),
            '--bs-hl-color'  => $this->color('highlight_color', 'inherit'),
            '--bs-radius'    => $this->int('live_radius', 10, 0, 40) . 'px',
            '--bs-shadow'    => self::SHADOWS[$this->str('live_shadow', 'strong', array_keys(self::SHADOWS))],
            '--bs-font'      => $this->int('live_font_size', 14, 10, 24) . 'px',
            '--bs-img'       => $size . 'px',
            '--bs-img-bg'    => $this->color('live_image_bg', '#ffffff'),
            '--bs-img-fit'   => $this->str('live_image_fit', 'contain', ['contain', 'cover']),
            '--bs-img-radius' => ['rect' => '0', 'rounded' => '6px', 'circle' => '50%'][$shape],
            '--bs-lines'     => (string) $this->int('live_title_lines', 2, 1, 5),
            '--bs-max-h'     => $this->int('live_max_height', 560, 200, 2000) . 'px',
            '--bs-cols'      => (string) $cols,
            '--bs-z'         => (string) $this->int('live_z', 99999, 1, 2147483647),
            '--bs-anim-dur'  => $this->int('live_anim_duration', 200, 0, 2000) . 'ms',
            '--bs-item-dur'  => $this->int('live_item_duration', 260, 0, 2000) . 'ms',
            '--bs-stagger'   => $this->int('live_item_stagger', 35, 0, 500) . 'ms',
            '--bs-feat'      => $this->color('featured_color', 'var(--bs-accent)'),
        ];

        // a theme other than the default replaces the colour and shape settings above
        $theme = Themes::tokens($this->params);
        if ($theme !== null) {
            $vars = array_merge($vars, Themes::liveVars($theme));
        }

        $css = '.bs-live{' . $this->vars($vars) . '}';
        $css .= <<<CSS
.bs-live{position:absolute;box-sizing:border-box;background:var(--bs-bg);color:var(--bs-text);border:var(--bs-bw,1px) solid var(--bs-border);border-radius:var(--bs-radius);box-shadow:var(--bs-shadow);font-size:var(--bs-font);line-height:1.35;z-index:var(--bs-z);overflow:hidden;display:none;flex-direction:column;text-align:start;opacity:0}
.bs-live *{box-sizing:border-box}
.bs-live a,.bs-live span,.bs-live div,.bs-live li,.bs-live b,.bs-live del,.bs-live ul{font-size:inherit;line-height:inherit;font-family:inherit;font-weight:inherit;font-style:normal;letter-spacing:normal;text-transform:none;text-decoration:none;color:inherit;margin:0;padding:0;border:0;background:none;text-align:inherit}
.bs-live.is-open{display:flex}
.bs-live.is-shown{opacity:1}
.bs-live .bs-live-body{overflow-y:auto;max-height:var(--bs-max-h);padding:6px 0;overscroll-behavior:contain}
.bs-live .bs-section+.bs-section{border-top:1px solid var(--bs-border);margin-top:4px;padding-top:4px}
.bs-live .bs-section-title{font-size:.78em;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--bs-muted);padding:8px 16px 4px}
.bs-live .bs-section-count{font-weight:400;opacity:.8}
.bs-live ul{list-style:none;margin:0;padding:0}
.bs-live li{margin:0;padding:0}
.bs-live .bs-opt{display:flex;gap:12px;align-items:center;padding:8px 16px;color:inherit;text-decoration:none;outline:none}
.bs-live .bs-opt:hover,.bs-live .bs-opt.is-active{background:var(--bs-hover)}
.bs-live .bs-opt.is-active{box-shadow:inset 3px 0 0 var(--bs-accent)}
[dir=rtl] .bs-live .bs-opt.is-active,.bs-live[dir=rtl] .bs-opt.is-active{box-shadow:inset -3px 0 0 var(--bs-accent)}
.bs-live .bs-img{flex:0 0 var(--bs-img);width:var(--bs-img);height:var(--bs-img);display:flex;align-items:center;justify-content:center;background:var(--bs-img-bg);border-radius:var(--bs-img-radius);overflow:hidden}
.bs-live .bs-img img{width:100%;height:100%;object-fit:var(--bs-img-fit);display:block}
.bs-live .bs-info{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}
.bs-live .bs-title{font-weight:var(--bs-tw,600);display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--bs-lines);overflow:hidden}
.bs-live .bs-meta{font-size:.85em;color:var(--bs-muted);display:flex;flex-wrap:wrap;gap:0 6px}
.bs-live .bs-excerpt{font-size:.85em;color:var(--bs-muted);display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
.bs-live .bs-price{flex:0 0 auto;text-align:end;white-space:nowrap;font-weight:700;display:flex;flex-direction:column;align-items:flex-end}
.bs-live .bs-price del{font-weight:400;font-size:.85em;color:var(--bs-muted);text-decoration:line-through;text-decoration-thickness:1px}
.bs-live .bs-sr{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.bs-live .bs-price .bs-from{font-weight:400;font-size:.8em;color:var(--bs-muted)}
.bs-live mark{background:var(--bs-hl-bg);color:var(--bs-hl-color);font-weight:800;padding:0}
.bs-live .bs-cat{gap:8px;align-items:baseline;flex-wrap:wrap}
.bs-live .bs-cat-name{font-weight:600}
.bs-live .bs-cat-path{font-size:.85em;color:var(--bs-muted)}
.bs-live .bs-pop{list-style:none;margin:0;padding:0}
.bs-live .bs-sugg{gap:10px;align-items:center}
.bs-live .bs-sugg-icon{flex:0 0 auto;width:12px;height:12px;border:2px solid var(--bs-muted);border-radius:50%;position:relative;opacity:.8}
.bs-live .bs-sugg-icon::after{content:'';position:absolute;width:2px;height:6px;background:var(--bs-muted);inset-inline-end:-4px;bottom:-5px;transform:rotate(-45deg)}
.bs-live .bs-go{gap:8px;align-items:center;font-weight:600}
.bs-live .bs-go-arrow{margin-inline-start:auto;color:var(--bs-accent, inherit)}
[dir=rtl] .bs-live .bs-go-arrow{transform:scaleX(-1)}
.bs-live .bs-didyoumean a{font-weight:600;color:var(--bs-accent, inherit)}
.bs-live .bs-didyoumean .bs-opt{display:inline;padding:0}
.bs-live .bs-note{padding:8px 16px;font-size:.9em;color:var(--bs-muted);border-bottom:1px solid var(--bs-border)}
.bs-live .bs-empty{padding:24px 16px;text-align:center;color:var(--bs-muted)}
.bs-live .bs-all{justify-content:center;font-weight:700;background:var(--bs-accent);color:var(--bs-on-accent,#fff);padding:12px 16px;border-radius:0}
.bs-live .bs-all:hover,.bs-live .bs-all.is-active{background:var(--bs-accent);filter:brightness(1.08);box-shadow:none}
.bs-live .bs-stock-in{color:#15803d}.bs-live .bs-stock-out{color:#b91c1c}
.bs-live-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:calc(var(--bs-z, 99999) - 1)}
.bs-live.is-full{position:fixed;inset:0;border-radius:0;border:0;max-height:none}
.bs-live.is-full .bs-live-body{max-height:none;flex:1}
.bs-live .bs-full-head{display:none}
.bs-live.is-full .bs-full-head{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--bs-border)}
.bs-live .bs-full-head input{flex:1;font-size:16px;padding:10px 12px;border:1px solid var(--bs-border);border-radius:8px;background:var(--bs-input-bg,#fff);color:var(--bs-input-text,#111);min-width:0}
.bs-live .bs-full-head button{border:0;background:none;font-size:26px;line-height:1;padding:4px 8px;color:var(--bs-text);cursor:pointer}
.bs-live.is-loading .bs-live-body{opacity:.55}
.bs-live li.is-featured{background:color-mix(in srgb,var(--bs-feat) 7%,transparent)}
.bs-live .bs-item.is-featured:hover,.bs-live .bs-item.is-featured.is-active{background:color-mix(in srgb,var(--bs-feat) 13%,transparent)}
.bs-live .bs-badge{align-self:flex-start;background:var(--bs-feat);color:var(--bs-on-accent,#fff);font-size:.72em;font-weight:700;line-height:1.5;padding:0 7px;border-radius:999px;margin-bottom:2px}
.bs-live .bs-badges{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:2px}
.bs-live .bs-badges .bs-badge{margin-bottom:0}
.bs-live .bs-pbadge{background:var(--bs-badge,var(--bs-accent));color:#fff;font-size:.72em;font-weight:700;line-height:1.5;padding:0 7px;border-radius:4px;white-space:nowrap}
.bs-live .bs-avail{display:inline-flex;flex-wrap:wrap;gap:0 6px}
.bs-live .bs-stock-low{color:#b45309}
.bs-live .bs-delivery{color:var(--bs-muted)}
.bs-live li.bs-has-actions{display:flex;align-items:center}
.bs-live li.bs-has-actions>.bs-item{flex:1 1 auto;min-width:0}
.bs-live .bs-actions{display:flex;flex-direction:column;gap:4px;padding-inline-end:12px;flex:0 0 auto}
.bs-live .bs-cart,.bs-live .bs-quote{display:inline-block;font:inherit;font-size:.8em;font-weight:600;line-height:1.2;padding:6px 10px;border-radius:var(--bs-btn-radius,6px);border:1px solid var(--bs-accent);background:var(--bs-accent);color:var(--bs-on-accent,#fff);cursor:pointer;white-space:nowrap;text-align:center;text-decoration:none;margin:0}
.bs-live .bs-quote,.bs-live .bs-cart-options{background:transparent;color:var(--bs-accent)}
.bs-live .bs-cart:hover,.bs-live .bs-quote:hover{filter:brightness(1.08)}
.bs-live .bs-cart[disabled]{opacity:.6;cursor:wait}
.bs-live .bs-cart.is-done{background:#15803d;border-color:#15803d;color:#fff}
.bs-live .bs-recent-head{display:flex;align-items:center;justify-content:space-between;gap:8px}
.bs-live .bs-recent-head span{text-transform:uppercase;letter-spacing:.04em}
.bs-live .bs-recent-clear{border:0;background:none;padding:0;margin:0;font:inherit;font-size:1em;text-transform:none;letter-spacing:normal;color:var(--bs-accent);cursor:pointer}
.bs-live .bs-recent-row{display:flex;align-items:center}
.bs-live .bs-recent-row>.bs-opt{flex:1 1 auto;min-width:0}
.bs-live .bs-recent-del{border:0;background:none;margin:0;padding:6px 14px;font:inherit;font-size:1.15em;line-height:1;color:var(--bs-muted);cursor:pointer}
.bs-live .bs-recent-del:hover{color:var(--bs-text)}
.bs-live .bs-recent-icon{flex:0 0 auto;width:13px;height:13px;border:2px solid var(--bs-muted);border-radius:50%;position:relative;opacity:.8}
.bs-live .bs-recent-icon::before{content:'';position:absolute;left:3.5px;top:1px;width:2px;height:4.5px;background:var(--bs-muted)}
.bs-live .bs-recent-icon::after{content:'';position:absolute;left:3.5px;top:4.5px;width:3.5px;height:2px;background:var(--bs-muted)}
CSS;

        $css .= $this->liveAnimationCss();

        if ($pos === 'right') {
            $css .= '.bs-live .bs-item{flex-direction:row-reverse}.bs-live .bs-item .bs-price{align-items:flex-start;text-align:start}';
        } elseif ($pos === 'top') {
            $css .= '.bs-live .bs-item{flex-direction:column;align-items:stretch;text-align:center}.bs-live .bs-item .bs-img{width:100%;height:auto;aspect-ratio:1/1;flex-basis:auto}.bs-live .bs-item .bs-price{align-items:center;text-align:center}';
        }
        if ($layout === 'grid') {
            $css .= '.bs-live .bs-list{display:grid;grid-template-columns:repeat(var(--bs-cols),minmax(0,1fr));gap:4px;padding:0 6px}'
                . '.bs-live .bs-list .bs-opt{flex-direction:column;align-items:stretch;text-align:center;padding:10px;border-radius:8px}'
                . '.bs-live .bs-list .bs-img{width:100%;height:auto;aspect-ratio:1/1;flex-basis:auto}'
                . '.bs-live .bs-list .bs-price{align-items:center;text-align:center}';
        }
        if ($theme !== null) {
            $css .= Themes::liveRules($theme);
        }

        // the administrator's own CSS last, so it wins over everything above
        return $css . Themes::css($this->params);
    }


    /** Keyframes of the chosen effects: the panel appearing, and the results inside it. */
    public const PANEL_EFFECTS = ['none', 'fade', 'slide_up', 'slide_down', 'zoom', 'flip', 'expand'];
    public const ITEM_EFFECTS  = ['none', 'fade', 'fade_up', 'slide', 'zoom', 'blur'];

    private function liveAnimationCss(): string
    {
        $panel = $this->str('live_animation', 'slide_up', self::PANEL_EFFECTS);
        $items = $this->str('live_item_animation', 'none', self::ITEM_EFFECTS);
        $ease  = 'cubic-bezier(.2,.75,.25,1)';
        $css   = '';

        $frames = [
            'fade'       => 'from{opacity:0}to{opacity:1}',
            'slide_up'   => 'from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}',
            'slide_down' => 'from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}',
            'zoom'       => 'from{opacity:0;transform:scale(.94)}to{opacity:1;transform:none}',
            'flip'       => 'from{opacity:0;transform:perspective(900px) rotateX(-14deg)}to{opacity:1;transform:none}',
            'expand'     => 'from{opacity:.4;clip-path:inset(0 0 100% 0 round var(--bs-radius))}to{opacity:1;clip-path:inset(0 0 0 0 round var(--bs-radius))}',
        ];
        if ($panel !== 'none') {
            $css .= '@keyframes bs-panel-in{' . $frames[$panel] . '}'
                . '.bs-live.is-shown{animation:bs-panel-in var(--bs-anim-dur) ' . $ease . ' both;transform-origin:top center}';
        }

        $itemFrames = [
            'fade'    => 'from{opacity:0}to{opacity:1}',
            'fade_up' => 'from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}',
            'slide'   => 'from{opacity:0;transform:translateX(-14px)}to{opacity:1;transform:none}',
            'zoom'    => 'from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}',
            'blur'    => 'from{opacity:0;filter:blur(6px)}to{opacity:1;filter:none}',
        ];
        if ($items !== 'none') {
            // every new set of results plays the effect, one item after another
            $css .= '@keyframes bs-item-in{' . $itemFrames[$items] . '}'
                . '.bs-live.is-shown .bs-opt:not(.bs-all),.bs-live.is-shown .bs-section-title,.bs-live.is-shown .bs-note,.bs-live.is-shown .bs-empty'
                . '{animation:bs-item-in var(--bs-item-dur) ' . $ease . ' both;animation-delay:calc(var(--i, 0) * var(--bs-stagger))}';
        }

        // visitors who asked their system for less motion get none
        return $css . '@media (prefers-reduced-motion: reduce){.bs-live,.bs-live *{animation:none!important}}';
    }

    // ================================================================ results page

    /**
     * The results page block.
     *
     * @param array  $result   Searcher::search() result (all items, already filtered by category/app)
     * @param array  $state    query, page, sort, cat, app, perPage, url(callable), chips, appChips, total (before filters)
     */
    public function page(array $result, array $state, string $id): string
    {
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $q     = $result['query'];
        $this->query = (string) $q;
        $total = $result['total'];
        $center = $this->str('page_align', 'left', ['left', 'center']) === 'center' ? ' bsr-center' : '';
        $html  = '<div class="bettersearch-results' . $center . '" id="' . $id . '" data-query="' . $esc($q) . '">';

        $heading = $this->str('page_heading', 'gridbox', ['gridbox', 'custom', 'none']);
        if ($heading === 'custom' && $q !== '') {
            $html .= '<h2 class="bsr-heading">' . $this->fmt($esc($this->text('text_heading', 'PLG_SYSTEM_BETTERSEARCH_T_HEADING')), ['%s' => $esc($q), '%d' => $total]) . '</h2>';
        }

        if ($q === '') {
            return $html . '<p class="bsr-empty">' . $esc($this->text('text_enter_query', 'PLG_SYSTEM_BETTERSEARCH_T_ENTER_QUERY')) . '</p></div>';
        }

        if ($result['mode'] === 'typo' && $result['corrected'] !== '') {
            $html .= '<p class="bsr-note">' . $this->fmt($esc($this->text('text_corrected', 'PLG_SYSTEM_BETTERSEARCH_T_CORRECTED')), ['%s' => '<b>' . $esc($result['corrected']) . '</b>']) . '</p>';
        } elseif ($result['mode'] === 'partial') {
            $html .= '<p class="bsr-note">' . $esc($this->text('text_partial', 'PLG_SYSTEM_BETTERSEARCH_T_PARTIAL')) . '</p>';
        }

        // filters: apps, categories
        if (count($state['appChips']) > 1) {
            $html .= $this->chips($state['appChips'], 'bsr-apps');
        }
        if ($state['chips']) {
            $html .= $this->chips($state['chips'], 'bsr-cats');
        }

        // brand, price, sale, stock: beside the results (or above them)
        $facets = $this->facets($state);
        $side   = $facets !== '' && $this->str('page_facets_position', 'side', ['side', 'top']) === 'side';
        if ($facets !== '') {
            $html .= $side ? '<div class="bsr-layout">' . $facets . '<div class="bsr-main">' : $facets;
        }
        $close = $side ? '</div></div>' : '';

        // toolbar: count + sorting
        $html .= '<div class="bsr-toolbar">';
        if ($this->bool('page_count', true)) {
            $html .= '<div class="bsr-count">' . $this->fmt($esc($this->text('text_count', 'PLG_SYSTEM_BETTERSEARCH_T_COUNT')), ['%d' => $total]) . '</div>';
        }
        $sorts = $this->sortOptions();
        if ($this->bool('page_sort', true) && count($sorts) > 1 && $total > 1) {
            $html .= '<form class="bsr-sort" method="get" action="' . $esc($state['action']) . '">';
            foreach ($state['hidden'] as $name => $value) {
                $html .= '<input type="hidden" name="' . $esc($name) . '" value="' . $esc($value) . '">';
            }
            $html .= '<label><span>' . $esc($this->text('text_sort', 'PLG_SYSTEM_BETTERSEARCH_T_SORT')) . '</span> <select name="bs_sort" onchange="this.form.submit()">';
            foreach ($sorts as $value => $label) {
                $html .= '<option value="' . $value . '"' . ($value === $state['sort'] ? ' selected' : '') . '>' . $esc($label) . '</option>';
            }
            $html .= '</select></label><noscript><button type="submit">OK</button></noscript></form>';
        }
        $html .= '</div>';

        if (!$total) {
            $html .= '<div class="bsr-empty">' . $this->fmt($esc($this->text('text_no_results', 'PLG_SYSTEM_BETTERSEARCH_T_NO_RESULTS')), ['%s' => '<b>' . $esc($q) . '</b>']) . '</div>';
            if (!empty($result['suggest'])) {
                $links = array_map(fn ($phrase) => '<a href="' . $esc(($state['queryUrl'] ?? fn ($x) => '#')($phrase)) . '"' . $this->nofollow() . '>' . $esc($phrase) . '</a>', $result['suggest']);
                $html .= '<p class="bsr-note bsr-didyoumean">' . $esc($this->text('text_did_you_mean', 'PLG_SYSTEM_BETTERSEARCH_T_DID_YOU_MEAN')) . ' ' . implode(', ', $links) . '</p>';
            }

            return $html . $close . '</div>';
        }

        $html .= '<ul class="bsr-grid">' . $this->cards($result['items'], $state) . '</ul>';
        $html .= $this->pagination($state, $total);

        return $html . $close . '</div>';
    }

    /** The filter panel of the results page (a plain GET form: works without JavaScript). */
    private function facets(array $state): string
    {
        $f = $state['facets'] ?? [];
        if (!$f) {
            return '';
        }
        $esc    = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $vars   = $state['facetVars'] ?? [];
        $open   = $vars || $this->device !== 'mobile';
        $html   = '<details class="bsr-facets"' . ($open ? ' open' : '') . '><summary>' . $esc($this->text('text_filters', 'PLG_SYSTEM_BETTERSEARCH_T_FILTERS'))
            . ($vars ? ' <span class="bsr-facets-n">' . count($vars) . '</span>' : '') . '</summary>';
        $html  .= '<form method="get" action="' . $esc($state['action']) . '">';
        foreach ($state['hidden'] as $name => $value) {
            if (!array_key_exists($name, $vars)) {
                $html .= '<input type="hidden" name="' . $esc($name) . '" value="' . $esc($value) . '">';
            }
        }
        if ($state['sort'] !== (string) $this->params->get('default_sort', 'relevance')) {
            $html .= '<input type="hidden" name="bs_sort" value="' . $esc($state['sort']) . '">';
        }

        if (isset($f['brand'])) {
            $label = $f['brand']['label'] !== '' ? $f['brand']['label'] : $this->text('text_brand', 'PLG_SYSTEM_BETTERSEARCH_T_BRAND');
            $html .= '<div class="bsr-facet"><label class="bsr-facet-title" for="' . $esc($state['action'] === '#' ? 'bsr-brand-p' : 'bsr-brand') . '">' . $esc($label) . '</label>'
                . '<select name="bs_brand" id="' . ($state['action'] === '#' ? 'bsr-brand-p' : 'bsr-brand') . '" onchange="this.form.submit()"><option value="">' . $esc($this->text('text_all_brands', 'PLG_SYSTEM_BETTERSEARCH_T_ALL')) . '</option>';
            foreach ($f['brand']['values'] as $value => $count) {
                $html .= '<option value="' . $esc($value) . '"' . (mb_strtolower((string) $value) === mb_strtolower($f['brand']['active']) ? ' selected' : '') . '>' . $esc($value) . ' (' . (int) $count . ')</option>';
            }
            $html .= '</select></div>';
        }
        if (isset($f['price'])) {
            $pr    = $f['price'];
            $fmt   = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
            $html .= '<div class="bsr-facet bsr-facet-price"><span class="bsr-facet-title">' . $esc($this->text('text_price_filter', 'PLG_SYSTEM_BETTERSEARCH_T_PRICE'))
                . ($pr['symbol'] !== '' ? ' (' . $esc($pr['symbol']) . ')' : '') . '</span><div class="bsr-price-range">'
                . '<input type="number" inputmode="decimal" min="0" step="any" name="bs_min" value="' . $esc($fmt($pr['from'])) . '" placeholder="' . $esc($fmt($pr['min'])) . '" aria-label="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_PRICE_FROM')) . '">'
                . '<span aria-hidden="true">–</span>'
                . '<input type="number" inputmode="decimal" min="0" step="any" name="bs_max" value="' . $esc($fmt($pr['to'])) . '" placeholder="' . $esc($fmt($pr['max'])) . '" aria-label="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_PRICE_TO')) . '">'
                . '<button type="submit">' . $esc($this->text('text_apply', 'PLG_SYSTEM_BETTERSEARCH_T_APPLY')) . '</button></div></div>';
        }
        foreach (['sale' => ['text_sale_only', 'PLG_SYSTEM_BETTERSEARCH_T_SALE_ONLY'], 'stock' => ['text_stock_only', 'PLG_SYSTEM_BETTERSEARCH_T_STOCK_ONLY']] as $flag => [$key, $lang]) {
            if (isset($f[$flag])) {
                $html .= '<label class="bsr-facet bsr-check"><input type="checkbox" name="bs_' . $flag . '" value="1"' . ($f[$flag]['active'] ? ' checked' : '')
                    . ' onchange="this.form.submit()"> <span>' . $esc($this->text($key, $lang)) . ' <span class="bsr-chip-count">' . (int) $f[$flag]['count'] . '</span></span></label>';
            }
        }
        $html .= '<noscript><button type="submit">OK</button></noscript></form>';
        if ($vars) {
            $clear = array_fill_keys(array_keys($vars), null);
            $html .= '<a class="bsr-clear" href="' . $esc(($state['url'])($clear + ['bs_page' => null])) . '"' . $this->nofollow() . '>' . $esc($this->text('text_clear_filters', 'PLG_SYSTEM_BETTERSEARCH_T_CLEAR_FILTERS')) . '</a>';
        }

        return $html . '</details>';
    }

    /** Cards of one page of results (also used by "load more"). */
    public function cards(array $items, array $state): string
    {
        $perPage = $state['perPage'];
        $slice   = array_slice($items, ($state['page'] - 1) * $perPage, $perPage);
        $data    = $this->store->items(array_column($slice, 'id'));
        $html    = '';
        $index   = 0;
        $this->query = (string) ($state['query'] ?? $this->query);
        foreach ($slice as $item) {
            if (isset($data[$item['id']])) {
                $data[$item['id']]->featured = !empty($item['featured']);
                $html .= $this->card($data[$item['id']], $state['page'] === 1 && $index++ < $this->deviceColumns());
                $this->listed[] = ['name' => (string) $data[$item['id']]->title, 'url' => (string) $data[$item['id']]->link];
            }
        }

        return $html;
    }

    private function card(object $row, bool $eager): string
    {
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<li class="bsr-card' . ($row->featured ? ' is-featured' : '') . '" data-bs-id="' . (int) $row->id . '"><a class="bsr-cover" href="' . $esc($row->link) . '" aria-label="' . $esc($row->title) . '" tabindex="-1"></a>';
        $featured = $row->featured && $this->bool('featured_badge', true)
            ? '<span class="bsr-badge">' . $esc($this->text('text_featured', 'PLG_SYSTEM_BETTERSEARCH_T_FEATURED')) . '</span>' : '';
        $html .= $this->productBadges($row, 'page', 'bsr-badges', 'bsr-pbadge', $featured);

        if ($this->bool('page_image', true) && $this->str('page_image_position', 'top', ['top', 'left', 'right', 'none']) !== 'none') {
            $width = $this->str('page_image_position', 'top', ['top', 'left', 'right']) === 'top'
                ? (int) ceil(1280 / max(1, $this->int('page_cols', 4, 1, 8))) : 240;
            $sizes = '';
            if ($this->str('page_image_position', 'top', ['top', 'left', 'right']) === 'top' && $this->str('page_layout', 'grid', ['grid', 'list']) === 'grid') {
                $sizes = sprintf('(max-width:768px) %dvw, (max-width:1024px) %dvw, %dpx', (int) ceil(100 / $this->int('page_cols_mobile', 2, 1, 4)),
                    (int) ceil(100 / $this->int('page_cols_tablet', 3, 1, 8)), $width);
            }
            $html .= '<div class="bsr-img">' . ($row->image !== '' ? $this->img($row, $width, $eager ? 'eager' : 'lazy', $sizes) : '<span class="bsr-noimg"></span>') . '</div>';
        }

        $html .= '<div class="bsr-body">';
        if ($this->bool('page_show_category', true) && $row->category !== '') {
            $html .= '<div class="bsr-cat">' . ($row->catLink !== '' ? '<a href="' . $esc($row->catLink) . '">' . $esc($row->category) . '</a>' : $esc($row->category)) . '</div>';
        }
        if ($this->bool('page_show_app', false)) {
            $html .= '<div class="bsr-app">' . $esc($this->store->appTitle($row->app_id)) . '</div>';
        }
        $tag   = $this->str('page_title_tag', 'h3', ['h2', 'h3', 'h4', 'div']);
        $html .= '<' . $tag . ' class="bsr-title"><a href="' . $esc($row->link) . '">' . $this->mark($row->title) . '</a></' . $tag . '>';
        if ($this->bool('page_show_sku', false) && trim((string) $row->sku) !== '') {
            $html .= '<div class="bsr-sku">' . $esc($this->text('text_sku', 'PLG_SYSTEM_BETTERSEARCH_T_SKU')) . ' ' . $this->mark((string) $row->sku) . '</div>';
        }
        if ($this->bool('page_show_excerpt', true) && trim((string) $row->excerpt) !== '') {
            $html .= '<p class="bsr-excerpt">' . $this->mark($this->cut((string) $row->excerpt, $this->int('page_excerpt_length', 140, 20, 1000))) . '</p>';
        }
        $foot = '';
        if ($this->bool('page_show_price', true)) {
            $foot .= $this->price($row, 'bsr-price');
        }
        if ($this->showAvailability('page') && $row->isProduct) {
            $foot .= '<div class="bsr-stock">' . $this->availability($row) . '</div>';
        } elseif ($this->bool('page_show_stock', false) && $row->isProduct) {
            $foot .= '<div class="bsr-stock">' . $this->stock($row) . '</div>';
        }
        // "Add to cart" / "Ask for a quote" first, the link to the product beside them (outlined)
        $actions = $this->actions($row, 'page');
        $view    = $this->bool('page_show_button', true)
            ? '<a class="bsr-btn' . ($actions !== '' ? ' bsr-btn-alt' : '') . '" href="' . $esc($row->link) . '">' . $esc($this->text('text_button', 'PLG_SYSTEM_BETTERSEARCH_T_BUTTON')) . '</a>' : '';
        $foot   .= $actions !== '' ? '<div class="bsr-buttons">' . $actions . $view . '</div>' : $view;
        if ($foot !== '') {
            $html .= '<div class="bsr-foot">' . $foot . '</div>';
        }

        return $html . '</div></li>';
    }

    private function chips(array $chips, string $class): string
    {
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<nav class="bsr-chips ' . $class . '">';
        foreach ($chips as $chip) {
            $html .= '<a class="bsr-chip' . ($chip['active'] ? ' is-active' : '') . '" href="' . $esc($chip['url']) . '"' . $this->nofollow()
                . ($chip['active'] ? ' aria-current="true"' : '') . '>' . $esc($chip['title'])
                . ' <span class="bsr-chip-count">' . (int) $chip['count'] . '</span></a>';
        }

        return $html . '</nav>';
    }

    private function pagination(array $state, int $total): string
    {
        $pages = (int) ceil($total / max(1, $state['perPage']));
        if ($pages <= 1) {
            return '';
        }
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $mode = $this->str('page_pagination', 'both', ['numbers', 'loadmore', 'both']);
        $page = $state['page'];
        $url  = $state['url'];
        $html = '';

        if ($mode !== 'numbers' && $page < $pages) {
            // only the search parameters: the page's own (option, view…) must not reach the AJAX request
            $search = http_build_query(array_filter(array_merge(['query' => $state['query'], 'bs_sort' => $state['sort'], 'bs_cat' => $state['cat'] ?: null,
                'bs_app' => $state['app'] ?: null], $state['facetVars'] ?? []), fn ($v) => $v !== null), '', '&', PHP_QUERY_RFC3986);
            $html .= '<div class="bsr-more-wrap"><button type="button" class="bsr-more" data-page="' . ($page + 1) . '" data-pages="' . $pages . '" data-search="'
                . $esc($search) . '">' . $esc($this->text('text_load_more', 'PLG_SYSTEM_BETTERSEARCH_T_LOAD_MORE')) . '</button></div>';
        }
        if ($mode !== 'loadmore') {
            $html .= '<nav class="bsr-pages" aria-label="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_PAGES')) . '">';
            $link  = fn (int $p, string $label, string $class = '') => '<a class="bsr-page' . $class . '" href="' . $esc($url(['bs_page' => $p > 1 ? $p : null])) . '"' . $this->nofollow()
                . ($p === $page ? ' aria-current="page"' : '') . '>' . $label . '</a>';
            if ($page > 1) {
                $html .= $link($page - 1, '&lsaquo;', ' bsr-prev');
            }
            $shown = [];
            foreach ([1, $pages, $page - 2, $page - 1, $page, $page + 1, $page + 2] as $p) {
                if ($p >= 1 && $p <= $pages) {
                    $shown[$p] = true;
                }
            }
            ksort($shown);
            $prev = 0;
            foreach (array_keys($shown) as $p) {
                if ($p - $prev > 1) {
                    $html .= '<span class="bsr-gap">…</span>';
                }
                $html .= $link($p, (string) $p, $p === $page ? ' is-active' : '');
                $prev  = $p;
            }
            if ($page < $pages) {
                $html .= $link($page + 1, '&rsaquo;', ' bsr-next');
            }
            $html .= '</nav>';
        }

        return $html;
    }

    /** @return array<string, string> sort value => label, in the order of the setting */
    public function sortOptions(): array
    {
        $all = [
            'relevance'  => 'PLG_SYSTEM_BETTERSEARCH_SORT_RELEVANCE',
            'title'      => 'PLG_SYSTEM_BETTERSEARCH_SORT_TITLE',
            'title_desc' => 'PLG_SYSTEM_BETTERSEARCH_SORT_TITLE_DESC',
            'price'      => 'PLG_SYSTEM_BETTERSEARCH_SORT_PRICE',
            'price_desc' => 'PLG_SYSTEM_BETTERSEARCH_SORT_PRICE_DESC',
            'newest'     => 'PLG_SYSTEM_BETTERSEARCH_SORT_NEWEST',
            'popular'    => 'PLG_SYSTEM_BETTERSEARCH_SORT_POPULAR',
        ];
        $chosen = $this->params->get('page_sorts', array_keys($all));
        // a fresh install stores the default of the checkboxes as one comma-separated string
        $chosen = is_string($chosen) ? array_map('trim', explode(',', $chosen)) : (array) $chosen;
        $out    = [];
        foreach ($all as $key => $lang) {
            if (in_array($key, $chosen, true) || $key === 'relevance') {
                $out[$key] = Text::_($lang);
            }
        }

        return $out;
    }

    /** CSS of the results page block. */
    public function pageCss(string $id): string
    {
        $s      = '#' . $id;
        $layout = $this->str('page_layout', 'grid', ['grid', 'list']);
        $pos    = $this->str('page_image_position', 'top', ['top', 'left', 'right', 'none']);
        if ($layout === 'list' && $pos === 'top') {
            $pos = 'left';
        }
        $ratio  = self::RATIOS[$this->str('page_image_ratio', '1-1', array_keys(self::RATIOS))];
        $hover  = $this->str('page_hover', 'lift', ['none', 'lift', 'shadow', 'zoom', 'border']);
        $colsD  = $layout === 'list' ? 1 : $this->int('page_cols', 4, 1, 8);
        $colsT  = $layout === 'list' ? 1 : $this->int('page_cols_tablet', 3, 1, 8);
        $colsM  = $layout === 'list' ? 1 : $this->int('page_cols_mobile', 2, 1, 4);
        $cols   = ['desktop' => $colsD, 'tablet' => $colsT, 'mobile' => $colsM][$this->device];

        $vars = [
            '--bsr-cols'       => (string) $cols,
            '--bsr-gap'        => $this->int('page_gap', 20, 0, 80) . 'px',
            '--bsr-card-bg'    => $this->color('page_card_bg', '#ffffff'),
            '--bsr-border'     => $this->color('page_card_border', '#e5e7eb'),
            '--bsr-radius'     => $this->int('page_card_radius', 10, 0, 40) . 'px',
            '--bsr-shadow'     => self::SHADOWS[$this->str('page_card_shadow', 'none', array_keys(self::SHADOWS))],
            '--bsr-pad'        => $this->int('page_card_padding', 16, 0, 60) . 'px',
            '--bsr-img-bg'     => $this->color('page_image_bg', '#ffffff'),
            '--bsr-img-fit'    => $this->str('page_image_fit', 'contain', ['contain', 'cover']),
            '--bsr-img-ratio'  => $ratio,
            '--bsr-img-w'      => $this->int('page_image_width', 32, 10, 70) . '%',
            '--bsr-img-radius' => ['rect' => '0', 'rounded' => '8px', 'circle' => '50%'][$this->str('page_image_shape', 'rect', ['rect', 'rounded', 'circle'])],
            '--bsr-title-size' => $this->int('page_title_size', 16, 10, 40) . 'px',
            '--bsr-title-lines' => (string) $this->int('page_title_lines', 2, 1, 6),
            '--bsr-font'       => $this->int('page_font_size', 14, 10, 24) . 'px',
            '--bsr-title'      => $this->color('page_title_color', 'inherit'),
            '--bsr-text'       => $this->color('page_text_color', '#4b5563'),
            '--bsr-price'      => $this->color('page_price_color', 'inherit'),
            '--bsr-accent'     => $this->color('page_accent', 'var(--primary, #1a73e8)'),
            '--bsr-hl-bg'      => $this->color('highlight_bg', 'transparent'),
            '--bsr-hl-color'   => $this->color('highlight_color', 'inherit'),
            '--bsr-align'      => $this->str('page_align', 'left', ['left', 'center']) === 'center' ? 'center' : 'start',
            '--bsr-max'        => $this->int('page_max_width', 0, 0, 3000) > 0 ? $this->int('page_max_width', 0, 0, 3000) . 'px' : 'none',
            '--bsr-feat'       => $this->color('featured_color', 'var(--bsr-accent)'),
        ];

        $theme = Themes::tokens($this->params);
        if ($theme !== null) {
            $vars = array_merge($vars, Themes::pageVars($theme));
        }

        $css  = $s . '{' . $this->vars($vars) . '}';
        $css .= str_replace('#S', $s, <<<CSS
#S{box-sizing:border-box;width:100%;max-width:var(--bsr-max);margin:0 auto;font-size:var(--bsr-font);text-align:start}
#S *{box-sizing:border-box}
#S a,#S span,#S div,#S li,#S p,#S b,#S del,#S ul,#S nav,#S h2,#S h3,#S h4,#S label,#S select,#S button{font-family:inherit;letter-spacing:normal;text-transform:none}
#S a,#S span,#S b,#S del,#S li,#S p,#S label,#S div,#S ul,#S nav,#S form{font-size:inherit;line-height:inherit;font-weight:inherit;color:inherit}
#S .bsr-body,#S .bsr-title{text-align:var(--bsr-align)}
#S .bsr-heading{margin:0 0 16px}
#S .bsr-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 16px}
#S .bsr-count{color:var(--bsr-text)}
#S .bsr-sort label{display:flex;gap:8px;align-items:center;margin:0}
#S .bsr-sort select{padding:6px 10px;border:1px solid var(--bsr-border);border-radius:6px;background:#fff;font:inherit;min-height:0;height:auto;width:auto}
#S .bsr-note{margin:0 0 12px;color:var(--bsr-text)}
#S .bsr-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}
#S .bsr-chip{display:inline-flex;gap:6px;align-items:center;padding:6px 12px;border:var(--bsr-bw,1px) solid var(--bsr-border);border-radius:999px;color:inherit;text-decoration:none;background:var(--bsr-card-bg);font-size:.92em;line-height:1.2}
#S .bsr-chip:hover{border-color:var(--bsr-accent)}
#S .bsr-chip.is-active{background:var(--bsr-accent);border-color:var(--bsr-accent);color:var(--bsr-on-accent,#fff)}
#S .bsr-chip-count{opacity:.7;font-size:.9em}
#S .bsr-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(var(--bsr-cols),minmax(0,1fr));gap:var(--bsr-gap)}
#S .bsr-card{position:relative;margin:0;display:flex;flex-direction:column;background:var(--bsr-card-bg);border:var(--bsr-bw,1px) solid var(--bsr-border);border-radius:var(--bsr-radius);box-shadow:var(--bsr-shadow);overflow:hidden;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
#S .bsr-cover{position:absolute;inset:0;z-index:1}
#S .bsr-img{position:relative;background:var(--bsr-img-bg);aspect-ratio:var(--bsr-img-ratio);display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:var(--bsr-img-radius)}
#S .bsr-img img{width:100%;height:100%;object-fit:var(--bsr-img-fit);display:block;transition:transform .35s ease}
#S .bsr-body{padding:var(--bsr-pad);display:flex;flex-direction:column;gap:6px;flex:1 1 auto;min-width:0}
#S .bsr-cat,#S .bsr-app,#S .bsr-sku{font-size:.85em;color:var(--bsr-text)}
#S .bsr-cat a{position:relative;z-index:2;color:inherit;text-decoration:none}
#S .bsr-cat a:hover{text-decoration:underline}
#S .bsr-title{margin:0;font-size:var(--bsr-title-size);line-height:1.3;font-weight:var(--bsr-tw,600);color:var(--bsr-title);display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--bsr-title-lines);overflow:hidden}
#S .bsr-title a{color:inherit;text-decoration:none}
#S .bsr-excerpt{margin:0;color:var(--bsr-text);font-size:.92em;line-height:1.45}
#S .bsr-foot{margin-top:auto;padding-top:6px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center}
#S .bsr-foot .bsr-btn{margin-inline-start:auto}
#S .bsr-center .bsr-foot{justify-content:center;flex-direction:column}
#S .bsr-center .bsr-foot .bsr-btn{margin-inline-start:0}
#S .bsr-price{font-weight:700;font-size:1.1em;color:var(--bsr-price);display:flex;gap:6px;align-items:baseline;flex-wrap:wrap}
#S .bsr-price del{font-weight:400;font-size:.85em;color:var(--bsr-text);text-decoration:line-through;text-decoration-thickness:1px}
#S .bs-sr{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
#S .bsr-price .bs-from{font-weight:400;font-size:.8em;color:var(--bsr-text)}
#S .bsr-stock{font-size:.85em}
#S .bsr-layout{display:grid;grid-template-columns:minmax(180px,240px) minmax(0,1fr);gap:24px;align-items:start}
#S .bsr-main{min-width:0}
#S .bsr-facets{border:var(--bsr-bw,1px) solid var(--bsr-border);border-radius:var(--bsr-radius);background:var(--bsr-card-bg);padding:12px 14px;margin:0 0 16px}
#S .bsr-layout .bsr-facets{margin:0;position:sticky;top:16px}
#S .bsr-facets summary{cursor:pointer;font-weight:600;list-style:none;display:flex;align-items:center;gap:8px}
#S .bsr-facets summary::-webkit-details-marker{display:none}
#S .bsr-facets summary::after{content:'';margin-inline-start:auto;width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);transition:transform .2s}
#S .bsr-facets[open] summary::after{transform:rotate(-135deg)}
#S .bsr-facets-n{background:var(--bsr-accent);color:var(--bsr-on-accent,#fff);border-radius:999px;padding:0 7px;font-size:.8em;line-height:1.6}
#S .bsr-facets form{display:flex;flex-direction:column;gap:14px;margin:12px 0 0}
#S > .bsr-facets form{flex-direction:row;flex-wrap:wrap;align-items:flex-end}
#S .bsr-facet{display:flex;flex-direction:column;gap:6px;margin:0}
#S .bsr-facet-title{font-weight:600;font-size:.92em}
#S .bsr-facets select,#S .bsr-facets input[type=number]{padding:6px 8px;border:1px solid var(--bsr-border);border-radius:var(--bsr-btn-radius,6px);background:var(--bsr-input-bg,#fff);font:inherit;min-height:0;height:auto;width:100%;max-width:100%;color:var(--bsr-input-text,#111)}
#S .bsr-price-range{display:flex;gap:6px;align-items:center}
#S .bsr-price-range input{width:0;flex:1 1 0;min-width:0}
#S .bsr-facets button{padding:6px 10px;border:1px solid var(--bsr-accent);background:var(--bsr-accent);color:var(--bsr-on-accent,#fff);border-radius:var(--bsr-btn-radius,6px);font:inherit;cursor:pointer;line-height:1.2}
#S .bsr-check{flex-direction:row;align-items:center;gap:8px;cursor:pointer}
#S .bsr-check input{margin:0;width:16px;height:16px;accent-color:var(--bsr-accent)}
#S .bsr-clear{display:inline-block;margin-top:12px;font-size:.9em;color:var(--bsr-accent)}
#S .bsr-didyoumean a{color:var(--bsr-accent);font-weight:600}
@media (max-width:860px){#S .bsr-layout{grid-template-columns:1fr;gap:12px}#S .bsr-layout .bsr-facets{position:static}}
#S .bs-stock-in{color:#15803d}#S .bs-stock-out{color:#b91c1c}
#S .bsr-btn{position:relative;z-index:2;display:inline-block;padding:8px 14px;border-radius:var(--bsr-btn-radius,6px);background:var(--bsr-accent);color:var(--bsr-on-accent,#fff);text-decoration:none;font-weight:600;font-size:.92em}
#S .bsr-btn:hover{filter:brightness(1.08)}
#S mark{background:var(--bsr-hl-bg);color:var(--bsr-hl-color);font-weight:800;padding:0}
#S .bsr-empty{padding:32px 0;color:var(--bsr-text)}
#S .bsr-pages{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin:28px 0 0}
#S .bsr-page,#S .bsr-gap{min-width:38px;padding:8px 10px;text-align:center;border:var(--bsr-bw,1px) solid var(--bsr-border);border-radius:var(--bsr-btn-radius,6px);color:inherit;text-decoration:none;line-height:1}
#S .bsr-gap{border:0}
#S .bsr-page.is-active{background:var(--bsr-accent);border-color:var(--bsr-accent);color:var(--bsr-on-accent,#fff)}
#S .bsr-more-wrap{text-align:center;margin:28px 0 0}
#S .bsr-more{padding:12px 28px;border:var(--bsr-bw,1px) solid var(--bsr-accent);border-radius:var(--bsr-btn-radius,6px);background:transparent;color:var(--bsr-accent);font:inherit;font-weight:600;cursor:pointer}
#S .bsr-more:hover{background:var(--bsr-accent);color:var(--bsr-on-accent,#fff)}
#S .bsr-more[disabled]{opacity:.5;cursor:wait}
#S .bsr-noimg{display:block;width:40%;aspect-ratio:1/1;border-radius:8px;background:repeating-linear-gradient(45deg,#f3f4f6,#f3f4f6 8px,#e5e7eb 8px,#e5e7eb 16px)}
#S .bsr-badges{position:absolute;top:10px;inset-inline-start:10px;inset-inline-end:10px;z-index:2;pointer-events:none;display:flex;flex-wrap:wrap;align-items:flex-start;gap:5px}
#S .bsr-badge{background:var(--bsr-feat);color:var(--bsr-on-accent,#fff);font-size:.75em;font-weight:700;line-height:1.5;padding:1px 9px;border-radius:999px}
#S .bsr-pbadge{background:var(--bs-badge,var(--bsr-accent));color:#fff;font-size:.75em;font-weight:700;line-height:1.5;padding:1px 9px;border-radius:4px;white-space:nowrap}
#S .bs-avail{display:inline-flex;flex-wrap:wrap;gap:0 8px}
#S .bs-stock-low{color:#b45309}
#S .bs-delivery{color:var(--bsr-text)}
#S .bsr-buttons{display:flex;flex-wrap:wrap;gap:8px;width:100%}
#S .bsr-buttons .bsr-btn{margin-inline-start:0}
#S .bsr-center .bsr-buttons{justify-content:center}
#S .bs-cart,#S .bs-quote{position:relative;z-index:2;display:inline-block;padding:8px 14px;border-radius:var(--bsr-btn-radius,6px);border:1px solid var(--bsr-accent);background:var(--bsr-accent);color:var(--bsr-on-accent,#fff);font:inherit;font-weight:600;font-size:.92em;line-height:1.2;text-decoration:none;cursor:pointer;margin:0}
#S .bs-quote,#S .bs-cart-options{background:transparent;color:var(--bsr-accent)}
#S .bs-cart:hover,#S .bs-quote:hover{filter:brightness(1.08)}
#S .bs-cart[disabled]{opacity:.6;cursor:wait}
#S .bs-cart.is-done{background:#15803d;border-color:#15803d;color:#fff}
#S .bsr-btn-alt{background:transparent;color:var(--bsr-accent);border:1px solid var(--bsr-accent)}
CSS);

        if ($pos === 'left' || $pos === 'right') {
            $css .= "$s .bsr-card{flex-direction:" . ($pos === 'left' ? 'row' : 'row-reverse') . "}$s .bsr-img{flex:0 0 var(--bsr-img-w);aspect-ratio:auto;min-height:100%}"
                . "$s .bsr-img img{position:absolute;inset:0}";
        }
        if ($this->bool('featured_frame', true)) {
            $css .= "$s .bsr-card.is-featured{border-color:var(--bsr-feat);box-shadow:0 0 0 1px var(--bsr-feat),var(--bsr-shadow)}";
        }
        $css .= $theme !== null ? Themes::pageRules($theme, $s) : match ($hover) {
            'lift'   => "$s .bsr-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(0,0,0,.12)}",
            'shadow' => "$s .bsr-card:hover{box-shadow:0 12px 28px rgba(0,0,0,.14)}",
            'zoom'   => "$s .bsr-card:hover .bsr-img img{transform:scale(1.06)}",
            'border' => "$s .bsr-card:hover{border-color:var(--bsr-accent)}",
            default  => '',
        };

        // columns for the other devices (the visitor's device is the base value above)
        $css .= "@media (min-width:1025px){{$s}{--bsr-cols:$colsD}}@media (min-width:769px) and (max-width:1024px){{$s}{--bsr-cols:$colsT}}"
            . "@media (max-width:768px){{$s}{--bsr-cols:$colsM}}";
        if ($pos === 'left' || $pos === 'right') {
            // narrow phones: side images become top images when there is more than one column
            $css .= $colsM > 1 ? "@media (max-width:768px){{$s} .bsr-card{flex-direction:column}{$s} .bsr-img{aspect-ratio:var(--bsr-img-ratio)}{$s} .bsr-img img{position:static}}" : '';
        }

        // the administrator's own CSS last; ".bettersearch-results" selectors get the block id
        return $css . Themes::scopePage(Themes::css($this->params), $id);
    }

    // ================================================================ shared parts

    private function img(object $row, int $width, string $loading, string $sizes = ''): string
    {
        $esc         = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        [$src, $set] = $this->bool('thumbnails', true) ? $this->thumbs->get($row->image, $width) : ['', ''];
        $alt         = trim((string) $row->image_alt) !== '' ? $row->image_alt : $row->title;

        return '<img src="' . $esc($src !== '' ? $src : $this->store->imageUrl($row->image)) . '"'
            . ($set !== '' ? ' srcset="' . $esc($set) . '" sizes="' . $esc($sizes !== '' ? $sizes : $width . 'px') . '"' : '')
            . ' alt="' . $esc($alt) . '" loading="' . $loading . '" decoding="async"'
            . ($loading === 'eager' ? ' fetchpriority="high"' : '') . '>';
    }

    private function price(object $row, string $class = 'bs-price'): string
    {
        if (!$row->prices) {
            return '';
        }
        $p    = $row->prices;
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $from = $p['from'] ? '<span class="bs-from">' . $esc($this->text('text_price_from', 'PLG_SYSTEM_BETTERSEARCH_T_PRICE_FROM')) . '</span> ' : '';

        // the regular price crossed out, the current one in bold; both named for screen readers
        $old = $p['sale'] ? '<del><span class="bs-sr">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_PRICE_REGULAR')) . ' </span>' . $esc($p['regular']) . '</del>' : '';
        $now = $p['sale'] ? '<span class="bs-sr">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_PRICE_SALE')) . ' </span>' : '';

        return '<span class="' . $class . '">' . $old . '<span>' . $now . $from . '<b>' . $esc($p['price']) . '</b></span></span>';
    }

    private function stock(object $row): string
    {
        return (int) $row->in_stock
            ? '<span class="bs-stock-in">' . htmlspecialchars($this->text('text_in_stock', 'PLG_SYSTEM_BETTERSEARCH_T_IN_STOCK'), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="bs-stock-out">' . htmlspecialchars($this->text('text_out_of_stock', 'PLG_SYSTEM_BETTERSEARCH_T_OUT_OF_STOCK'), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** Availability and delivery time are on for this view (live results or the results page). */
    private function showAvailability(string $view): bool
    {
        return $this->bool('avail_enabled', false) && in_array($this->str('avail_where', 'both', ['live', 'page', 'both']), [$view, 'both'], true);
    }

    /**
     * Stock state (in stock, last items, out of stock, optionally the quantity) and the delivery time:
     * the value of the chosen Gridbox field, else the texts for products in and out of stock.
     */
    private function availability(object $row): string
    {
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $qty = $row->stockQty ?? null;
        $in  = (int) $row->in_stock === 1;
        $low = $this->int('avail_low', 3, 0, 100000);
        if (!$in) {
            $html = '<span class="bs-stock-out">' . $esc($this->text('text_out_of_stock', 'PLG_SYSTEM_BETTERSEARCH_T_OUT_OF_STOCK')) . '</span>';
        } elseif ($qty !== null && $low > 0 && $qty <= $low) {
            $html = '<span class="bs-stock-low">' . $esc($this->fmt($this->text('text_low_stock', 'PLG_SYSTEM_BETTERSEARCH_T_LOW_STOCK'), ['%d' => $qty])) . '</span>';
        } else {
            $label = $this->text('text_in_stock', 'PLG_SYSTEM_BETTERSEARCH_T_IN_STOCK');
            if ($qty !== null && $this->bool('avail_qty', false)) {
                $label .= ' (' . $this->fmt($this->text('text_quantity', 'PLG_SYSTEM_BETTERSEARCH_T_QUANTITY'), ['%d' => $qty]) . ')';
            }
            $html = '<span class="bs-stock-in">' . $esc($label) . '</span>';
        }
        $delivery = trim((string) ($row->delivery ?? ''));
        if ($delivery === '') {
            $delivery = trim((string) $this->params->get($in ? 'delivery_in' : 'delivery_out', ''));
        }

        return '<span class="bs-avail">' . $html . ($delivery !== '' ? '<span class="bs-delivery">' . $esc($delivery) . '</span>' : '') . '</span>';
    }

    /** "Add to cart" (or "Choose options") and "Ask for a quote" of a product, where they are switched on. */
    private function actions(object $row, string $view): string
    {
        if (!$row->isProduct || !in_array($this->str('buttons_where', 'page', ['live', 'page', 'both']), [$view, 'both'], true)) {
            return '';
        }
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $id   = (int) $row->id;
        $html = '';
        if ($this->bool('cart_button', false) && (int) $row->in_stock === 1 && $row->prices !== null) {
            if ($row->cartable) {
                $html .= '<button type="button" class="bs-cart' . ($view === 'page' ? ' bsr-cart' : '') . '" data-bs-cart="' . $id . '" data-bs-id="' . $id . '">'
                    . $esc($this->text('text_add_to_cart', 'PLG_SYSTEM_BETTERSEARCH_T_ADD_TO_CART')) . '</button>';
            } elseif ($row->hasOptions) {
                // variants or extra options are chosen on the product page
                $html .= '<a class="bs-cart bs-cart-options" href="' . $esc($row->link) . '" data-bs-id="' . $id . '">'
                    . $esc($this->text('text_choose_options', 'PLG_SYSTEM_BETTERSEARCH_T_CHOOSE_OPTIONS')) . '</a>';
            }
        }
        if ($this->bool('quote_button', false)) {
            $when = $this->str('quote_when', 'no_price', ['always', 'no_price', 'out_of_stock', 'no_price_or_out']);
            $want = match ($when) {
                'always'       => true,
                'out_of_stock' => (int) $row->in_stock !== 1,
                'no_price'     => $row->prices === null,
                default        => $row->prices === null || (int) $row->in_stock !== 1,
            };
            $url = $want ? $this->quoteUrl($row) : '';
            if ($url !== '') {
                $html .= '<a class="bs-quote" href="' . $esc($url) . '" data-bs-id="' . $id . '"' . (preg_match('#^https?://#i', $url) && !str_starts_with($url, \Joomla\CMS\Uri\Uri::root()) ? ' target="_blank" rel="noopener"' : '') . '>'
                    . $esc($this->text('text_ask_quote', 'PLG_SYSTEM_BETTERSEARCH_T_ASK_QUOTE')) . '</a>';
            }
        }

        return $html;
    }

    /**
     * Address of "Ask for a quote": the setting with {title}, {sku}, {id}, {url} and {query} filled in
     * (URL-encoded), else an e-mail to the site. Only site paths, http(s), mailto: and tel: addresses.
     */
    private function quoteUrl(object $row): string
    {
        $template = trim((string) $this->params->get('quote_url', ''));
        if ($template === '') {
            $template = $this->quoteFallback;
        }
        if ($template === '' || preg_match('/[\x00-\x1F"<>]/', $template)) {
            return '';
        }
        if (!preg_match('#^(https?://|mailto:|tel:|/(?!/))#i', $template)) {
            if (!preg_match('#^[a-z0-9][a-z0-9_\-./]*(\?.*)?$#i', $template)) {
                return '';
            }
            $template = \Joomla\CMS\Uri\Uri::root(true) . '/' . $template;
        }
        $link = (string) $row->link;
        $abs  = $link === '' || $link === '#' ? \Joomla\CMS\Uri\Uri::root() : (preg_match('#^https?://#i', $link) ? $link
            : \Joomla\CMS\Uri\Uri::getInstance()->toString(['scheme', 'host', 'port']) . '/' . ltrim($link, '/'));
        $values = ['{title}' => (string) $row->title, '{sku}' => trim((string) $row->sku), '{id}' => (string) (int) $row->id, '{url}' => $abs, '{query}' => $this->query];

        return strtr($template, array_map('rawurlencode', $values));
    }

    /**
     * Escapes the text and marks the parts that match the query groups — also across spaces and
     * dashes ("MI 3155" is marked for the query "mi3155").
     */
    public function mark(string $text): string
    {
        $esc = fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if (!$this->highlight || !$this->bool('highlight', true)) {
            return $esc($text);
        }

        // map every alphanumeric character of the folded text back to its offset in the original
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fold  = $this->norm ??= new Normalizer();
        $norm  = '';
        $map   = [];
        foreach ($chars as $i => $ch) {
            // the alphabet of a title is small: fold each distinct character once per request
            $folded = $this->charMap[$ch] ??= $fold->compact($ch);
            for ($k = 0; $k < strlen($folded); $k++) {
                $norm  .= $folded[$k];
                $map[]  = $i;
            }
        }
        $on = array_fill(0, count($chars), false);
        foreach ($this->highlight as $term) {
            $offset = 0;
            while (($pos = strpos($norm, $term, $offset)) !== false) {
                // only from the start of a word (avoid marking "mi" inside "Termin")
                $first = $map[$pos];
                $prev  = $first > 0 ? $chars[$first - 1] : ' ';
                if ($first === 0 || !preg_match('/[\p{L}\p{N}]/u', $prev) || preg_match('/[0-9]/', $term)) {
                    for ($k = $pos; $k < $pos + strlen($term); $k++) {
                        $on[$map[$k]] = true;
                    }
                }
                $offset = $pos + 1;
            }
        }

        $out  = '';
        $open = false;
        foreach ($chars as $i => $ch) {
            // spaces between marked characters stay inside the mark
            $mark = $on[$i] || ($open && trim($ch) === '' || $open && preg_match('/^[-\/.]$/', $ch)) && $this->nextMarked($on, $chars, $i);
            if ($mark && !$open) {
                $out .= '<mark>';
                $open = true;
            } elseif (!$mark && $open) {
                $out .= '</mark>';
                $open = false;
            }
            $out .= $esc($ch);
        }

        return $out . ($open ? '</mark>' : '');
    }

    private function nextMarked(array $on, array $chars, int $i): bool
    {
        for ($k = $i + 1, $n = count($chars); $k < $n; $k++) {
            if (preg_match('/[\p{L}\p{N}]/u', $chars[$k])) {
                return $on[$k];
            }
        }

        return false;
    }

    private function cut(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut   = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:-') . '…';
    }

    private function vars(array $vars): string
    {
        $out = '';
        foreach ($vars as $k => $v) {
            $out .= $k . ':' . $v . ';';
        }

        return $out;
    }

    public function deviceColumns(): int
    {
        if ($this->str('page_layout', 'grid', ['grid', 'list']) === 'list') {
            return 1;
        }

        return match ($this->device) {
            'mobile' => $this->int('page_cols_mobile', 2, 1, 4),
            'tablet' => $this->int('page_cols_tablet', 3, 1, 8),
            default  => $this->int('page_cols', 4, 1, 8),
        };
    }
}
