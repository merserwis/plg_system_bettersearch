<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Relevance of one indexed item for a prepared query. Pure PHP (no database), so the ranking can
 * be tested and explained in the administrator test console.
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

final class Scorer
{
    /** Fields of the index row: tokens column, compact column. */
    public const FIELDS = [
        'sku'    => ['t_sku', 'c_sku'],
        'title'  => ['t_title', 'c_title'],
        'fields' => ['t_fields', 'c_fields'],
        'cats'   => ['t_cats', 'c_cats'],
    ];

    /** How well an alternative of a query group matches: whole token, start of a token, anywhere in the compact text. */
    private const EXACT  = 1.0;
    private const PREFIX = 0.75;
    private const INFIX  = 0.55;
    private const INFLECTED = 0.9;

    /** Kind of alternative: the typed term, a synonym, a stemmed word, a corrected typo. */
    private const KIND = ['term' => 1.0, 'syn' => 0.9, 'stem' => 0.7, 'typo' => 0.8, 'join' => 0.9];

    /** @var array<string, float> */
    private array $weights;

    private float $digitFactor;

    /** @param array<string, float> $weights title, sku, fields, cats, body, params */
    public function __construct(array $weights = [], float $digitFactor = 1.5)
    {
        $this->weights     = array_merge(['title' => 10.0, 'sku' => 12.0, 'fields' => 4.0, 'cats' => 3.0, 'body' => 1.0, 'params' => 8.0], $weights);
        $this->digitFactor = $digitFactor;
    }

    /**
     * @param array<int, array{term: string, digits: bool, alts: array<int, array{c: string, kind: string}>}> $groups
     * @param object  $row      index row (t_* and c_* columns, b<i> body flags)
     * @param string  $phrase   the whole query in compact form
     * @param bool    $explain  collect the reasons
     *
     * @return array{score: float, matched: int, missed: int, reasons: string[]}  missed: technical values not held
     */
    public function score(array $groups, object $row, string $phrase, bool $explain = false): array
    {
        $score   = 0.0;
        $matched = 0;
        $reasons = [];
        $inTitle = 0;
        $missed  = 0;

        foreach ($groups as $i => $group) {
            $best = 0.0;
            $why  = '';
            if (isset($group['param'])) {
                // a technical value: compared as a number with its unit, or the same text as one whole word
                $tokens = (string) ($row->t_params ?? '');
                $m      = Params::score($group['param'], $tokens);
                if ($m > 0) {
                    $best = $m * $this->weights['params'];
                    $why  = $explain ? sprintf('%s:param %s', Params::label($group['param']), $m >= 1 ? 'exact' : 'in range') : '';
                }
                // the text as one word only for items that state no value of this kind ("2,5 kV" holds the word "5kv")
                $alts = str_contains($tokens, ' ' . $group['param']['dim'] . '=') ? [] : $group['alts'];
                foreach ($alts as $alt) {
                    foreach (self::FIELDS as $field => [$tokCol]) {
                        if (str_contains((string) ($row->{$tokCol} ?? ''), ' ' . $alt['c'] . ' ') && self::EXACT * 0.9 * $this->weights[$field] > $best) {
                            $best = 0.9 * $this->weights[$field];
                            $why  = $explain ? sprintf('%s:%s exact', $alt['c'], $field) : '';
                        }
                    }
                }
                if ($best > 0) {
                    $matched++;
                    $score += $best;
                    if ($explain) {
                        $reasons[] = sprintf('%s +%.1f', $why, $best);
                    }
                } else {
                    $missed++;
                }
                continue;
            }
            foreach ($group['alts'] as $alt) {
                $a    = $alt['c'];
                $kind = self::KIND[$alt['kind']] ?? 1.0;
                foreach (self::FIELDS as $field => [$tokCol, $cmpCol]) {
                    $tokens = (string) ($row->{$tokCol} ?? '');
                    if ($alt['kind'] === 'stem' && $this->inflected($a, $tokens)) {
                        // the same word in another form ("kamery" ~ "kamera"): almost a whole-word match
                        $m = self::INFLECTED;
                        $k = 1.0;
                    } else {
                        $m = $this->match($a, $tokens, (string) ($row->{$cmpCol} ?? ''), $alt['kind'] === 'stem');
                        $k = $kind;
                    }
                    $s = $m * $k * $this->weights[$field];
                    if ($s > $best) {
                        $best = $s;
                        $why  = $explain ? sprintf('%s:%s %s%s', $group['term'], $field, $this->label($m), $alt['kind'] !== 'term' ? ' (' . $alt['kind'] . ' ' . $a . ')' : '') : '';
                    }
                    if ($field === 'title' && $m > 0) {
                        $inTitle |= 1 << $i;
                    }
                }
            }
            if (!empty($row->{'b' . $i}) && $this->weights['body'] > $best) {
                $best = $this->weights['body'];
                $why  = $explain ? $group['term'] . ':body' : '';
            }
            if ($best > 0) {
                $matched++;
                $best *= $group['digits'] ? $this->digitFactor : 1.0;
                $score += $best;
                if ($explain) {
                    $reasons[] = sprintf('%s +%.1f', $why, $best);
                }
            }
        }

        if ($matched === 0) {
            return ['score' => 0.0, 'matched' => 0, 'missed' => $missed, 'reasons' => $reasons];
        }

        // the whole query as typed, wherever the spaces are
        $title = (string) ($row->c_title ?? '');
        $sku   = (string) ($row->c_sku ?? '');
        if (strlen($phrase) >= 3) {
            $bonus = 0.0;
            if ($sku !== '' && $this->skuEquals($sku, $phrase)) {
                $bonus += 30;
            } elseif ($sku !== '' && str_contains($sku, $phrase)) {
                $bonus += 10;
            }
            // the products of a category named like the query ("kamery termowizyjne")
            if (count($groups) > 1 && str_contains((string) ($row->c_cats ?? ''), $phrase)) {
                $bonus += 5;
            }
            if ($title === $phrase) {
                $bonus += 20;
            } elseif (($pos = strpos($title, $phrase)) !== false) {
                // earlier in the name = the product itself rather than an accessory "for MI 3155"
                $bonus += 8 + 6 * (1 - $pos / max(1, strlen($title)));
            }
            if ($bonus > 0) {
                $score += $bonus;
                if ($explain) {
                    $reasons[] = sprintf('phrase +%.1f', $bonus);
                }
            }
        }

        if (count($groups) > 1 && $inTitle === (1 << count($groups)) - 1) {
            $score += 4;
            if ($explain) {
                $reasons[] = 'all words in title +4';
            }
        }

        // shorter names first among equals (a meter before its "case for meter ..." accessory)
        $words = substr_count(trim((string) ($row->t_title ?? '')), ' ') + 1;
        $short = 2.0 / (1 + $words / 8);
        $score += $short;

        return ['score' => round($score, 3), 'matched' => $matched, 'missed' => $missed, 'reasons' => $reasons];
    }

    /** 0 .. 1 */
    public function match(string $alt, string $tokens, string $compact, bool $prefixOnly = false): float
    {
        if ($alt === '') {
            return 0.0;
        }
        if (!$prefixOnly && str_contains($tokens, ' ' . $alt . ' ')) {
            return self::EXACT;
        }
        if (str_contains($tokens, ' ' . $alt)) {
            return self::PREFIX;
        }
        if (!$prefixOnly && (strlen($alt) >= 3 || preg_match('/[0-9]/', $alt)) && str_contains($compact, $alt)) {
            return self::INFIX;
        }

        return 0.0;
    }

    /** A word of the tokens is the stem plus a short ending (up to 4 letters). */
    private function inflected(string $stem, string $tokens): bool
    {
        return strlen($stem) >= 4 && (bool) preg_match('/ ' . preg_quote($stem, '/') . '[a-z]{0,4} /', $tokens);
    }

    /** The compact SKU column holds the product code and its variant codes separated by spaces. */
    private function skuEquals(string $compactSkus, string $phrase): bool
    {
        return in_array($phrase, explode(' ', $compactSkus), true);
    }

    private function label(float $m): string
    {
        return $m >= self::EXACT ? 'exact' : ($m >= self::PREFIX ? 'prefix' : 'inside');
    }
}
