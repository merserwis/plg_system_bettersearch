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
use Joomla\CMS\Router\Route;
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

    /** @var string[] compact query groups for highlighting */
    private array $highlight = [];

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
        $items = $result['items'];
        $esc   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html  = '';

        if ($result['mode'] === 'typo' && $result['corrected'] !== '') {
            $html .= '<div class="bs-note">' . sprintf($esc($this->text('text_corrected', 'PLG_SYSTEM_BETTERSEARCH_T_CORRECTED')), '<b>' . $esc($result['corrected']) . '</b>') . '</div>';
        } elseif ($result['mode'] === 'partial') {
            $html .= '<div class="bs-note">' . $esc($this->text('text_partial', 'PLG_SYSTEM_BETTERSEARCH_T_PARTIAL')) . '</div>';
        }

        $opt = 0;
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
                $html .= '<li role="presentation"><a class="bs-opt bs-cat" role="option" id="bs-opt-' . $opt++ . '" href="'
                    . $esc(Route::_($this->store->categoryLink($cat->app_id, $cat->id), false)) . '">'
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
                    $byApp[$this->bool('live_group_apps', true) ? $item['app_id'] : 0][] = $data[$item['id']];
                }
            }
            $counts = $result['app_counts'] ?? [];
            foreach ($byApp as $appId => $rows) {
                $html .= '<div class="bs-section">';
                if ($this->bool('live_section_titles', true) && (count($byApp) > 1 || $categories)) {
                    $title = $appId ? $this->store->appTitle($appId) : $this->text('text_products', 'PLG_SYSTEM_BETTERSEARCH_T_PRODUCTS');
                    $count = $appId ? ($counts[$appId] ?? count($rows)) : $result['total'];
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
            $html .= '<div class="bs-empty">' . sprintf($esc($this->text('text_no_results', 'PLG_SYSTEM_BETTERSEARCH_T_NO_RESULTS')), '<b>' . $esc($q) . '</b>') . '</div>';
        }

        $out = '<div class="bs-live-body">' . $html . '</div>';
        if ($items && $this->bool('live_show_all', true)) {
            $label = sprintf($this->text('text_show_all', 'PLG_SYSTEM_BETTERSEARCH_T_SHOW_ALL'), (int) $result['total']);
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
        if ($this->bool('live_show_stock', false) && $row->isProduct) {
            $meta[] = $this->stock($row);
        }

        $excerpt = '';
        if ($this->bool('live_show_excerpt', false) && trim((string) $row->excerpt) !== '') {
            $excerpt = '<span class="bs-excerpt">' . $this->mark($this->cut((string) $row->excerpt, $this->int('live_excerpt_length', 90, 20, 400))) . '</span>';
        }

        $price = $this->bool('live_show_price', true) ? $this->price($row) : '';

        return '<li role="presentation"><a class="bs-opt bs-item" role="option" id="bs-opt-' . $n . '" href="' . $esc($row->link) . '">' . $image
            . '<span class="bs-info"><span class="bs-title">' . $this->mark($row->title) . '</span>'
            . ($meta ? '<span class="bs-meta">' . implode('<span class="bs-dot">·</span>', $meta) . '</span>' : '')
            . $excerpt . '</span>' . $price . '</a></li>';
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
        ];

        $css = '.bs-live{' . $this->vars($vars) . '}';
        $css .= <<<CSS
.bs-live{position:absolute;box-sizing:border-box;background:var(--bs-bg);color:var(--bs-text);border:1px solid var(--bs-border);border-radius:var(--bs-radius);box-shadow:var(--bs-shadow);font-size:var(--bs-font);line-height:1.35;z-index:var(--bs-z);overflow:hidden;display:none;flex-direction:column;text-align:left;opacity:0;transform:translateY(6px);transition:opacity .16s ease,transform .16s ease}
.bs-live *{box-sizing:border-box}
.bs-live a,.bs-live span,.bs-live div,.bs-live li,.bs-live b,.bs-live del,.bs-live ul{font-size:inherit;line-height:inherit;font-family:inherit;font-weight:inherit;font-style:normal;letter-spacing:normal;text-transform:none;text-decoration:none;color:inherit;margin:0;padding:0;border:0;background:none;text-align:inherit}
.bs-live.is-open{display:flex}
.bs-live.is-shown{opacity:1;transform:none}
.bs-live .bs-live-body{overflow-y:auto;max-height:var(--bs-max-h);padding:6px 0;overscroll-behavior:contain}
.bs-live .bs-section+.bs-section{border-top:1px solid var(--bs-border);margin-top:4px;padding-top:4px}
.bs-live .bs-section-title{font-size:.78em;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--bs-muted);padding:8px 16px 4px}
.bs-live .bs-section-count{font-weight:400;opacity:.8}
.bs-live ul{list-style:none;margin:0;padding:0}
.bs-live li{margin:0;padding:0}
.bs-live .bs-opt{display:flex;gap:12px;align-items:center;padding:8px 16px;color:inherit;text-decoration:none;outline:none}
.bs-live .bs-opt:hover,.bs-live .bs-opt.is-active{background:var(--bs-hover)}
.bs-live .bs-opt.is-active{box-shadow:inset 3px 0 0 var(--bs-accent)}
.bs-live .bs-img{flex:0 0 var(--bs-img);width:var(--bs-img);height:var(--bs-img);display:flex;align-items:center;justify-content:center;background:var(--bs-img-bg);border-radius:var(--bs-img-radius);overflow:hidden}
.bs-live .bs-img img{width:100%;height:100%;object-fit:var(--bs-img-fit);display:block}
.bs-live .bs-info{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}
.bs-live .bs-title{font-weight:600;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--bs-lines);overflow:hidden}
.bs-live .bs-meta{font-size:.85em;color:var(--bs-muted);display:flex;flex-wrap:wrap;gap:0 6px}
.bs-live .bs-excerpt{font-size:.85em;color:var(--bs-muted);display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
.bs-live .bs-price{flex:0 0 auto;text-align:right;white-space:nowrap;font-weight:700;display:flex;flex-direction:column;align-items:flex-end}
.bs-live .bs-price del{font-weight:400;font-size:.85em;color:var(--bs-muted)}
.bs-live .bs-price .bs-from{font-weight:400;font-size:.8em;color:var(--bs-muted)}
.bs-live mark{background:var(--bs-hl-bg);color:var(--bs-hl-color);font-weight:800;padding:0}
.bs-live .bs-cat{gap:8px;align-items:baseline;flex-wrap:wrap}
.bs-live .bs-cat-name{font-weight:600}
.bs-live .bs-cat-path{font-size:.85em;color:var(--bs-muted)}
.bs-live .bs-note{padding:8px 16px;font-size:.9em;color:var(--bs-muted);border-bottom:1px solid var(--bs-border)}
.bs-live .bs-empty{padding:24px 16px;text-align:center;color:var(--bs-muted)}
.bs-live .bs-all{justify-content:center;font-weight:700;background:var(--bs-accent);color:#fff;padding:12px 16px;border-radius:0}
.bs-live .bs-all:hover,.bs-live .bs-all.is-active{background:var(--bs-accent);filter:brightness(1.08);box-shadow:none}
.bs-live .bs-stock-in{color:#15803d}.bs-live .bs-stock-out{color:#b91c1c}
.bs-live-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:calc(var(--bs-z, 99999) - 1)}
.bs-live.is-full{position:fixed;inset:0;border-radius:0;border:0;max-height:none}
.bs-live.is-full .bs-live-body{max-height:none;flex:1}
.bs-live .bs-full-head{display:none}
.bs-live.is-full .bs-full-head{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--bs-border)}
.bs-live .bs-full-head input{flex:1;font-size:16px;padding:10px 12px;border:1px solid var(--bs-border);border-radius:8px;background:#fff;color:#111;min-width:0}
.bs-live .bs-full-head button{border:0;background:none;font-size:26px;line-height:1;padding:4px 8px;color:var(--bs-text);cursor:pointer}
.bs-live.is-loading .bs-live-body{opacity:.55}
CSS;

        if ($pos === 'right') {
            $css .= '.bs-live .bs-item{flex-direction:row-reverse}.bs-live .bs-item .bs-price{align-items:flex-start;text-align:left}';
        } elseif ($pos === 'top') {
            $css .= '.bs-live .bs-item{flex-direction:column;align-items:stretch;text-align:center}.bs-live .bs-item .bs-img{width:100%;height:auto;aspect-ratio:1/1;flex-basis:auto}.bs-live .bs-item .bs-price{align-items:center;text-align:center}';
        }
        if ($layout === 'grid') {
            $css .= '.bs-live .bs-list{display:grid;grid-template-columns:repeat(var(--bs-cols),minmax(0,1fr));gap:4px;padding:0 6px}'
                . '.bs-live .bs-list .bs-opt{flex-direction:column;align-items:stretch;text-align:center;padding:10px;border-radius:8px}'
                . '.bs-live .bs-list .bs-img{width:100%;height:auto;aspect-ratio:1/1;flex-basis:auto}'
                . '.bs-live .bs-list .bs-price{align-items:center;text-align:center}';
        }

        return $css;
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
        $total = $result['total'];
        $center = $this->str('page_align', 'left', ['left', 'center']) === 'center' ? ' bsr-center' : '';
        $html  = '<div class="bettersearch-results' . $center . '" id="' . $id . '" data-query="' . $esc($q) . '">';

        $heading = $this->str('page_heading', 'gridbox', ['gridbox', 'custom', 'none']);
        if ($heading === 'custom' && $q !== '') {
            $html .= '<h2 class="bsr-heading">' . sprintf($esc($this->text('text_heading', 'PLG_SYSTEM_BETTERSEARCH_T_HEADING')), $esc($q), $total) . '</h2>';
        }

        if ($q === '') {
            return $html . '<p class="bsr-empty">' . $esc($this->text('text_enter_query', 'PLG_SYSTEM_BETTERSEARCH_T_ENTER_QUERY')) . '</p></div>';
        }

        if ($result['mode'] === 'typo' && $result['corrected'] !== '') {
            $html .= '<p class="bsr-note">' . sprintf($esc($this->text('text_corrected', 'PLG_SYSTEM_BETTERSEARCH_T_CORRECTED')), '<b>' . $esc($result['corrected']) . '</b>') . '</p>';
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

        // toolbar: count + sorting
        $html .= '<div class="bsr-toolbar">';
        if ($this->bool('page_count', true)) {
            $html .= '<div class="bsr-count">' . sprintf($esc($this->text('text_count', 'PLG_SYSTEM_BETTERSEARCH_T_COUNT')), $total) . '</div>';
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
            $html .= '<div class="bsr-empty">' . sprintf($esc($this->text('text_no_results', 'PLG_SYSTEM_BETTERSEARCH_T_NO_RESULTS')), '<b>' . $esc($q) . '</b>') . '</div>';

            return $html . '</div>';
        }

        $html .= '<ul class="bsr-grid">' . $this->cards($result['items'], $state) . '</ul>';
        $html .= $this->pagination($state, $total);

        return $html . '</div>';
    }

    /** Cards of one page of results (also used by "load more"). */
    public function cards(array $items, array $state): string
    {
        $perPage = $state['perPage'];
        $slice   = array_slice($items, ($state['page'] - 1) * $perPage, $perPage);
        $data    = $this->store->items(array_column($slice, 'id'));
        $html    = '';
        $index   = 0;
        foreach ($slice as $item) {
            if (isset($data[$item['id']])) {
                $html .= $this->card($data[$item['id']], $state['page'] === 1 && $index++ < $this->deviceColumns());
            }
        }

        return $html;
    }

    private function card(object $row, bool $eager): string
    {
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<li class="bsr-card"><a class="bsr-cover" href="' . $esc($row->link) . '" aria-label="' . $esc($row->title) . '" tabindex="-1"></a>';

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
        if ($this->bool('page_show_stock', false) && $row->isProduct) {
            $foot .= '<div class="bsr-stock">' . $this->stock($row) . '</div>';
        }
        if ($this->bool('page_show_button', true)) {
            $foot .= '<a class="bsr-btn" href="' . $esc($row->link) . '">' . $esc($this->text('text_button', 'PLG_SYSTEM_BETTERSEARCH_T_BUTTON')) . '</a>';
        }
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
            $html .= '<a class="bsr-chip' . ($chip['active'] ? ' is-active' : '') . '" href="' . $esc($chip['url']) . '"'
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
            $search = http_build_query(array_filter(['query' => $state['query'], 'bs_sort' => $state['sort'], 'bs_cat' => $state['cat'] ?: null,
                'bs_app' => $state['app'] ?: null], fn ($v) => $v !== null), '', '&', PHP_QUERY_RFC3986);
            $html .= '<div class="bsr-more-wrap"><button type="button" class="bsr-more" data-page="' . ($page + 1) . '" data-pages="' . $pages . '" data-search="'
                . $esc($search) . '">' . $esc($this->text('text_load_more', 'PLG_SYSTEM_BETTERSEARCH_T_LOAD_MORE')) . '</button></div>';
        }
        if ($mode !== 'loadmore') {
            $html .= '<nav class="bsr-pages" aria-label="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_PAGES')) . '">';
            $link  = fn (int $p, string $label, string $class = '') => '<a class="bsr-page' . $class . '" href="' . $esc($url(['bs_page' => $p > 1 ? $p : null])) . '"'
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
            '--bsr-align'      => $this->str('page_align', 'left', ['left', 'center']),
            '--bsr-max'        => $this->int('page_max_width', 0, 0, 3000) > 0 ? $this->int('page_max_width', 0, 0, 3000) . 'px' : 'none',
        ];

        $css  = $s . '{' . $this->vars($vars) . '}';
        $css .= str_replace('#S', $s, <<<CSS
#S{box-sizing:border-box;width:100%;max-width:var(--bsr-max);margin:0 auto;font-size:var(--bsr-font);text-align:left}
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
#S .bsr-chip{display:inline-flex;gap:6px;align-items:center;padding:6px 12px;border:1px solid var(--bsr-border);border-radius:999px;color:inherit;text-decoration:none;background:var(--bsr-card-bg);font-size:.92em;line-height:1.2}
#S .bsr-chip:hover{border-color:var(--bsr-accent)}
#S .bsr-chip.is-active{background:var(--bsr-accent);border-color:var(--bsr-accent);color:#fff}
#S .bsr-chip-count{opacity:.7;font-size:.9em}
#S .bsr-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(var(--bsr-cols),minmax(0,1fr));gap:var(--bsr-gap)}
#S .bsr-card{position:relative;margin:0;display:flex;flex-direction:column;background:var(--bsr-card-bg);border:1px solid var(--bsr-border);border-radius:var(--bsr-radius);box-shadow:var(--bsr-shadow);overflow:hidden;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
#S .bsr-cover{position:absolute;inset:0;z-index:1}
#S .bsr-img{position:relative;background:var(--bsr-img-bg);aspect-ratio:var(--bsr-img-ratio);display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:var(--bsr-img-radius)}
#S .bsr-img img{width:100%;height:100%;object-fit:var(--bsr-img-fit);display:block;transition:transform .35s ease}
#S .bsr-body{padding:var(--bsr-pad);display:flex;flex-direction:column;gap:6px;flex:1 1 auto;min-width:0}
#S .bsr-cat,#S .bsr-app,#S .bsr-sku{font-size:.85em;color:var(--bsr-text)}
#S .bsr-cat a{position:relative;z-index:2;color:inherit;text-decoration:none}
#S .bsr-cat a:hover{text-decoration:underline}
#S .bsr-title{margin:0;font-size:var(--bsr-title-size);line-height:1.3;font-weight:600;color:var(--bsr-title);display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--bsr-title-lines);overflow:hidden}
#S .bsr-title a{color:inherit;text-decoration:none}
#S .bsr-excerpt{margin:0;color:var(--bsr-text);font-size:.92em;line-height:1.45}
#S .bsr-foot{margin-top:auto;padding-top:6px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center}
#S .bsr-foot .bsr-btn{margin-left:auto}
#S .bsr-center .bsr-foot{justify-content:center;flex-direction:column}
#S .bsr-center .bsr-foot .bsr-btn{margin-left:0}
#S .bsr-price{font-weight:700;font-size:1.1em;color:var(--bsr-price);display:flex;gap:6px;align-items:baseline;flex-wrap:wrap}
#S .bsr-price del{font-weight:400;font-size:.85em;color:var(--bsr-text)}
#S .bsr-price .bs-from{font-weight:400;font-size:.8em;color:var(--bsr-text)}
#S .bsr-stock{font-size:.85em}
#S .bs-stock-in{color:#15803d}#S .bs-stock-out{color:#b91c1c}
#S .bsr-btn{position:relative;z-index:2;display:inline-block;padding:8px 14px;border-radius:6px;background:var(--bsr-accent);color:#fff;text-decoration:none;font-weight:600;font-size:.92em}
#S .bsr-btn:hover{filter:brightness(1.08)}
#S mark{background:var(--bsr-hl-bg);color:var(--bsr-hl-color);font-weight:800;padding:0}
#S .bsr-empty{padding:32px 0;color:var(--bsr-text)}
#S .bsr-pages{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin:28px 0 0}
#S .bsr-page,#S .bsr-gap{min-width:38px;padding:8px 10px;text-align:center;border:1px solid var(--bsr-border);border-radius:6px;color:inherit;text-decoration:none;line-height:1}
#S .bsr-gap{border:0}
#S .bsr-page.is-active{background:var(--bsr-accent);border-color:var(--bsr-accent);color:#fff}
#S .bsr-more-wrap{text-align:center;margin:28px 0 0}
#S .bsr-more{padding:12px 28px;border:1px solid var(--bsr-accent);border-radius:6px;background:transparent;color:var(--bsr-accent);font:inherit;font-weight:600;cursor:pointer}
#S .bsr-more:hover{background:var(--bsr-accent);color:#fff}
#S .bsr-more[disabled]{opacity:.5;cursor:wait}
#S .bsr-noimg{display:block;width:40%;aspect-ratio:1/1;border-radius:8px;background:repeating-linear-gradient(45deg,#f3f4f6,#f3f4f6 8px,#e5e7eb 8px,#e5e7eb 16px)}
CSS);

        if ($pos === 'left' || $pos === 'right') {
            $css .= "$s .bsr-card{flex-direction:" . ($pos === 'left' ? 'row' : 'row-reverse') . "}$s .bsr-img{flex:0 0 var(--bsr-img-w);aspect-ratio:auto;min-height:100%}"
                . "$s .bsr-img img{position:absolute;inset:0}";
        }
        $css .= match ($hover) {
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

        return $css;
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

        return '<span class="' . $class . '">' . ($p['sale'] ? '<del>' . $esc($p['regular']) . '</del>' : '') . '<span>' . $from . '<b>' . $esc($p['price']) . '</b></span></span>';
    }

    private function stock(object $row): string
    {
        return (int) $row->in_stock
            ? '<span class="bs-stock-in">' . htmlspecialchars($this->text('text_in_stock', 'PLG_SYSTEM_BETTERSEARCH_T_IN_STOCK'), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="bs-stock-out">' . htmlspecialchars($this->text('text_out_of_stock', 'PLG_SYSTEM_BETTERSEARCH_T_OUT_OF_STOCK'), ENT_QUOTES, 'UTF-8') . '</span>';
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
            $folded = $fold->compact($ch);
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
