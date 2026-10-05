<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Builds and keeps the search index (#__bettersearch_items) in step with Gridbox. Gridbox saves
 * products through its own editor without Joomla content events, so changes are found by a
 * signature per page (title, save time, categories, product data, fields, tags) compared with the
 * one stored at indexing time. Visibility (published, access, dates, language) is not indexed: it
 * is checked live at search time, so publishing a product needs no reindex.
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

final class Indexer
{
    /** Bump when the index format changes: every item is indexed again. */
    public const FORMAT = 5;

    private DatabaseInterface $db;

    private Registry $params;

    private Normalizer $norm;

    /** @var array<int, object>|null all Gridbox categories by id */
    private ?array $categories = null;

    /** @var array<int, object>|null Gridbox fields chosen for the index, by id */
    private ?array $fields = null;

    /** @var int[]|null */
    private ?array $appIdsMemo = null;

    /** @var int[] single-page apps (indexed with the pages when pages are searched) */
    private array $singleApps = [];

    /** Seconds between two deep checks (the page layouts, by checksum). */
    private const DEEP_INTERVAL = 86400;

    /** Seconds the visibility stamp is reused before the pages are looked at again. */
    private const STAMP_INTERVAL = 60;

    /** Bytes of one REPLACE statement (hosts with a small max_allowed_packet). */
    private const STATEMENT_BYTES = 900000;

    public function __construct(DatabaseInterface $db, Registry $params, Normalizer $norm)
    {
        $this->db     = $db;
        $this->params = $params;
        $this->norm   = $norm;
    }

    // ---------------------------------------------------------------- configuration

    /**
     * Ids of the Gridbox apps whose pages are indexed. With "Search pages" on, 0 (the Gridbox Pages)
     * and the single-page apps are added: their pages are indexed as one group "Pages" (app 0).
     *
     * @return int[]
     */
    public function appIds(): array
    {
        if ($this->appIdsMemo !== null) {
            return $this->appIdsMemo;
        }
        $ids = array_values(array_filter(array_map('intval', (array) $this->params->get('apps', []))));
        if (!$ids) {
            // default: every store app
            $query = $this->db->createQuery()
                ->select('id')
                ->from($this->db->quoteName('#__gridbox_app'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('products'));
            $ids = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);
        }
        if ($this->params->get('index_pages', 0)) {
            $query = $this->db->createQuery()
                ->select('id')
                ->from($this->db->quoteName('#__gridbox_app'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('single'));
            $this->singleApps = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);
            $ids = array_values(array_unique(array_merge($ids, [0], $this->singleApps)));
        }

        return $this->appIdsMemo = $ids;
    }

    /** The index group of a page: its app, or 0 for the Gridbox Pages and single-page apps. */
    private function indexApp(int $appId): int
    {
        return in_array($appId, $this->singleApps, true) ? 0 : $appId;
    }

    /** @return int[] */
    private function fieldIds(): array
    {
        return array_values(array_filter(array_map('intval', (array) $this->params->get('index_fields', []))));
    }

    /**
     * Everything that changes how items are indexed: the settings and the shared data (category
     * names and tree, field definitions, tag names). A change marks every item for indexing.
     */
    public function configSignature(): string
    {
        $keys = ['apps', 'index_fields', 'index_intro', 'index_meta', 'index_content', 'content_limit', 'index_tags',
            'index_parent_cats', 'index_variation_sku', 'index_pages'];
        $cfg  = [self::FORMAT, $this->appIds()];
        foreach ($keys as $key) {
            $cfg[] = $this->params->get($key);
        }

        $db   = $this->db;
        $data = [];
        foreach ([
            ['#__gridbox_categories', "CONCAT_WS('|', id, title, parent)"],
            ['#__gridbox_tags', "CONCAT_WS('|', id, title)"],
            ['#__gridbox_fields', "CONCAT_WS('|', id, label, field_type, IFNULL(options, ''))"],
            ['#__gridbox_fields_data', "CONCAT_WS('|', id, option_key, value)"],
        ] as [$table, $expr]) {
            try {
                $data[] = $db->setQuery('SELECT COUNT(*), IFNULL(SUM(CRC32(' . $expr . ')), 0) FROM ' . $db->quoteName($table))->loadRow();
            } catch (\Throwable $e) {
                $data[] = 'missing';
            }
        }

        return md5(json_encode([$cfg, $data]));
    }

    // ---------------------------------------------------------------- state

    public function state(string $key, ?string $default = null): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('v'))
            ->from($this->db->quoteName('#__bettersearch_state'))
            ->where($this->db->quoteName('k') . ' = ' . $this->db->quote($key));
        $value = $this->db->setQuery($query)->loadResult();

        return $value === null ? $default : (string) $value;
    }

    public function setState(string $key, string $value): void
    {
        $this->db->setQuery('REPLACE INTO ' . $this->db->quoteName('#__bettersearch_state') . ' (' . $this->db->quoteName('k') . ', '
            . $this->db->quoteName('v') . ') VALUES (' . $this->db->quote($key) . ', ' . $this->db->quote($value) . ')')->execute();
    }

    /** @return array<string, string> the given state keys (missing ones are left out) */
    public function states(array $keys): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['k', 'v']))
            ->from($this->db->quoteName('#__bettersearch_state'))
            ->whereIn($this->db->quoteName('k'), $keys, ParameterType::STRING);
        $out = [];
        foreach ($this->db->setQuery($query)->loadRowList() ?: [] as [$k, $v]) {
            $out[(string) $k] = (string) $v;
        }

        return $out;
    }

    /**
     * Takes the timestamp $key for this process when at least $interval seconds passed since it was
     * last taken — one atomic UPDATE, so of two parallel requests exactly one gets true.
     */
    public function claim(string $key, int $interval): bool
    {
        $db  = $this->db;
        $now = time();
        $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName('#__bettersearch_state') . ' (' . $db->quoteName('k') . ', ' . $db->quoteName('v')
            . ') VALUES (' . $db->quote($key) . ', ' . $db->quote('0') . ')')->execute();
        $db->setQuery('UPDATE ' . $db->quoteName('#__bettersearch_state') . ' SET ' . $db->quoteName('v') . ' = ' . $db->quote((string) $now)
            . ' WHERE ' . $db->quoteName('k') . ' = ' . $db->quote($key) . ' AND CAST(' . $db->quoteName('v') . ' AS SIGNED) <= ' . ($now - $interval))->execute();

        return $db->getAffectedRows() === 1;
    }

    /**
     * The index version and a stamp of the pages' visibility (published, access, language, dates),
     * refreshed at most every minute: a product unpublished a moment ago changes every cache key.
     *
     * @return array{version: string, vis: string}
     */
    public function versionAndStamp(): array
    {
        $state = $this->states(['version', 'vis', 'vis_at']);
        $vis   = $state['vis'] ?? '';
        if ($vis === '' || time() - (int) ($state['vis_at'] ?? 0) > self::STAMP_INTERVAL) {
            $apps = $this->appIds();
            $db   = $this->db;
            $row  = $apps ? $db->setQuery('SELECT COUNT(*), IFNULL(SUM(CRC32(CONCAT_WS(' . $db->quote('|') . ', id, published, page_access, language, created, end_publishing))), 0)'
                . ' FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE app_id IN (' . implode(',', $apps) . ')')->loadRow() : [0, 0];
            $vis  = substr(md5(implode('|', (array) $row)), 0, 12);
            $this->setState('vis', $vis);
            $this->setState('vis_at', (string) time());
        }

        return ['version' => $state['version'] ?? '0', 'vis' => $vis];
    }

    // ---------------------------------------------------------------- synchronisation

    /**
     * Brings the index up to date: removes items that left Gridbox (or an indexed app), indexes new
     * and changed ones — at most $budget items per call (the rest on the next call).
     *
     * @return array{changed: int, indexed: int, removed: int, remaining: int, total: int, ms: float}
     */
    public function sync(int $budget = 300, bool $force = false): array
    {
        $t0  = hrtime(true);
        $this->ensureColumns();
        $cfg = $this->configSignature();
        if ($force || $this->state('config') !== $cfg) {
            // everything is indexed again (old rows stay searchable until replaced)
            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__bettersearch_items') . ' SET sig = 0')->execute();
            $this->setState('config', $cfg);
        }

        $current = $this->signatures();
        $stored  = [];
        $query   = $this->db->createQuery()
            ->select(['id', 'sig'])
            ->from($this->db->quoteName('#__bettersearch_items'));
        foreach ($this->db->setQuery($query)->loadRowList() ?: [] as [$id, $sig]) {
            $stored[(int) $id] = (int) $sig;
        }

        $removed = array_keys(array_diff_key($stored, $current));
        foreach (array_chunk($removed, 500) as $chunk) {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__bettersearch_items') . ' WHERE id IN (' . implode(',', $chunk) . ')')->execute();
        }

        $changed = [];
        foreach ($current as $id => $sig) {
            if (($stored[$id] ?? null) !== $sig) {
                $changed[] = $id;
            }
        }

        // once a day (and on a forced update) the page layouts are compared by checksum, too: the
        // quick signature relies on Gridbox's save time, which an edit may leave unchanged
        $deep = $force || time() - (int) $this->state('deep_at', '0') > self::DEEP_INTERVAL;
        if ($deep && $this->params->get('index_content', 1)) {
            foreach ($this->layoutChanges(array_diff(array_keys($current), $changed)) as $id) {
                $changed[] = $id;
            }
            $this->setState('deep_at', (string) time());
        }

        $todo = $budget > 0 ? array_slice($changed, 0, $budget) : $changed;
        foreach (array_chunk($todo, 100) as $chunk) {
            $this->indexItems($chunk, $current);
        }

        $this->setState('checked_at', (string) time());
        if (count($todo) === count($changed)) {
            $this->setState('complete_at', (string) time());
        }
        if ($todo || $removed) {
            $this->setState('version', (string) (((int) $this->state('version', '0')) + 1));
        }

        return [
            'changed'   => count($changed),
            'indexed'   => count($todo),
            'removed'   => count($removed),
            'remaining' => count($changed) - count($todo),
            'total'     => count($current),
            'ms'        => round((hrtime(true) - $t0) / 1e6, 1),
        ];
    }

    /** Columns added by updates, in case the update script of the package did not run (Joomla skips it on some errors). */
    private function ensureColumns(): void
    {
        $db = $this->db;
        if (!$db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__bettersearch_items') . ' LIKE ' . $db->quote('t_params'))->loadRow()) {
            $db->setQuery('ALTER TABLE ' . $db->quoteName('#__bettersearch_items') . ' ADD COLUMN ' . $db->quoteName('t_params') . ' text NOT NULL AFTER ' . $db->quoteName('t_body'))->execute();
        }
    }

    /** Clears the index (the next sync indexes everything). */
    public function truncate(): void
    {
        $this->db->setQuery('TRUNCATE TABLE ' . $this->db->quoteName('#__bettersearch_items'))->execute();
        $this->setState('version', (string) (((int) $this->state('version', '0')) + 1));
    }

    /**
     * Current signature of every page of the indexed apps (trashed pages are left out). The page
     * layout (a large blob) is not read here: Gridbox stamps saved_time when the layout is saved;
     * layoutChanges() compares the layouts by checksum once a day.
     *
     * @return array<int, int>
     */
    public function signatures(): array
    {
        $apps = $this->appIds();
        if (!$apps) {
            return [];
        }

        $db     = $this->db;
        $fields = array_keys($this->fields());
        $inApps = ' WHERE page_id IN (SELECT id FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE app_id IN (' . implode(',', $apps) . '))';
        $sql    = 'SELECT p.id, CRC32(CONCAT_WS(' . $db->quote('|') . ', p.title, p.saved_time, p.page_category, p.app_id, p.intro_image,'
            . ' CRC32(IFNULL(p.intro_text, ' . $db->quote('') . ')),'
            . ' CRC32(IFNULL(p.meta_description, ' . $db->quote('') . ')), CRC32(IFNULL(p.meta_keywords, ' . $db->quote('') . ')),'
            . ' IFNULL(d.sku, ' . $db->quote('') . '), IFNULL(d.price, ' . $db->quote('') . '), IFNULL(d.stock, ' . $db->quote('') . '),'
            . ' CRC32(IFNULL(d.variations, ' . $db->quote('') . ')), IFNULL(m.cats, ' . $db->quote('') . '), IFNULL(f.fsig, 0),'
            . ' IFNULL(t.tags, ' . $db->quote('') . '))) AS sig'
            . ' FROM ' . $db->quoteName('#__gridbox_pages', 'p')
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id'
            . ' LEFT JOIN (SELECT page_id, GROUP_CONCAT(category_id ORDER BY category_id) AS cats FROM '
            . $db->quoteName('#__gridbox_category_page_map') . $inApps . ' GROUP BY page_id) AS m ON m.page_id = p.id'
            . ' LEFT JOIN (SELECT page_id, SUM(CRC32(CONCAT(field_id, ' . $db->quote(':') . ', IFNULL(value, ' . $db->quote('') . ')))) AS fsig FROM '
            . $db->quoteName('#__gridbox_page_fields') . $inApps . ($fields ? ' AND field_id IN (' . implode(',', $fields) . ')' : ' AND 1 = 0') . ' GROUP BY page_id) AS f ON f.page_id = p.id'
            . ' LEFT JOIN (SELECT page_id, GROUP_CONCAT(tag_id ORDER BY tag_id) AS tags FROM '
            . $db->quoteName('#__gridbox_tags_map') . $inApps . ' GROUP BY page_id) AS t ON t.page_id = p.id'
            . ' WHERE p.app_id IN (' . implode(',', $apps) . ')'
            . ' AND p.page_category <> ' . $db->quote('trashed');

        $out = [];
        foreach ($db->setQuery($sql)->getIterator() as $row) {
            $out[(int) $row->id] = (int) $row->sig;
        }

        return $out;
    }

    /**
     * Pages whose layout checksum differs from the one stored at indexing time.
     *
     * @param int[] $ids
     *
     * @return int[]
     */
    private function layoutChanges(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $db  = $this->db;
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $sql = 'SELECT p.id FROM ' . $db->quoteName('#__gridbox_pages', 'p') . ' INNER JOIN ' . $db->quoteName('#__bettersearch_items', 'i')
                . ' ON i.id = p.id WHERE p.id IN (' . implode(',', $chunk) . ') AND i.pcrc <> CRC32(IFNULL(p.params, ' . $db->quote('') . '))';
            foreach ($db->setQuery($sql)->loadColumn() ?: [] as $id) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- indexing

    /**
     * @param int[]            $ids
     * @param array<int, int>  $sigs
     */
    private function indexItems(array $ids, array $sigs): void
    {
        if (!$ids) {
            return;
        }
        $db   = $this->db;
        $list = implode(',', array_map('intval', $ids));

        $content = (bool) $this->params->get('index_content', 1);
        $query   = $db->createQuery()
            ->select(['p.id', 'p.title', 'p.app_id', 'p.page_category', 'p.intro_text', 'p.meta_description', 'p.meta_keywords'])
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->where('p.id IN (' . $list . ')');
        if ($content) {
            $query->select(['p.params', 'CRC32(IFNULL(p.params, ' . $db->quote('') . ')) AS pcrc']);
        }
        $pages = $db->setQuery($query)->loadObjectList('id') ?: [];

        $products = [];
        $query    = $db->createQuery()
            ->select(['product_id', 'sku', 'price', 'sale_price', 'stock', 'variations'])
            ->from($db->quoteName('#__gridbox_store_product_data'))
            ->where('product_id IN (' . $list . ')');
        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $products[(int) $row->product_id] = $row;
        }

        $mapped = [];
        $query  = $db->createQuery()
            ->select(['page_id', 'category_id'])
            ->from($db->quoteName('#__gridbox_category_page_map'))
            ->where('page_id IN (' . $list . ')');
        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $mapped[(int) $row->page_id][] = (int) $row->category_id;
        }

        $tags = [];
        if ($this->params->get('index_tags', 1)) {
            $query = $db->createQuery()
                ->select(['m.page_id', 't.title'])
                ->from($db->quoteName('#__gridbox_tags_map', 'm'))
                ->innerJoin($db->quoteName('#__gridbox_tags', 't') . ' ON t.id = m.tag_id')
                ->where('m.page_id IN (' . $list . ')');
            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $tags[(int) $row->page_id][] = (string) $row->title;
            }
        }

        $fieldValues = [];
        $fields      = $this->fields();
        if ($fields) {
            $query = $db->createQuery()
                ->select(['page_id', 'field_id', 'value'])
                ->from($db->quoteName('#__gridbox_page_fields'))
                ->where('page_id IN (' . $list . ')')
                ->where('field_id IN (' . implode(',', array_keys($fields)) . ')');
            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $text = $this->fieldText($fields[(int) $row->field_id] ?? null, (string) $row->value);
                if ($text !== '') {
                    $fieldValues[(int) $row->page_id][] = $text;
                }
            }
        }

        $now  = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach ($ids as $id) {
            $page = $pages[$id] ?? null;
            if (!$page) {
                continue;
            }
            $rows[] = $this->buildRow($page, $products[$id] ?? null, $mapped[$id] ?? [], $tags[$id] ?? [], $fieldValues[$id] ?? [], $sigs[$id] ?? 0, $now);
        }

        $this->store($rows);
    }

    private function buildRow(object $page, ?object $product, array $mapped, array $tags, array $fields, int $sig, string $now): array
    {
        $n = $this->norm;

        // categories: primary + additional, with their parents (a search for "mierniki" finds
        // products of every subcategory of "Mierniki")
        $catIds  = [];
        $primary = (int) $page->page_category;
        foreach (array_unique(array_merge([$primary], $mapped)) as $catId) {
            foreach ($this->categoryPath((int) $catId) as $pathId) {
                $catIds[$pathId] = true;
            }
        }
        $catTitles = [];
        $direct    = array_unique(array_merge([$primary], $mapped));
        foreach (array_keys($catIds) as $catId) {
            $isDirect = in_array($catId, $direct, true);
            if ($isDirect || $this->params->get('index_parent_cats', 1)) {
                $catTitles[] = $this->categories()[$catId]->title ?? '';
            }
        }
        $catText = implode(' ', array_merge($catTitles, $tags));

        // product codes: the product SKU and the codes of its variants
        $skus  = [];
        $price = null;
        $stock = true;
        if ($product) {
            if (trim((string) $product->sku) !== '') {
                $skus[] = (string) $product->sku;
            }
            $prices = [(string) $product->price];
            $stocks = [(string) $product->stock];
            $variations = json_decode((string) $product->variations);
            if (is_object($variations)) {
                foreach ($variations as $variation) {
                    if (!is_object($variation)) {
                        continue;
                    }
                    if ($this->params->get('index_variation_sku', 1) && trim((string) ($variation->sku ?? '')) !== '') {
                        $skus[] = (string) $variation->sku;
                    }
                    $prices[] = (string) ($variation->price ?? '');
                    $stocks[] = (string) ($variation->stock ?? '');
                }
            }
            foreach ($prices as $p) {
                if (is_numeric($p) && (float) $p > 0 && ($price === null || (float) $p < $price)) {
                    $price = (float) $p;
                }
            }
            // empty stock = not tracked = available; with variations their stocks decide (Gridbox sells the chosen variation)
            if (count($stocks) > 1) {
                array_shift($stocks);
            }
            $stock = (bool) array_filter($stocks, fn ($s) => trim($s) === '' || (float) $s > 0);
        }
        $skus = array_values(array_unique($skus));

        $fieldText = implode(' ', $fields);

        // body: intro text, meta data, the text of the page itself
        $body = [];
        if ($this->params->get('index_intro', 1)) {
            $body[] = (string) $page->intro_text;
        }
        if ($this->params->get('index_meta', 1)) {
            $body[] = (string) $page->meta_description;
            $body[] = (string) $page->meta_keywords;
        }
        if (isset($page->params) && $page->params) {
            $body[] = $this->pageText((string) $page->params);
        }
        $limit    = max(1000, (int) $this->params->get('content_limit', 20000));
        $bodyText = mb_substr($this->plain(implode(' ', $body)), 0, $limit);

        $excerpt = $this->plain((string) $page->intro_text);
        if ($excerpt === '') {
            $excerpt = $this->plain((string) $page->meta_description);
        }
        if ($excerpt === '' && isset($page->params)) {
            $excerpt = mb_substr($this->pageText((string) $page->params), 0, 600);
        }

        $title = (string) $page->title;

        return [
            'id'          => (int) $page->id,
            'app_id'      => $this->indexApp((int) $page->app_id),
            'category_id' => $primary,
            'cat_ids'     => $catIds ? ',' . implode(',', array_keys($catIds)) . ',' : '',
            't_title'     => $n->indexTokens($title),
            'c_title'     => mb_substr($n->compact($title), 0, 1000),
            't_sku'       => $n->indexTokens(implode(' ', $skus)),
            'c_sku'       => mb_substr(implode(' ', array_filter(array_map(fn ($s) => $n->compact($s), $skus))), 0, 2000),
            't_fields'    => $n->indexTokens($fieldText),
            'c_fields'    => $n->compact($fieldText),
            't_cats'      => $n->indexTokens($catText),
            'c_cats'      => $n->compact($catText),
            'c_main'      => implode(' ', array_filter([$n->compact($title), implode(' ', array_map(fn ($s) => $n->compact($s), $skus)),
                $n->compact($fieldText), $n->compact($catText)])),
            't_main'      => $n->indexTokens($title . ' ' . implode(' ', $skus) . ' ' . $fieldText . ' ' . $catText),
            't_body'      => $n->indexTokens($bodyText, 4000),
            // technical values of the name, the fields and the description ("1000 V", "IP67", "-20…50 °C")
            't_params'    => Params::index($title . "\n" . $fieldText . "\n" . $bodyText),
            'excerpt'     => mb_substr($excerpt, 0, 600),
            'price'       => $price,
            'in_stock'    => $stock ? 1 : 0,
            'pcrc'        => (int) ($page->pcrc ?? 0),
            'sig'         => $sig,
            'indexed_at'  => $now,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function store(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $db      = $this->db;
        $columns = array_keys($rows[0]);
        $values  = [];
        foreach ($rows as $row) {
            $values[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $db->quote((string) $v)), $row)) . ')';
        }
        // statements of a bounded size: a host may allow only a few MB per packet
        $head  = 'REPLACE INTO ' . $db->quoteName('#__bettersearch_items') . ' (' . implode(',', array_map([$db, 'quoteName'], $columns)) . ') VALUES ';
        $chunk = [];
        $bytes = 0;
        foreach ($values as $value) {
            if ($chunk && $bytes + strlen($value) > self::STATEMENT_BYTES) {
                $db->setQuery($head . implode(',', $chunk))->execute();
                $chunk = [];
                $bytes = 0;
            }
            $chunk[] = $value;
            $bytes  += strlen($value);
        }
        if ($chunk) {
            $db->setQuery($head . implode(',', $chunk))->execute();
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int, object> */
    public function categories(): array
    {
        if ($this->categories === null) {
            $query = $this->db->createQuery()
                ->select(['id', 'title', 'parent', 'app_id'])
                ->from($this->db->quoteName('#__gridbox_categories'));
            $this->categories = [];
            foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $row->id     = (int) $row->id;
                $row->parent = (int) $row->parent;
                $this->categories[$row->id] = $row;
            }
        }

        return $this->categories;
    }

    /** @return int[] the category and its parents */
    private function categoryPath(int $id): array
    {
        $cats  = $this->categories();
        $path  = [];
        $guard = 0;
        while ($id > 0 && isset($cats[$id]) && $guard++ < 50) {
            $path[] = $id;
            $id     = $cats[$id]->parent;
        }

        return $path;
    }

    /** @return array<int, object> */
    private function fields(): array
    {
        if ($this->fields === null) {
            $this->fields = [];
            $ids          = $this->fieldIds();
            if ($ids) {
                $query = $this->db->createQuery()
                    ->select(['id', 'field_type', 'label', 'options'])
                    ->from($this->db->quoteName('#__gridbox_fields'))
                    ->where('id IN (' . implode(',', $ids) . ')');
                foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
                    $this->fields[(int) $row->id] = $row;
                }
            }
        }

        return $this->fields;
    }

    /** Readable text of a field value: option titles for select/radio/checkbox fields, plain text otherwise. */
    private function fieldText(?object $field, string $value): string
    {
        if (!$field || trim($value) === '') {
            return '';
        }

        $options = [];
        $decoded = json_decode((string) $field->options);
        foreach ((is_object($decoded) ? ($decoded->items ?? []) : []) as $item) {
            if (is_object($item)) {
                // select/radio options carry a key, category options an id
                foreach (['key', 'id'] as $k) {
                    if (isset($item->{$k})) {
                        $options[(string) $item->{$k}] = (string) ($item->title ?? '');
                    }
                }
            }
        }

        $json = json_decode($value);
        if ($options) {
            $keys = is_array($json) ? $json : [$value];

            return implode(' ', array_map(fn ($k) => $options[(string) $k] ?? '', $keys));
        }
        if (is_array($json) || is_object($json)) {
            // structured fields (links, files…): their visible texts only
            $texts = [];
            array_walk_recursive($json, function ($v, $k) use (&$texts) {
                if (is_string($v) && in_array((string) $k, ['title', 'label', 'text', 'value'], true)) {
                    $texts[] = $v;
                }
            });

            return $this->plain(implode(' ', $texts));
        }

        return $this->plain($value);
    }

    /** Visible text of a Gridbox page layout. */
    private function pageText(string $html): string
    {
        $html = preg_replace('#<(script|style|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        // block elements end words
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/td|/tr)\b[^>]*>#i', ' $0', $html) ?? '';

        return $this->plain($html);
    }

    private function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
