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
    public const FORMAT = 6;

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
     * Everything that changes how every item is indexed: the settings of the index and the
     * definitions of the indexed fields (their option titles become words). A change marks every
     * item for indexing. Category and tag names are not here (since 1.7.0): they are part of the
     * signature of each page, so renaming or adding a category or a tag reindexes only the pages
     * that have it — before, any new tag of a blog post marked the whole shop.
     *
     * $legacy: the signature of the versions before 1.7.0, to take over an index made by them (migrate()).
     */
    public function configSignature(bool $legacy = false): string
    {
        $keys = ['apps', 'index_fields', 'index_intro', 'index_meta', 'index_content', 'content_limit', 'index_tags',
            'index_parent_cats', 'index_variation_sku', 'index_pages'];
        $cfg  = [self::FORMAT, $this->appIds()];
        foreach ($keys as $key) {
            $cfg[] = $this->params->get($key);
        }

        $db = $this->db;
        if (!$legacy) {
            $fields = [];
            foreach ($this->fields() as $id => $field) {
                $fields[$id] = [(string) $field->field_type, (string) $field->label, (string) $field->options];
            }
            ksort($fields);

            return md5(json_encode([$cfg, 'sig2', $fields]));
        }
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
            // the last column counts the pages live now: a page whose publishing period began or ended
            // since the last look changes the stamp, though none of its saved values did
            $now  = $db->quote((new \DateTime('now', Searcher::siteZone()))->format('Y-m-d H:i:s'));
            $row  = $apps ? $db->setQuery('SELECT COUNT(*), IFNULL(SUM(CRC32(CONCAT_WS(' . $db->quote('|') . ', id, published, page_access, language, created, end_publishing))), 0),'
                . ' IFNULL(SUM(created <= ' . $now . ' AND (end_publishing = ' . $db->quote($db->getNullDate()) . ' OR end_publishing >= ' . $now . ')), 0)'
                . ' FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE app_id IN (' . implode(',', $apps) . ')')->loadRow() : [0, 0];
            // redirects give products old names (Redirects): their change also renews the cached results
            $vis  = substr(md5(implode('|', (array) $row) . '|' . Redirects::signature($db)), 0, 12);
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
     * @return array{changed: int, indexed: int, removed: int, values: int, remaining: int, total: int, ms: float}
     */
    public function sync(int $budget = 300, bool $force = false): array
    {
        $t0  = hrtime(true);
        $this->ensureColumns();
        $this->migrate();
        $cfg = $this->configSignature();
        if ($force || $this->state('config') !== $cfg) {
            // everything is indexed again (old rows stay searchable until replaced)
            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__bettersearch_items') . ' SET sig = 0')->execute();
            $this->setState('config', $cfg);
        }

        $rows    = $this->signatureRows();
        $current = array_map(fn ($row) => $row[0], $rows);
        $stored  = $storedValues = [];
        $query   = $this->db->createQuery()
            ->select(['id', 'sig', 'vsig'])
            ->from($this->db->quoteName('#__bettersearch_items'));
        foreach ($this->db->setQuery($query)->loadRowList() ?: [] as [$id, $sig, $vsig]) {
            $stored[(int) $id]       = (int) $sig;
            $storedValues[(int) $id] = (int) $vsig;
        }

        $removed = array_keys(array_diff_key($stored, $current));
        foreach (array_chunk($removed, 500) as $chunk) {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__bettersearch_items') . ' WHERE id IN (' . implode(',', $chunk) . ')')->execute();
        }

        // what is missing from the index first (e.g. pages just switched on), then pages edited since,
        // and only then the items a settings change marked for indexing again (they stay searchable
        // meanwhile): a full rebuild of thousands of products no longer delays new pages for minutes
        $missing = $edited = $rebuild = $values = [];
        foreach ($current as $id => $sig) {
            $old = $stored[$id] ?? null;
            if ($old === $sig) {
                // the same words, other price or stock: updated below at once
                if ($storedValues[$id] !== $rows[$id][1]) {
                    $values[$id] = $rows[$id][1];
                }
                continue;
            }
            if ($old === null) {
                $missing[] = $id;
            } elseif ($old !== 0) {
                $edited[] = $id;
            } else {
                $rebuild[] = $id;
            }
        }
        $changed = array_merge($missing, $edited, $rebuild);

        // once a day (and on a forced update) the page layouts are compared by checksum, too: the
        // quick signature relies on Gridbox's save time, which an edit may leave unchanged
        $deep   = $force || time() - (int) $this->state('deep_at', '0') > self::DEEP_INTERVAL;
        $layout = [];
        if ($deep && $this->params->get('index_content', 1)) {
            $layout  = $this->layoutChanges(array_diff(array_keys($current), $changed));
            $changed = array_merge($changed, $layout);
            $this->setState('deep_at', (string) time());
        }

        $todo = $budget > 0 ? array_slice($changed, 0, $budget) : $changed;
        foreach (array_chunk($todo, 100) as $chunk) {
            $this->indexItems($chunk, $rows);
        }
        // the layout check runs once a day: the changed layouts the budget left over are marked for
        // indexing again, or the next check would find them only tomorrow
        $deferred = array_diff($layout, $todo);
        foreach (array_chunk($deferred, 500) as $chunk) {
            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__bettersearch_items') . ' SET sig = 0 WHERE id IN (' . implode(',', array_map('intval', $chunk)) . ')')->execute();
        }
        $this->refreshValues(array_diff_key($values, array_flip($todo)));

        // why the rest waits (shown in the Tools tab): the end of the queue, which is worked through
        // in the order new, edited, after a settings change, changed layout
        $left  = count($changed) - count($todo);
        $kinds = [];
        foreach (['layout' => $layout, 'settings' => $rebuild, 'edited' => $edited, 'new' => $missing] as $kind => $list) {
            $kinds[$kind] = min(count($list), $left);
            $left        -= $kinds[$kind];
        }
        $this->setState('pending_kinds', json_encode(array_reverse($kinds, true)));

        $this->setState('checked_at', (string) time());
        // the panel shows these figures instead of computing the signatures again on every opening
        $this->setState('pages', (string) count($current));
        $this->setState('pending', (string) (count($changed) - count($todo)));
        if (count($todo) === count($changed)) {
            $this->setState('complete_at', (string) time());
        }
        if ($todo || $removed || $values) {
            $this->setState('version', (string) (((int) $this->state('version', '0')) + 1));
        }

        return [
            'changed'   => count($changed),
            'indexed'   => count($todo),
            'removed'   => count($removed),
            'values'    => count($values),
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
        if (!$db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__bettersearch_items') . ' LIKE ' . $db->quote('vsig'))->loadRow()) {
            $db->setQuery('ALTER TABLE ' . $db->quoteName('#__bettersearch_items') . ' ADD COLUMN ' . $db->quoteName('vsig') . ' bigint(20) NOT NULL DEFAULT 0 AFTER ' . $db->quoteName('sig'))->execute();
        }
    }

    /** Clears the index (the next sync indexes everything). */
    public function truncate(): void
    {
        $this->db->setQuery('TRUNCATE TABLE ' . $this->db->quoteName('#__bettersearch_items'))->execute();
        $this->setState('pending', (string) $this->state('pages', '0'));
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
        return array_map(fn ($row) => $row[0], $this->signatureRows());
    }

    /**
     * Two signatures per page: [0] of everything that gives words to the index (title, texts, codes,
     * categories with their names and parents, tags with their names, fields), [1] of the values
     * only (price, stock, the variations). A page whose values alone changed — a sale lowered the
     * stock, a price list was imported — gets them updated at once (refreshValues()), without
     * indexing its text again and without waiting in the queue.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function signatureRows(): array
    {
        $apps = $this->appIds();
        if (!$apps) {
            return [];
        }
        $db   = $this->db;
        $cats = $this->categories();
        $tags = $this->tagTitles();
        // a category: its id, name and parents (a renamed or moved category changes its pages only)
        $catSig = [];
        $catOf  = function (int $id) use (&$catSig, $cats): string {
            if (!isset($catSig[$id])) {
                $catSig[$id] = implode('/', array_map(fn ($c) => $c . ':' . ($cats[$c]->title ?? ''), $this->categoryPath($id)));
            }

            return $catSig[$id];
        };

        // the lists of categories and tags are concatenated in SQL: 1024 bytes (the usual default) would cut a long list short
        try {
            $db->setQuery('SET SESSION group_concat_max_len = 1048576')->execute();
        } catch (\Throwable $e) {
        }

        $out = [];
        foreach ($db->setQuery($this->signatureSql(false))->getIterator() as $row) {
            $ids = array_unique(array_merge([(int) $row->page_category], array_map('intval', array_filter(explode(',', (string) $row->cats)))));
            $catText = implode(',', array_map($catOf, $ids));
            $tagText = implode(',', array_map(fn ($t) => $tags[(int) $t] ?? '', array_filter(explode(',', (string) $row->tags))));
            // 0 marks an item for indexing again: never a signature
            $out[(int) $row->id] = [crc32($row->sig . '|' . $catText . '|' . $tagText) ?: 1, (int) $row->vsig ?: 1];
        }

        return $out;
    }

    /**
     * The query of the signatures. $legacy: the one of the versions before 1.7.0 (one signature with the
     * values in it, category and tag ids without their names), to take over their index once.
     */
    private function signatureSql(bool $legacy): string
    {
        $db     = $this->db;
        $apps   = $this->appIds();
        $fields = array_keys($this->fields());
        $e      = $db->quote('');
        $inApps = ' WHERE page_id IN (SELECT id FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE app_id IN (' . implode(',', $apps) . '))';
        if ($legacy) {
            $select = 'p.id, CRC32(CONCAT_WS(' . $db->quote('|') . ', p.title, p.saved_time, p.page_category, p.app_id, p.intro_image,'
                . ' CRC32(IFNULL(p.intro_text, ' . $e . ')),'
                . ' CRC32(IFNULL(p.meta_description, ' . $e . ')), CRC32(IFNULL(p.meta_keywords, ' . $e . ')),'
                . ' IFNULL(d.sku, ' . $e . '), IFNULL(d.price, ' . $e . '), IFNULL(d.stock, ' . $e . '),'
                . ' CRC32(IFNULL(d.variations, ' . $e . ')), IFNULL(m.cats, ' . $e . '), IFNULL(f.fsig, 0),'
                . ' IFNULL(t.tags, ' . $e . '))) AS sig';
        } else {
            // the codes of the variants are words; their prices and stocks are values
            $variantSkus = 'IF(JSON_VALID(d.variations), IFNULL(JSON_EXTRACT(d.variations, ' . $db->quote('$.*.sku') . '), ' . $e . '), IFNULL(d.variations, ' . $e . '))';
            $select = 'p.id, p.page_category, m.cats, t.tags,'
                . ' CRC32(CONCAT_WS(' . $db->quote('|') . ', p.title, p.saved_time, p.page_category, p.app_id, p.intro_image,'
                . ' CRC32(IFNULL(p.intro_text, ' . $e . ')),'
                . ' CRC32(IFNULL(p.meta_description, ' . $e . ')), CRC32(IFNULL(p.meta_keywords, ' . $e . ')),'
                . ' IFNULL(d.sku, ' . $e . '), CRC32(' . $variantSkus . '), IFNULL(f.fsig, 0))) AS sig,'
                . ' CRC32(CONCAT_WS(' . $db->quote('|') . ', IFNULL(d.price, ' . $e . '), IFNULL(d.stock, ' . $e . '),'
                . ' CRC32(IFNULL(d.variations, ' . $e . ')))) AS vsig';
        }

        return 'SELECT ' . $select
            . ' FROM ' . $db->quoteName('#__gridbox_pages', 'p')
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id'
            . ' LEFT JOIN (SELECT page_id, GROUP_CONCAT(category_id ORDER BY category_id) AS cats FROM '
            . $db->quoteName('#__gridbox_category_page_map') . $inApps . ' GROUP BY page_id) AS m ON m.page_id = p.id'
            . ' LEFT JOIN (SELECT page_id, SUM(CRC32(CONCAT(field_id, ' . $db->quote(':') . ', IFNULL(value, ' . $e . ')))) AS fsig FROM '
            . $db->quoteName('#__gridbox_page_fields') . $inApps . ($fields ? ' AND field_id IN (' . implode(',', $fields) . ')' : ' AND 1 = 0') . ' GROUP BY page_id) AS f ON f.page_id = p.id'
            . ' LEFT JOIN (SELECT page_id, GROUP_CONCAT(tag_id ORDER BY tag_id) AS tags FROM '
            . $db->quoteName('#__gridbox_tags_map') . $inApps . ' GROUP BY page_id) AS t ON t.page_id = p.id'
            . ' WHERE p.app_id IN (' . implode(',', $apps) . ')'
            . ' AND p.page_category <> ' . $db->quote('trashed');
    }

    /**
     * Once after the update to 1.7.0: the pages that were up to date with the signatures of the
     * older version get the new ones without being indexed again (the whole shop would wait in
     * the queue for hours otherwise). Pages that were waiting stay waiting.
     */
    public function migrate(): void
    {
        if ($this->state('sig_format') === '2') {
            return;
        }
        $db = $this->db;
        if ($this->appIds() && $this->state('config') === $this->configSignature(true)) {
            $legacy = [];
            foreach ($db->setQuery($this->signatureSql(true))->getIterator() as $row) {
                $legacy[(int) $row->id] = (int) $row->sig;
            }
            $current = $this->signatureRows();
            $adopt   = [];
            foreach ($db->setQuery('SELECT id, sig FROM ' . $db->quoteName('#__bettersearch_items'))->loadRowList() ?: [] as [$id, $sig]) {
                $id = (int) $id;
                if ((int) $sig !== 0 && (int) $sig === ($legacy[$id] ?? null) && isset($current[$id])) {
                    $adopt[$id] = $current[$id];
                }
            }
            foreach (array_chunk($adopt, 500, true) as $chunk) {
                $this->updateColumns($chunk, ['sig' => fn ($r) => $r[0], 'vsig' => fn ($r) => $r[1]]);
            }
            $this->setState('config', $this->configSignature());
        }
        $this->setState('sig_format', '2');
    }

    /**
     * One UPDATE for many items: $values maps an item id to its data, $columns a column to the
     * function that gives its value from that data.
     *
     * @param array<int, mixed>                $values
     * @param array<string, callable>          $columns
     */
    private function updateColumns(array $values, array $columns): void
    {
        if (!$values) {
            return;
        }
        $db  = $this->db;
        $set = [];
        foreach ($columns as $column => $value) {
            $case = [];
            foreach ($values as $id => $data) {
                $v      = $value($data);
                $case[] = 'WHEN ' . (int) $id . ' THEN ' . ($v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $db->quote((string) $v)));
            }
            $set[] = $db->quoteName($column) . ' = CASE ' . $db->quoteName('id') . ' ' . implode(' ', $case) . ' END';
        }
        $db->setQuery('UPDATE ' . $db->quoteName('#__bettersearch_items') . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($values))) . ')')->execute();
    }

    /**
     * Price and stock of items whose words did not change: read from Gridbox and written into the
     * index in a few UPDATEs.
     *
     * @param array<int, int> $vsigs item id => value signature
     */
    private function refreshValues(array $vsigs): void
    {
        foreach (array_chunk($vsigs, 500, true) as $chunk) {
            $query = $this->db->createQuery()
                ->select(['product_id', 'sku', 'price', 'stock', 'variations'])
                ->from($this->db->quoteName('#__gridbox_store_product_data'))
                ->where('product_id IN (' . implode(',', array_map('intval', array_keys($chunk))) . ')');
            $products = [];
            foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $products[(int) $row->product_id] = $row;
            }
            $values = [];
            foreach ($chunk as $id => $vsig) {
                $values[$id] = $this->productValues($products[$id] ?? null) + ['vsig' => $vsig];
            }
            $this->updateColumns($values, [
                'price'    => fn ($v) => $v['price'],
                'in_stock' => fn ($v) => $v['stock'] ? 1 : 0,
                'vsig'     => fn ($v) => $v['vsig'],
            ]);
        }
    }

    /** @return array<int, string> tag id => name */
    private function tagTitles(): array
    {
        $out = [];
        try {
            $query = $this->db->createQuery()->select(['id', 'title'])->from($this->db->quoteName('#__gridbox_tags'));
            foreach ($this->db->setQuery($query)->loadRowList() ?: [] as [$id, $title]) {
                $out[(int) $id] = (string) $title;
            }
        } catch (\Throwable $e) {
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
     * @param int[]                                $ids
     * @param array<int, array{0: int, 1: int}>    $sigs item id => [signature, value signature]
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
            $rows[] = $this->buildRow($page, $products[$id] ?? null, $mapped[$id] ?? [], $tags[$id] ?? [], $fieldValues[$id] ?? [], $sigs[$id] ?? [0, 0], $now);
        }

        $this->store($rows);
    }

    /** @param array{0: int, 1: int} $sig */
    private function buildRow(object $page, ?object $product, array $mapped, array $tags, array $fields, array $sig, string $now): array
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
        ['skus' => $skus, 'price' => $price, 'stock' => $stock] = $this->productValues($product);

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
            'sig'         => $sig[0],
            'vsig'        => $sig[1],
            'indexed_at'  => $now,
        ];
    }

    /**
     * Product codes (the product and its variants), the lowest price above zero and whether
     * anything of the product is in stock (empty stock = not tracked = available).
     *
     * @return array{skus: string[], price: ?float, stock: bool}
     */
    private function productValues(?object $product): array
    {
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

        return ['skus' => array_values(array_unique($skus)), 'price' => $price, 'stock' => $stock];
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
        // not strip_tags(): it would cut the text at a "<" in "Przewód <50 V"
        $text = html_entity_decode(Normalizer::stripTags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
