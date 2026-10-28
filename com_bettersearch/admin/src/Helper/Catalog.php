<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_bettersearch
 *
 * The map of BetterSearch' administrator: which page shows which tool, in which section of the control
 * panel, with which icon, and which of its switches say that it is on. The settings themselves are the
 * fieldsets of the plugin's manifest (plg_system_bettersearch/bettersearch.xml): a fieldset with page="x" is a
 * tab of page x, in the order of the file. Two kinds of pages:
 *
 *  - the tools used day to day: an overview first (what the tool did, its buttons), then its settings;
 *    the most used ones are in the administrator menu, with a cog that opens their settings;
 *  - the configuration: what is set once and forgotten, one page with a tab for each tool.
 *
 * Quick setup: the few switches a new site decides first, each one setting one or more settings of the
 * plugin, with the value most sites should have.
 */

namespace Merserwis\Component\BetterSearch\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Catalog
{
    /** the sections of the control panel, in order */
    public const GROUPS = ['search', 'display', 'insights', 'shop'];

    /**
     * The pages of the tools: group (section of the control panel), icon (Joomla's icon-*), on (the
     * switches that say the tool works, any of them; none: the tool is always there and shows no dot),
     * settings (the tab where the tool is set, the one the cog of the menu opens), store (only shown with a Gridbox store).
     */
    public const PAGES = [
        'index'      => ['group' => 'search',   'icon' => 'database',    'on' => [], 'settings' => 'indexing'],
        'engine'     => ['group' => 'search',   'icon' => 'search',      'on' => [], 'settings' => 'engine'],
        'rules'      => ['group' => 'search',   'icon' => 'filter',      'on' => []],
        'live'       => ['group' => 'display',  'icon' => 'bolt',        'on' => ['live_enabled'], 'settings' => 'live'],
        'results'    => ['group' => 'display',  'icon' => 'list',        'on' => ['replace_results'], 'settings' => 'page'],
        'appearance' => ['group' => 'display',  'icon' => 'paint-brush', 'on' => []],
        'stats'      => ['group' => 'insights', 'icon' => 'chart',       'on' => ['log_searches', 'track_conversions', 'report_enabled'], 'settings' => 'stats_settings'],
        'products'   => ['group' => 'shop',     'icon' => 'cart',        'on' => ['product_badges', 'avail_enabled', 'cart_button', 'quote_button'], 'store' => true],
        'config'     => ['group' => '',         'icon' => 'cog',         'on' => []],
    ];

    /** the tabs of the configuration page (fieldsets with page="config"), as tiles of the control panel */
    public const CONFIG = [
        'voice'  => ['icon' => 'comments', 'on' => ['voice_search']],
        'seo'    => ['icon' => 'code',     'on' => ['seo_opensearch', 'seo_jsonld', 'seo_canonical']],
        'texts'  => ['icon' => 'edit',     'on' => []],
        'backup' => ['icon' => 'download', 'on' => []],
        'basic'  => ['icon' => 'info-circle', 'on' => []],
    ];

    /**
     * Quick setup, in sections: switch => [the settings it sets, the recommended value]. A switch of
     * several settings is on when all of them are; only a switch that was changed is saved, so a
     * setting tuned on its own page is not overwritten by a switch nobody touched. 'values' names the
     * off and on value of a setting that is a list rather than a yes/no (voice search: off / all).
     */
    public const SETUP = [
        'search' => [
            'live_enabled'    => ['set' => ['live_enabled'], 'recommended' => 1],
            'replace_results' => ['set' => ['replace_results'], 'recommended' => 1],
            'qs_forgiving'    => ['set' => ['typo_tolerance', 'partial_matches', 'stemming', 'did_you_mean'], 'recommended' => 1],
            'index_pages'     => ['set' => ['index_pages'], 'recommended' => 0],
            'qs_index'        => ['set' => ['auto_sync', 'instant_reindex'], 'recommended' => 1],
        ],
        'display' => [
            'voice_search' => ['set' => ['voice_search'], 'recommended' => 0, 'values' => ['voice_search' => ['off', 'all']]],
            'thumbnails'   => ['set' => ['thumbnails'], 'recommended' => 1],
            'qs_seo'       => ['set' => ['seo_opensearch', 'seo_jsonld', 'seo_canonical'], 'recommended' => 1],
        ],
        'insights' => [
            'log_searches'      => ['set' => ['log_searches'], 'recommended' => 1],
            'track_conversions' => ['set' => ['track_conversions'], 'recommended' => 1],
            'report_enabled'    => ['set' => ['report_enabled'], 'recommended' => 0],
        ],
        'shop' => [
            'featured_badge' => ['set' => ['featured_badge'], 'recommended' => 1],
            'avail_enabled'  => ['set' => ['avail_enabled'], 'recommended' => 0],
            'cart_button'    => ['set' => ['cart_button'], 'recommended' => 0],
            'quote_button'   => ['set' => ['quote_button'], 'recommended' => 0],
        ],
    ];

    /** the page of a name, or null */
    public static function page(string $name): ?array
    {
        return self::PAGES[$name] ?? null;
    }

    /** a setting that is on: anything but empty, 0, off or none (switches, lists such as "off / report / enforce", texts) */
    public static function isOn(Registry $params, array $defaults, string $key): bool
    {
        $value = $params->get($key, $defaults[$key] ?? '');
        if (\is_array($value) || \is_object($value)) {
            return (bool) $value;
        }

        return !\in_array(strtolower(trim((string) $value)), ['', '0', 'off', 'none', 'no'], true);
    }

    /** 'on' when any of the switches is on, 'off' when none is, '' for a page without switches */
    public static function state(array $keys, Registry $params, array $defaults): string
    {
        if (!$keys) {
            return '';
        }
        foreach ($keys as $key) {
            if (self::isOn($params, $defaults, $key)) {
                return 'on';
            }
        }

        return 'off';
    }

    /**
     * The tiles of the control panel: group => [[name, icon, state, link], …]; the tools of the store
     * only when the site has a Gridbox store, the configuration as a group of its own.
     *
     * @return array<string, list<array{name: string, icon: string, state: string, link: string, config: bool}>>
     */
    public static function tiles(Registry $params, array $defaults, bool $store): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach (self::PAGES as $name => $page) {
            if ($page['group'] === '' || (!empty($page['store']) && !$store)) {
                continue;
            }
            $groups[$page['group']][] = ['name' => $name, 'icon' => $page['icon'], 'state' => self::state($page['on'], $params, $defaults),
                'link' => 'index.php?option=com_bettersearch&view=page&page=' . $name, 'config' => false];
        }
        $groups['config'] = [];
        foreach (self::CONFIG as $name => $tab) {
            if (!empty($tab['store']) && !$store) {
                continue;
            }
            $groups['config'][] = ['name' => $name, 'icon' => $tab['icon'], 'state' => self::state($tab['on'], $params, $defaults),
                'link' => 'index.php?option=com_bettersearch&view=page&page=config&tab=' . $name, 'config' => true];
        }

        return array_filter($groups);
    }

    /**
     * The state of a quick setup switch from the settings: on when every setting it sets is on.
     */
    public static function setupValue(array $item, Registry $params, array $defaults): int
    {
        foreach ($item['set'] as $key) {
            if (!self::isOn($params, $defaults, $key)) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * The settings a quick setup sends: only the switches whose value differs from the one shown
     * (the initial values come back with the form).
     *
     * @param array<string, mixed> $posted  switch => 0|1
     * @param array<string, mixed> $initial switch => 0|1, as the page showed it
     *
     * @return array<string, int|string> setting => 0|1 (or the value of a list)
     */
    public static function setupChanges(array $posted, array $initial, bool $store): array
    {
        $out = [];
        foreach (self::SETUP as $section => $items) {
            if ($section === 'shop' && !$store) {
                continue;
            }
            foreach ($items as $switch => $item) {
                if (!\array_key_exists($switch, $posted)) {
                    continue;
                }
                $value = (int) $posted[$switch] === 1 ? 1 : 0;
                if (\array_key_exists($switch, $initial) && (int) $initial[$switch] === $value) {
                    continue;
                }
                foreach ($item['set'] as $key) {
                    $out[$key] = $item['values'][$key][$value] ?? $value;
                }
            }
        }

        return $out;
    }
}
