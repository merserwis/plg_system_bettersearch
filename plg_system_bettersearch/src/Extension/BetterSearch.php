<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Replaces the Gridbox search: the live results under the search fields and the results page.
 * Products are found through an own index in which model codes match however they are typed
 * ("MI 3155" = "MI3155" = "MI-3155"); the administrator sets the ranking (pinned and hidden
 * products per query, boosts), the exclusions and the look of both result views.
 *
 * Gridbox keeps rendering its search elements; this plugin
 *  - takes the query of the results page away from Gridbox (onAfterRoute) so Gridbox does no
 *    search work, and puts its own results into the Gridbox results element (onAfterRender);
 *  - adds a script that takes over the Gridbox search fields (live results, Enter, the icon);
 *  - keeps the index up to date after the response is sent (onAfterRespond).
 */

namespace Merserwis\Plugin\System\BetterSearch\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\BetterSearch\Engine\Indexer;
use Merserwis\Plugin\System\BetterSearch\Engine\Normalizer;
use Merserwis\Plugin\System\BetterSearch\Engine\Searcher;
use Merserwis\Plugin\System\BetterSearch\Render\Renderer;
use Merserwis\Plugin\System\BetterSearch\Render\Store;
use Merserwis\Plugin\System\BetterSearch\Render\Thumbs;

final class BetterSearch extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '1.1.0';

    private const CACHE_GROUP = 'plg_system_bettersearch';

    /** Default words left out of queries (Polish and English connectors). */
    private const STOPWORDS = 'i, w, z, ze, na, do, dla, od, po, o, u, a, oraz, lub, czy, the, and, of, for, with, to, in';

    protected $autoloadLanguage = true;

    /** Query of the results page, taken from Gridbox (null = not a results page). */
    private ?string $query = null;

    /** The visitor asked for a page: the index check may run after the response. */
    private bool $syncDue = false;

    private ?array $levels = null;

    private ?string $deviceClass = null;

    private ?Normalizer $normalizer = null;

    public static function getSubscribedEvents(): array
    {
        return [
            // after Gridbox: its redirect to the SEF address is built from the request (with the query)
            'onAfterRoute'          => ['onAfterRoute', Priority::MIN],
            // after Gridbox, which builds its search elements in its own onAfterRender
            'onAfterRender'         => ['onAfterRender', Priority::MIN],
            'onAfterRespond'        => 'onAfterRespond',
            'onAjaxBettersearch'    => 'onAjax',
            'onExtensionAfterSave'  => 'onExtensionAfterSave',
            'onBetterSearchModule'  => 'onModule',
        ];
    }

    // ================================================================ site: results page

    public function onAfterRoute(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        // settings as saved (some sites hand phones an older copy of plugin parameters)
        $this->params = $this->savedParams() ?? $this->params;
        $this->syncDue = true;

        $input = $app->getInput();
        if (!$this->params->get('replace_results', 1) || $input->getCmd('option') !== 'com_gridbox'
            || !in_array($input->getCmd('view'), ['system', 'page'], true) || $input->getCmd('format', 'html') !== 'html') {
            return;
        }
        $query = trim((string) $input->get('query', '', 'raw'));
        if ($query === '') {
            return;
        }
        $this->query = mb_substr($query, 0, 200);
        // Gridbox searches when it sees the query: give it none, the results come from this plugin
        $input->set('query', '');
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site') || $app->getDocument()->getType() !== 'html' || $app->getInput()->getCmd('tmpl') === 'component') {
            return;
        }
        if ($app->getInput()->getCmd('option') === 'com_gridbox' && $app->getInput()->getCmd('view') === 'gridbox') {
            // the Gridbox editor
            return;
        }

        $body    = $app->getBody();
        $changed = false;

        if ($this->query !== null) {
            $t0   = hrtime(true);
            $html = '';
            try {
                $html = $this->resultsPage($this->query);
            } catch (\Throwable $e) {
                $html = '<!-- Better Search: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
            }
            $html .= sprintf('<!-- Better Search %s | %s | %.1f ms -->', self::VERSION, $this->device(), (hrtime(true) - $t0) / 1e6);
            $new   = $this->placeResults($body, $html, $this->query);
            if ($new !== null) {
                $body    = $new;
                $changed = true;
            }
        }

        $wanted = $this->query !== null || (bool) $this->params->get('load_everywhere', 0)
            || preg_match('/ba-item-(store-)?search\b|bettersearch-box/', $body);
        if ($wanted && $this->params->get('live_enabled', 1) && ($pos = stripos($body, '</head>')) !== false) {
            $body    = substr($body, 0, $pos) . $this->assets() . substr($body, $pos);
            $changed = true;
        } elseif ($this->query !== null && ($pos = stripos($body, '</body>')) !== false) {
            // results page without live search: the script still runs "load more"
            $body    = substr($body, 0, $pos) . $this->assets(false) . substr($body, $pos);
            $changed = true;
        }

        if ($changed) {
            $app->setBody($body);
        }
    }

    /** Puts the results into the Gridbox results element and the query into its headline. */
    private function placeResults(string $body, string $html, string $query): ?string
    {
        // the class as a whole word: "ba-item-search-result-headline" is the headline, not the list
        if (!preg_match('/<div\b[^>]*\bclass="(?:[^"]*\s)?ba-item-(?:store-)?search-result(?:\s[^"]*)?"[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            // no Gridbox results element on this page: put the block at the start of the component output
            if (preg_match('/<div\b[^>]*\bclass="(?:[^"]*\s)?ba-gridbox-page(?:\s[^"]*)?"[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
                $at = $m[0][1] + strlen($m[0][0]);

                return substr($body, 0, $at) . $html . substr($body, $at);
            }

            return null;
        }
        $open  = $m[0][1];
        $start = $open + strlen($m[0][0]);
        $end   = $this->elementEnd($body, $open);
        if ($end === null) {
            return null;
        }
        $body = substr($body, 0, $start) . $html . substr($body, $end);

        // headline "Search results for" + the query (Gridbox added an empty one)
        $heading = (string) $this->params->get('page_heading', 'gridbox');
        $body    = preg_replace_callback('/(<div\b[^>]*\bclass="[^"]*\bsearch-result-headline-wrapper\b[^"]*"[^>]*>\s*<(h[1-6]|p|div)\b[^>]*>)(.*?)(<\/\2>)/is',
            function ($h) use ($heading, $query) {
                if ($heading === 'none' || $heading === 'custom') {
                    return $h[1] . '' . $h[4] . '<style>.ba-item-search-result-headline{display:none}</style>';
                }

                return $h[1] . rtrim($h[3]) . ' ' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . $h[4];
            }, $body, 1) ?? $body;

        return $body;
    }

    /** Offset just before the closing tag of the div opened at $start (nested divs counted). */
    private function elementEnd(string $body, int $start): ?int
    {
        $depth = 0;
        if (!preg_match_all('/<(\/?)div\b[^>]*>/i', $body, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $start)) {
            return null;
        }
        foreach ($tags as $tag) {
            $depth += $tag[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return $tag[0][1];
            }
        }

        return null;
    }

    private function resultsPage(string $query): string
    {
        $input   = $this->getApplication()->getInput();
        $sorts   = Searcher::SORTS;
        $sort    = $input->getCmd('bs_sort', (string) $this->params->get('default_sort', 'relevance'));
        $sort    = in_array($sort, $sorts, true) ? $sort : 'relevance';
        $page    = max(1, $input->getInt('bs_page', 1));
        $cat     = max(0, $input->getInt('bs_cat', 0));
        $appId   = max(0, $input->getInt('bs_app', 0));
        $perPage = max(1, min(200, (int) $this->params->get('page_per_page', 24)));

        $result = $this->search($query, $sort);
        $state  = $this->filterState($result, $query, $sort, $cat, $appId, $page, $perPage);

        if ($page === 1 && !$cat && !$appId && $this->params->get('log_searches', 1)) {
            $this->logSearch($query, $result['total']);
        }

        $renderer = $this->renderer();
        $renderer->setHighlight($result['groups']);
        $id = 'bsr-' . substr(md5($query . microtime()), 0, 8);

        return '<style>' . $renderer->pageCss($id) . '</style>' . $renderer->page($state['result'], $state, $id);
    }

    /**
     * Category and app filters (chips), the filtered result and the URLs of the results page.
     */
    private function filterState(array $result, string $query, string $sort, int $cat, int $appId, int $page, int $perPage): array
    {
        $store = $this->store();
        $items = $result['items'];

        // the address of the results page with other filters
        $base = Uri::getInstance();
        $url  = function (array $set) use ($base, $query, $sort, $cat, $appId): string {
            $vars = array_merge(['query' => $query, 'bs_sort' => $sort !== $this->params->get('default_sort', 'relevance') ? $sort : null,
                'bs_cat' => $cat ?: null, 'bs_app' => $appId ?: null], $set);
            foreach ($base->getQuery(true) as $k => $v) {
                if (!array_key_exists($k, $vars) && $k !== 'bs_page') {
                    $vars[$k] = $v;
                }
            }
            $vars = array_filter($vars, fn ($v) => $v !== null && $v !== '');

            return $base->getPath() . ($vars ? '?' . http_build_query($vars, '', '&', PHP_QUERY_RFC3986) : '');
        };

        // apps present in the results
        $appCounts = [];
        foreach ($items as $item) {
            $appCounts[$item['app_id']] = ($appCounts[$item['app_id']] ?? 0) + 1;
        }
        $appChips = [];
        if ($this->params->get('page_app_filter', 1) && count($appCounts) > 1) {
            $appChips[] = ['title' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_ALL'), 'count' => count($items), 'active' => $appId === 0,
                'url' => $url(['bs_app' => null, 'bs_cat' => null])];
            foreach ($appCounts as $id => $count) {
                $appChips[] = ['title' => $store->appTitle($id), 'count' => $count, 'active' => $appId === $id,
                    'url' => $url(['bs_app' => $id, 'bs_cat' => null])];
            }
        }
        if ($appId > 0) {
            $items = array_values(array_filter($items, fn ($i) => $i['app_id'] === $appId));
        }

        // categories of the results (the product's own category, or its top category)
        $chips = [];
        if ($this->params->get('page_cat_filter', 1) && count($items) > 1) {
            $level  = (string) $this->params->get('page_cat_level', 'direct');
            $cats   = $store->categories();
            $counts = [];
            foreach ($items as $item) {
                $id = $item['category_id'];
                if ($level === 'top') {
                    $guard = 0;
                    while (($cats[$id]->parent ?? 0) > 0 && $guard++ < 50) {
                        $id = $cats[$id]->parent;
                    }
                }
                if ($id > 0 && isset($cats[$id])) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
            arsort($counts);
            $limit = max(1, (int) $this->params->get('page_cat_limit', 12));
            if (count($counts) > 1 || $cat) {
                $chips[] = ['title' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_ALL'), 'count' => count($items), 'active' => $cat === 0,
                    'url' => $url(['bs_cat' => null])];
                foreach (array_slice($counts, 0, $limit, true) as $id => $count) {
                    $chips[] = ['title' => $cats[$id]->title, 'count' => $count, 'active' => $cat === $id, 'url' => $url(['bs_cat' => $id])];
                }
                // the chosen category stays visible even when it is not among the top ones
                if ($cat && !isset(array_slice($counts, 0, $limit, true)[$cat]) && isset($cats[$cat])) {
                    $chips[] = ['title' => $cats[$cat]->title, 'count' => $counts[$cat] ?? 0, 'active' => true, 'url' => $url(['bs_cat' => $cat])];
                }
            }
        }
        if ($cat > 0) {
            $items = array_values(array_filter($items, fn ($i) => in_array($cat, $i['cats'], true)));
        }

        $filtered          = $result;
        $filtered['items'] = $items;
        $filtered['total'] = count($items);
        $page              = min($page, max(1, (int) ceil(count($items) / $perPage)));

        $hidden = ['query' => $query];
        if ($cat) {
            $hidden['bs_cat'] = (string) $cat;
        }
        if ($appId) {
            $hidden['bs_app'] = (string) $appId;
        }
        foreach ($base->getQuery(true) as $k => $v) {
            if (!isset($hidden[$k]) && !in_array($k, ['bs_page', 'bs_sort', 'query', 'bs_cat', 'bs_app'], true) && is_scalar($v)) {
                $hidden[$k] = (string) $v;
            }
        }

        return [
            'result'   => $filtered,
            'query'    => $query,
            'page'     => $page,
            'perPage'  => $perPage,
            'sort'     => $sort,
            'cat'      => $cat,
            'app'      => $appId,
            'chips'    => $chips,
            'appChips' => $appChips,
            'url'      => $url,
            'action'   => $base->toString(['path']),
            'hidden'   => $hidden,
        ];
    }

    // ================================================================ search with cache

    /** Searcher result, cached per query, sort, visitor levels and index version. */
    private function search(string $query, string $sort, array $apps = []): array
    {
        $minutes = max(0, min(1440, (int) $this->params->get('cache_time', 15)));
        $key     = md5(implode('|', [self::VERSION, $this->configHash(), $this->indexVersion(), $query, $sort, implode(',', $apps),
            implode(',', $this->levels()), $this->getApplication()->getLanguage()->getTag()]));

        $cache = $minutes > 0 ? $this->cache($minutes) : null;
        if ($cache) {
            $hit = $cache->get($key);
            if (is_array($hit)) {
                return $hit;
            }
        }

        $searcher = $this->searcher();
        $result   = $searcher->search($query, $sort, $apps);
        if ($cache) {
            $cache->store($result, $key);
        }

        return $result;
    }

    private function searcher(): Searcher
    {
        $searcher = new Searcher($this->db(), $this->params, $this->normalizer(), $this->levels(), $this->getApplication()->getLanguage()->getTag());

        // the vocabulary for typo correction is read once per index version
        $cache = $this->cache(1440);
        $key   = 'vocab-' . $this->indexVersion();
        $vocab = $cache->get($key);
        if (is_array($vocab)) {
            $searcher->setVocabulary($vocab);
        } elseif ($this->params->get('typo_tolerance', 1)) {
            $cache->store($searcher->exportVocabulary(), $key);
        }

        return $searcher;
    }

    // ================================================================ live results (com_ajax)

    public function onAjax($event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $task  = $input->getCmd('task', 'live');
        $this->params = $this->savedParams() ?? $this->params;

        try {
            if (str_starts_with($task, 'admin_')) {
                $data = $this->adminTask(substr($task, 6));
            } elseif ($task === 'live' && $app->isClient('site')) {
                $data = $this->liveResults(trim((string) $input->get('q', '', 'raw')));
            } elseif ($task === 'more' && $app->isClient('site')) {
                $data = $this->moreResults();
            } else {
                $data = ['error' => 'unknown task'];
            }
        } catch (\Throwable $e) {
            $data = ['error' => $e->getMessage()];
        }

        if (method_exists($event, 'addResult')) {
            $event->addResult($data);
        } else {
            $result   = $event->getArgument('result') ?? [];
            $result[] = $data;
            $event->setArgument('result', $result);
        }
    }

    private function liveResults(string $query): array
    {
        $query = mb_substr(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 0, 200);
        $min   = max(1, (int) $this->params->get('live_min_chars', 2));
        if (mb_strlen($this->normalizer()->compact($query)) < $min) {
            return ['html' => '', 'count' => 0, 'query' => $query];
        }

        $minutes  = max(0, min(1440, (int) $this->params->get('cache_time', 15)));
        $currency = $this->getApplication()->getInput()->cookie->getString('gridbox-currency', '');
        $key      = md5(implode('|', ['live', self::VERSION, $this->configHash(), $this->indexVersion(), $query, implode(',', $this->levels()),
            $this->getApplication()->getLanguage()->getTag(), $this->device(), $currency]));
        $cache = $minutes > 0 ? $this->cache($minutes) : null;
        if ($cache && is_array($hit = $cache->get($key))) {
            return $hit;
        }

        $t0     = hrtime(true);
        $result = $this->search($query, 'relevance');
        $limit  = max(1, min(50, (int) $this->params->get('live_limit', 8)));

        $live = $this->liveSlice($result);

        $categories = [];
        if ($this->params->get('live_categories', 1)) {
            $apps       = $this->indexer()->appIds();
            $categories = $this->searcher()->matchCategories($query, $this->store()->visibleCategories($apps),
                max(0, (int) $this->params->get('live_categories_limit', 4)));
        }

        $renderer = $this->renderer();
        $renderer->setHighlight($result['groups']);
        $data = [
            'html'  => $renderer->live($live, $categories, '#'),
            'count' => $result['total'],
            'query' => $query,
            'mode'  => $result['mode'],
            'ms'    => round((hrtime(true) - $t0) / 1e6, 1),
        ];
        if ($cache) {
            $cache->store($data, $key);
        }

        return $data;
    }

    /** The live results: the best items of each app (products first, in the order of the indexed apps). */
    private function liveSlice(array $result): array
    {
        $limit  = max(1, min(50, (int) $this->params->get('live_limit', 8)));
        $counts = [];
        $byApp  = [];
        foreach ($result['items'] as $item) {
            $counts[$item['app_id']]  = ($counts[$item['app_id']] ?? 0) + 1;
            $byApp[$item['app_id']][] = $item;
        }
        $items = [];
        if ($this->params->get('live_group_apps', 1)) {
            $other = max(0, (int) $this->params->get('live_limit_other', 3));
            $first = true;
            foreach ($this->indexer()->appIds() as $appId) {
                if (isset($byApp[$appId])) {
                    $items = array_merge($items, array_slice($byApp[$appId], 0, $first ? $limit : $other));
                    $first = false;
                }
            }
        } else {
            $items = array_slice($result['items'], 0, $limit);
        }
        $live               = $result;
        $live['items']      = $items;
        $live['app_counts'] = $counts;

        return $live;
    }

    /** Next page of the results page ("load more"). */
    private function moreResults(): array
    {
        $input   = $this->getApplication()->getInput();
        $query   = mb_substr(trim((string) $input->get('query', '', 'raw')), 0, 200);
        $sort    = $input->getCmd('bs_sort', (string) $this->params->get('default_sort', 'relevance'));
        $sort    = in_array($sort, Searcher::SORTS, true) ? $sort : 'relevance';
        $page    = max(1, $input->getInt('bs_page', 2));
        $perPage = max(1, min(200, (int) $this->params->get('page_per_page', 24)));
        $result  = $this->search($query, $sort);
        $state   = $this->filterState($result, $query, $sort, max(0, $input->getInt('bs_cat', 0)), max(0, $input->getInt('bs_app', 0)), $page, $perPage);

        $renderer = $this->renderer();
        $renderer->setHighlight($result['groups']);

        return ['html' => $renderer->cards($state['result']['items'], $state), 'page' => $state['page'],
            'pages' => (int) ceil($state['result']['total'] / $perPage)];
    }

    /** The module "Better Search for Gridbox - search field" asks for its markup (the same script serves it). */
    public function onModule($event): void
    {
        $params = $event->getArgument('params');
        $params = $params instanceof Registry ? $params : new Registry();
        $esc    = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $color  = fn (string $key, string $default) => preg_match('/^#[0-9a-f]{3,8}$/i', trim((string) $params->get($key, ''))) ? trim((string) $params->get($key)) : $default;
        $width  = trim((string) $params->get('width', '100%'));
        $width  = preg_match('/^\d+(\.\d+)?(px|%|rem|em|vw)$/', $width) ? $width : '100%';
        $id     = 'bsbox-' . substr(md5(uniqid('', true)), 0, 8);
        $ph     = trim((string) $params->get('placeholder', '')) ?: Text::_('PLG_SYSTEM_BETTERSEARCH_T_PLACEHOLDER');
        $button = (string) $params->get('button', 'icon') === 'icon';

        $css = '#' . $id . '{width:' . $width . ';max-width:100%}'
            . '#' . $id . ' .bettersearch-form{display:flex;align-items:center;height:' . max(24, min(120, (int) $params->get('height', 44))) . 'px;'
            . 'background:' . $color('background', '#ffffff') . ';border:1px solid ' . $color('border', '#d0d5dd') . ';border-radius:' . max(0, min(60, (int) $params->get('radius', 8))) . 'px;overflow:hidden;margin:0}'
            . '#' . $id . ' .bettersearch-input{flex:1;min-width:0;height:100%;border:0;outline:0;background:transparent;padding:0 14px;margin:0;box-shadow:none;'
            . 'font-size:' . max(10, min(30, (int) $params->get('font_size', 15))) . 'px;color:' . $color('color', '#1f2328') . '}'
            . '#' . $id . ' button{border:0;background:transparent;height:100%;padding:0 14px;cursor:pointer;color:' . $color('button_color', $color('color', '#1f2328')) . ';display:flex;align-items:center}';

        $html = '<style>' . $css . '</style><div class="bettersearch-box" id="' . $id . '"><form class="bettersearch-form" role="search" method="get" action="' . $esc($this->resultsUrl()) . '">'
            . '<input class="bettersearch-input" type="search" name="query" autocomplete="off" placeholder="' . $esc($ph) . '" aria-label="' . $esc($ph) . '">'
            . ($button ? '<button type="submit" aria-label="' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_T_SEARCH')) . '"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">'
                . '<path fill="currentColor" d="M10 2a8 8 0 0 1 6.32 12.9l5.39 5.4-1.42 1.4-5.39-5.38A8 8 0 1 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/></svg></button>' : '')
            . '</form></div>';

        $event->setArgument('html', $html);
    }

    /** Address of the results page (setting, else the Gridbox store search system page). */
    private function resultsUrl(): string
    {
        $url = trim((string) $this->params->get('results_url', ''));
        if ($url !== '') {
            return $url;
        }
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select('id')
                ->from($db->quoteName('#__gridbox_system_pages'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('store-search'));
            $id = (int) $db->setQuery($query)->loadResult();
            if ($id) {
                return \Joomla\CMS\Router\Route::_('index.php?option=com_gridbox&view=system&id=' . $id, false);
            }
        } catch (\Throwable $e) {
        }

        return Uri::root(true) . '/index.php?option=com_gridbox&view=system&id=7';
    }

    /** Stylesheet, configuration and script of the live search. */
    private function assets(bool $live = true): string
    {
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = Uri::root(true) . '/media/plg_system_bettersearch';
        // the file time too: browsers fetch the script again after an update of the plugin
        $ver  = self::VERSION . '-' . substr($this->configHash(), 0, 6) . '-' . (int) @filemtime(JPATH_ROOT . '/media/plg_system_bettersearch/js/bettersearch.js');
        $p    = $this->params;
        $cfg  = [
            'ajax'       => Uri::root(true) . '/index.php?option=com_ajax&plugin=bettersearch&group=system&format=json',
            'live'       => $live,
            'selector'   => trim((string) $p->get('input_selector', '')) ?: '.ba-item-store-search input, .ba-item-search input, input.bettersearch-input',
            'resultsUrl' => trim((string) $p->get('results_url', '')),
            'fallbackUrl' => $this->resultsUrl(),
            'minChars'   => max(1, (int) $p->get('live_min_chars', 2)),
            'debounce'   => max(0, min(2000, (int) $p->get('live_debounce', 220))),
            'widthMode'  => (string) $p->get('live_width_mode', 'wide'),
            'width'      => max(200, (int) $p->get('live_width', 640)),
            'align'      => (string) $p->get('live_align', 'left'),
            'offset'     => (int) $p->get('live_offset', 8),
            'mobile'     => (string) $p->get('live_mobile', 'fullscreen'),
            'mobileMax'  => max(0, (int) $p->get('live_mobile_breakpoint', 768)),
            'iconSubmit' => (bool) $p->get('icon_submit', 1),
            'texts'      => [
                'close'   => Text::_('PLG_SYSTEM_BETTERSEARCH_T_CLOSE'),
                'loading' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_LOADING'),
                'placeholder' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_PLACEHOLDER'),
            ],
        ];
        $css = $live ? $this->renderer()->liveCss() : '';

        return ($css !== '' ? '<style id="bettersearch-css">' . $css . '</style>' : '')
            . '<script type="application/json" id="bettersearch-config">' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>'
            . '<script src="' . $esc($base . '/js/bettersearch.js?' . $ver) . '" defer></script>';
    }

    // ================================================================ index upkeep

    /** After the page went out: bring the index up to date (at most every few minutes). */
    public function onAfterRespond(): void
    {
        if (!$this->syncDue || !$this->params->get('auto_sync', 1)) {
            return;
        }
        $interval = max(1, (int) $this->params->get('sync_interval', 10)) * 60;
        try {
            $indexer = $this->indexer();
            $last    = (int) $indexer->state('checked_at', '0');
            if (time() - $last < $interval) {
                return;
            }
            // claim the check first: parallel requests do not all run it
            $indexer->setState('checked_at', (string) time());
            if (function_exists('fastcgi_finish_request') && !(defined('JDEBUG') && JDEBUG)) {
                @fastcgi_finish_request();
            }
            $result = $indexer->sync(max(10, (int) $this->params->get('sync_budget', 300)));
            if ($result['remaining'] > 0) {
                // more to index (first build, many changes): the next batch after half a minute
                $indexer->setState('checked_at', (string) (time() - $interval + 30));
            }
        } catch (\Throwable $e) {
        }
    }

    public function onExtensionAfterSave($event): void
    {
        $args    = method_exists($event, 'getArguments') ? $event->getArguments() : [];
        $context = $args['context'] ?? $args[0] ?? '';
        $table   = $args['subject'] ?? $args['item'] ?? $args[1] ?? null;
        if ($context !== 'com_plugins.plugin' || !is_object($table) || ($table->element ?? '') !== 'bettersearch') {
            return;
        }
        $this->cleanCaches();
    }

    private function cleanCaches(): void
    {
        $factory = Factory::getContainer()->get(CacheControllerFactoryInterface::class);
        foreach (array_unique([JPATH_SITE . '/cache', JPATH_ADMINISTRATOR . '/cache', JPATH_CACHE]) as $base) {
            foreach ([self::CACHE_GROUP, 'com_plugins', 'page'] as $group) {
                try {
                    $factory->createCacheController('callback', ['defaultgroup' => $group, 'cachebase' => $base])->clean($group);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    // ================================================================ administrator tools

    private function adminTask(string $task): array
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();
        if (!$app->isClient('administrator') || !$user || !$user->authorise('core.manage', 'com_plugins')
            || !(Session::checkToken('get') || Session::checkToken('post'))) {
            $app->setHeader('status', 403, true);

            return ['error' => Text::_('JERROR_ALERTNOAUTHOR')];
        }
        $input = $app->getInput();

        // the settings of the form (not yet saved) for the test console
        $form = $input->post->get('jform', [], 'array');
        if (is_array($form) && isset($form['params']) && is_array($form['params']) && in_array($task, ['test', 'preview'], true)) {
            $this->params = new Registry($form['params']);
        }

        switch ($task) {
            case 'status':
                return $this->status();

            case 'sync':
                $force  = $input->getInt('force', 0) === 1;
                $result = $this->indexer()->sync(max(50, min(5000, $input->getInt('budget', 400))), $force);
                if ($result['remaining'] === 0) {
                    $this->cleanCaches();
                }

                return $result + $this->status();

            case 'test':
                $query    = mb_substr(trim((string) $input->get('q', '', 'raw')), 0, 200);
                // as a guest of the site sees it: guest view levels, the default site language
                $siteLang = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
                $searcher = new Searcher($this->db(), $this->params, $this->normalizer(), $this->guestLevels(), $siteLang);
                $result   = $searcher->search($query, $input->getCmd('sort', 'relevance'), [], true);
                $titles   = $this->titles(array_slice(array_column($result['items'], 'id'), 0, 60));
                $rows     = [];
                foreach (array_slice($result['items'], 0, 60) as $item) {
                    $rows[] = ['id' => $item['id'], 'title' => $titles[$item['id']]['title'] ?? '', 'sku' => $titles[$item['id']]['sku'] ?? '',
                        'score' => $item['score'], 'pinned' => $item['pinned'], 'reasons' => $item['reasons']];
                }

                return ['query' => $query, 'mode' => $result['mode'], 'corrected' => $result['corrected'], 'total' => $result['total'],
                    'groups' => $result['groups'], 'ms' => $result['ms'], 'rows' => $rows];

            case 'preview':
                return $this->preview((string) $input->getCmd('mode', 'live'), (string) $input->getCmd('device', 'desktop'),
                    mb_substr(trim((string) $input->get('q', '', 'raw')), 0, 200));

            case 'products':
                return ['items' => $this->findProducts(trim((string) $input->get('q', '', 'raw')))];

            case 'titles':
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) $input->get('ids', '', 'string')))));

                return ['items' => array_values($this->titles(array_slice($ids, 0, 500)))];

            case 'stats':
                return $this->stats();

            case 'clearlog':
                $this->db()->setQuery('TRUNCATE TABLE ' . $this->db()->quoteName('#__bettersearch_log'))->execute();

                return ['ok' => true] + $this->stats();

            case 'clearthumbs':
                return ['removed' => Thumbs::clear()];

            case 'clearcache':
                $this->cleanCaches();

                return ['ok' => true];
        }

        return ['error' => 'unknown task'];
    }

    /**
     * Administrator live preview: the live results or the results page as a guest of the site would
     * see them, rendered from the settings in the form (also unsaved ones), for the chosen device.
     */
    private function preview(string $mode, string $device, string $query): array
    {
        $device = in_array($device, ['desktop', 'tablet', 'mobile'], true) ? $device : 'desktop';
        $this->deviceClass = $device;
        $this->levels      = $this->guestLevels();
        $this->storeHelper = null;
        $siteLang          = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $searcher          = new Searcher($this->db(), $this->params, $this->normalizer(), $this->levels, $siteLang);

        if ($query === '') {
            $query = $this->previewQuery();
        }
        $result = $searcher->search($query, (string) $this->params->get('default_sort', 'relevance'));
        $store  = $this->store();
        $store->setPreview(true);
        $renderer = new Renderer($this->params, $store, new Thumbs((float) $this->params->get('thumb_budget', 1.0),
            (int) $this->params->get('thumb_quality', 80), true), $device);
        $renderer->setHighlight($result['groups']);

        $out = ['q' => $query, 'count' => $result['total'], 'mode' => $mode];
        if ($mode === 'page') {
            $perPage = max(1, min(200, (int) $this->params->get('page_per_page', 24)));
            $state   = $this->filterState($result, $query, (string) $this->params->get('default_sort', 'relevance'), 0, 0, 1, $perPage);
            $state['url']    = fn (array $set = []) => '#';
            $state['action'] = '#';
            $state['hidden'] = [];
            foreach (['chips', 'appChips'] as $key) {
                foreach ($state[$key] as &$chip) {
                    $chip['url'] = '#';
                }
                unset($chip);
            }
            $out['css']     = $renderer->pageCss('bsr-preview');
            $out['html']    = $renderer->page($state['result'], $state, 'bsr-preview');
            $out['heading'] = (string) $this->params->get('page_heading', 'gridbox') === 'gridbox' ? Text::_('PLG_SYSTEM_BETTERSEARCH_PREVIEW_GRIDBOX_HEADING') . ' ' . $query : '';
        } else {
            $categories = [];
            if ($this->params->get('live_categories', 1)) {
                $categories = $searcher->matchCategories($query, $store->visibleCategories($this->indexer()->appIds()),
                    max(0, (int) $this->params->get('live_categories_limit', 4)));
            }
            $out['css']    = $renderer->liveCss();
            $out['html']   = $renderer->live($this->liveSlice($result), $categories, '#');
            $out['layout'] = [
                'widthMode' => (string) $this->params->get('live_width_mode', 'wide'),
                'width'     => max(200, (int) $this->params->get('live_width', 640)),
                'align'     => (string) $this->params->get('live_align', 'left'),
                'offset'    => (int) $this->params->get('live_offset', 8),
                'full'      => $device === 'mobile' && (string) $this->params->get('live_mobile', 'fullscreen') === 'fullscreen',
            ];
        }

        return $out;
    }

    /** A query that shows something: the most searched one with results, else the start of the most viewed product name. */
    private function previewQuery(): string
    {
        $db = $this->db();
        try {
            $q = (string) $db->setQuery('SELECT query FROM ' . $db->quoteName('#__bettersearch_log') . ' WHERE results > 1 ORDER BY searches DESC', 0, 1)->loadResult();
            if ($q !== '') {
                return $q;
            }
            $title = (string) $db->setQuery('SELECT p.title FROM ' . $db->quoteName('#__bettersearch_items', 'i') . ' JOIN ' . $db->quoteName('#__gridbox_pages', 'p')
                . ' ON p.id = i.id WHERE p.published = 1 ORDER BY p.hits DESC', 0, 1)->loadResult();

            return implode(' ', array_slice(preg_split('/\s+/u', trim($title)) ?: [], 0, 2));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function status(): array
    {
        $db      = $this->db();
        $indexer = $this->indexer();
        $count   = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__bettersearch_items'))->loadResult();
        $last    = (int) $indexer->state('checked_at', '0');
        $done    = (int) $indexer->state('complete_at', '0');
        $pending = 0;
        try {
            $current = $indexer->signatures();
            $stored  = [];
            foreach ($db->setQuery('SELECT id, sig FROM ' . $db->quoteName('#__bettersearch_items'))->loadRowList() ?: [] as [$id, $sig]) {
                $stored[(int) $id] = (int) $sig;
            }
            foreach ($current as $id => $sig) {
                $pending += ($stored[$id] ?? null) !== $sig ? 1 : 0;
            }
            $pending += count(array_diff_key($stored, $current));
            $total    = count($current);
            $config   = $indexer->state('config') === $indexer->configSignature();
        } catch (\Throwable $e) {
            $total  = 0;
            $config = false;
        }

        return [
            'items'      => $count,
            'pages'      => $total,
            'pending'    => $pending,
            'configOk'   => $config,
            'checked'    => $last ? gmdate('Y-m-d H:i:s', $last) . ' UTC' : '—',
            'complete'   => $done ? gmdate('Y-m-d H:i:s', $done) . ' UTC' : '—',
            'apps'       => array_map(fn ($id) => ['id' => $id, 'title' => $this->store()->appTitle($id)], $indexer->appIds()),
            'version'    => self::VERSION,
        ];
    }

    private function stats(): array
    {
        $db   = $this->db();
        $read = function (string $where, string $order) use ($db): array {
            return $db->setQuery('SELECT query, searches, results, last_at FROM ' . $db->quoteName('#__bettersearch_log')
                . ($where !== '' ? ' WHERE ' . $where : '') . ' ORDER BY ' . $order . ' LIMIT 50')->loadAssocList() ?: [];
        };

        return [
            'top'    => $read('', 'searches DESC, last_at DESC'),
            'zero'   => $read('results = 0', 'searches DESC, last_at DESC'),
            'recent' => $read('', 'last_at DESC'),
            'total'  => (int) $db->setQuery('SELECT IFNULL(SUM(searches), 0) FROM ' . $db->quoteName('#__bettersearch_log'))->loadResult(),
        ];
    }

    private function logSearch(string $query, int $results): void
    {
        $ua = (string) $this->getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python/i', $ua)) {
            return;
        }
        $q = mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 'UTF-8'), 0, 190);
        if ($q === '') {
            return;
        }
        try {
            $db = $this->db();
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__bettersearch_log') . ' (query, searches, results, last_at) VALUES ('
                . $db->quote($q) . ', 1, ' . $results . ', ' . $db->quote(gmdate('Y-m-d H:i:s')) . ') ON DUPLICATE KEY UPDATE searches = searches + 1, results = '
                . $results . ', last_at = VALUES(last_at)')->execute();
        } catch (\Throwable $e) {
        }
    }

    /** Products for the picker: by id, title or SKU. */
    private function findProducts(string $q): array
    {
        $db    = $this->db();
        $query = $db->createQuery()
            ->select(['p.id', 'p.title', 'p.published', 'p.app_id', 'd.sku'])
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->leftJoin($db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id')
            ->where('p.app_id > 0')
            ->where('p.page_category <> ' . $db->quote('trashed'))
            ->order('p.title ASC')
            ->setLimit(30);
        if ($q !== '') {
            $words = array_filter(explode(' ', $this->normalizer()->fold($q)));
            if (ctype_digit($q)) {
                $query->where('p.id = ' . (int) $q);
            } else {
                foreach ($words as $w) {
                    $like = $db->quote('%' . $db->escape($w, true) . '%', false);
                    $query->where('(p.title LIKE ' . $like . ' OR d.sku LIKE ' . $like . ')');
                }
            }
        }
        $out = [];
        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $out[] = ['id' => (int) $row->id, 'title' => (string) $row->title, 'sku' => (string) $row->sku, 'published' => (int) $row->published,
                'app' => $this->store()->appTitle((int) $row->app_id)];
        }

        return $out;
    }

    /** @return array<int, array{id: int, title: string, sku: string}> */
    private function titles(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $db    = $this->db();
        $query = $db->createQuery()
            ->select(['p.id', 'p.title', 'd.sku'])
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->leftJoin($db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id')
            ->where('p.id IN (' . implode(',', $ids) . ')');
        $out = [];
        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $out[(int) $row->id] = ['id' => (int) $row->id, 'title' => (string) $row->title, 'sku' => (string) $row->sku];
        }

        return $out;
    }

    private function guestLevels(): array
    {
        $guest = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 9);
        try {
            $db     = $this->db();
            $levels = [];
            foreach ($db->setQuery('SELECT id, rules FROM ' . $db->quoteName('#__viewlevels'))->loadObjectList() ?: [] as $row) {
                $groups = json_decode((string) $row->rules, true) ?: [];
                if (in_array($guest, array_map('intval', $groups), true) || (int) $row->id === 1) {
                    $levels[] = (int) $row->id;
                }
            }

            return $levels ?: [1];
        } catch (\Throwable $e) {
            return [1];
        }
    }

    // ================================================================ helpers

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function normalizer(): Normalizer
    {
        if ($this->normalizer === null) {
            $words = (string) $this->params->get('stopwords', self::STOPWORDS);
            $this->normalizer = new Normalizer(array_map('trim', explode(',', $words)));
        }

        return $this->normalizer;
    }

    private function indexer(): Indexer
    {
        return new Indexer($this->db(), $this->params, $this->normalizer());
    }

    private ?Store $storeHelper = null;

    private function store(): Store
    {
        return $this->storeHelper ??= new Store($this->db(), $this->getApplication(), $this->levels());
    }

    private function renderer(): Renderer
    {
        $thumbs = new Thumbs((float) $this->params->get('thumb_budget', 1.0), (int) $this->params->get('thumb_quality', 80));

        return new Renderer($this->params, $this->store(), $thumbs, $this->device());
    }

    private function levels(): array
    {
        if ($this->levels === null) {
            $user         = $this->getApplication()->getIdentity();
            $this->levels = array_map('intval', $user ? $user->getAuthorisedViewLevels() : [1]) ?: [1];
        }

        return $this->levels;
    }

    private function device(): string
    {
        if ($this->deviceClass === null) {
            $ua = (string) $this->getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');
            if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)/i', $ua)) {
                $this->deviceClass = 'tablet';
            } else {
                $this->deviceClass = preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini/i', $ua) ? 'mobile' : 'desktop';
            }
        }

        return $this->deviceClass;
    }

    private function configHash(): string
    {
        return substr(md5(json_encode($this->params->toArray())), 0, 12);
    }

    private ?string $version = null;

    private function indexVersion(): string
    {
        if ($this->version === null) {
            try {
                $this->version = (string) $this->indexer()->state('version', '0');
            } catch (\Throwable $e) {
                $this->version = '0';
            }
        }

        return $this->version;
    }

    private function cache(int $minutes)
    {
        return Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('output', ['defaultgroup' => self::CACHE_GROUP, 'lifetime' => $minutes, 'caching' => true]);
    }

    private function savedParams(): ?Registry
    {
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('bettersearch'));
            $json = $db->setQuery($query)->loadResult();

            return is_string($json) && $json !== '' ? new Registry($json) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
