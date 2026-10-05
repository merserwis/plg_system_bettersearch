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

    /** Words of a query that are searched (the rest is ignored). */
    public const MAX_GROUPS = 8;

    /** Misspelt words corrected per query (each costs a pass over the vocabulary). */
    private const MAX_CORRECTIONS = 3;

    /** The last run() hit the candidate limit: products beyond it were not scored. */
    public bool $truncated = false;

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

    /** Shortest word the full-text index holds (innodb_ft_min_token_size); shorter words use LIKE. */
    private int $ftMin = 3;

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
            'params' => (float) $params->get('w_params', 8),
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

        // technical values ("1000 V", "-20…50 °C", "IP67") are compared as values, the rest as words
        $parsed = $this->params->get('tech_params', 1) && $this->paramsColumn() ? Params::query($query) : ['params' => [], 'rest' => $query];
        $tech   = $parsed['params'];
        $words  = $tech ? $parsed['rest'] : $query;

        // 1. as typed, model codes joined ("mi 3155" = "mi3155")
        $mode   = 'exact';
        $groups = $this->prepareAll($words, true, $tech);
        $items  = $this->run($groups, $phrase, $apps, $explain, false);

        // 1b. no item has those values: the same text searched as words (as before values were known)
        if (!$items && $tech) {
            $tech   = [];
            $words  = $query;
            $groups = $this->prepare($query, true);
            $items  = $this->run($groups, $phrase, $apps, $explain, false);
        }

        // 2. the parts of a joined code as separate words
        if (!$items) {
            $plain = $this->prepareAll($words, false, $tech);
            if (array_column($plain, 'term') !== array_column($groups, 'term')) {
                $groups = $plain;
                $items  = $this->run($groups, $phrase, $apps, $explain, false);
                $mode   = 'split';
            }
        }

        // 3. typos: words the index does not know, replaced by the closest known word
        $corrected = '';
        if (!$items && $this->params->get('typo_tolerance', 1) && trim($words) !== '') {
            $fix = $this->correct($words);
            if ($fix !== '' && $fix !== $this->norm->fold($words)) {
                $tried = $this->prepareAll($fix, true, $tech);
                $items = $this->run($tried, $this->norm->compact($fix), $apps, $explain, false);
                if ($items) {
                    $groups    = $tried;
                    $mode      = 'typo';
                    $corrected = trim($fix . ' ' . implode(' ', array_column($tech, 'text')));
                }
            }
        }

        // 4. not every word found: the items with the most words
        if (!$items && $this->params->get('partial_matches', 1) && count($groups) > 1) {
            $items = $this->run($groups, $phrase, $apps, $explain, true);
            $mode  = 'partial';
        }

        // pinned and featured products of matching rules go first, in the order set by the administrator;
        // featured ones are also marked in the results
        $pinned = [];
        foreach ($rules as $rule) {
            if ($rule['action'] === 'pin' || $rule['action'] === 'feature') {
                foreach ($rule['products'] as $id) {
                    $pinned[$id] = ($pinned[$id] ?? false) || $rule['action'] === 'feature';
                }
            }
        }
        if ($pinned) {
            $visible = $this->visibleIds(array_keys($pinned), $apps);
            $order   = 0;
            foreach ($pinned as $id => $featured) {
                if (!isset($visible[$id])) {
                    continue;
                }
                $items[$id] = array_merge($visible[$id], ['score' => 1e6 - $order++, 'pinned' => true, 'featured' => $featured,
                    'reasons' => $explain ? [$featured ? 'featured by rule' : 'pinned by rule'] : []]);
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
        $result['params']    = array_map(fn ($g) => Params::label($g['param']), array_values(array_filter($groups, fn ($g) => isset($g['param']))));
        $result['truncated'] = $this->truncated;
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

        // every group is one more LIKE per row: a query of more than MAX_GROUPS words is cut
        return array_slice($groups, 0, self::MAX_GROUPS);
    }

    /**
     * Word groups of the text plus one group per technical value. A value group is found by the value
     * (in any unit prefix or range that holds it) or by its text as one whole word ("87V").
     */
    private function prepareAll(string $words, bool $merge, array $tech): array
    {
        $groups = trim($words) !== '' ? $this->prepare($words, $merge) : [];
        $groups = array_slice($groups, 0, max(1, self::MAX_GROUPS - count($tech)));
        foreach (array_slice($tech, 0, self::MAX_GROUPS) as $p) {
            $text   = $this->norm->compact($p['text']);
            $single = $p['lo'] === $p['hi'] && !str_contains($p['text'], '/') && $text !== '' && strlen($text) <= 16;
            $groups[] = ['term' => $single ? $text : Params::label($p), 'digits' => false, 'parts' => [$text],
                'alts' => $single ? [['c' => $text, 'kind' => 'term']] : [], 'param' => $p];
        }

        return $groups;
    }

    /** The index has the column of technical values (added by the 1.5.0 update). */
    private function paramsColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (bool) $this->db->setQuery('SHOW COLUMNS FROM ' . $this->db->quoteName('#__bettersearch_items') . ' LIKE ' . $this->db->quote('t_params'))->loadRow();
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
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
        $rank   = [];
        $ft     = $this->fulltext();
        if (array_filter($groups, fn ($g) => isset($g['param']))) {
            $select[] = 'i.t_params';
        }
        foreach ($groups as $gi => $group) {
            if (isset($group['param'])) {
                // the value (exact token, or any range of its kind: the numbers are compared in PHP) or the text as a whole word
                $or = [];
                foreach (Params::likePatterns($group['param']) as $i => $pattern) {
                    $or[] = 'i.t_params LIKE ' . $db->quote($pattern);
                    if ($i === 0) {
                        $rank[] = '(i.t_params LIKE ' . $db->quote($pattern) . ') * 3';
                    }
                }
                foreach ($group['alts'] as $alt) {
                    $or[] = 'i.t_main LIKE ' . $db->quote('% ' . $db->escape($alt['c'], true) . ' %', false);
                }
                $conds[] = '(' . implode(' OR ', $or) . ')';
                continue;
            }
            $or     = [];
            $bodyOr = [];
            $words  = [];
            // a cheap SQL pre-rank for the candidate limit: a word in the code or name counts most
            $first  = $db->quote('% ' . $db->escape($group['term'], true) . '%', false);
            $rank[] = '(i.t_sku LIKE ' . $first . ') * 4 + (i.t_title LIKE ' . $first . ') * 2 + (i.t_fields LIKE ' . $first . ')';
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
                    if ($ft && strlen($a) >= $this->ftMin) {
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

        // the candidates most likely to rank high come first, so the limit cuts the weak tail only
        $limit = max(100, min(5000, (int) $this->params->get('max_candidates', 3000)));
        $query = $db->createQuery()
            ->select($select)
            ->from($db->quoteName('#__bettersearch_items', 'i'))
            ->innerJoin($db->quoteName('#__gridbox_pages', 'p') . ' ON p.id = i.id')
            ->where($partial ? '(' . implode(' OR ', $conds) . ')' : implode(' AND ', $conds))
            ->order('(' . implode(' + ', $rank) . ') DESC, p.hits DESC, i.id ASC');
        $this->visibility($query, $apps);
        $query->setLimit($limit);

        $productBoost  = $this->idMap((array) $this->params->get('product_boosts', []), 'product', 'boost');
        $categoryBoost = $this->idMap((array) $this->params->get('category_boosts', []), 'category', 'boost');
        $popularity    = (float) $this->params->get('popularity_boost', 1);
        $outOfStock    = (string) $this->params->get('out_of_stock', 'none');

        $items = [];
        $n     = 0;
        try {
            $rows = $db->setQuery($query)->getIterator();
        } catch (\Throwable $e) {
            if (!$ft) {
                throw $e;
            }
            // the full-text index is missing or unusable on this server: search without it
            $this->fulltext = false;

            return $this->run($groups, $phrase, $apps, $explain, $partial);
        }
        // rows are scored as they stream in: only the scored items stay in memory
        foreach ($rows as $row) {
            $n++;
            $row->cats = array_map('intval', array_filter(explode(',', (string) $row->cat_ids)));
            $s = $this->scorer->score($groups, $row, $phrase, $explain);
            // the SQL lets through every item with values of the kind: a value it does not hold excludes it
            if ($s['matched'] === 0 || (!$partial && $s['missed'] > 0)) {
                continue;
            }
            $score   = $s['score'];
            $reasons = $s['reasons'];

            if ($partial) {
                // a partial match ranks by how many words it has, then by relevance
                $score += 100 * $s['matched'];
            }
            $boost = (float) ($productBoost[(int) $row->id] ?? 0);
            foreach ($row->cats as $catId) {
                $boost += (float) ($categoryBoost[$catId] ?? 0);
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
        $this->truncated = $this->truncated || $n >= $limit;

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
        $now  = $db->quote((new \DateTime('now', self::siteZone()))->format('Y-m-d H:i:s'));
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

        $excluded = array_merge($this->ids($this->params->get('exclude_products', [])), $this->ids($this->params->get('exclude_pages', [])), $this->subscriptionHidden());
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
            'cats'        => $row->cats ?? array_map('intval', array_filter(explode(',', (string) $row->cat_ids))),
            'score'       => round($score, 3),
            'base'        => $base,
            'pinned'      => false,
            'featured'    => false,
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
        // the sort key of a title once per item, not once per comparison
        foreach ($items as &$it) {
            $it['tkey'] = mb_strtolower((string) $it['title'], 'UTF-8');
        }
        unset($it);
        // names in the order of the site language (ł after l, not after z) when intl is there
        $collator = class_exists(\Collator::class) ? \Collator::create(str_replace('-', '_', $this->language)) : null;
        $title    = $collator
            ? fn ($a, $b) => $collator->compare($a['tkey'], $b['tkey'])
            : fn ($a, $b) => strnatcasecmp($a['tkey'], $b['tkey']);
        $rel   = fn ($a, $b) => $b['score'] <=> $a['score'] ?: $title($a, $b);
        // pinned products first, among themselves in the order the administrator set (their score)
        $pin   = fn ($a, $b) => ($b['pinned'] <=> $a['pinned']) ?: ($a['pinned'] ? $b['score'] <=> $a['score'] : 0);
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
                    $action = (string) ($rule['action'] ?? 'pin');
                    $out[]  = ['action' => in_array($action, ['hide', 'feature'], true) ? $action : 'pin', 'products' => $products];
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
        $buckets = $this->buckets($vocab);
        $out     = [];
        $changed = 0;
        foreach (array_slice($this->norm->tokens($query), 0, self::MAX_GROUPS) as $token) {
            $len = strlen($token);
            if (isset($vocab[$token]) || $len < 4 || preg_match('/^[0-9]+$/', $token) || $changed >= self::MAX_CORRECTIONS
                || $this->norm->isStopword($token)) {
                $out[] = $token;
                continue;
            }
            $max  = $len >= 8 ? 2 : 1;
            $best = null;
            $rank = [PHP_INT_MAX, 1, 0];
            // candidates: words of about the same length (the whole word) and longer words (their
            // start) — only those beginning with the same letter, as spell checkers do
            $first = $token[0];
            for ($wlen = max(3, $len - $max); $wlen <= $len + 8; $wlen++) {
                foreach ($buckets[$wlen][$first] ?? [] as $word => $df) {
                    $word = (string) $word;
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
            }
            if ($best !== null && $best !== $token) {
                $out[] = $best;
                $changed++;
            } else {
                $out[] = $token;
            }
        }

        return $changed ? implode(' ', $out) : '';
    }

    /** @var array<int, array<string, array<string, int>>>|null vocabulary by length and first letter */
    private ?array $vocabBuckets = null;

    /** @return array<int, array<string, array<string, int>>> */
    private function buckets(array $vocab): array
    {
        if ($this->vocabBuckets === null) {
            $this->vocabBuckets = [];
            foreach ($vocab as $word => $df) {
                $word = (string) $word;
                $this->vocabBuckets[strlen($word)][$word[0]][$word] = $df;
            }
        }

        return $this->vocabBuckets;
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

    /** @var callable|null gives the (cached) vocabulary when correct() needs it */
    private $vocabLoader = null;

    /**
     * Words of titles, codes, categories and fields with their frequency — of the products a guest
     * can see, so a correction never points at an unpublished or restricted product.
     *
     * @return array<string, int>
     */
    private function vocabulary(): array
    {
        if ($this->vocab !== null) {
            return $this->vocab;
        }
        if ($this->vocabLoader !== null) {
            $loaded = ($this->vocabLoader)();
            if (is_array($loaded)) {
                return $this->vocab = $loaded;
            }
        }

        return $this->vocab = $this->buildVocabulary();
    }

    /** @return array<string, int> */
    public function buildVocabulary(): array
    {
        $db    = $this->db;
        $vocab = [];
        $query = $db->createQuery()
            ->select('i.t_main')
            ->from($db->quoteName('#__bettersearch_items', 'i'))
            ->innerJoin($db->quoteName('#__gridbox_pages', 'p') . ' ON p.id = i.id')
            ->where('p.published = 1')
            ->where('p.page_access = 1')
            ->where('p.page_category <> ' . $db->quote('trashed'));
        foreach ($db->setQuery($query)->getIterator() as $row) {
            foreach (explode(' ', trim((string) $row->t_main)) as $t) {
                // a word of letters, long enough to be worth a correction (codes are not corrected)
                if (strlen($t) >= 3 && !ctype_digit($t)) {
                    $vocab[$t] = ($vocab[$t] ?? 0) + 1;
                }
            }
        }

        return $vocab;
    }

    /** Lets the caller provide the vocabulary only when it is needed (e.g. from a cache). */
    public function setVocabularyLoader(callable $loader): void
    {
        $this->vocabLoader = $loader;
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
                if ($this->fulltext) {
                    $row = $this->db->setQuery('SHOW VARIABLES LIKE ' . $this->db->quote('innodb_ft_min_token_size'))->loadRow();
                    $this->ftMin = max(1, min(10, (int) ($row[1] ?? 3)));
                }
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

    /** Gridbox compares publishing dates with the time of the site's time zone (DateHelper::make()). */
    private static function siteZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) \Joomla\CMS\Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
