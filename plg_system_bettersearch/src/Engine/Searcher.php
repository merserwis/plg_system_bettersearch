<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Runs a query against the index: candidates from SQL (every query group must be found), ranking
 * in PHP (Scorer), then the administrator's rules — pinned and hidden products, boosts, excluded
 * categories and products — and the fallbacks when nothing is found (model code typed in parts,
 * typo correction, partial matches).
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Searcher
{
    public const SORTS = ['relevance', 'title', 'title_desc', 'price', 'price_desc', 'newest', 'popular'];

    private DatabaseInterface $db;

    private Registry $params;

    private Normalizer $norm;

    private Scorer $scorer;

    /** @var int[] */
    private array $levels;

    private string $language;

    /** @var array<string, string[]>|null compact phrase => compact alternatives */
    private ?array $synonyms = null;

    /** @var int[]|null */
    private ?array $subscriptionHidden = null;

    /** The index table has its full-text index (null = not checked yet). */
    private ?bool $fulltext = null;

    /** @param int[] $levels view levels of the visitor */
    public function __construct(DatabaseInterface $db, Registry $params, Normalizer $norm, array $levels, string $language)
    {
        $this->db       = $db;
        $this->params   = $params;
        $this->norm     = $norm;
        $this->levels   = array_map('intval', $levels) ?: [1];
        $this->language = $language;
        $this->scorer   = new Scorer([
            'title'  => (float) $params->get('w_title', 10),
            'sku'    => (float) $params->get('w_sku', 12),
            'fields' => (float) $params->get('w_fields', 4),
            'cats'   => (float) $params->get('w_cats', 3),
            'body'   => (float) $params->get('w_body', 1),
        ], (float) $params->get('w_digits', 1.5));
    }

    /**
     * @param int[] $apps only these apps (empty = all indexed)
     *
     * @return array{query: string, mode: string, corrected: string, total: int,
     *               items: array<int, array{id: int, app_id: int, score: float, pinned: bool, reasons: string[]}>,
     *               groups: string[], ms: float}
     */
    public function search(string $query, string $sort = 'relevance', array $apps = [], bool $explain = false): array
    {
        $t0     = hrtime(true);
        $query  = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        $result = ['query' => $query, 'mode' => 'none', 'corrected' => '', 'total' => 0, 'items' => [], 'groups' => [], 'ms' => 0.0];
        $phrase = $this->norm->compact($query);

        if (mb_strlen($phrase) < max(1, (int) $this->params->get('min_chars', 2))) {
            return $result;
        }

        $rules  = $this->matchingRules($phrase);
        $hidden = [];
        foreach ($rules as $rule) {
            if ($rule['action'] === 'hide') {
                $hidden = array_merge($hidden, $rule['products']);
            }
        }

        // 1. as typed, model codes joined ("mi 3155" = "mi3155")
        $mode   = 'exact';
        $groups = $this->prepare($query, true);
        $items  = $this->run($groups, $phrase, $apps, $explain, false);

        // 2. the parts of a joined code as separate words
        if (!$items) {
            $plain = $this->prepare($query, false);
            if (array_column($plain, 'term') !== array_column($groups, 'term')) {
                $groups = $plain;
                $items  = $this->run($groups, $phrase, $apps, $explain, false);
                $mode   = 'split';
            }
        }

        // 3. typos: words the index does not know, replaced by the closest known word
        $corrected = '';
        if (!$items && $this->params->get('typo_tolerance', 1)) {
            $fix = $this->correct($query);
            if ($fix !== '' && $fix !== $this->norm->fold($query)) {
                $groups    = $this->prepare($fix, true);
                $items     = $this->run($groups, $this->norm->compact($fix), $apps, $explain, false);
                $mode      = 'typo';
                $corrected = $fix;
            }
        }

        // 4. not every word found: the items with the most words
        if (!$items && $this->params->get('partial_matches', 1) && count($groups) > 1) {
            $items = $this->run($groups, $phrase, $apps, $explain, true);
            $mode  = 'partial';
        }

        // pinned products of matching rules go first, in the order set by the administrator
        $pinned = [];
        foreach ($rules as $rule) {
            if ($rule['action'] === 'pin') {
                foreach ($rule['products'] as $id) {
                    $pinned[$id] = true;
                }
            }
        }
        if ($pinned) {
            $visible = $this->visibleIds(array_keys($pinned), $apps);
            $order   = 0;
            foreach (array_keys($pinned) as $id) {
                if (!isset($visible[$id])) {
                    continue;
                }
                $items[$id] = array_merge($visible[$id], ['score' => 1e6 - $order++, 'pinned' => true,
                    'reasons' => $explain ? ['pinned by rule'] : []]);
            }
        }
        foreach ($hidden as $id) {
            unset($items[$id]);
        }

        $items = $this->order(array_values($items), $sort);

        $result['mode']      = $items ? $mode : 'none';
        $result['corrected'] = $corrected;
        $result['total']     = count($items);
        $result['items']     = $items;
        $result['groups']    = array_column($groups, 'term');
        $result['ms']        = round((hrtime(true) - $t0) / 1e6, 1);

        return $result;
    }

    // ---------------------------------------------------------------- query preparation

    /**
     * Query groups with their alternatives (synonyms, light stem).
     *
     * @return array<int, array{term: string, digits: bool, parts: string[], alts: array<int, array{c: string, kind: string}>}>
     */
    public function prepare(string $query, bool $merge): array
    {
        $groups   = $this->norm->queryGroups($query, $merge);
        $synonyms = $this->synonyms();
        $stem     = (bool) $this->params->get('stemming', 1);

        foreach ($groups as &$group) {
            $alts = [['c' => $group['term'], 'kind' => 'term']];
            foreach ($synonyms[$group['term']] ?? [] as $syn) {
                $alts[] = ['c' => $syn, 'kind' => 'syn'];
            }
            if ($stem) {
                $s = $this->norm->stem($group['term']);
                if ($s !== $group['term']) {
                    $alts[] = ['c' => $s, 'kind' => 'stem'];
                }
            }
            $group['alts'] = $alts;
        }
        unset($group);

        // a short word after another one may be its ending written apart: "eurotest xd" = "EurotestXD"
        foreach ($groups as $i => $group) {
            if ($i > 0 && strlen($group['term']) <= 2 && !$group['digits']) {
                $groups[$i]['alts'][] = ['c' => $groups[$i - 1]['term'] . $group['term'], 'kind' => 'join'];
            }
        }

        // multi-word synonyms ("miernik uniwersalny" = "multimetr"): the words become one group
        if ($synonyms && count($groups) > 1) {
            $groups = $this->phraseSynonyms($groups, $synonyms);
        }

        return $groups;
    }

    /** @return array<string, string[]> */
    private function synonyms(): array
    {
        if ($this->synonyms !== null) {
            return $this->synonyms;
        }
        $this->synonyms = [];
        foreach (preg_split('/\R/', (string) $this->params->get('synonyms', '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // "a, b, c" = all equal; "a => b, c" = a also finds b and c (one way)
            $oneWay = str_contains($line, '=>');
            [$left, $right] = $oneWay ? array_map('trim', explode('=>', $line, 2)) : [$line, $line];
            $from = array_values(array_filter(array_map(fn ($w) => $this->norm->compact($w), explode(',', $left))));
            $to   = array_values(array_filter(array_map(fn ($w) => $this->norm->compact($w), explode(',', $right))));
            foreach ($from as $f) {
                foreach ($to as $t) {
                    if ($t !== $f) {
                        $this->synonyms[$f][] = $t;
                    }
                }
            }
        }
        foreach ($this->synonyms as &$list) {
            $list = array_values(array_unique($list));
        }

        return $this->synonyms;
    }

    private function phraseSynonyms(array $groups, array $synonyms): array
    {
        $n = count($groups);
        for ($len = min(4, $n); $len >= 2; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $slice  = array_slice($groups, $i, $len);
                $joined = implode('', array_column($slice, 'term'));
                if (!isset($synonyms[$joined])) {
                    continue;
                }
                $alts = [['c' => $joined, 'kind' => 'term']];
                foreach ($synonyms[$joined] as $syn) {
                    $alts[] = ['c' => $syn, 'kind' => 'syn'];
                }
                $group = ['term' => $joined, 'digits' => (bool) preg_match('/[0-9]/', $joined),
                    'parts' => array_merge(...array_column($slice, 'parts')), 'alts' => $alts];
                array_splice($groups, $i, $len, [$group]);

                return $this->phraseSynonyms($groups, $synonyms);
            }
        }

        return $groups;
    }

    // ---------------------------------------------------------------- candidates and ranking

    /**
     * @return array<int, array<string, mixed>> scored items by id
     */
    private function run(array $groups, string $phrase, array $apps, bool $explain, bool $partial): array
    {
        if (!$groups) {
            return [];
        }
        $db   = $this->db;
        $body = (bool) $this->params->get('search_body', 1);

        $select = ['i.id', 'i.app_id', 'i.t_title', 'i.c_title', 'i.t_sku', 'i.c_sku', 'i.t_fields', 'i.c_fields', 'i.t_cats', 'i.c_cats',
            'i.price', 'i.in_stock', 'i.cat_ids', 'i.category_id', 'p.title', 'p.hits', 'p.created'];
        $conds  = [];
        $ft     = $this->fulltext();
        foreach ($groups as $gi => $group) {
            $or     = [];
            $bodyOr = [];
            $words  = [];
            foreach ($group['alts'] as $alt) {
                $a    = $alt['c'];
                $like = $db->quote('%' . $db->escape($a, true) . '%', false);
                $word = $db->quote('% ' . $db->escape($a, true) . '%', false);
                if (strlen($a) === 1) {
                    // a single letter or digit: only as a whole word
                    $or[] = 'i.t_main LIKE ' . $db->quote('% ' . $db->escape($a, true) . ' %', false);
                } elseif ($alt['kind'] === 'stem' || (strlen($a) < 3 && !preg_match('/[0-9]/', $a))) {
                    // short letters ("mi") or a stem: only at the start of a word
                    $or[] = 'i.t_main LIKE ' . $word;
                } else {
                    $or[] = 'i.c_main LIKE ' . $like;
                }
                if ($body && strlen($a) > 1) {
                    // descriptions: words starting with the alternative (full-text index when there is one)
                    if ($ft && strlen($a) >= 3) {
                        $words[] = $a . '*';
                    } else {
                        $bodyOr[] = 'i.t_body LIKE ' . $word;
                    }
                }
            }
            if ($words) {
                $bodyOr[] = 'MATCH(i.t_body) AGAINST(' . $db->quote(implode(' ', array_unique($words))) . ' IN BOOLEAN MODE)';
            }
            if ($bodyOr) {
                $select[] = '(' . implode(' OR ', $bodyOr) . ') AS b' . $gi;
                $or       = array_merge($or, $bodyOr);
            }
            $conds[] = '(' . implode(' OR ', $or) . ')';
        }

        $query = $db->createQuery()
            ->select($select)
            ->from($db->quoteName('#__bettersearch_items', 'i'))
            ->innerJoin($db->quoteName('#__gridbox_pages', 'p') . ' ON p.id = i.id')
            ->where($partial ? '(' . implode(' OR ', $conds) . ')' : implode(' AND ', $conds));
        $this->visibility($query, $apps);
        $query->setLimit(max(100, min(20000, (int) $this->params->get('max_candidates', 3000))));

        try {
            $rows = $db->setQuery($query)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            if (!$ft) {
                throw $e;
            }
            // the full-text index is missing or unusable on this server: search without it
            $this->fulltext = false;

            return $this->run($groups, $phrase, $apps, $explain, $partial);
        }
        if (!$rows) {
            return [];
        }

        $productBoost  = $this->idMap((array) $this->params->get('product_boosts', []), 'product', 'boost');
        $categoryBoost = $this->idMap((array) $this->params->get('category_boosts', []), 'category', 'boost');
        $popularity    = (float) $this->params->get('popularity_boost', 1);
        $outOfStock    = (string) $this->params->get('out_of_stock', 'none');

        $items = [];
        foreach ($rows as $row) {
            $s = $this->scorer->score($groups, $row, $phrase, $explain);
            if ($s['matched'] === 0) {
                continue;
            }
            $score   = $s['score'];
            $reasons = $s['reasons'];

            if ($partial) {
                // a partial match ranks by how many words it has, then by relevance
                $score += 100 * $s['matched'];
            }
            $boost = (float) ($productBoost[(int) $row->id] ?? 0);
            foreach (array_filter(explode(',', (string) $row->cat_ids)) as $catId) {
                $boost += (float) ($categoryBoost[(int) $catId] ?? 0);
            }
            if ($boost != 0.0) {
                $score += $boost;
                if ($explain) {
                    $reasons[] = sprintf('boost %+.1f', $boost);
                }
            }
            if ($popularity > 0 && (int) $row->hits > 0) {
                $pop    = $popularity * log10(1 + (int) $row->hits);
                $score += $pop;
                if ($explain) {
                    $reasons[] = sprintf('views +%.1f', $pop);
                }
            }
            if (!(int) $row->in_stock && $outOfStock === 'end') {
                $score -= 1000;
                if ($explain) {
                    $reasons[] = 'out of stock: last';
                }
            }

            $items[(int) $row->id] = $this->item($row, $score, $s['score'], $reasons) + ['matched' => $s['matched']];
        }

        // drop the weak tail: matches far below the best one (e.g. a word only in a long description)
        $min = max(0, min(90, (int) $this->params->get('min_relevance', 15)));
        if ($partial && $items) {
            // partial matches: at least half of the words, and a stricter cut
            $need  = (int) ceil(count($groups) / 2);
            $items = array_filter($items, fn ($i) => $i['matched'] >= $need);
            $min   = max($min, 30);
        }
        if ($min > 0 && $items) {
            $best  = max(array_column($items, 'base'));
            $limit = $best * $min / 100;
            $items = array_filter($items, fn ($i) => $i['base'] >= $limit);
        }

        return $items;
    }

    /** Published, live, visible pages of the indexed apps, without excluded categories and products. */
    private function visibility($query, array $apps): void
    {
        $db   = $this->db;
        $now  = $db->quote(gmdate('Y-m-d H:i:s'));
        $null = $db->quote($db->getNullDate());

        $query->where('p.published = 1')
            ->where('p.created <= ' . $now)
            ->where('(p.end_publishing = ' . $null . ' OR p.end_publishing >= ' . $now . ')')
            ->where('p.language IN (' . $db->quote($this->language) . ', ' . $db->quote('*') . ')')
            ->where('p.page_access IN (' . implode(',', $this->levels) . ')')
            ->where('p.page_category <> ' . $db->quote('trashed'));

        $apps = array_values(array_filter(array_map('intval', $apps)));
        if ($apps) {
            $query->where('i.app_id IN (' . implode(',', $apps) . ')');
        }

        $excluded = array_merge($this->ids($this->params->get('exclude_products', [])), $this->subscriptionHidden());
        if ($excluded) {
            $query->where('i.id NOT IN (' . implode(',', array_unique($excluded)) . ')');
        }

        $cats = $this->ids($this->params->get('exclude_categories', []));
        if ($cats) {
            // cat_ids holds the categories of the product with their parents: excluding a category
            // also excludes its subcategories unless only the category itself is excluded
            $column = $this->params->get('exclude_children', 1) ? 'i.cat_ids' : 'CONCAT(' . $db->quote(',') . ', i.category_id, ' . $db->quote(',') . ')';
            foreach ($cats as $catId) {
                $query->where($column . ' NOT LIKE ' . $db->quote('%,' . $catId . ',%'));
            }
        }

        if ($this->params->get('out_of_stock', 'none') === 'hide') {
            $query->where('i.in_stock = 1');
        }
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, int> visible ids => app id
     */
    private function visibleIds(array $ids, array $apps): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $query = $this->db->createQuery()
            ->select(['i.id', 'i.app_id', 'i.category_id', 'i.cat_ids', 'i.price', 'i.in_stock', 'p.title', 'p.hits', 'p.created'])
            ->from($this->db->quoteName('#__bettersearch_items', 'i'))
            ->innerJoin($this->db->quoteName('#__gridbox_pages', 'p') . ' ON p.id = i.id')
            ->where('i.id IN (' . implode(',', $ids) . ')');
        $this->visibility($query, $apps);

        $out = [];
        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $out[(int) $row->id] = $this->item($row, 0.0, 0.0, []);
        }

        return $out;
    }

    /** Result entry of an index row. */
    private function item(object $row, float $score, float $base, array $reasons): array
    {
        return [
            'id'          => (int) $row->id,
            'app_id'      => (int) $row->app_id,
            'category_id' => (int) $row->category_id,
            'cats'        => array_map('intval', array_filter(explode(',', (string) $row->cat_ids))),
            'score'       => round($score, 3),
            'base'        => $base,
            'pinned'      => false,
            'reasons'     => $reasons,
            'price'       => $row->price === null ? null : (float) $row->price,
            'title'       => (string) $row->title,
            'created'     => (string) $row->created,
            'hits'        => (int) $row->hits,
            'in_stock'    => (int) $row->in_stock,
        ];
    }

    private function order(array $items, string $sort): array
    {
        $title = fn ($a, $b) => strcoll(mb_strtolower($a['title']), mb_strtolower($b['title']));
        $rel   = fn ($a, $b) => $b['score'] <=> $a['score'] ?: $title($a, $b);
        $pin   = fn ($a, $b) => $b['pinned'] <=> $a['pinned'];
        // items without a price go last in price sorting
        $price = fn ($a, $b, $dir) => ($a['price'] === null) <=> ($b['price'] === null) ?: $dir * ($a['price'] <=> $b['price']);

        usort($items, match ($sort) {
            'title'      => fn ($a, $b) => $pin($a, $b) ?: $title($a, $b),
            'title_desc' => fn ($a, $b) => $pin($a, $b) ?: $title($b, $a),
            'price'      => fn ($a, $b) => $pin($a, $b) ?: $price($a, $b, 1) ?: $rel($a, $b),
            'price_desc' => fn ($a, $b) => $pin($a, $b) ?: $price($a, $b, -1) ?: $rel($a, $b),
            'newest'     => fn ($a, $b) => $pin($a, $b) ?: strcmp($b['created'], $a['created']) ?: $rel($a, $b),
            'popular'    => fn ($a, $b) => $pin($a, $b) ?: $b['hits'] <=> $a['hits'] ?: $rel($a, $b),
            default      => $rel,
        });

        return $items;
    }

    // ---------------------------------------------------------------- rules

    /**
     * Manual rules whose phrases match the query (compact form).
     *
     * @return array<int, array{action: string, products: int[]}>
     */
    public function matchingRules(string $phrase): array
    {
        $out = [];
        foreach ((array) $this->params->get('rules', []) as $rule) {
            $rule = (array) $rule;
            if (isset($rule['enabled']) && !(int) $rule['enabled']) {
                continue;
            }
            $products = $this->ids($rule['products'] ?? []);
            if (!$products) {
                continue;
            }
            $match = (string) ($rule['match'] ?? 'exact');
            foreach (explode(',', (string) ($rule['queries'] ?? '')) as $q) {
                $c = $this->norm->compact($q);
                if ($c === '') {
                    continue;
                }
                $hit = match ($match) {
                    'contains' => str_contains($phrase, $c),
                    'starts'   => str_starts_with($phrase, $c),
                    default    => $phrase === $c,
                };
                if ($hit) {
                    $out[] = ['action' => ($rule['action'] ?? 'pin') === 'hide' ? 'hide' : 'pin', 'products' => $products];
                    break;
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- typo correction

    /**
     * The query with unknown words replaced by the closest indexed word, or by the start of one
     * ("eurotset" -> "eurotest", the start of "eurotestxd"); '' when there is nothing to correct.
     */
    public function correct(string $query): string
    {
        $vocab = $this->vocabulary();
        if (!$vocab) {
            return '';
        }
        $out     = [];
        $changed = false;
        foreach ($this->norm->tokens($query) as $token) {
            $len = strlen($token);
            if (isset($vocab[$token]) || $len < 4 || preg_match('/^[0-9]+$/', $token)) {
                $out[] = $token;
                continue;
            }
            $max  = $len >= 8 ? 2 : 1;
            $best = null;
            $rank = [PHP_INT_MAX, 1, 0];
            foreach ($vocab as $word => $df) {
                $word = (string) $word;
                $wlen = strlen($word);
                if ($wlen + $max < $len) {
                    continue;
                }
                // the whole word, then the start of a longer word of the query's length
                $candidates = abs($wlen - $len) <= $max ? [[$word, 0]] : [];
                if ($wlen > $len) {
                    $candidates[] = [substr($word, 0, $len), 1];
                }
                foreach ($candidates as [$cand, $isPrefix]) {
                    $d = $this->distance($token, $cand);
                    if ($d > $max) {
                        continue;
                    }
                    $r = [$d, $isPrefix, -$df];
                    if ($r < $rank) {
                        $rank = $r;
                        $best = $cand;
                    }
                }
            }
            if ($best !== null && $best !== $token) {
                $out[]   = $best;
                $changed = true;
            } else {
                $out[] = $token;
            }
        }

        return $changed ? implode(' ', $out) : '';
    }

    /** Levenshtein distance where swapping two neighbouring letters counts as one edit. */
    private function distance(string $a, string $b): int
    {
        $d = levenshtein($a, $b);
        if ($d === 2 && strlen($a) === strlen($b)) {
            for ($i = 0, $n = strlen($a) - 1; $i < $n; $i++) {
                if ($a[$i] !== $b[$i]) {
                    return $a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i] && substr($a, $i + 2) === substr($b, $i + 2) ? 1 : 2;
                }
            }
        }

        return $d;
    }

    /** @var array<string, int>|null */
    private ?array $vocab = null;

    /** @return array<string, int> words of titles, codes, categories and fields with their frequency */
    private function vocabulary(): array
    {
        if ($this->vocab !== null) {
            return $this->vocab;
        }
        $this->vocab = [];
        $query       = $this->db->createQuery()
            ->select('t_main')
            ->from($this->db->quoteName('#__bettersearch_items'));
        foreach ($this->db->setQuery($query)->loadColumn() ?: [] as $tokens) {
            foreach (explode(' ', trim((string) $tokens)) as $t) {
                if (strlen($t) >= 3) {
                    $this->vocab[$t] = ($this->vocab[$t] ?? 0) + 1;
                }
            }
        }

        return $this->vocab;
    }

    /** Lets the caller provide a cached vocabulary. */
    public function setVocabulary(array $vocab): void
    {
        $this->vocab = $vocab;
    }

    public function exportVocabulary(): array
    {
        return $this->vocabulary();
    }

    // ---------------------------------------------------------------- categories

    /**
     * Categories whose name matches every query group (for the live search "Categories" section).
     *
     * @param array<int, object> $categories visible categories (id, title, app_id)
     *
     * @return array<int, array{id: int, score: float}>
     */
    public function matchCategories(string $query, array $categories, int $limit): array
    {
        $groups = $this->prepare($query, true);
        if (!$groups || $limit <= 0) {
            return [];
        }
        $phrase = $this->norm->compact($query);
        $out    = [];
        foreach ($categories as $cat) {
            $tokens  = $this->norm->indexTokens((string) $cat->title);
            $compact = $this->norm->compact((string) $cat->title);
            $score   = 0.0;
            foreach ($groups as $group) {
                $best = 0.0;
                foreach ($group['alts'] as $alt) {
                    $best = max($best, $this->scorer->match($alt['c'], $tokens, $compact, $alt['kind'] === 'stem'));
                }
                if ($best <= 0) {
                    continue 2;
                }
                $score += $best;
            }
            if ($compact === $phrase) {
                $score += 2;
            }
            $out[] = ['id' => (int) $cat->id, 'score' => $score - strlen($compact) / 1000];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $limit);
    }

    // ---------------------------------------------------------------- helpers

    /** @return int[] */
    private function ids($value): array
    {
        // "1,2", [1, 2] or ["1,2"] (a multiple field saves its one comma-separated value as a list)
        $flat = [];
        if (is_array($value) || is_object($value)) {
            $value = json_decode(json_encode($value), true);
            array_walk_recursive($value, function ($v) use (&$flat) {
                $flat[] = (string) $v;
            });
        } else {
            $flat[] = (string) $value;
        }

        return array_values(array_unique(array_filter(array_map('intval', explode(',', implode(',', $flat))))));
    }

    /** @return array<int, float> */
    private function idMap(array $rows, string $idKey, string $valueKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            foreach ($this->ids($row[$idKey] ?? []) as $id) {
                $out[$id] = (float) ($row[$valueKey] ?? 0);
            }
        }

        return $out;
    }

    /** Whether the descriptions can be searched through the full-text index. */
    private function fulltext(): bool
    {
        if ($this->fulltext === null) {
            try {
                $this->fulltext = (bool) $this->db->setQuery('SHOW INDEX FROM ' . $this->db->quoteName('#__bettersearch_items')
                    . ' WHERE Key_name = ' . $this->db->quote('ft_body'))->loadRow();
            } catch (\Throwable $e) {
                $this->fulltext = false;
            }
        }

        return $this->fulltext;
    }

    /** Products Gridbox hides from listings (add-ons sold by subscription) — same rule as Gridbox. */
    private function subscriptionHidden(): array
    {
        if ($this->subscriptionHidden !== null) {
            return $this->subscriptionHidden;
        }
        $this->subscriptionHidden = [];
        try {
            $query = $this->db->createQuery()
                ->select('subscription')
                ->from($this->db->quoteName('#__gridbox_store_product_data'))
                ->where($this->db->quoteName('product_type') . ' = ' . $this->db->quote('subscription'));
            foreach ($this->db->setQuery($query)->loadColumn() ?: [] as $json) {
                $s = json_decode((string) $json);
                if (is_object($s) && in_array($s->action ?? '', ['products', 'full'], true) && !empty($s->remove)) {
                    foreach ((array) ($s->products ?? []) as $id) {
                        $this->subscriptionHidden[] = (int) (is_object($id) ? ($id->id ?? 0) : $id);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        $this->subscriptionHidden = array_values(array_filter($this->subscriptionHidden));

        return $this->subscriptionHidden;
    }
}
