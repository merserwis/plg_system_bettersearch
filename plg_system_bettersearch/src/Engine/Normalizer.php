<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Text normalisation shared by the index and the query side. Everything is lower case, without
 * diacritics, reduced to the characters a-z and 0-9. Two forms are kept:
 *  - tokens:  "Metrel MI-3155 EurotestXD" -> metrel mi 3155 eurotestxd (+ variants mi3155)
 *  - compact: the same text without any separators -> metrelmi3155eurotestxd
 * A query "MI 3155", "MI3155" or "MI-3155" becomes the compact group "mi3155", found inside the
 * compact text whatever the spacing in the product description.
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

final class Normalizer
{
    private const FOLD = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a',
        'ç' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ñ' => 'n',
        'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ő' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŕ' => 'r', 'ř' => 'r',
        'š' => 's', 'ș' => 's', 'ş' => 's', 'ß' => 'ss', 'ť' => 't', 'ț' => 't', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ů' => 'u', 'ű' => 'u', 'ū' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'æ' => 'ae', 'œ' => 'oe',
        'µ' => 'u', 'μ' => 'u', 'Ω' => 'ohm', 'ω' => 'ohm', '²' => '2', '³' => '3', '½' => '1 2',
    ];

    /**
     * Endings tried in this order by the light stemmer (written folded: no diacritics). The Polish set
     * (with a few English plurals) is always used — the content of a shop is often not in the language
     * Joomla runs in — and the set of the site language is added to it.
     */
    private const SUFFIXES = [
        'pl' => ['owego', 'owych', 'owymi', 'iami', 'ach', 'ami', 'owi', 'owa', 'owe', 'owy', 'ego', 'emu', 'ych', 'ymi', 'imi',
            'iem', 'iach', 'om', 'ow', 'ie', 'ia', 'ii', 'ej', 'ym', 'im', 'es', 'a', 'e', 'i', 'y', 'u', 'o', 's'],
        'en' => ['ings', 'ing', 'ies', 'es', 'ed', 's'],
        'de' => ['ern', 'em', 'en', 'er', 'es', 'e', 's'],
        'fr' => ['euses', 'euse', 'eaux', 'aux', 'ees', 'ee', 'es', 's', 'x', 'e'],
        'cs' => ['ovych', 'ovymi', 'atech', 'etech', 'ovou', 'emi', 'ami', 'ach', 'ech', 'ich', 'ove', 'ovi', 'ovy', 'eho', 'emu',
            'ymi', 'imi', 'ych', 'ou', 'um', 'em', 'om', 'am', 'ho', 'a', 'e', 'i', 'o', 'u', 'y', 's'],
        'nl' => ['heden', 'ingen', 'etjes', 'tjes', 'jes', 'tje', 'je', 'en', 'es', 's', 'e'],
    ];

    /** Words ignored in queries when the administrator keeps the built-in list: Polish, English and the site language. */
    private const STOPWORDS = [
        'pl' => 'i, w, z, ze, na, do, dla, od, po, o, u, a, oraz, lub, czy',
        'en' => 'the, and, of, for, with, to, in, a, an, or, on, by',
        'de' => 'der, die, das, den, dem, des, ein, eine, einen, einem, einer, und, oder, mit, für, von, vom, zu, zum, zur, im, in, am, an, auf, aus, bei',
        'fr' => 'le, la, les, l, un, une, des, du, de, d, et, ou, pour, avec, en, au, aux, à, sur, par',
        'cs' => 'a, i, v, ve, s, se, z, ze, na, do, pro, od, po, o, u, k, ke, nebo, či',
        'nl' => 'de, het, een, en, of, van, voor, met, in, op, aan, te, bij, uit, door',
    ];

    /** @var array<string, bool> */
    private array $stopwords;

    /** @var array<int, string[]> */
    private array $suffixSets = [self::SUFFIXES['pl']];

    private bool $builtinStopwords;

    /**
     * @param string[] $stopwords  the administrator's list; empty = the built-in list of the site language
     *                             (applied by forLanguage())
     */
    public function __construct(array $stopwords = [])
    {
        $this->stopwords        = array_fill_keys(array_filter(array_map(fn ($w) => $this->fold($w), $stopwords)), true);
        $this->builtinStopwords = !$this->stopwords;
        if ($this->builtinStopwords) {
            $this->stopwords = $this->words(self::STOPWORDS['pl'] . ',' . self::STOPWORDS['en']);
        }
    }

    /** A copy with the endings and (unless the administrator has an own list) the ignored words of a language tag. */
    public function forLanguage(string $tag): self
    {
        $lang = strtolower(substr($tag, 0, 2));
        $lang = isset(self::SUFFIXES[$lang]) ? $lang : 'en';
        $copy = clone $this;
        $copy->suffixSets = $lang === 'pl' ? [self::SUFFIXES['pl']] : [self::SUFFIXES['pl'], self::SUFFIXES[$lang]];
        if ($this->builtinStopwords) {
            $copy->stopwords = $this->words(self::STOPWORDS['pl'] . ',' . self::STOPWORDS['en'] . ',' . self::STOPWORDS[$lang]);
        }

        return $copy;
    }

    /** @return array<string, bool> */
    private function words(string $list): array
    {
        return array_fill_keys(array_filter(array_map(fn ($w) => $this->fold(trim($w)), explode(',', $list))), true);
    }

    /**
     * Removes HTML tags and comments. strip_tags() is not used: it cuts the text at any "<" that
     * looks like a tag start, so "<1 kV" or "Przewód <50 V" would lose everything after it.
     */
    public static function stripTags(string $text): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }

        return preg_replace('#<(?:/?[a-zA-Z][a-zA-Z0-9:-]*(?:\s[^<>]*)?/?|!--.*?--|!DOCTYPE[^>]*|\?[^>]*\?)>#su', ' ', $text) ?? $text;
    }

    public function isStopword(string $term): bool
    {
        return isset($this->stopwords[$term]);
    }

    /** Lower case, without diacritics, every other character turned into a space. */
    public function fold(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = html_entity_decode(self::stripTags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);
        // remaining Latin letters with marks (from any language) through the intl/iconv route when present
        if (preg_match('/[^\x00-\x7F]/', $text)) {
            if (class_exists(\Normalizer::class)) {
                $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
                if (is_string($decomposed) && $decomposed !== '') {
                    $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?: $text;
                }
            }
        }

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }

    /** All separators removed: "MI-3155 XD" -> "mi3155xd". */
    public function compact(string $text): string
    {
        return str_replace(' ', '', $this->fold($text));
    }

    /** @return string[] tokens of the text, in order, duplicates kept */
    public function tokens(string $text): array
    {
        $folded = $this->fold($text);

        return $folded === '' ? [] : explode(' ', $folded);
    }

    /**
     * Index tokens: the tokens of the text plus the variants a visitor may type — letters and digits
     * split ("mi3155" -> mi, 3155) and neighbours joined when one of them carries digits
     * ("mi 3155" -> mi3155, "1000 v" -> 1000v). Returned as " tok tok tok " for LIKE '% tok%'.
     */
    public function indexTokens(string $text, int $limit = 0): string
    {
        $tokens = $this->tokens($text);
        if ($limit > 0 && count($tokens) > $limit) {
            $tokens = array_slice($tokens, 0, $limit);
        }

        $out = [];
        $n   = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t       = $tokens[$i];
            $out[$t] = true;
            if (preg_match('/[a-z][0-9]|[0-9][a-z]/', $t)) {
                foreach (preg_split('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/', $t) ?: [] as $part) {
                    $out[$part] = true;
                }
            }
            if ($i + 1 < $n && $this->joinable($t, $tokens[$i + 1])) {
                $out[$t . $tokens[$i + 1]] = true;
                if ($i + 2 < $n && $this->joinable($tokens[$i + 1], $tokens[$i + 2]) && strlen($t . $tokens[$i + 1] . $tokens[$i + 2]) <= 16) {
                    $out[$t . $tokens[$i + 1] . $tokens[$i + 2]] = true;
                }
            }
        }

        return $out ? ' ' . implode(' ', array_keys($out)) . ' ' : '';
    }

    /** Two neighbouring tokens that form one model code when written together ("mi"+"3155", "3155"+"xd"). */
    public function joinable(string $a, string $b): bool
    {
        if (strlen($a . $b) > 14) {
            return false;
        }
        $digitsA = (bool) preg_match('/[0-9]/', $a);
        $digitsB = (bool) preg_match('/[0-9]/', $b);
        if (!$digitsA && !$digitsB) {
            return false;
        }

        // a short prefix or suffix of letters next to a number, or two numbers (e.g. "1 000")
        return ($digitsA && $digitsB) || (!$digitsA && strlen($a) <= 4) || (!$digitsB && strlen($b) <= 4);
    }

    /**
     * Query groups: each group must be found in a product. Neighbouring parts of a model code are
     * one group ("MI 3155" -> [mi3155]); other words are groups of their own. Stopwords are dropped
     * unless the query has nothing else.
     *
     * @return array<int, array{term: string, digits: bool, parts: string[]}>
     */
    public function queryGroups(string $query, bool $merge = true): array
    {
        $tokens = $this->tokens($query);
        $groups = [];
        $n      = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $parts = [$tokens[$i]];
            while ($merge && $i + 1 < $n && $this->joinable(end($parts), $tokens[$i + 1]) && strlen(implode('', $parts) . $tokens[$i + 1]) <= 16) {
                $parts[] = $tokens[++$i];
            }
            $term     = implode('', $parts);
            $groups[] = ['term' => $term, 'digits' => (bool) preg_match('/[0-9]/', $term), 'parts' => $parts];
        }

        $kept = array_values(array_filter($groups, fn ($g) => count($g['parts']) > 1 || !isset($this->stopwords[$g['term']])));
        $kept = $kept ?: $groups;

        // the same group twice adds nothing
        $seen = [];

        return array_values(array_filter($kept, function ($g) use (&$seen) {
            if (isset($seen[$g['term']])) {
                return false;
            }

            return $seen[$g['term']] = true;
        }));
    }

    /**
     * Light stem for words of 6+ letters: "miernikow" -> "miernik", "izolacji" -> "izolacj". Short
     * words (often brands: "testo", "fluke") are left alone.
     */
    public function stem(string $term): string
    {
        return $this->stems($term)[0] ?? $term;
    }

    /**
     * The stems of a word, one per set of endings (Polish first, then the site language), without
     * repeats and without the word itself.
     *
     * @return string[]
     */
    public function stems(string $term): array
    {
        if (strlen($term) < 6 || preg_match('/[0-9]/', $term)) {
            return [];
        }
        $out = [];
        foreach ($this->suffixSets as $suffixes) {
            foreach ($suffixes as $suffix) {
                if (str_ends_with($term, $suffix) && strlen($term) - strlen($suffix) >= 5) {
                    $out[substr($term, 0, -strlen($suffix))] = true;
                    break;
                }
            }
        }

        return array_keys($out);
    }
}
