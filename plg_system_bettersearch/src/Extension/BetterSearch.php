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

use Joomla\CMS\Access\Access;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
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
use Merserwis\Plugin\System\BetterSearch\Render\Seo;
use Merserwis\Plugin\System\BetterSearch\Render\Store;
use Merserwis\Plugin\System\BetterSearch\Render\Thumbs;

final class BetterSearch extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '1.5.2';

    /** Log file of the plugin, in Joomla's log folder. */
    public const LOG_FILE = 'plg_system_bettersearch.php';

    private const CACHE_GROUP = 'plg_system_bettersearch';

    /** Seconds between two looks at the index state from one server (a file stamp, no query). */
    private const CHECK_GATE = 30;

    /** Rows kept in the search statistics. */
    private const LOG_ROWS = 20000;

    /** Rows kept in the conversion statistics. */
    private const EVENT_ROWS = 200000;

    /** Days of searches per day kept (the e-mail report compares two periods of up to a month). */
    private const DAILY_DAYS = 400;

    /** Hour (site time) from which a due e-mail report is sent. */
    private const REPORT_HOUR = 7;

    /** Default words left out of queries (Polish and English connectors). */
    private const STOPWORDS = 'i, w, z, ze, na, do, dla, od, po, o, u, a, oraz, lub, czy, the, and, of, for, with, to, in';

    protected $autoloadLanguage = true;

    /** A Gridbox page was saved / published / trashed in this request: sync the index after the response. */
    private bool $reindexDue = false;

    /** Query of the results page, taken from Gridbox (null = not a results page). */
    private ?string $query = null;

    /** The visitor asked for a page: the index check may run after the response. */
    private bool $syncDue = false;

    private ?array $levels = null;

    private ?string $deviceClass = null;

    private ?Normalizer $normalizer = null;

    /** Settings as saved in #__extensions (read once per request). */
    private ?Registry $saved = null;

    private bool $savedRead = false;

    private ?Searcher $searcherInstance = null;

    /** @var int[]|null */
    private ?array $appIdsMemo = null;

    /** @var array<string, string>|null version and visibility stamp of the index (read once per request) */
    private ?array $indexState = null;

    private ?string $resultsUrlMemo = null;

    /** What the results page showed, for its title, description and structured data. */
    private ?array $seoInfo = null;

    /** Parameters of the results page address that name the page itself (the rest is filtering or noise). */
    private const CANONICAL_VARS = ['option', 'view', 'id', 'Itemid', 'lang', 'query'];

    /** URL parameters of the results page filters. */
    private const FACET_VARS = ['bs_brand', 'bs_min', 'bs_max', 'bs_sale', 'bs_stock'];

    public static function getSubscribedEvents(): array
    {
        return [
            // after Gridbox: its redirect to the SEF address is built from the request (with the query)
            'onAfterRoute'          => ['onAfterRoute', Priority::MIN],
            // after Gridbox, which builds its search elements in its own onAfterRender
            'onAfterRender'         => ['onAfterRender', Priority::MIN],
            'onAfterRespond'        => ['onAfterRespond', Priority::MIN],
            'onAjaxBettersearch'    => 'onAjax',
            'onExtensionAfterSave'  => 'onExtensionAfterSave',
            'onBetterSearchModule'  => 'onModule',
        ];
    }

    // ================================================================ site: results page

    public function onAfterRoute(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();

        // a Gridbox page, product or category saved, published or trashed (editor on the site, lists in
        // the administrator): the index is brought up to date right after that response
        if ($input->getCmd('option') === 'com_gridbox' && $input->getMethod() === 'POST') {
            $user = $app->getIdentity();
            $task = strtolower($input->getCmd('task', ''));
            if ($user && !$user->guest && !str_starts_with($task, 'store.') && !str_starts_with($task, 'account.')) {
                $this->reindexDue = true;
            }
        }

        if (!$app->isClient('site')) {
            return;
        }

        // a product put into the cart after a click in the search results: a conversion of that query
        if ($input->getCmd('option') === 'com_gridbox' && strtolower($input->getCmd('task', '')) === 'store.addproducttocart') {
            $this->params = $this->savedParams() ?? $this->params;
            $this->trackCart($input->getInt('id', 0));
        }
        // the index check runs after ordinary pages only, never after live-search or other ajax requests
        $this->syncDue = $input->getCmd('format', 'html') === 'html' && $input->getCmd('option') !== 'com_ajax';

        if ($input->getCmd('option') !== 'com_gridbox' || !in_array($input->getCmd('view'), ['system', 'page'], true)
            || $input->getCmd('format', 'html') !== 'html') {
            return;
        }
        $query = trim((string) $input->get('query', '', 'raw'));
        // Gridbox's items filter travels in the same parameter ("brand__metrel--sonel"): not a search
        if ($query === '' || str_contains($query, '__')) {
            return;
        }
        // settings as saved (some sites hand phones an older copy of plugin parameters)
        $this->params = $this->savedParams() ?? $this->params;
        if (!$this->params->get('replace_results', 1) || !$this->isSearchPage($input->getCmd('view'), $input->getInt('id'))) {
            return;
        }
        // a phrase with its own destination (e.g. a brand page): no results page
        $redirect = $this->redirectFor($query);
        if ($redirect !== null) {
            if ($this->params->get('log_searches', 1)) {
                $this->logSearch($query, -1);
            }
            $app->redirect($redirect['url'], 302);

            return;
        }
        $this->query = mb_substr($query, 0, 200);
        // Gridbox searches when it sees the query: give it none, the results come from this plugin
        $input->set('query', '');
    }

    // ================================================================ redirects and suggestions

    /**
     * Destination of a phrase ("Redirects" setting: "phrase, other phrase => /address | Label"),
     * matched on the whole query, written in any way ("MI-3155" = "mi 3155").
     *
     * @return array{url: string, label: string}|null
     */
    private function redirectFor(string $query): ?array
    {
        $phrase = $this->normalizer()->compact($query);
        if ($phrase === '') {
            return null;
        }
        foreach (preg_split('/\R/', (string) $this->params->get('redirects', '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=>')) {
                continue;
            }
            [$left, $right] = array_map('trim', explode('=>', $line, 2));
            [$url, $label]  = array_pad(array_map('trim', explode('|', $right, 2)), 2, '');
            $url            = $this->safeUrl($url);
            if ($url === '') {
                continue;
            }
            foreach (explode(',', $left) as $word) {
                if ($this->normalizer()->compact($word) === $phrase) {
                    return ['url' => $url, 'label' => $label !== '' ? $label : trim($left)];
                }
            }
        }

        return null;
    }

    /** An address set by the administrator: a path on this site or an http(s) address (nothing else). */
    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\s"<>]/', $url)) {
            return '';
        }
        if (preg_match('#^https?://[^/]+#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('#^[a-z0-9][a-z0-9_\-./]*(\?[^#]*)?$#i', $url) ? Uri::root(true) . '/' . $url : '';
    }

    /** Searches with results that start like the typed text (most searched first). */
    private function popularFor(string $query, int $limit): array
    {
        $q = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 'UTF-8');
        if ($limit <= 0 || mb_strlen($q) < 2) {
            return [];
        }
        try {
            $db   = $this->db();
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $rows = $db->setQuery($db->createQuery()
                ->select('query')
                ->from($db->quoteName('#__bettersearch_log'))
                ->where('results > 0')
                ->where('searches >= ' . max(1, (int) $this->params->get('suggest_min_searches', 2)))
                ->where('(query LIKE ' . $db->quote($like . '%') . ' OR query LIKE ' . $db->quote('% ' . $like . '%') . ')')
                ->where('query <> ' . $db->quote($q))
                ->order('searches DESC, last_at DESC'), 0, $limit)->loadColumn() ?: [];

            return array_values(array_map('strval', $rows));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** "Did you mean": searches with results that are written almost like this one. */
    private function didYouMean(string $query): array
    {
        $norm   = $this->normalizer();
        $target = $norm->compact($query);
        if (strlen($target) < 3) {
            return [];
        }
        try {
            $db   = $this->db();
            $rows = $db->setQuery($db->createQuery()
                ->select(['query', 'searches'])
                ->from($db->quoteName('#__bettersearch_log'))
                ->where('results > 0')
                ->order('searches DESC'), 0, 2000)->loadRowList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $max   = max(1, intdiv(strlen($target), 4));
        $found = [];
        foreach ($rows as [$candidate, $searches]) {
            $c = $norm->compact((string) $candidate);
            if ($c === '' || $c === $target || abs(strlen($c) - strlen($target)) > $max) {
                continue;
            }
            $d = levenshtein($c, $target);
            if ($d <= $max) {
                $found[(string) $candidate] = $d * 100000 - (int) $searches;
            }
        }
        asort($found);

        return array_slice(array_keys($found), 0, 3);
    }

    // ================================================================ conversions

    /** A click on a result (sent by the page script). */
    private function trackClick(): array
    {
        $input = $this->getApplication()->getInput();
        $q     = mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $input->post->get('q', '', 'raw')) ?? ''), 'UTF-8'), 0, 120);
        $id    = $input->post->getInt('id', 0);
        if (!$this->params->get('track_conversions', 1) || $input->getMethod() !== 'POST' || $id <= 0 || $q === '' || $this->isBot()) {
            return ['ok' => false];
        }
        $db     = $this->db();
        $exists = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__bettersearch_items'))->where('id = ' . $id))->loadResult();
        if (!$exists) {
            return ['ok' => false];
        }
        $this->recordEvent($q, $id, 1);

        return ['ok' => true];
    }

    /** The product put into the Gridbox cart came from a search result (cookie of the page script). */
    private function trackCart(int $id): void
    {
        if ($id <= 0 || !$this->params->get('track_conversions', 1) || $this->isBot()) {
            return;
        }
        $raw = (string) $this->getApplication()->getInput()->cookie->getString('bs_src', '');
        $map = json_decode(rawurldecode($raw), true);
        if (!is_array($map) || !isset($map[(string) $id]) || !is_string($map[(string) $id])) {
            return;
        }
        $q = mb_substr(mb_strtolower(trim($map[(string) $id]), 'UTF-8'), 0, 120);
        if ($q !== '') {
            $this->recordEvent($q, $id, 2);
        }
    }

    /** kind: 1 = click, 2 = cart. One row per day, query, product and kind (counted up). */
    private function recordEvent(string $q, int $id, int $kind): void
    {
        try {
            $db = $this->db();
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__bettersearch_events') . ' (day, query, item_id, kind, hits) VALUES ('
                . $db->quote(gmdate('Y-m-d')) . ', ' . $db->quote($q) . ', ' . $id . ', ' . $kind . ', 1) ON DUPLICATE KEY UPDATE hits = hits + 1')->execute();
            // now and then: a year of history, at most EVENT_ROWS rows
            if (random_int(1, 100) === 1) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__bettersearch_events') . ' WHERE day < ' . $db->quote(gmdate('Y-m-d', time() - 366 * 86400)))->execute();
                $over = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__bettersearch_events'))->loadResult() - self::EVENT_ROWS;
                if ($over > 0) {
                    $db->setQuery('DELETE FROM ' . $db->quoteName('#__bettersearch_events') . ' ORDER BY day ASC LIMIT ' . $over)->execute();
                }
            }
        } catch (\Throwable $e) {
            try {
                $this->ensureTables();
            } catch (\Throwable $ignored) {
            }
        }
    }

    private function isBot(): bool
    {
        $ua = (string) $this->getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');

        return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless/i', $ua);
    }

    /**
     * Only a Gridbox search results page gets its query taken over: the search system pages, or a
     * Gridbox page that holds a search results element. Any other page keeps its query parameter
     * (Gridbox uses it for the items filter too).
     */
    private function isSearchPage(string $view, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            $db = $this->db();
            if ($view === 'system') {
                $type = (string) $db->setQuery($db->createQuery()
                    ->select($db->quoteName('type'))
                    ->from($db->quoteName('#__gridbox_system_pages'))
                    ->where($db->quoteName('id') . ' = ' . $id))->loadResult();

                return in_array($type, ['search', 'store-search'], true);
            }
            $params = (string) $db->setQuery($db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__gridbox_pages'))
                ->where($db->quoteName('id') . ' = ' . $id))->loadResult();

            return str_contains($params, 'search-result');
        } catch (\Throwable $e) {
            return false;
        }
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
                // the reason goes to the log, not to the visitor
                $this->logError($e);
                $html = '<!-- Better Search: unavailable' . (JDEBUG ? ' — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : '') . ' -->';
            }
            $html .= sprintf('<!-- Better Search | %s | %.1f ms -->', $this->device(), (hrtime(true) - $t0) / 1e6);
            $new   = $this->placeResults($body, $html, $this->query);
            if ($new !== null) {
                $body    = $new;
                $changed = true;
            }
            try {
                $body    = $this->resultsSeo($body, $this->query);
                $changed = true;
            } catch (\Throwable $e) {
                $this->logError($e);
            }
        }

        $wanted = $this->query !== null || (bool) $this->params->get('load_everywhere', 0)
            || preg_match('/ba-item-(store-)?search\b|bettersearch-box/', $body);
        if ($wanted) {
            $this->params = $this->savedParams() ?? $this->params;
        }
        if ($wanted && $this->params->get('live_enabled', 1) && ($pos = stripos($body, '</head>')) !== false) {
            $body    = substr($body, 0, $pos) . $this->assets() . substr($body, $pos);
            $changed = true;
        } elseif ($this->query !== null && ($pos = stripos($body, '</body>')) !== false) {
            // results page without live search: the script still runs "load more"
            $body    = substr($body, 0, $pos) . $this->assets(false) . substr($body, $pos);
            $changed = true;
        }

        // OpenSearch link and SearchAction: in the head of every page that has a search field
        if ($wanted && ($pos = stripos($body, '</head>')) !== false) {
            $head = $this->headSeo();
            if ($head !== '') {
                $body    = substr($body, 0, $pos) . $head . substr($body, $pos);
                $changed = true;
            }
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
        $appId   = max(-1, $input->getInt('bs_app', 0));
        $perPage = max(1, min(200, (int) $this->params->get('page_per_page', 24)));

        $result = $this->search($query, $sort);
        $state  = $this->filterState($result, $query, $sort, $cat, $appId, $page, $perPage);
        if (!$result['total'] && $this->params->get('did_you_mean', 1)) {
            $state['result']['suggest'] = $this->didYouMean($query);
        }

        if ($page === 1 && !$cat && !$appId && !$state['facetVars'] && $this->params->get('log_searches', 1)) {
            $this->logSearch($query, $result['total']);
        }

        $renderer = $this->renderer();
        $renderer->setHighlight($result['groups']);
        $id = 'bsr-' . substr(md5($query . microtime()), 0, 8);

        $html = '<style>' . $renderer->pageCss($id) . '</style>' . $renderer->page($state['result'], $state, $id);
        $this->seoInfo = ['total' => (int) $state['result']['total'], 'cards' => $renderer->listed(), 'page' => (int) $state['page'],
            'perPage' => $perPage, 'filtered' => $cat > 0 || $appId !== 0 || $page > 1 || $input->getCmd('bs_sort', '') !== '' || $state['facetVars']];

        return $html;
    }

    // ================================================================ SEO

    /** Title, description, robots, canonical address and structured data of the results page. */
    private function resultsSeo(string $body, string $query): string
    {
        $p    = $this->params;
        $app  = $this->getApplication();
        $seo  = new Seo($body);
        $info = $this->seoInfo ?? ['total' => 0, 'cards' => [], 'page' => 1, 'perPage' => 24, 'filtered' => false];
        if (!$seo->hasHead()) {
            return $body;
        }
        $site      = (string) $app->get('sitename', '');
        $canonical = $this->canonicalUrl($query);

        // robots: search results are thin content, kept out of the index but their links followed
        $robots = (string) $p->get('seo_robots', 'noindex_follow');
        if ($robots !== 'keep') {
            $value = ['noindex_follow' => 'noindex, follow', 'noindex_nofollow' => 'noindex, nofollow', 'index_follow' => 'index, follow'][$robots] ?? 'noindex, follow';
            if ($robots === 'index_follow' && ($info['filtered'] || $info['total'] === 0)) {
                // sorted, filtered, further pages and empty results are never worth indexing
                $value = 'noindex, follow';
            }
            $seo->meta('robots', $value);
        }

        $title = '';
        if ($p->get('seo_title', 1)) {
            $pattern = trim((string) $p->get('seo_title_pattern', '')) ?: Text::_('PLG_SYSTEM_BETTERSEARCH_T_SEO_TITLE');
            $title   = Seo::fill($pattern, $query, $info['total'], $site);
            if ($info['page'] > 1) {
                $title .= ' – ' . Text::sprintf('PLG_SYSTEM_BETTERSEARCH_T_SEO_PAGE', $info['page']);
            }
            // the site name as Joomla adds it to every title, unless the pattern places it itself
            if ($site !== '' && !str_contains($pattern, '{site}')) {
                $mode  = (int) $app->get('sitename_pagetitles', 0);
                $title = $mode === 1 ? Text::sprintf('JPAGETITLE', $site, $title) : ($mode === 2 ? Text::sprintf('JPAGETITLE', $title, $site) : $title);
            }
            $seo->title($title);
            $seo->metaIfPresent('og:title', $title);
            $seo->metaIfPresent('twitter:title', $title, 'name');
        }

        if ($p->get('seo_description', 1)) {
            $pattern     = trim((string) $p->get('seo_description_pattern', '')) ?: Text::_('PLG_SYSTEM_BETTERSEARCH_T_SEO_DESCRIPTION');
            $description = Seo::fill($pattern, $query, $info['total'], $site);
            $seo->meta('description', $description);
            $seo->metaIfPresent('og:description', $description);
        }

        if ($p->get('seo_canonical', 1)) {
            $seo->canonical($canonical);
            $seo->metaIfPresent('og:url', $canonical);
        }

        if ($p->get('seo_jsonld', 1) && $info['cards']) {
            $host  = Uri::getInstance()->toString(['scheme', 'host', 'port']);
            $items = [];
            foreach ($info['cards'] as $i => $card) {
                $items[] = ['@type' => 'ListItem', 'position' => ($info['page'] - 1) * $info['perPage'] + $i + 1,
                    'url' => $this->absolute($card['url'], $host), 'name' => $card['name']];
            }
            $seo->jsonLd(['@context' => 'https://schema.org', '@type' => 'SearchResultsPage', 'name' => $title !== '' ? $title : $query,
                'url' => $canonical, 'inLanguage' => $app->getLanguage()->getTag(),
                'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $info['total'], 'itemListElement' => $items]]);
        }

        return $seo->body();
    }

    /** The results page of this query alone: no sorting, filters, page number or unknown parameters. */
    private function canonicalUrl(string $query): string
    {
        $uri  = Uri::getInstance();
        $vars = [];
        foreach ($uri->getQuery(true) as $k => $v) {
            if (in_array($k, self::CANONICAL_VARS, true) && is_scalar($v) && $k !== 'query') {
                $vars[$k] = (string) $v;
            }
        }
        $vars['query'] = $query;

        return $uri->toString(['scheme', 'host', 'port', 'path']) . '?' . http_build_query($vars, '', '&', PHP_QUERY_RFC3986);
    }

    /** A root-relative or absolute address as an absolute one on this host. */
    private function absolute(string $url, string $host): string
    {
        if ($url === '' || $url === '#') {
            return $host . '/';
        }

        return preg_match('#^https?://#i', $url) ? $url : $host . '/' . ltrim($url, '/');
    }

    /** Address template of the results page for a query placeholder (OpenSearch, SearchAction). */
    private function searchTemplate(string $placeholder): string
    {
        $base = $this->absolute($this->resultsUrl(), Uri::getInstance()->toString(['scheme', 'host', 'port']));
        if (preg_match('/[?&]query=$/', $base)) {
            return $base . $placeholder;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . 'query=' . $placeholder;
    }

    /** OpenSearch link and (optional) WebSite SearchAction on pages with a search field. */
    private function headSeo(): string
    {
        $p    = $this->params;
        $app  = $this->getApplication();
        $html = '';
        $site = (string) $app->get('sitename', '');
        if ($p->get('seo_opensearch', 1)) {
            $name  = trim((string) $p->get('seo_opensearch_name', '')) ?: $site;
            $html .= '<link rel="search" type="application/opensearchdescription+xml" title="' . Seo::attr($name) . '" href="'
                . Seo::attr(Uri::root(true) . '/index.php?option=com_ajax&plugin=bettersearch&group=system&format=raw&task=opensearch') . '">';
        }
        if ($p->get('seo_sitelinks', 0)) {
            $menu   = $app->getMenu();
            $active = $menu ? $menu->getActive() : null;
            $home   = $menu ? $menu->getDefault($app->getLanguage()->getTag()) : null;
            if ($active && $home && (int) $active->id === (int) $home->id) {
                $json = json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'url' => Uri::root(), 'name' => $site,
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->searchTemplate('{search_term_string}')],
                        'query-input' => 'required name=search_term_string']], Seo::JSON_FLAGS);
                if (is_string($json)) {
                    $html .= '<script type="application/ld+json">' . $json . '</script>';
                }
            }
        }

        return $html;
    }

    /** OpenSearch description document (format=raw). */
    private function openSearch(): string
    {
        $app  = $this->getApplication();
        $site = (string) $app->get('sitename', '');
        $name = trim((string) $this->params->get('seo_opensearch_name', '')) ?: $site;
        $app->getDocument()->setMimeEncoding('application/opensearchdescription+xml');
        $app->setHeader('Cache-Control', 'public, max-age=86400', true);
        $icon = is_file(JPATH_ROOT . '/favicon.ico') ? rtrim(Uri::root(), '/') . '/favicon.ico' : '';

        return Seo::openSearchXml($name, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_T_OPENSEARCH_DESC', $site), $this->searchTemplate('{searchTerms}'), $icon);
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
        $f    = $this->facetInput();
        $url  = function (array $set) use ($base, $query, $sort, $cat, $appId, $f): string {
            $vars = array_merge(['query' => $query, 'bs_sort' => $sort !== $this->params->get('default_sort', 'relevance') ? $sort : null,
                'bs_cat' => $cat ?: null, 'bs_app' => $appId ?: null], $this->facetVars($f), $set);
            foreach ($base->getQuery(true) as $k => $v) {
                if (!array_key_exists($k, $vars) && $k !== 'bs_page' && !in_array($k, self::FACET_VARS, true)) {
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
                // the pages (app 0 of the index) are chosen with -1: 0 means "all"
                $chipId     = $id === 0 ? -1 : $id;
                $appChips[] = ['title' => $store->appTitle($id), 'count' => $count, 'active' => $appId === $chipId,
                    'url' => $url(['bs_app' => $chipId, 'bs_cat' => null])];
            }
        }
        if ($appId !== 0) {
            $only  = $appId === -1 ? 0 : $appId;
            $items = array_values(array_filter($items, fn ($i) => $i['app_id'] === $only));
        }

        // categories of the results (the product's own category, or its top category); the filter
        // below uses the same category per item, so a chip's count is what a click on it shows
        $chips  = [];
        $level  = (string) $this->params->get('page_cat_level', 'direct');
        $cats   = $store->categories();
        $chipOf = function (array $item) use ($level, $cats): int {
            $id = (int) $item['category_id'];
            if ($level === 'top') {
                $guard = 0;
                while (($cats[$id]->parent ?? 0) > 0 && $guard++ < 50) {
                    $id = $cats[$id]->parent;
                }
            }

            return $id > 0 && isset($cats[$id]) ? $id : 0;
        };
        if ($this->params->get('page_cat_filter', 1) && count($items) > 1) {
            $counts = [];
            foreach ($items as $item) {
                $id = $chipOf($item);
                if ($id > 0) {
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
            $items = array_values(array_filter($items, fn ($i) => $chipOf($i) === $cat));
        }

        [$items, $facets] = $this->applyFacets($items, $f);

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
        foreach ($this->facetVars($f) as $k => $v) {
            $hidden[$k] = (string) $v;
        }
        foreach ($base->getQuery(true) as $k => $v) {
            if (!isset($hidden[$k]) && !in_array($k, array_merge(['bs_page', 'bs_sort', 'query', 'bs_cat', 'bs_app'], self::FACET_VARS), true) && is_scalar($v)) {
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
            'facets'   => $facets,
            'facetVars' => $this->facetVars($f),
            'queryUrl' => fn (string $q) => $base->getPath() . '?' . http_build_query(array_merge(array_diff_key(array_filter($base->getQuery(true), 'is_scalar'),
                array_flip(array_merge(['query', 'bs_page', 'bs_sort', 'bs_cat', 'bs_app'], self::FACET_VARS))), ['query' => $q]), '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /** Filters of the results page chosen by the visitor (validated). */
    private function facetInput(): array
    {
        $input = $this->getApplication()->getInput();
        $num   = function (string $name): ?float {
            $raw = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $this->getApplication()->getInput()->get($name, '', 'string')));

            return is_numeric($raw) && (float) $raw >= 0 ? round((float) $raw, 2) : null;
        };

        return [
            'brand' => mb_substr(trim((string) $input->get('bs_brand', '', 'string')), 0, 80),
            'min'   => $num('bs_min'),
            'max'   => $num('bs_max'),
            'sale'  => $input->getInt('bs_sale', 0) === 1,
            'stock' => $input->getInt('bs_stock', 0) === 1,
        ];
    }

    /** The chosen filters as URL parameters. */
    private function facetVars(array $f): array
    {
        return array_filter([
            'bs_brand' => $f['brand'] !== '' ? $f['brand'] : null,
            'bs_min'   => $f['min'] !== null ? (string) $f['min'] : null,
            'bs_max'   => $f['max'] !== null ? (string) $f['max'] : null,
            'bs_sale'  => $f['sale'] ? '1' : null,
            'bs_stock' => $f['stock'] ? '1' : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Brand, price, sale and stock filters. The counts of each filter come from the items the other
     * filters let through (what a click on it would show).
     *
     * @return array{0: array, 1: array} filtered items, facet data for the renderer
     */
    private function applyFacets(array $items, array $f): array
    {
        $p = $this->params;
        if (!$p->get('page_facets', 1) || !$items) {
            return [$items, []];
        }
        $brandField = (int) $p->get('facet_brand_field', 0);
        $useBrand   = $brandField > 0;
        $usePrice   = (bool) $p->get('facet_price', 1);
        $useSale    = (bool) $p->get('facet_sale', 1);
        $useStock   = (bool) $p->get('facet_stock', 1);
        if (!$useBrand && !$usePrice && !$useSale && !$useStock) {
            return [$items, []];
        }

        $store = $this->store();
        $data  = $store->facets(array_slice(array_column($items, 'id'), 0, 5000), $useBrand ? $brandField : 0);
        $rate  = $store->rate();
        $pass  = function (array $item, string $skip) use ($data, $f, $rate, $useBrand, $usePrice, $useSale, $useStock): bool {
            $d = $data[$item['id']] ?? null;
            if (!$d) {
                // not a product with data (e.g. blog post): only filters that do not apply let it through
                return !($useBrand && $f['brand'] !== '' && $skip !== 'brand') && !($usePrice && ($f['min'] !== null || $f['max'] !== null) && $skip !== 'price')
                    && !($useSale && $f['sale'] && $skip !== 'sale') && !($useStock && $f['stock'] && $skip !== 'stock');
            }
            if ($useBrand && $skip !== 'brand' && $f['brand'] !== '' && mb_strtolower($d['brand']) !== mb_strtolower($f['brand'])) {
                return false;
            }
            if ($usePrice && $skip !== 'price' && ($f['min'] !== null || $f['max'] !== null)) {
                if ($d['price'] === null) {
                    return false;
                }
                $shown = $d['price'] * $rate;
                if (($f['min'] !== null && $shown < $f['min'] - 0.005) || ($f['max'] !== null && $shown > $f['max'] + 0.005)) {
                    return false;
                }
            }
            if ($useSale && $skip !== 'sale' && $f['sale'] && !$d['sale']) {
                return false;
            }

            return !($useStock && $skip !== 'stock' && $f['stock'] && !$d['stock']);
        };

        $facets = [];
        if ($useBrand) {
            $counts = [];
            foreach ($items as $item) {
                $brand = $data[$item['id']]['brand'] ?? '';
                if ($brand !== '' && $pass($item, 'brand')) {
                    $counts[$brand] = ($counts[$brand] ?? 0) + 1;
                }
            }
            uksort($counts, fn ($a, $b) => strnatcasecmp($a, $b));
            if (count($counts) > 1 || $f['brand'] !== '') {
                $facets['brand'] = ['values' => $counts, 'active' => $f['brand'],
                    'label' => $this->fieldLabel($brandField)];
            }
        }
        if ($usePrice) {
            $prices = [];
            foreach ($items as $item) {
                $price = $data[$item['id']]['price'] ?? null;
                if ($price !== null && $pass($item, 'price')) {
                    $prices[] = $price * $rate;
                }
            }
            if (count($prices) > 1 || $f['min'] !== null || $f['max'] !== null) {
                $facets['price'] = ['min' => $prices ? floor(min($prices)) : 0, 'max' => $prices ? ceil(max($prices)) : 0,
                    'from' => $f['min'], 'to' => $f['max'], 'symbol' => $store->currencySymbol()];
            }
        }
        foreach (['sale' => $useSale, 'stock' => $useStock] as $flag => $use) {
            if (!$use) {
                continue;
            }
            $count = 0;
            foreach ($items as $item) {
                if (!empty($data[$item['id']][$flag]) && $pass($item, $flag)) {
                    $count++;
                }
            }
            // a filter that removes nothing or everything helps nobody (unless it is on)
            $all = count(array_filter($items, fn ($i) => $pass($i, $flag)));
            if ($f[$flag] || ($count > 0 && $count < $all)) {
                $facets[$flag] = ['count' => $count, 'active' => $f[$flag]];
            }
        }

        $items = array_values(array_filter($items, fn ($i) => $pass($i, '')));

        return [$items, $facets];
    }

    /** Label of a Gridbox field (the brand filter's heading). */
    private function fieldLabel(int $id): string
    {
        try {
            $db = $this->db();

            return trim((string) $db->setQuery($db->createQuery()->select('label')->from($db->quoteName('#__gridbox_fields'))->where('id = ' . $id))->loadResult());
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ================================================================ search with cache

    /** Searcher result, cached per query, sort, visitor levels and index version. */
    private function search(string $query, string $sort, array $apps = []): array
    {
        $minutes = max(0, min(1440, (int) $this->params->get('cache_time', 15)));
        $key     = md5(json_encode([self::VERSION, $this->configHash(), $this->indexVersion(), $this->visibilityStamp(), $query, $sort, $apps,
            $this->levels(), $this->getApplication()->getLanguage()->getTag()]));

        $cache = $minutes > 0 ? $this->cache($minutes) : null;
        if ($cache) {
            $hit = $cache->get($key);
            if (is_array($hit)) {
                return $hit;
            }
        }

        $result = $this->searcher()->search($query, $sort, $apps);
        if ($cache) {
            // only what the pages read later: ids, apps, categories, order (not titles, prices, reasons)
            $lean          = $result;
            $lean['items'] = array_map(fn ($i) => ['id' => $i['id'], 'app_id' => $i['app_id'], 'category_id' => $i['category_id'],
                'cats' => $i['cats'], 'score' => $i['score'], 'pinned' => $i['pinned'], 'featured' => !empty($i['featured'])], $result['items']);
            $cache->store($lean, $key);
        }

        return $result;
    }

    /** One searcher per request; the vocabulary for typo correction is read only when a correction is tried. */
    private function searcher(): Searcher
    {
        if ($this->searcherInstance !== null) {
            return $this->searcherInstance;
        }
        $searcher = new Searcher($this->db(), $this->params, $this->normalizer(), $this->levels(), $this->getApplication()->getLanguage()->getTag());
        $searcher->setVocabularyLoader(function () use ($searcher) {
            $cache = $this->cache(1440);
            $key   = 'vocab-' . $this->indexVersion();
            $vocab = $cache->get($key);
            if (!is_array($vocab)) {
                $vocab = $searcher->buildVocabulary();
                $cache->store($vocab, $key);
            }

            return $vocab;
        });

        return $this->searcherInstance = $searcher;
    }

    // ================================================================ live results (com_ajax)

    public function onAjax($event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $task  = $input->getCmd('task', 'live');
        $this->params = $this->savedParams() ?? $this->params;

        try {
            if ($app->isClient('site')) {
                // answers of the search endpoints are not pages: never indexed
                $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
            }
            if (str_starts_with($task, 'admin_')) {
                $data = $this->adminTask(substr($task, 6));
            } elseif ($task === 'opensearch' && $app->isClient('site') && $this->params->get('seo_opensearch', 1)) {
                $data = $this->openSearch();
            } elseif ($task === 'live' && $app->isClient('site')) {
                $data = $this->liveResults(trim((string) $input->get('q', '', 'raw')));
            } elseif ($task === 'more' && $app->isClient('site')) {
                $data = $this->moreResults();
            } elseif ($task === 'track' && $app->isClient('site')) {
                $data = $this->trackClick();
            } else {
                $data = ['error' => 'unknown task'];
            }
        } catch (\Throwable $e) {
            $this->logError($e);
            // administrators (checked in adminTask) see the cause; visitors get a general message
            $admin = str_starts_with($task, 'admin_') && $app->isClient('administrator') && $app->getIdentity()?->authorise('core.manage', 'com_plugins');
            $data  = ['error' => JDEBUG || $admin ? Text::sprintf('PLG_SYSTEM_BETTERSEARCH_ERR_ADMIN', $e->getMessage()) : Text::_('PLG_SYSTEM_BETTERSEARCH_T_UNAVAILABLE')];
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
        $key      = md5(json_encode(['live', self::VERSION, $this->configHash(), $this->indexVersion(), $this->visibilityStamp(), $query, $this->levels(),
            $this->getApplication()->getLanguage()->getTag(), $currency]));
        $cache = $minutes > 0 ? $this->cache($minutes) : null;
        if ($cache && is_array($hit = $cache->get($key))) {
            return $hit;
        }

        $t0     = hrtime(true);
        $result = $this->search($query, 'relevance');
        $live   = $this->liveSlice($result);

        $categories = [];
        if ($this->params->get('live_categories', 1)) {
            $categories = $this->searcher()->matchCategories($query, $this->store()->visibleCategories($this->appIds()),
                max(0, (int) $this->params->get('live_categories_limit', 4)));
        }

        // a keystroke must not wait for thumbnails: a short budget, the originals meanwhile
        $renderer = $this->renderer(0.25);
        $renderer->setHighlight($result['groups']);
        $live['redirect'] = $this->redirectFor($query);
        $live['popular']  = $this->params->get('live_suggestions', 1) ? $this->popularFor($query, max(0, min(10, (int) $this->params->get('live_suggestions_limit', 4)))) : [];
        $live['suggest']  = !$result['total'] && $this->params->get('did_you_mean', 1) ? $this->didYouMean($query) : [];
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
            foreach ($this->appIds() as $appId) {
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
        $state   = $this->filterState($result, $query, $sort, max(0, $input->getInt('bs_cat', 0)), max(-1, $input->getInt('bs_app', 0)), $page, $perPage);

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
        if ($this->resultsUrlMemo !== null) {
            return $this->resultsUrlMemo;
        }
        // the Gridbox store search page and its route change rarely: looked up once an hour
        $cache = $this->cache(60);
        $key   = 'results-url-' . $this->getApplication()->getLanguage()->getTag();
        $hit   = $cache->get($key);
        if (is_string($hit) && $hit !== '') {
            return $this->resultsUrlMemo = $hit;
        }
        $url = $this->findResultsUrl();
        $cache->store($url, $key);

        return $this->resultsUrlMemo = $url;
    }

    private function findResultsUrl(): string
    {
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select('id')
                ->from($db->quoteName('#__gridbox_system_pages'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('store-search'));
            $id = (int) $db->setQuery($query)->loadResult();
            if ($id) {
                return \Joomla\CMS\Router\Route::_($this->store()->systemLink($id), false);
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
            'track'      => (bool) $p->get('track_conversions', 1),
            // the visitor's own last searches, kept in their browser only
            'recent'     => (bool) $p->get('live_recent', 1),
            'recentLimit' => max(1, min(20, (int) $p->get('live_recent_limit', 5))),
            'cart'       => Uri::root(true) . '/index.php?option=com_gridbox&view=editor&task=store.addProductToCart',
            'cartAfter'  => (string) $p->get('cart_after', 'cart') === 'message' ? 'message' : 'cart',
            'texts'      => [
                'close'   => Text::_('PLG_SYSTEM_BETTERSEARCH_T_CLOSE'),
                'loading' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_LOADING'),
                'placeholder' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_PLACEHOLDER'),
                'recent'  => trim((string) $p->get('text_recent', '')) ?: Text::_('PLG_SYSTEM_BETTERSEARCH_T_RECENT'),
                'clearRecent' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_RECENT_CLEAR'),
                'removeRecent' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_RECENT_REMOVE'),
                'added'   => trim((string) $p->get('text_added', '')) ?: Text::_('PLG_SYSTEM_BETTERSEARCH_T_ADDED'),
                'cartError' => Text::_('PLG_SYSTEM_BETTERSEARCH_T_CART_ERROR'),
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
        if ($this->storeHelper !== null) {
            $this->storeHelper->saveLinks();
        }
        if ($this->reindexDue) {
            $this->reindexDue = false;
            $this->reindexNow();

            return;
        }
        if ($this->syncDue && $this->params->get('gsc_property', '') !== '' && $this->params->get('gsc_key', '') !== '') {
            $this->gscDaily();
        }
        if ($this->syncDue && $this->params->get('report_enabled', 0)) {
            $this->reportCheck();
        }
        if (!$this->syncDue || !$this->params->get('auto_sync', 1)) {
            return;
        }
        $interval = max(1, (int) $this->params->get('sync_interval', 10)) * 60;
        // a cheap gate before any database work: at most one check per CHECK_GATE seconds per process
        $stamp = JPATH_CACHE . '/plg_system_bettersearch.stamp';
        $mtime = @filemtime($stamp);
        if ($mtime && time() - $mtime < self::CHECK_GATE) {
            return;
        }
        @touch($stamp);
        try {
            $indexer = $this->indexer();
            // the claim is one atomic UPDATE: of two parallel requests only one gets to run the check
            if (!$indexer->claim('checked_at', $interval)) {
                return;
            }
            // the visitor has the page already: everything below runs after the response
            if (function_exists('fastcgi_finish_request') && !(defined('JDEBUG') && JDEBUG)) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            // the session must not stay locked for the visitor's next request while the index is built
            try {
                $this->getApplication()->getSession()->close();
            } catch (\Throwable $e) {
            }
            $before = $indexer->state('version', '0');
            $result = $indexer->sync(max(10, (int) $this->params->get('sync_budget', 300)));
            if ($result['remaining'] > 0) {
                // more to index (first build, many changes): the next batch after half a minute
                $indexer->setState('checked_at', (string) (time() - $interval + 30));
            }
            $cache = $this->cache(1);
            if ($indexer->state('version', '0') !== $before) {
                // every cached result embeds the index version: all of them are dead now
                $cache->clean(self::CACHE_GROUP);
            } else {
                // Joomla never removes expired cache files on the site by itself
                $cache->gc();
            }
        } catch (\Throwable $e) {
            $this->logError($e);
        }
    }

    // ================================================================ tables, product list, settings file

    /** The tables of the install script (CREATE TABLE IF NOT EXISTS), e.g. after an update that skipped them. */
    private function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db   = $this->db();
        $file = JPATH_PLUGINS . '/system/bettersearch/sql/install.mysql.utf8.sql';
        foreach (is_file($file) ? $db->splitSql((string) file_get_contents($file)) : [] as $statement) {
            if (stripos(trim($statement), 'CREATE TABLE IF NOT EXISTS') === 0) {
                $db->setQuery($statement)->execute();
            }
        }
    }

    /** Which pages a picker lists: the items of apps (products, posts) or the Gridbox pages and single-page apps. */
    private function pickerScope($query, string $scope): void
    {
        $db = $this->db();
        if ($scope === 'pages') {
            $singles = array_map('intval', $db->setQuery($db->createQuery()->select('id')->from($db->quoteName('#__gridbox_app'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('single')))->loadColumn() ?: []);
            $query->where('(p.app_id = 0' . ($singles ? ' OR p.app_id IN (' . implode(',', $singles) . ')' : '') . ')');
        } else {
            $query->where('p.app_id > 0');
        }
    }

    /** Every product (id, title, code, app, published) for the instant filter of the product pickers. */
    private function allProducts(string $scope = 'products'): array
    {
        $db    = $this->db();
        $query = $db->createQuery()
            ->select(['p.id', 'p.title', 'p.published', 'p.app_id', 'd.sku'])
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->leftJoin($db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id')
            ->where('p.page_category <> ' . $db->quote('trashed'))
            ->order('p.title ASC');
        $this->pickerScope($query, $scope);
        $rows = $db->setQuery($query, 0, 30000)->loadRowList() ?: [];
        if ($scope === 'pages') {
            // single-page apps are listed under "Pages" too
            foreach ($rows as &$row) {
                $row[3] = 0;
            }
            unset($row);
        }
        $apps = [];
        foreach ($rows as $row) {
            $apps[(int) $row[3]] = true;
        }

        return [
            'items' => array_map(fn ($r) => [(int) $r[0], (string) $r[1], (string) $r[4], (int) $r[3], (int) $r[2]], $rows),
            'apps'  => array_map(fn ($id) => $this->store()->appTitle($id), array_combine(array_keys($apps), array_keys($apps))),
        ];
    }

    /** Names of the settings a settings file may carry (form fields of the manifest; not the Google key). */
    private function settingNames(): array
    {
        $xml   = @simplexml_load_file(JPATH_PLUGINS . '/system/bettersearch/bettersearch.xml');
        $names = [];
        foreach ($xml ? $xml->xpath('//config/fields[@name="params"]/fieldset/field') : [] as $field) {
            $type = (string) $field['type'];
            if (!in_array($type, ['note', 'spacer', 'bstools', 'bspreview', 'bsdict'], true)) {
                $names[(string) $field['name']] = $type;
            }
        }
        unset($names['gsc_key']);

        return $names;
    }

    /** The settings of the form (also unsaved) as a file; the Search Console key is never exported. */
    private function exportSettings(): array
    {
        $params = array_intersect_key($this->params->toArray(), $this->settingNames());
        ksort($params);

        return ['name' => 'better-search-settings-' . gmdate('Y-m-d') . '.json', 'data' => [
            'extension' => 'plg_system_bettersearch', 'version' => self::VERSION, 'exported' => gmdate('c'), 'params' => $params]];
    }

    /** Settings from a file: known settings with plain values only; saved at once (other settings stay). */
    private function importSettings(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 1048576) {
            return ['error' => Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_ERR_FILE')];
        }
        $file = json_decode($raw, true);
        if (!is_array($file) || ($file['extension'] ?? '') !== 'plg_system_bettersearch' || !is_array($file['params'] ?? null)) {
            return ['error' => Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_ERR_FILE')];
        }
        $names = $this->settingNames();
        $clean = function ($value, int $depth) use (&$clean) {
            if (is_scalar($value) || $value === null) {
                return is_string($value) ? mb_substr($value, 0, 200000) : $value;
            }
            if (is_array($value) && $depth < 5 && count($value) <= 5000) {
                $out = [];
                foreach ($value as $k => $v) {
                    if (is_int($k) || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', (string) $k)) {
                        $c = $clean($v, $depth + 1);
                        if ($c !== false) {
                            $out[$k] = $c;
                        }
                    }
                }

                return $out;
            }

            return false;
        };
        $imported = [];
        foreach ($file['params'] as $key => $value) {
            if (isset($names[$key]) && ($v = $clean($value, 0)) !== false) {
                $imported[$key] = $v;
            }
        }
        if (!$imported) {
            return ['error' => Text::_('PLG_SYSTEM_BETTERSEARCH_SETTINGS_ERR_EMPTY')];
        }
        $params = array_merge(($this->readSavedParams() ?? new Registry())->toArray(), $imported);
        $this->saveParams($params);

        return ['ok' => true, 'count' => count($imported)];
    }

    /** All settings back to their defaults (the Search Console connection stays). */
    private function resetSettings(): array
    {
        $saved = ($this->readSavedParams() ?? new Registry())->toArray();
        $this->saveParams(array_intersect_key($saved, array_flip(['gsc_key', 'gsc_property'])));

        return ['ok' => true];
    }

    private function saveParams(array $params): void
    {
        $db = $this->db();
        $db->setQuery($db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params, JSON_UNESCAPED_UNICODE)))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('bettersearch')))->execute();
        $this->cleanCaches();
    }

    // ================================================================ e-mail report

    /**
     * After a page response, at most every 15 minutes: the report of the last period is sent when its
     * day came (Monday, every other Monday or the 1st of the month, from 7:00 site time) and it was
     * not sent yet. The first check after switching the report on only notes the time: the first
     * report goes out at the start of the next period.
     */
    private function reportCheck(): void
    {
        $stamp = JPATH_CACHE . '/plg_system_bettersearch.report';
        $mtime = @filemtime($stamp);
        if ($mtime && time() - $mtime < 900) {
            return;
        }
        @touch($stamp);
        try {
            $indexer = $this->indexer();
            // one process at a time (the state row is claimed in one atomic UPDATE)
            if (!$indexer->claim('report_lock', 600)) {
                return;
            }
            $last = $indexer->state('report_last');
            if ($last === null || $last === '') {
                $indexer->setState('report_last', (string) time());

                return;
            }
            $now    = new \DateTimeImmutable('now', self::siteZone());
            $period = $this->reportPeriod($now, false);
            $due    = $now->getTimestamp() >= $period['start'] + self::REPORT_HOUR * 3600 && (int) $last < $period['start'];
            if ($due && (string) $this->params->get('report_frequency', 'weekly') === 'biweekly') {
                // every other week: not when the previous Monday's report went out
                $due = (int) $last < $period['start'] - 7 * 86400 + self::REPORT_HOUR * 3600;
            }
            if (!$due) {
                return;
            }
            if (function_exists('fastcgi_finish_request') && !(defined('JDEBUG') && JDEBUG)) {
                @fastcgi_finish_request();
            }
            // noted before sending: a failing mail server is not asked again every few minutes (the status shows the error)
            $indexer->setState('report_last', (string) time());
            $this->sendReport(false);
        } catch (\Throwable $e) {
            $this->logError($e);
        }
    }

    /**
     * The period of the report that is due now (or was due last): the previous week, two weeks or
     * calendar month, as days of the site's time zone [from, to).
     *
     * @return array{from: string, to: string, start: int, label: string, days: int}
     */
    private function reportPeriod(\DateTimeImmutable $now, bool $last): array
    {
        $frequency = (string) $this->params->get('report_frequency', 'weekly');
        if ($frequency === 'monthly') {
            $start = $now->modify('first day of this month')->setTime(0, 0);
            $from  = $start->modify('-1 month');
        } else {
            $start = $now->modify('monday this week')->setTime(0, 0);
            $from  = $start->modify($frequency === 'biweekly' ? '-14 days' : '-7 days');
        }
        $end = $start->modify('-1 day');

        return ['from' => $from->format('Y-m-d'), 'to' => $start->format('Y-m-d'), 'start' => $start->getTimestamp(),
            'label' => $from->format('d.m.Y') . ' – ' . $end->format('d.m.Y'), 'days' => (int) $from->diff($start)->days];
    }

    /** Addresses of the report: the setting, else the Super Users who receive system e-mails. */
    private function reportRecipients(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) $this->params->get('report_recipients', '')) ?: [] as $mail) {
            $mail = trim($mail);
            if ($mail !== '' && MailHelper::isEmailAddress($mail)) {
                $out[strtolower($mail)] = $mail;
            }
        }
        if ($out) {
            return array_values($out);
        }
        try {
            $db   = $this->db();
            $rows = $db->setQuery($db->createQuery()
                ->select(['u.email', 'u.sendEmail'])
                ->from($db->quoteName('#__users', 'u'))
                ->innerJoin($db->quoteName('#__user_usergroup_map', 'm') . ' ON m.user_id = u.id')
                ->where('m.group_id = 8')
                ->where('u.block = 0'))->loadObjectList() ?: [];
            $all  = array_values(array_unique(array_filter(array_map(fn ($r) => (string) $r->email, $rows), [MailHelper::class, 'isEmailAddress'])));
            $want = array_values(array_unique(array_filter(array_map(fn ($r) => (int) $r->sendEmail === 1 ? (string) $r->email : '', $rows), [MailHelper::class, 'isEmailAddress'])));

            return $want ?: $all;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Sends the report of the last period. @return array{ok: bool, message: string} */
    private function sendReport(bool $manual): array
    {
        $to = $this->reportRecipients();
        if (!$to) {
            return $this->reportStatus(false, Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_ERR_RECIPIENTS'), $manual);
        }
        $period = $this->reportPeriod(new \DateTimeImmutable('now', self::siteZone()), true);
        $site   = (string) $this->getApplication()->get('sitename', '');
        try {
            $html   = $this->reportHtml($period['from'], $period['to']);
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            foreach ($to as $mail) {
                $mailer->addRecipient($mail);
            }
            $mailer->setSubject(Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_SUBJECT', $site, $period['label']));
            if (method_exists($mailer, 'isHtml')) {
                $mailer->isHtml(true);
            }
            $mailer->setBody($html);
            if (property_exists($mailer, 'AltBody')) {
                $mailer->AltBody = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/tr|/h[1-6]|/div)\b[^>]*>#i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $sent = $mailer->send();
        } catch (\Throwable $e) {
            $this->logError($e);

            return $this->reportStatus(false, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_ERR_SEND', $e->getMessage()), $manual);
        }
        if ($sent === false) {
            return $this->reportStatus(false, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_ERR_SEND', '—'), $manual);
        }

        return $this->reportStatus(true, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_SENT', implode(', ', $to), $period['label']), $manual);
    }

    private function reportStatus(bool $ok, string $message, bool $manual): array
    {
        try {
            $this->indexer()->setState('report_status', json_encode(['ok' => $ok, 'message' => $message, 'at' => time(), 'manual' => $manual]));
        } catch (\Throwable $e) {
        }

        return ['ok' => $ok, 'message' => $message];
    }

    /**
     * The report: totals of the period against the period before, the most searched phrases (with the
     * change), the phrases without results, the phrases that led to the cart.
     */
    private function reportHtml(string $from, string $to): string
    {
        $db    = $this->db();
        // language strings carry entities for the settings form ("&amp;"): decoded first, then escaped once
        $esc   = fn ($s) => htmlspecialchars(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $n     = fn ($v) => number_format((float) $v, 0, ',', "\u{00A0}");
        $limit = max(5, min(100, (int) $this->params->get('report_top', 20)));
        $days  = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        $pFrom = (new \DateTimeImmutable($from))->modify('-' . $days . ' days')->format('Y-m-d');
        $range = fn (string $a, string $b) => ' WHERE day >= ' . $db->quote($a) . ' AND day < ' . $db->quote($b);
        $daily = $db->quoteName('#__bettersearch_daily');

        $totals = function (string $a, string $b) use ($db, $daily, $range): array {
            return $db->setQuery('SELECT IFNULL(SUM(searches), 0) AS searches, COUNT(DISTINCT query) AS phrases, IFNULL(SUM(CASE WHEN results = 0 THEN searches ELSE 0 END), 0) AS zero,'
                . ' IFNULL(SUM(CASE WHEN results < 0 THEN searches ELSE 0 END), 0) AS redirected FROM ' . $daily . $range($a, $b))->loadAssoc() ?: [];
        };
        $now  = $totals($from, $to);
        $prev = $totals($pFrom, $from);

        // the result count of the latest day a phrase was searched
        $top = $db->setQuery('SELECT query, SUM(searches) AS searches, CAST(SUBSTRING_INDEX(GROUP_CONCAT(results ORDER BY day DESC), \',\', 1) AS SIGNED) AS results FROM '
            . $daily . $range($from, $to) . ' GROUP BY query ORDER BY searches DESC, query ASC LIMIT ' . $limit)->loadAssocList() ?: [];
        $before = [];
        if ($top) {
            $list = implode(',', array_map(fn ($r) => $db->quote((string) $r['query']), $top));
            foreach ($db->setQuery('SELECT query, SUM(searches) FROM ' . $daily . $range($pFrom, $from) . ' AND query IN (' . $list . ') GROUP BY query')->loadRowList() ?: [] as [$q, $c]) {
                $before[mb_strtolower((string) $q)] = (int) $c;
            }
        }
        $zero = $this->params->get('report_zero', 1) ? ($db->setQuery('SELECT query, SUM(searches) AS searches FROM ' . $daily . $range($from, $to)
            . ' AND results = 0 GROUP BY query ORDER BY searches DESC, query ASC LIMIT ' . min(25, $limit))->loadAssocList() ?: []) : [];

        $conv = ['clicks' => 0, 'carts' => 0];
        $convTop = [];
        if ($this->params->get('report_conversions', 1)) {
            try {
                $events = $db->quoteName('#__bettersearch_events');
                $conv   = $db->setQuery('SELECT IFNULL(SUM(CASE WHEN kind = 1 THEN hits ELSE 0 END), 0) AS clicks, IFNULL(SUM(CASE WHEN kind = 2 THEN hits ELSE 0 END), 0) AS carts FROM '
                    . $events . $range($from, $to))->loadAssoc() ?: $conv;
                $convTop = $db->setQuery('SELECT query, SUM(CASE WHEN kind = 1 THEN hits ELSE 0 END) AS clicks, SUM(CASE WHEN kind = 2 THEN hits ELSE 0 END) AS carts FROM '
                    . $events . $range($from, $to) . ' GROUP BY query HAVING carts > 0 ORDER BY carts DESC, clicks DESC LIMIT 10')->loadAssocList() ?: [];
            } catch (\Throwable $e) {
            }
        }

        $site   = (string) $this->getApplication()->get('sitename', '');
        $label  = (new \DateTimeImmutable($from))->format('d.m.Y') . ' – ' . (new \DateTimeImmutable($to))->modify('-1 day')->format('d.m.Y');
        $accent = '#1a73e8';
        // $good: a rise is good news (green); for searches without results it is not
        $change = function (int $a, int $b, bool $good = true): string {
            if ($b <= 0) {
                return $a > 0 ? '<span style="color:#15803d">' . htmlspecialchars(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_NEW'), ENT_QUOTES, 'UTF-8') . '</span>' : '–';
            }
            $pct = (int) round(($a - $b) / $b * 100);

            return $pct === 0 ? '±0%' : '<span style="color:' . (($pct > 0) === $good ? '#15803d' : '#b91c1c') . '">' . ($pct > 0 ? '▲ +' : '▼ ') . $pct . '%</span>';
        };
        $th = 'style="text-align:left;padding:6px 8px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#6b7280;text-transform:uppercase"';
        $td = 'style="padding:6px 8px;border-bottom:1px solid #f0f0f0"';
        $tdr = 'style="padding:6px 8px;border-bottom:1px solid #f0f0f0;text-align:right;white-space:nowrap"';
        $h2 = 'style="font-size:16px;margin:28px 0 8px;color:#111"';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;color:#1f2328;max-width:680px;margin:0 auto">'
            . '<h1 style="font-size:20px;margin:0 0 4px;color:#111">' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_TITLE')) . ($site !== '' ? ' – ' . $esc($site) : '') . '</h1>'
            . '<p style="margin:0 0 16px;color:#6b7280">' . $esc(Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_PERIOD', $label)) . '</p>';

        if (!(int) ($now['searches'] ?? 0)) {
            $html .= '<p>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_NO_DATA')) . '</p>';
        } else {
            $tiles = [
                ['REPORT_SEARCHES', (int) $now['searches'], (int) ($prev['searches'] ?? 0)],
                ['REPORT_PHRASES', (int) $now['phrases'], (int) ($prev['phrases'] ?? 0)],
                ['REPORT_ZERO', (int) $now['zero'], (int) ($prev['zero'] ?? 0)],
            ];
            if ($this->params->get('report_conversions', 1)) {
                $tiles[] = ['REPORT_CLICKS', (int) $conv['clicks'], null];
                $tiles[] = ['REPORT_CARTS', (int) $conv['carts'], null];
            }
            $html .= '<table role="presentation" style="width:100%;border-collapse:separate;border-spacing:6px"><tr>';
            foreach ($tiles as [$key, $value, $old]) {
                $html .= '<td style="background:#f6f8fa;border-radius:8px;padding:10px 12px;vertical-align:top"><div style="font-size:12px;color:#6b7280">'
                    . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_' . $key)) . '</div><div style="font-size:22px;font-weight:700">' . $n($value) . '</div>'
                    . ($old !== null ? '<div style="font-size:12px">' . $change($value, $old, $key !== 'REPORT_ZERO') . '</div>' : '') . '</td>';
            }
            $html .= '</tr></table>';
            if ((int) $now['searches'] > 0) {
                $html .= '<p style="margin:6px 0 0;color:#6b7280;font-size:13px">' . $esc(Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_ZERO_SHARE',
                    round(100 * (int) $now['zero'] / (int) $now['searches'], 1) . '%')) . '</p>';
            }

            $html .= '<h2 ' . $h2 . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_TOP')) . '</h2><table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>#</th><th ' . $th . '>'
                . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_PHRASE')) . '</th><th ' . $th . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_SEARCHES'))
                . '</th><th ' . $th . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_RESULTS')) . '</th><th ' . $th . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_CHANGE')) . '</th></tr>';
            foreach ($top as $i => $row) {
                $res   = (int) $row['results'];
                $html .= '<tr><td ' . $td . '>' . ($i + 1) . '</td><td ' . $td . '>' . $esc($row['query']) . '</td><td ' . $tdr . '>' . $n($row['searches']) . '</td><td ' . $tdr . '>'
                    . ($res === 0 ? '<b style="color:#b91c1c">0</b>' : ($res < 0 ? '→' : $n($res))) . '</td><td ' . $tdr . '>'
                    . $change((int) $row['searches'], $before[mb_strtolower((string) $row['query'])] ?? 0) . '</td></tr>';
            }
            $html .= '</table>';

            if ($zero) {
                $html .= '<h2 ' . $h2 . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_ZERO_TOP')) . '</h2><p style="margin:0 0 8px;color:#6b7280;font-size:13px">'
                    . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_ZERO_HINT')) . '</p><table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>'
                    . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_PHRASE')) . '</th><th ' . $th . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_SEARCHES')) . '</th></tr>';
                foreach ($zero as $row) {
                    $html .= '<tr><td ' . $td . '>' . $esc($row['query']) . '</td><td ' . $tdr . '>' . $n($row['searches']) . '</td></tr>';
                }
                $html .= '</table>';
            }

            if ($convTop) {
                $html .= '<h2 ' . $h2 . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_CONV_TOP')) . '</h2><table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>'
                    . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_COL_PHRASE')) . '</th><th ' . $th . '>' . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_CLICKS')) . '</th><th ' . $th . '>'
                    . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_CARTS')) . '</th></tr>';
                foreach ($convTop as $row) {
                    $html .= '<tr><td ' . $td . '>' . $esc($row['query']) . '</td><td ' . $tdr . '>' . $n($row['clicks']) . '</td><td ' . $tdr . '>' . $n($row['carts']) . '</td></tr>';
                }
                $html .= '</table>';
            }
        }

        $id    = (int) $this->db()->setQuery($this->db()->createQuery()->select('extension_id')->from($this->db()->quoteName('#__extensions'))
            ->where($this->db()->quoteName('type') . ' = ' . $this->db()->quote('plugin'))->where($this->db()->quoteName('element') . ' = ' . $this->db()->quote('bettersearch')))->loadResult();
        $admin = rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=' . $id;
        $html .= '<p style="margin:28px 0 0"><a href="' . $esc($admin) . '" style="display:inline-block;background:' . $accent . ';color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:700">'
            . $esc(Text::_('PLG_SYSTEM_BETTERSEARCH_REPORT_OPEN')) . '</a></p><p style="margin:16px 0 0;color:#9ca3af;font-size:12px">'
            . $esc(Text::sprintf('PLG_SYSTEM_BETTERSEARCH_REPORT_FOOTER', $site !== '' ? $site : Uri::root())) . '</p></div>';

        return $html;
    }

    /** The site's time zone (Global Configuration), as Gridbox uses it for dates. */
    private static function siteZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    // ================================================================ Google Search Console

    /** Once a day, after a page response: the queries of the last days from Search Console. */
    private function gscDaily(): void
    {
        $stamp = JPATH_CACHE . '/plg_system_bettersearch.gsc';
        $mtime = @filemtime($stamp);
        if ($mtime && time() - $mtime < 3600) {
            return;
        }
        @touch($stamp);
        try {
            if (!$this->indexer()->claim('gsc_at', 86400)) {
                return;
            }
            if (function_exists('fastcgi_finish_request') && !(defined('JDEBUG') && JDEBUG)) {
                @fastcgi_finish_request();
            }
            $this->gscFetch();
        } catch (\Throwable $e) {
            $this->logError($e);
        }
    }

    /**
     * Search Console queries through a service account (its JSON key in the settings; the account's
     * e-mail address must be a user of the Search Console property).
     *
     * @return array{ok: bool, rows?: int, error?: string}
     */
    private function gscFetch(): array
    {
        $property = trim((string) $this->params->get('gsc_property', ''));
        $key      = json_decode((string) $this->params->get('gsc_key', ''), true);
        if ($property === '' || !is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            return $this->gscResult(false, Text::_('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_SETUP'));
        }
        if (!function_exists('openssl_sign')) {
            return $this->gscResult(false, Text::_('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_OPENSSL'));
        }

        // an access token for the read-only Search Console scope (OAuth 2.0 JWT bearer grant)
        $tokenUri = 'https://oauth2.googleapis.com/token';
        $b64      = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now      = time();
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss' => (string) $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600]));
        $signature = '';
        $pkey      = @openssl_pkey_get_private((string) $key['private_key']);
        if (!$pkey || !openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            return $this->gscResult(false, Text::_('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_KEY'));
        }
        try {
            $http     = HttpFactory::getHttp();
            $response = $http->post($tokenUri, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned . '.' . $b64($signature)]), ['Content-Type' => 'application/x-www-form-urlencoded'], 20);
            $token = json_decode((string) $response->body, true)['access_token'] ?? '';
            if ($response->code !== 200 || $token === '') {
                return $this->gscResult(false, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_AUTH', (int) $response->code));
            }

            $days     = max(7, min(480, (int) $this->params->get('gsc_days', 28)));
            $response = $http->post('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query',
                json_encode(['startDate' => gmdate('Y-m-d', $now - ($days + 2) * 86400), 'endDate' => gmdate('Y-m-d', $now - 2 * 86400),
                    'dimensions' => ['query'], 'rowLimit' => 5000]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], 30);
            if ($response->code !== 200) {
                return $this->gscResult(false, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_QUERY', (int) $response->code));
            }
        } catch (\Throwable $e) {
            $this->logError($e);

            return $this->gscResult(false, Text::_('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_NETWORK'));
        }

        $rows = [];
        foreach ((json_decode((string) $response->body, true)['rows'] ?? []) as $row) {
            $rows[] = [(string) ($row['keys'][0] ?? ''), (int) ($row['clicks'] ?? 0), (int) ($row['impressions'] ?? 0), (float) ($row['position'] ?? 0)];
        }

        return $this->gscStore($rows, 'api');
    }

    /**
     * A CSV file exported from Search Console ("Queries" table): the first column is the query, then
     * clicks, impressions, CTR and position (any language of the headings, comma or semicolon).
     */
    private function gscImport(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (trim($csv) === '' || strlen($csv) > 5 * 1048576) {
            return $this->gscResult(false, Text::_('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_CSV'));
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $sep   = substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $num   = fn ($v) => (float) str_replace([' ', "\u{00A0}", '%', ','], ['', '', '', '.'], trim((string) $v));
        $rows  = [];
        foreach (array_slice($lines, 1) as $line) {
            $cols = str_getcsv($line, $sep, '"', '');
            if (count($cols) < 3 || trim((string) $cols[0]) === '') {
                continue;
            }
            $rows[] = [(string) $cols[0], (int) $num($cols[1]), (int) $num($cols[2]), isset($cols[4]) ? $num($cols[4]) : 0.0];
        }
        if (!$rows) {
            return $this->gscResult(false, Text::_('PLG_SYSTEM_BETTERSEARCH_GSC_ERR_CSV'));
        }

        return $this->gscStore($rows, 'csv');
    }

    /** @param array<int, array{0: string, 1: int, 2: int, 3: float}> $rows query, clicks, impressions, position */
    private function gscStore(array $rows, string $source): array
    {
        $db = $this->db();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__bettersearch_gsc'))->execute();
        $now    = $db->quote(gmdate('Y-m-d H:i:s'));
        $values = [];
        $seen   = [];
        foreach (array_slice($rows, 0, 5000) as [$q, $clicks, $impressions, $position]) {
            $q = mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q) ?? ''), 'UTF-8'), 0, 190);
            if ($q === '' || isset($seen[$q]) || preg_match('/[\x00-\x1F]/', $q)) {
                continue;
            }
            $seen[$q] = true;
            $values[] = '(' . $db->quote($q) . ', ' . max(0, $clicks) . ', ' . max(0, $impressions) . ', ' . round(max(0, $position), 2) . ', ' . $now . ')';
        }
        foreach (array_chunk($values, 500) as $chunk) {
            // the table compares text without accents ("pętli" = "petli"): such queries are added up
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__bettersearch_gsc') . ' (query, clicks, impressions, position, updated_at) VALUES ' . implode(', ', $chunk)
                . ' ON DUPLICATE KEY UPDATE position = IF(impressions + VALUES(impressions) > 0, (position * impressions + VALUES(position) * VALUES(impressions)) / (impressions + VALUES(impressions)), position),'
                . ' clicks = clicks + VALUES(clicks), impressions = impressions + VALUES(impressions)')->execute();
        }

        $saved = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__bettersearch_gsc'))->loadResult();

        return $this->gscResult(true, Text::sprintf('PLG_SYSTEM_BETTERSEARCH_GSC_DONE', $saved), $source, $saved);
    }

    private function gscResult(bool $ok, string $message, string $source = '', int $rows = 0): array
    {
        try {
            $this->indexer()->setState('gsc_status', json_encode(['ok' => $ok, 'message' => $message, 'source' => $source, 'at' => time()]));
        } catch (\Throwable $e) {
        }

        // "count", not "rows": the list of queries is merged into the same answer under "rows"
        return ['ok' => $ok, 'message' => $message, 'count' => $rows];
    }

    /**
     * Search Console queries for the report, with what this search finds for each. Sorted by the
     * column the administrator clicked (query, clicks, impressions, CTR, position), optionally only
     * the queries containing a text.
     */
    private function gscList(bool $check): array
    {
        $db     = $this->db();
        $input  = $this->getApplication()->getInput();
        $sorts  = ['query' => 'query', 'clicks' => 'clicks', 'impressions' => 'impressions', 'ctr' => '(clicks / NULLIF(impressions, 0))', 'position' => 'position'];
        $sort   = $input->getCmd('sort', 'impressions');
        $sort   = isset($sorts[$sort]) ? $sort : 'impressions';
        $dir    = strtolower($input->getCmd('dir', $sort === 'query' || $sort === 'position' ? 'asc' : 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $limit  = max(50, min(1000, $input->getInt('limit', 200)));
        $filter = mb_substr(trim((string) $input->get('filter', '', 'string')), 0, 100);
        $where  = $filter !== '' ? ' WHERE query LIKE ' . $db->quote('%' . $db->escape($filter, true) . '%', false) : '';
        $rows   = $db->setQuery('SELECT query, clicks, impressions, position FROM ' . $db->quoteName('#__bettersearch_gsc') . $where
            . ' ORDER BY ' . $sorts[$sort] . ' ' . $dir . ', impressions DESC, query ASC LIMIT ' . $limit)->loadAssocList() ?: [];
        foreach ($rows as &$row) {
            $row['ctr'] = (int) $row['impressions'] > 0 ? round(100 * (int) $row['clicks'] / (int) $row['impressions'], 2) : 0.0;
        }
        unset($row);
        $total  = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__bettersearch_gsc') . $where)->loadResult();
        $status = json_decode((string) $this->indexer()->state('gsc_status', ''), true) ?: [];
        if ($check && $rows) {
            $siteLang = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            $searcher = new Searcher($this->db(), $this->params, $this->normalizer(), $this->guestLevels(), $siteLang);
            $t0       = microtime(true);
            foreach ($rows as &$row) {
                // a bounded check: at most 20 seconds of searching
                $row['found'] = microtime(true) - $t0 < 20 ? $searcher->search((string) $row['query'])['total'] : null;
            }
            unset($row);
        }

        return ['rows' => $rows, 'status' => $status + ['at' => 0, 'message' => ''], 'configured' => trim((string) $this->params->get('gsc_property', '')) !== '',
            'sort' => $sort, 'dir' => strtolower($dir), 'limit' => $limit, 'filter' => $filter, 'total' => $total];
    }

    /** Right after a Gridbox change: the changed pages are indexed again (no waiting for the next check). */
    private function reindexNow(): void
    {
        $this->params = $this->savedParams() ?? $this->params;
        if (!$this->params->get('instant_reindex', 1)) {
            return;
        }
        try {
            if (function_exists('fastcgi_finish_request') && !(defined('JDEBUG') && JDEBUG)) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            @ignore_user_abort(true);
            $indexer = $this->indexer();
            $before  = $indexer->state('version', '0');
            $result  = $indexer->sync(max(10, (int) $this->params->get('sync_budget', 300)));
            if ($result['remaining'] > 0) {
                // the rest with the next check
                $indexer->setState('checked_at', '0');
            }
            if ($indexer->state('version', '0') !== $before) {
                $this->cache(1)->clean(self::CACHE_GROUP);
            }
        } catch (\Throwable $e) {
            $this->logError($e);
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
        if (is_array($form) && isset($form['params']) && is_array($form['params']) && in_array($task, ['test', 'preview', 'settings_export', 'report_preview'], true)) {
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
                        'score' => $item['score'], 'pinned' => $item['pinned'], 'featured' => !empty($item['featured']), 'reasons' => $item['reasons']];
                }

                return ['query' => $query, 'mode' => $result['mode'], 'corrected' => $result['corrected'], 'total' => $result['total'],
                    'groups' => $result['groups'], 'params' => $result['params'] ?? [], 'ms' => $result['ms'], 'rows' => $rows];

            case 'preview':
                return $this->preview((string) $input->getCmd('mode', 'live'), (string) $input->getCmd('device', 'desktop'),
                    mb_substr(trim((string) $input->get('q', '', 'raw')), 0, 200));

            case 'products':
                return ['items' => $this->findProducts(trim((string) $input->get('q', '', 'raw')), $input->getCmd('scope', 'products'))];

            case 'titles':
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) $input->get('ids', '', 'string')))));

                return ['items' => array_values($this->titles(array_slice($ids, 0, 500)))];

            case 'stats':
                return $this->stats();

            case 'clearlog':
                $this->db()->setQuery('TRUNCATE TABLE ' . $this->db()->quoteName('#__bettersearch_log'))->execute();

                return ['ok' => true] + $this->stats();

            case 'conversions':
                $this->ensureTables();

                return $this->conversions(max(1, min(366, $input->getInt('days', 30))));

            case 'products_all':
                return $this->allProducts($input->getCmd('scope', 'products'));

            case 'settings_export':
                return $this->exportSettings();

            case 'settings_import':
                if (!$user->authorise('core.edit', 'com_plugins')) {
                    return ['error' => Text::_('JERROR_ALERTNOAUTHOR')];
                }

                return $this->importSettings((string) $input->post->get('data', '', 'raw'));

            case 'settings_reset':
                if (!$user->authorise('core.edit', 'com_plugins')) {
                    return ['error' => Text::_('JERROR_ALERTNOAUTHOR')];
                }

                return $this->resetSettings();

            case 'gsc':
                $this->ensureTables();

                return $this->gscList($input->getInt('check', 0) === 1);

            case 'report_preview':
                $this->ensureTables();
                $period = $this->reportPeriod(new \DateTimeImmutable('now', self::siteZone()), true);

                $last = (int) $this->indexer()->state('report_last', '0');

                return ['html' => $this->reportHtml($period['from'], $period['to']), 'period' => $period['label'], 'to' => implode(', ', $this->reportRecipients()),
                    'status' => json_decode((string) $this->indexer()->state('report_status', ''), true) ?: null, 'last' => $last ? date('Y-m-d H:i', $last) : ''];

            case 'report_send':
                if (!$user->authorise('core.edit', 'com_plugins')) {
                    return ['error' => Text::_('JERROR_ALERTNOAUTHOR')];
                }
                if (is_array($form) && isset($form['params']) && is_array($form['params'])) {
                    $this->params = new Registry($form['params']);
                }
                $this->ensureTables();

                return $this->sendReport(true);

            case 'gsc_fetch':
                if (!$user->authorise('core.edit', 'com_plugins')) {
                    return ['error' => Text::_('JERROR_ALERTNOAUTHOR')];
                }
                // the key and address in the form (also unsaved) are used
                if (is_array($form) && isset($form['params']) && is_array($form['params'])) {
                    $this->params = new Registry($form['params']);
                }
                $this->ensureTables();

                return $this->gscFetch() + $this->gscList(false);

            case 'gsc_import':
                if (!$user->authorise('core.edit', 'com_plugins')) {
                    return ['error' => Text::_('JERROR_ALERTNOAUTHOR')];
                }

                $this->ensureTables();

                return $this->gscImport((string) $input->post->get('csv', '', 'raw')) + $this->gscList(false);

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
        $renderer = new Renderer($this->params, $store, new Thumbs(max(0.0, min(5.0, (float) $this->params->get('thumb_budget', 0.5))),
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
            'groups'     => $this->groupStatus($indexer->appIds()),
            'errors'     => $this->logTail(8),
            'version'    => self::VERSION,
        ];
    }

    /**
     * For every searched app: pages in Gridbox, how many a visitor (guest) may see now and why the
     * others are left out, and how many are in the index — shows at once where pages get lost.
     */
    private function groupStatus(array $apps): array
    {
        $db     = $this->db();
        $now    = $db->quote((new \DateTime('now', self::siteZone()))->format('Y-m-d H:i:s'));
        $null   = $db->quote($db->getNullDate());
        $lang   = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $levels = implode(',', array_map('intval', Access::getAuthorisedViewLevels(0)) ?: [1]);
        $out    = [];
        foreach ($apps as $appId) {
            $appId = (int) $appId;
            try {
                $row = $db->setQuery('SELECT COUNT(*) AS total,'
                    . ' SUM(p.published <> 1) AS unpublished,'
                    . ' SUM(p.published = 1 AND (p.created > ' . $now . ' OR (p.end_publishing <> ' . $null . ' AND p.end_publishing < ' . $now . '))) AS dates,'
                    . ' SUM(p.published = 1 AND p.language NOT IN (' . $db->quote($lang) . ', ' . $db->quote('*') . ')) AS language,'
                    . ' SUM(p.published = 1 AND p.page_access NOT IN (' . $levels . ')) AS access,'
                    . ' SUM(i.id IS NOT NULL) AS indexed'
                    . ' FROM ' . $db->quoteName('#__gridbox_pages', 'p')
                    . ' LEFT JOIN ' . $db->quoteName('#__bettersearch_items', 'i') . ' ON i.id = p.id'
                    . ' WHERE p.app_id = ' . $appId . ' AND p.page_category <> ' . $db->quote('trashed'))->loadObject();
                $out[] = ['id' => $appId, 'title' => $this->store()->appTitle($appId), 'type' => $appId === 0 ? 'pages' : $this->store()->appType($appId),
                    'total' => (int) $row->total, 'indexed' => (int) $row->indexed, 'unpublished' => (int) $row->unpublished,
                    'dates' => (int) $row->dates, 'language' => (int) $row->language, 'access' => (int) $row->access, 'siteLanguage' => $lang];
            } catch (\Throwable $e) {
                $out[] = ['id' => $appId, 'title' => $this->store()->appTitle($appId), 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** The latest lines of the plugin's log file (newest first). */
    private function logTail(int $lines): array
    {
        $file = rtrim((string) $this->getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs'), '/') . '/' . self::LOG_FILE;
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return [];
        }
        if ((int) @filesize($file) > 65536) {
            fseek($fh, -65536, SEEK_END);
        }
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && !str_starts_with($line, '<?php')) {
                $out[] = $line;
            }
        }

        return array_reverse(array_slice($out, -$lines));
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

    /** $results = -1: the phrase was redirected (counted as a search with a destination). */
    /** Clicks and cart additions per query (and the products behind them) in the last days. */
    private function conversions(int $days): array
    {
        $db    = $this->db();
        $since = $db->quote(gmdate('Y-m-d', time() - $days * 86400));
        $rows  = $db->setQuery('SELECT e.query, SUM(CASE WHEN e.kind = 1 THEN e.hits ELSE 0 END) AS clicks, SUM(CASE WHEN e.kind = 2 THEN e.hits ELSE 0 END) AS carts,'
            . ' MAX(l.searches) AS searches FROM ' . $db->quoteName('#__bettersearch_events', 'e')
            . ' LEFT JOIN ' . $db->quoteName('#__bettersearch_log', 'l') . ' ON l.query = e.query'
            . ' WHERE e.day >= ' . $since . ' GROUP BY e.query ORDER BY carts DESC, clicks DESC LIMIT 100')->loadAssocList() ?: [];
        $items = $db->setQuery('SELECT item_id, SUM(CASE WHEN kind = 1 THEN hits ELSE 0 END) AS clicks, SUM(CASE WHEN kind = 2 THEN hits ELSE 0 END) AS carts FROM '
            . $db->quoteName('#__bettersearch_events') . ' WHERE day >= ' . $since . ' GROUP BY item_id ORDER BY carts DESC, clicks DESC LIMIT 30')->loadAssocList() ?: [];
        $titles = $this->titles(array_map('intval', array_column($items, 'item_id')));
        foreach ($items as &$item) {
            $item['title'] = $titles[(int) $item['item_id']]['title'] ?? ('#' . $item['item_id']);
        }
        unset($item);
        $totals = $db->setQuery('SELECT SUM(CASE WHEN kind = 1 THEN hits ELSE 0 END) AS clicks, SUM(CASE WHEN kind = 2 THEN hits ELSE 0 END) AS carts FROM '
            . $db->quoteName('#__bettersearch_events') . ' WHERE day >= ' . $since)->loadAssoc() ?: [];

        return ['days' => $days, 'queries' => $rows, 'items' => $items, 'clicks' => (int) ($totals['clicks'] ?? 0), 'carts' => (int) ($totals['carts'] ?? 0),
            'searches' => (int) $db->setQuery('SELECT IFNULL(SUM(searches), 0) FROM ' . $db->quoteName('#__bettersearch_log') . ' WHERE last_at >= ' . $since)->loadResult()];
    }

    private function logSearch(string $query, int $results): void
    {
        if ($this->isBot()) {
            return;
        }
        $results = max(-1, $results);
        $q = mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 'UTF-8'), 0, 120);
        // statistics are for what shoppers type: not for noise or very long strings
        if ($q === '' || preg_match('/[\x00-\x1F]/', $q) || mb_strlen($this->normalizer()->compact($q)) < 2 || substr_count($q, ' ') > 9) {
            return;
        }
        try {
            $db = $this->db();
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__bettersearch_log') . ' (query, searches, results, last_at) VALUES ('
                . $db->quote($q) . ', 1, ' . $results . ', ' . $db->quote(gmdate('Y-m-d H:i:s')) . ') ON DUPLICATE KEY UPDATE searches = searches + 1, results = '
                . $results . ', last_at = VALUES(last_at)')->execute();
            $this->logDaily($q, $results);
            // now and then: keep the table bounded (the rarely searched, oldest rows go)
            if (random_int(1, 50) === 1) {
                $over = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__bettersearch_log'))->loadResult() - self::LOG_ROWS;
                if ($over > 0) {
                    $db->setQuery('DELETE FROM ' . $db->quoteName('#__bettersearch_log') . ' ORDER BY searches ASC, last_at ASC LIMIT ' . $over)->execute();
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** The search counted for its day (site time): the e-mail report sums the days of its period. */
    private function logDaily(string $q, int $results): void
    {
        $db  = $this->db();
        $day = (new \DateTime('now', self::siteZone()))->format('Y-m-d');
        try {
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__bettersearch_daily') . ' (day, query, searches, results) VALUES (' . $db->quote($day) . ', '
                . $db->quote($q) . ', 1, ' . $results . ') ON DUPLICATE KEY UPDATE searches = searches + 1, results = VALUES(results)')->execute();
            if (random_int(1, 200) === 1) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__bettersearch_daily') . ' WHERE day < ' . $db->quote(gmdate('Y-m-d', time() - self::DAILY_DAYS * 86400)))->execute();
            }
        } catch (\Throwable $e) {
            // the table of an older installation: created for the next search
            try {
                $this->ensureTables();
            } catch (\Throwable $ignored) {
            }
        }
    }

    /** Products for the picker: by id, title or SKU. */
    private function findProducts(string $q, string $scope = 'products'): array
    {
        $db    = $this->db();
        $query = $db->createQuery()
            ->select(['p.id', 'p.title', 'p.published', 'p.app_id', 'd.sku'])
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->leftJoin($db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id')
            ->where('p.page_category <> ' . $db->quote('trashed'))
            ->order('p.title ASC')
            ->setLimit(30);
        $this->pickerScope($query, $scope);
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
                'app' => $this->store()->appTitle($scope === 'pages' ? 0 : (int) $row->app_id)];
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

    /** View levels of a guest of the site (what Joomla itself gives user 0). */
    private function guestLevels(): array
    {
        try {
            $levels = array_map('intval', Access::getAuthorisedViewLevels(0));
        } catch (\Throwable $e) {
            $levels = [];
        }

        return $levels ?: [1];
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

    /** @return int[] ids of the indexed Gridbox apps (read once per request) */
    private function appIds(): array
    {
        return $this->appIdsMemo ??= $this->indexer()->appIds();
    }

    /** Version of the index and the visibility stamp of its pages, read together once per request. */
    private function indexState(): array
    {
        if ($this->indexState === null) {
            try {
                $this->indexState = $this->indexer()->versionAndStamp();
            } catch (\Throwable $e) {
                $this->indexState = ['version' => '0', 'vis' => '0'];
            }
        }

        return $this->indexState;
    }

    /** Changes whenever a page of the indexed apps is published, unpublished, restricted or expires (checked every minute). */
    private function visibilityStamp(): string
    {
        return $this->indexState()['vis'];
    }

    /** Errors go to the plugin's own log file (logs/plg_system_bettersearch.php); the Status shows the latest. */
    private function logError(\Throwable $e): void
    {
        static $ready = false;
        try {
            if (!$ready) {
                Log::addLogger(['text_file' => self::LOG_FILE], Log::ALL & ~Log::DEBUG, ['plg_system_bettersearch']);
                $ready = true;
            }
            Log::add(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), Log::ERROR, 'plg_system_bettersearch');
        } catch (\Throwable $ignored) {
        }
    }

    private ?Store $storeHelper = null;

    private function store(): Store
    {
        if ($this->storeHelper === null) {
            $this->storeHelper = new Store($this->db(), $this->getApplication(), $this->levels());
            $this->storeHelper->setPagesTitle(trim((string) $this->params->get('text_pages', '')) ?: Text::_('PLG_SYSTEM_BETTERSEARCH_T_SITE_PAGES'));
            $this->storeHelper->setDeliveryField($this->params->get('avail_enabled', 0) ? (int) $this->params->get('delivery_field', 0) : 0);
            $minutes = max(0, min(1440, (int) $this->params->get('cache_time', 15)));
            if ($minutes > 0 && $this->getApplication()->isClient('site')) {
                // routed links of the products shown so far, per language and access levels
                $this->storeHelper->setLinkCache($this->cache($minutes), 'links-' . md5(json_encode([
                    $this->getApplication()->getLanguage()->getTag(), $this->levels(), $this->indexVersion(),
                ])));
            }
        }

        return $this->storeHelper;
    }

    /** @param float|null $maxBudget a lower time budget for thumbnails than the setting (live results) */
    private function renderer(?float $maxBudget = null): Renderer
    {
        $budget = max(0.0, min(5.0, (float) $this->params->get('thumb_budget', 0.5)));
        if ($maxBudget !== null) {
            $budget = min($budget, $maxBudget);
        }
        $thumbs   = new Thumbs($budget, (int) $this->params->get('thumb_quality', 80));
        $renderer = new Renderer($this->params, $this->store(), $thumbs, $this->device());
        $renderer->setQuoteFallback($this->quoteFallback());

        return $renderer;
    }

    /** "Ask for a quote" without an address of its own: an e-mail to the site with the product in the subject. */
    private function quoteFallback(): string
    {
        $mail = trim((string) $this->getApplication()->get('mailfrom', ''));
        if ($mail === '' || !MailHelper::isEmailAddress($mail)) {
            return '';
        }
        // the placeholders survive the encoding of the texts: they are filled in per product
        $keep = fn (string $s) => str_replace(['%7Btitle%7D', '%7Bsku%7D', '%7Burl%7D'], ['{title}', '{sku}', '{url}'], rawurlencode($s));

        return 'mailto:' . $mail . '?subject=' . $keep(Text::_('PLG_SYSTEM_BETTERSEARCH_T_QUOTE_SUBJECT')) . '&body=' . $keep(Text::_('PLG_SYSTEM_BETTERSEARCH_T_QUOTE_BODY'));
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

    private function indexVersion(): string
    {
        return $this->indexState()['version'];
    }

    private function cache(int $minutes)
    {
        return Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('output', ['defaultgroup' => self::CACHE_GROUP, 'lifetime' => $minutes, 'caching' => true]);
    }

    private function savedParams(): ?Registry
    {
        if ($this->savedRead) {
            return $this->saved;
        }
        $this->savedRead = true;

        return $this->saved = $this->readSavedParams();
    }

    private function readSavedParams(): ?Registry
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
