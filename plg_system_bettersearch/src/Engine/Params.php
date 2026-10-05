<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Technical parameters: values with a unit ("1000 V", "1 kV", "50 Hz", "200 GΩ"), ranges
 * ("-20…+50 °C", "0–600 V", "od -10 do 40 °C"), measurement categories ("CAT IV", "kat. III")
 * and protection ratings ("IP67"). They are read from the product texts into tokens of one
 * canonical form ("v=1000", "c=-20..50", "cat=4", "ip=67") and from the query the same way,
 * so "1 kV" finds "1000 V" and a query range finds the products whose range covers it.
 *
 * In the product texts the units are read as written (case matters: "mA" is milliampere, "MΩ"
 * megaohm, a lone "a" is a Polish word); queries are typed in lower case, so there "10 ma" is
 * 10 mA and "10 mohm" may mean either milli- or megaohm (both are tried).
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

final class Params
{
    /** Unit as written => dimension of the token. */
    private const UNITS = [
        'VA' => 'va', 'Wh' => 'wh', 'Ah' => 'ah', 'Hz' => 'hz', 'dB' => 'db', 'lx' => 'lx', 'Pa' => 'pa', 'bar' => 'bar',
        'Ω' => 'ohm', 'Ohm' => 'ohm', 'ohm' => 'ohm', 'OHM' => 'ohm', '°C' => 'c', '℃' => 'c', 'V' => 'v', 'A' => 'a', 'W' => 'w', 'F' => 'f', 'm' => 'm',
    ];

    private const PREFIX = ['G' => 1e9, 'g' => 1e9, 'M' => 1e6, 'k' => 1e3, 'K' => 1e3, 'm' => 1e-3, 'c' => 1e-2, 'µ' => 1e-6, 'μ' => 1e-6, 'u' => 1e-6, 'n' => 1e-9, 'p' => 1e-12];

    private const ROMAN = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4];

    /** A number: "1000", "1,5", "-20", "1 000" (a space between thousands only after one or two digits). */
    private const NUM = '[-−–]?(?:\d{1,2}(?:[  ]\d{3})+|\d+)(?:[.,]\d+)?';

    /** Words and signs between the two ends of a range. */
    private const SEP = '(?:\.{2,3}|…|–|—|-|−|÷|~|\bto\b|\bdo\b|\bbis\b)';

    /** Tokens of one item at most (a long description lists many values). */
    private const MAX_TOKENS = 160;

    // ---------------------------------------------------------------- index side

    /** Parameter tokens of a product text: " v=1000 v=0..600 cat=4 ip=67 ". */
    public static function index(string $text): string
    {
        $tokens = [];
        foreach (self::extract($text, false)['params'] as $p) {
            $tokens[self::token($p['dim'], $p['lo'], $p['hi'])] = true;
            if (count($tokens) >= self::MAX_TOKENS) {
                break;
            }
        }

        return $tokens ? ' ' . implode(' ', array_keys($tokens)) . ' ' : '';
    }

    // ---------------------------------------------------------------- query side

    /**
     * Parameters of a query and the query without them.
     *
     * @return array{params: array<int, array{dim: string, lo: float, hi: float, alts: array<int, array{0: float, 1: float}>, text: string}>, rest: string}
     */
    public static function query(string $query): array
    {
        return self::extract($query, true);
    }

    /**
     * SQL conditions that let through the items which may hold the parameter (the exact value, or
     * any range of the same kind — the numbers of a range are compared in PHP).
     *
     * @return string[] LIKE patterns for the t_params column
     */
    public static function likePatterns(array $param): array
    {
        $out = [];
        foreach ($param['alts'] as [$lo, $hi]) {
            if ($lo === $hi) {
                $out[] = '% ' . self::token($param['dim'], $lo, $hi) . ' %';
            }
        }
        if (!in_array($param['dim'], ['cat', 'ip'], true)) {
            $out[] = '% ' . $param['dim'] . '=%..%';
        }

        return array_values(array_unique($out));
    }

    /**
     * How well the item's tokens hold the query parameter: 1 = the same value (or a range that is the
     * query range), 0.8 = a range of the item covers the value or the range of the query, 0 = none.
     */
    public static function score(array $param, string $tokens): float
    {
        if ($tokens === '' || !str_contains($tokens, ' ' . $param['dim'] . '=')) {
            return 0.0;
        }
        $best = 0.0;
        if (!preg_match_all('/ ' . preg_quote($param['dim'], '/') . '=(\S+)/', $tokens, $m)) {
            return 0.0;
        }
        foreach ($m[1] as $value) {
            [$lo, $hi] = str_contains($value, '..') ? array_map('floatval', explode('..', $value, 2)) : [(float) $value, (float) $value];
            foreach ($param['alts'] as [$qlo, $qhi]) {
                if (self::same($lo, $qlo) && self::same($hi, $qhi)) {
                    return 1.0;
                }
                $rangeItem = !self::same($lo, $hi);
                if ($rangeItem && !in_array($param['dim'], ['cat', 'ip'], true) && $lo <= $qlo + self::eps($qlo) && $hi >= $qhi - self::eps($qhi)) {
                    $best = 0.8;
                }
            }
        }

        return $best;
    }

    /** Readable form of a parameter for the test console ("v=1000", "c=-20..50"). */
    public static function label(array $param): string
    {
        return self::token($param['dim'], $param['lo'], $param['hi']);
    }

    // ---------------------------------------------------------------- parsing

    /**
     * @return array{params: array<int, array{dim: string, lo: float, hi: float, alts: array<int, array{0: float, 1: float}>, text: string}>, rest: string}
     */
    public static function extract(string $text, bool $isQuery): array
    {
        $params = [];
        if ($text === '' || !preg_match('/\d|IP|ip|CAT|cat|kat|KAT/u', $text)) {
            return ['params' => [], 'rest' => $text];
        }
        $rest  = $text;
        $blank = function (array $m) use (&$rest): void {
            // the found text is taken out of the query (same length: offsets of later matches stay right)
            $rest = substr_replace($rest, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
        };

        // protection rating: IP67, IP 54, IP-65
        if (preg_match_all('/(?<![\p{L}\d])IP[ \-]?([0-9X]{2})(?![\p{L}\d])/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $code = strtolower($m[1][0]);
                if (ctype_digit($code)) {
                    $params[] = self::param('ip', (float) $code, (float) $code, $m[0][0]);
                    $blank($m);
                }
            }
        }

        // measurement category: CAT IV, CAT III, kat. II, CAT 4
        if (preg_match_all('/(?<![\p{L}\d])(?:CAT|KAT\.?)\s*(IV|III|II|I|[1-4])(?![\p{L}\d])/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $n = self::ROMAN[strtolower($m[1][0])] ?? (int) $m[1][0];
                $params[] = self::param('cat', (float) $n, (float) $n, $m[0][0]);
                $blank($m);
            }
        }

        $unit = self::unitPattern($isQuery);

        // ranges: "-20…+50 °C", "0-600 V", "od -10 do 40°C", "1 mA ... 10 A"
        $range = '/(?<![\p{L}\d.,])(?:od\s+|from\s+|von\s+)?(?<n1>' . self::NUM . ')\s*(?:' . $unit . ')?\s*' . self::SEP
            . '\s*\+?(?<n2>' . self::NUM . ')\s*' . str_replace(['(?<prefix>', '(?<unit>'], ['(?<bprefix>', '(?<bunit>'], $unit) . '/u';
        if (preg_match_all($range, $rest, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $u2 = self::unitOf($m, 'b', $isQuery);
                if ($u2 === null) {
                    continue;
                }
                // the unit after the first number is optional ("-20…+50 °C"), but must be of the same kind
                $u1 = self::unitOf($m, '', $isQuery);
                if ($u1 !== null && $u1['dim'] !== $u2['dim']) {
                    continue;
                }
                $a = self::number($m['n1'][0]);
                $b = self::number($m['n2'][0]);
                if ($a === null || $b === null) {
                    continue;
                }
                $alts = [];
                foreach ($u2['factors'] as $i => $f2) {
                    $f1 = $u1 ? ($u1['factors'][$i] ?? $u1['factors'][0]) : $f2;
                    $alts[] = [min($a * $f1, $b * $f2), max($a * $f1, $b * $f2)];
                }
                $params[] = self::param($u2['dim'], $alts[0][0], $alts[0][1], $m[0][0], $alts);
                $blank($m);
            }
        }

        // single values, also lists sharing one unit: "1000 V", "230/400 V", "1,5kV", "200 GΩ"
        $single = '/(?<![\p{L}\d.,])(' . self::NUM . '(?:\s*\/\s*' . self::NUM . ')*)\s*' . $unit . '/u';
        if (preg_match_all($single, $rest, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $u = self::unitOf($m, '', $isQuery);
                if ($u === null) {
                    continue;
                }
                foreach (preg_split('/\s*\/\s*/', $m[1][0]) ?: [] as $raw) {
                    $n = self::number($raw);
                    if ($n === null) {
                        continue;
                    }
                    $alts = array_map(fn ($f) => [$n * $f, $n * $f], $u['factors']);
                    $params[] = self::param($u['dim'], $alts[0][0], $alts[0][1], $m[0][0], $alts);
                }
                $blank($m);
            }
        }

        return ['params' => $params, 'rest' => trim(preg_replace('/\s+/u', ' ', $rest) ?? $rest)];
    }

    /** Prefix and unit, as named groups "prefix" and "unit". */
    private static function unitPattern(bool $isQuery): string
    {
        $units = array_keys(self::UNITS);
        usort($units, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alt = implode('|', array_map(fn ($u) => preg_quote($u, '/'), $units)) . '|°\s?C|o\s?C';

        // a unit ends the token ("1000 VAC" = 1000 V AC; "mm²" is an area, not a length)
        return '(?<prefix>[GgMkKmcµμunp]?)(?<unit>' . ($isQuery ? '(?i:' . $alt . ')' : $alt) . ')(?:AC|DC|ac|dc|RMS|rms|~)?(?![\p{L}\d²³])';
    }

    /** @return array{dim: string, factors: float[]}|null */
    private static function unitOf(array $m, string $g, bool $isQuery): ?array
    {
        $prefix = (string) ($m[$g . 'prefix'][0] ?? '');
        $unit   = (string) ($m[$g . 'unit'][0] ?? '');
        if ($unit === '' || ($m[$g . 'unit'][1] ?? -1) < 0) {
            return null;
        }
        $key = preg_replace('/\s+/u', '', $unit) ?? $unit;
        if (preg_match('/^(°C|oC|℃)$/iu', $key)) {
            $dim = 'c';
        } else {
            $dim = self::UNITS[$key] ?? null;
            if ($dim === null && $isQuery) {
                foreach (self::UNITS as $u => $d) {
                    if (strcasecmp($u, $key) === 0) {
                        $dim = $d;
                        break;
                    }
                }
            }
        }
        if ($dim === null) {
            return null;
        }
        // "oC" only as a temperature written without the degree sign in a query
        if (!$isQuery && preg_match('/^oC$/i', $key)) {
            return null;
        }
        if ($prefix === '') {
            return ['dim' => $dim, 'factors' => [1.0]];
        }
        // no prefixes for temperature, decibels, IP; centi only for metres
        if (in_array($dim, ['c', 'db'], true) || ($prefix === 'c' && $dim !== 'm')) {
            return null;
        }
        $factor = self::PREFIX[$prefix] ?? null;
        if ($factor === null) {
            return null;
        }
        // a query in lower case: "mohm" may be milliohm or megaohm
        if ($isQuery && $prefix === 'm' && $dim === 'ohm') {
            return ['dim' => $dim, 'factors' => [1e-3, 1e6]];
        }

        return ['dim' => $dim, 'factors' => [$factor]];
    }

    private static function number(string $raw): ?float
    {
        $raw = str_replace(['−', '–', ' ', ' ', ','], ['-', '-', '', '', '.'], trim($raw));

        return is_numeric($raw) ? (float) $raw : null;
    }

    private static function param(string $dim, float $lo, float $hi, string $text, ?array $alts = null): array
    {
        return ['dim' => $dim, 'lo' => $lo, 'hi' => $hi, 'alts' => $alts ?? [[$lo, $hi]], 'text' => trim($text)];
    }

    private static function token(string $dim, float $lo, float $hi): string
    {
        return $dim . '=' . (self::same($lo, $hi) ? self::fmt($lo) : self::fmt($lo) . '..' . self::fmt($hi));
    }

    /** Canonical number: "1000", "0.5", "1e-06". */
    private static function fmt(float $v): string
    {
        if (abs($v) < 1e-15) {
            return '0';
        }
        $s = sprintf('%.6g', $v);
        if (str_contains($s, 'e')) {
            [$mantissa, $exp] = explode('e', $s, 2);

            return (str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa) . 'e' . $exp;
        }

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    private static function same(float $a, float $b): bool
    {
        return abs($a - $b) <= self::eps(max(abs($a), abs($b)));
    }

    private static function eps(float $v): float
    {
        return max(1e-12, abs($v) * 1e-6);
    }
}
