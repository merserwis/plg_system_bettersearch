<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * The technical values the filters of the results page use, one per product and kind: the highest
 * rated voltage, the highest IP rating and measurement category, the operating temperature range and
 * the measuring range of temperature. They are read from the description fields (the technical data
 * of a product), not from its plain text: a table row carries its label in the first cell ("Napięcie
 * zasilania", "Temperatura pracy") and a column its header ("Rozdzielczość"), and these decide what a
 * value is. A plain maximum of the text would take a supply of 230 V, a surge of 12 kV, the breaking
 * capacity of a fuse or a prospective short-circuit current for the rating of the instrument.
 *
 * The values themselves are read by Params (the same reader as the search); this class only finds
 * their context and keeps one value per kind.
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

final class Specs
{
    /** The kinds of a product's values, in the order of the filters. */
    public const KINDS = ['v', 'ip', 'cat', 'tw', 'tm'];

    /** Ranges of temperature: an item passes a filter when its range covers the chosen one. */
    public const RANGES = ['tw', 'tm'];

    /**
     * Contexts that tell a voltage or a current is not the rating of the instrument. "quality" only
     * counts in the row label and the column header (a column "Dokładność" next to the ranges), the
     * others anywhere in the label, header, heading or the line.
     */
    private const SKIP = [
        'supply'  => '/zasila|bateri|akumulator|ładowa|pobór|zużyci|ogniw|żywotno|power supply|battery|\bmains\b/iu',
        'output'  => '/wyjści|wyjście|output|przekaźnik|przełącza|obciążaln|styk/iu',
        'safety'  => '/przepięci|udar|surge|\besd\b|wyładowa|odporno|ochron|bezpiecznik|zabezpiecz|\bIR\s*\d|zdolno\w* wyłącza|wytrzym|withstand|izolacj[ai] (?:wzmocnion|podwójn)'
            . '|spodziewan|przewidywan|zwarciow|ipsc|\bpfc\b|\bpsc\b|transient|gwint|\bG\s?\d\/\d/iu',
        'quality' => '/rozdzielczo|dokładno|błąd|niepewno|czułoś|stabilno|współczynnik|netd|dryft|powtarzaln|liniowo|histerez|resolution|accuracy/iu',
    ];

    /** "Kategoria przepięciowa CAT III 1000 V" is the rated voltage, not a surge. */
    private const CATEGORY = '/kategori\w*\s+(?:prze(?:ciw)?)?przepię\w*/iu';

    /** The operating conditions (a label, header or heading). */
    private const WORK = '/(?:temperatur|warunk|środowisk|otoczeni)\w*[^|]{0,40}(?:prac|robocz|użytkowa|eksploatac)|warunki\s*(?:pracy|robocze)|temperatura otoczenia'
        . '|operating|(?<![\w ])prac|otoczeni|użytkowa|eksploatac|środowisko pracy/iu';

    /** Storage and transport (never a filter). */
    private const STORE = '/przechowyw|składowa|magazynow|transport|storage/iu';

    /** Reference conditions of an accuracy ("@ 23 °C", "w temperaturze odniesienia"): not a range. */
    private const REF = '/odniesienia|nominaln|referenc|zapewni|@|w temp/iu';

    /** Elements without visible text (a JSON-LD block of questions in a description: its values are not data). */
    private const HIDDEN = ['script', 'style', 'template', 'noscript', 'svg', 'iframe', 'object'];

    /** Values out of these bounds are misreadings. */
    private const BOUNDS = ['v' => [0.0, 1.0e6], 'ip' => [0.0, 69.0], 'cat' => [1.0, 4.0], 'c' => [-273.0, 5000.0]];

    /**
     * The values of one product from the HTML (or text) of its description fields.
     *
     * @param  string[]  $sources  the HTML or text of each field
     * @param  bool      $explain  also where each value comes from (the settings preview)
     *
     * @return array<string, array{value: float|array{0: float, 1: float}, src?: string}>
     */
    public static function read(array $sources, bool $explain = false): array
    {
        $values = [];
        foreach ($sources as $source) {
            if (trim($source) !== '') {
                array_push($values, ...self::values($source));
            }
        }

        return self::aggregate($values, $explain);
    }

    /** The values as the index keeps them: " v=1000 ip=67 cat=4 tw=-10..50 tm=-40..400 " (empty: none). */
    public static function encode(array $specs): string
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            if (!isset($specs[$kind])) {
                continue;
            }
            $value = $specs[$kind]['value'];
            $out[] = $kind . '=' . (is_array($value) ? self::num($value[0]) . '..' . self::num($value[1]) : self::num($value));
        }

        return $out ? ' ' . implode(' ', $out) . ' ' : '';
    }

    /** @return array<string, float|array{0: float, 1: float}> the values of the column f_params */
    public static function decode(string $tokens): array
    {
        $out = [];
        if (preg_match_all('/(?:^| )([a-z]+)=(-?\d+(?:\.\d+)?)(?:\.\.(-?\d+(?:\.\d+)?))?(?= |$)/', $tokens, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (in_array($hit[1], self::KINDS, true)) {
                    $out[$hit[1]] = isset($hit[3]) && $hit[3] !== '' ? [(float) $hit[2], (float) $hit[3]] : (float) $hit[2];
                }
            }
        }

        return $out;
    }

    /**
     * Whether a product's value passes a chosen threshold: at least the value (voltage, IP, CAT), or
     * a range that covers the chosen one (temperatures).
     *
     * @param float|array{0: float, 1: float}|null  $value
     * @param float|array{0: float, 1: float}       $wanted
     */
    public static function passes(string $kind, $value, $wanted): bool
    {
        if ($value === null) {
            return false;
        }
        if (in_array($kind, self::RANGES, true)) {
            return is_array($value) && is_array($wanted) && $value[0] <= $wanted[0] + 1e-9 && $value[1] >= $wanted[1] - 1e-9;
        }

        return !is_array($value) && !is_array($wanted) && $value >= $wanted - 1e-9;
    }

    /**
     * Every value of a description with its context.
     *
     * @return array<int, array{d: string, lo: float, hi: float, label: string, col: string, head: string, line: string}>
     */
    private static function values(string $source): array
    {
        if (!str_contains($source, '<')) {
            $source = '<p>' . nl2br(htmlspecialchars($source, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $source . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$root) {
            return [];
        }
        $out  = [];
        $head = '';
        self::walk($root, $head, $out);

        return $out;
    }

    /** Tables (row label + column header), headings, "Label: value" lines and lists under a heading. */
    private static function walk(\DOMNode $node, string &$head, array &$out): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                foreach (self::split($child->nodeValue ?? '') as $line) {
                    self::line($line, $head, $out);
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::HIDDEN, true)) {
                continue;
            }
            if ($tag === 'table') {
                self::table($child, $head, $out);
                continue;
            }
            if (preg_match('/^h[1-6]$/', $tag)) {
                $head = self::text($child);
                continue;
            }
            if (in_array($tag, ['p', 'li', 'div', 'ul', 'ol', 'span', 'strong', 'b', 'em', 'dl', 'dd', 'dt'], true) && $child->getElementsByTagName('table')->length === 0) {
                foreach (self::lines($child) as $line) {
                    self::line($line, $head, $out);
                }
                continue;
            }
            self::walk($child, $head, $out);
        }
    }

    /** One line outside a table: "Label: value", a heading of the next lines, or a value under the heading. */
    private static function line(string $line, string &$head, array &$out): void
    {
        if (preg_match('/^([^:\d]{3,60}):\s*(.*)$/u', $line, $m)) {
            if (trim($m[2]) === '') {
                $head = $m[1];

                return;
            }
            self::add($line, $m[1], '', $head, $out);

            return;
        }
        if (!preg_match('/\d/', $line) && mb_strlen($line) < 60) {
            $head = $line;

            return;
        }
        self::add($line, '', '', $head, $out);
    }

    private static function table(\DOMElement $table, string $head, array &$out): void
    {
        $cols = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if ($td instanceof \DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                    $cells[] = $td;
                }
            }
            if (!$cells) {
                continue;
            }
            $texts = array_map([self::class, 'text'], $cells);
            // a row of column headers: th cells or bold cells, and no value in it
            $bold = array_filter($cells, [self::class, 'isHeaderCell']);
            if (count($cells) > 1 && count($bold) === count($cells) && !preg_match('/\d\s*[a-zA-ZΩ°%]/u', implode(' ', $texts))) {
                $cols = $texts;
                continue;
            }
            $label  = count($cells) > 1 ? $texts[0] : '';
            // labels one under the other in the first cell and the values the same way in the next ones:
            // each value line under the label of its line
            $labels = count($cells) > 1 ? self::lines($cells[0]) : [];
            foreach ($cells as $i => $td) {
                if ($i === 0 && count($cells) > 1) {
                    continue;
                }
                $lines  = self::lines($td);
                $paired = count($labels) > 1 && count($labels) === count($lines);
                foreach ($lines as $n => $line) {
                    // labels that do not pair with the lines: a line's own "Label:" tells more than all of them
                    self::add($line, $paired ? $labels[$n] : $label, $cols[$i] ?? '', $head, $out, count($labels) > 1 && !$paired);
                }
            }
        }
    }

    private static function isHeaderCell(\DOMElement $td): bool
    {
        if (strtolower($td->tagName) === 'th') {
            return true;
        }
        $marks = $td->getElementsByTagName('strong');
        if (!$marks->length) {
            $marks = $td->getElementsByTagName('b');
        }
        if (!$marks->length) {
            return false;
        }
        $bold = [];
        foreach ($marks as $mark) {
            $bold[] = self::text($mark);
        }

        return self::text($td) === trim(implode(' ', $bold));
    }

    /**
     * The values of a line. A line of several labels ("temperatura pracy: -10…40 °C, temperatura
     * przechowywania: -20…60 °C") is read in parts, each under its own label next to the row's one.
     */
    private static function add(string $line, string $label, string $col, string $head, array &$out, bool $weak = false): void
    {
        $parts = preg_split('/(?<=[,;])\s+(?=[^\d:,;()]{3,60}:\s*\S)/u', $line) ?: [$line];
        foreach ($parts as $i => $part) {
            $own = $label;
            if (($weak || count($parts) > 1) && preg_match('/^([^:\d]{3,60}):\s*\S/u', $part, $m)) {
                $own = $weak ? $m[1] : ($i === 0 && $label !== '' && str_starts_with($line, $label) ? $label : trim($label . ' / ' . $m[1], ' /'));
            }
            foreach (Params::extract($part, false)['params'] as $p) {
                $out[] = ['d' => (string) $p['dim'], 'lo' => (float) $p['lo'], 'hi' => (float) $p['hi'], 'label' => mb_substr($own, 0, 120),
                    'col' => mb_substr($col, 0, 40), 'head' => mb_substr($head, 0, 60), 'line' => mb_substr($line, 0, 160)];
            }
        }
    }

    /** One value per kind: the highest voltage, IP and CAT not ruled out by their context, the temperature ranges. */
    private static function aggregate(array $values, bool $explain): array
    {
        $out  = [];
        $keep = array_filter($values, fn ($v) => self::skip($v) === null);
        foreach (['v', 'ip', 'cat'] as $kind) {
            $best = null;
            foreach ($keep as $v) {
                if ($v['d'] === $kind && $v['hi'] > 0 && $v['hi'] >= self::BOUNDS[$kind][0] && $v['hi'] <= self::BOUNDS[$kind][1] && ($best === null || $v['hi'] > $best['hi'])) {
                    $best = $v;
                }
            }
            if ($best) {
                $out[$kind] = ['value' => $best['hi']] + ($explain ? ['src' => self::source($best)] : []);
            }
        }

        [$min, $max] = self::BOUNDS['c'];
        $work = $meas = [];
        foreach ($values as $v) {
            if ($v['d'] !== 'c' || $v['lo'] < $min || $v['hi'] > $max) {
                continue;
            }
            $context = self::context($v);
            $skip    = self::skip($v);
            if ($skip === 'quality' || preg_match(self::STORE, $context)) {
                continue;
            }
            if (preg_match(self::WORK, $context)) {
                $work[] = $v;
            } elseif ($skip === null && $v['lo'] !== $v['hi'] && !preg_match(self::REF, $context . ' ' . $v['line'])) {
                $meas[] = $v;
            }
        }
        foreach (['tw' => $work, 'tm' => $meas] as $kind => $list) {
            if ($list) {
                $lo = $hi = $list[0];
                foreach ($list as $v) {
                    $lo = $v['lo'] < $lo['lo'] ? $v : $lo;
                    $hi = $v['hi'] > $hi['hi'] ? $v : $hi;
                }
                // where the two ends come from (one line, or the two that give them)
                $src = $explain ? self::source($lo) . ($hi !== $lo && self::source($hi) !== self::source($lo) ? ' + ' . self::source($hi) : '') : '';
                $out[$kind] = ['value' => [$lo['lo'], $hi['hi']]] + ($explain ? ['src' => $src] : []);
            }
        }

        return $out;
    }

    /** Why a voltage, current or temperature is not a rating (null: it is one). IP and CAT are never ruled out. */
    private static function skip(array $v): ?string
    {
        if ($v['d'] === 'ip' || $v['d'] === 'cat') {
            return null;
        }
        $context = preg_replace(self::CATEGORY, 'kat', self::context($v) . ' | ' . $v['line']) ?? '';
        foreach (self::SKIP as $kind => $rx) {
            $where = $kind === 'quality' ? $v['label'] . ' ' . $v['col'] : $context;
            if (preg_match($rx, $where)) {
                return $kind;
            }
        }

        return null;
    }

    private static function context(array $v): string
    {
        return $v['label'] . ' | ' . $v['col'] . ' | ' . $v['head'];
    }

    /** Where a value comes from, for the settings preview: "Label (column): line". */
    private static function source(array $v): string
    {
        $where = $v['label'] !== '' ? $v['label'] : $v['head'];
        if ($v['col'] !== '') {
            $where .= ($where !== '' ? ' ' : '') . '(' . $v['col'] . ')';
        }

        return ($where !== '' && !str_starts_with($v['line'], $where) ? $where . ': ' : '') . $v['line'];
    }

    /** The text of a node split at <br> and at the end of each block. @return string[] */
    private static function lines(\DOMNode $node): array
    {
        $out  = [''];
        $walk = function (\DOMNode $n) use (&$walk, &$out): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMText) {
                    $out[count($out) - 1] .= $c->nodeValue;
                    continue;
                }
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($c->tagName);
                if (in_array($tag, self::HIDDEN, true)) {
                    continue;
                }
                if ($tag === 'br') {
                    $out[] = '';
                    continue;
                }
                $walk($c);
                if (in_array($tag, ['p', 'li', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'dt', 'dd'], true)) {
                    $out[] = '';
                }
            }
        };
        $walk($node);

        return array_values(array_filter(array_map([self::class, 'clean'], $out), 'strlen'));
    }

    /** @return string[] */
    private static function split(string $text): array
    {
        return array_values(array_filter(array_map([self::class, 'clean'], preg_split('/\R/u', $text) ?: []), 'strlen'));
    }

    private static function text(\DOMNode $node): string
    {
        return self::clean($node->textContent ?? '');
    }

    /** Single spaces; a no-break space between digits stays ("123 456 V" is one number to Params). */
    private static function clean(string $text): string
    {
        $text = preg_replace('/(?<!\d)[\x{00A0}\x{202F}]|[\x{00A0}\x{202F}](?!\d)/u', ' ', $text) ?? $text;

        return trim(preg_replace('/[^\S\x{00A0}\x{202F}]+/u', ' ', $text) ?? '');
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
