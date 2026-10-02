<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Search engine settings of the results page, applied to the finished HTML: title, description,
 * robots, canonical address, Open Graph title, structured data of the results; on other pages the
 * OpenSearch link and the (optional) WebSite SearchAction.
 *
 * Every value is escaped for its place (attribute, text, JSON inside a script element); tags are
 * only touched inside <head>.
 */

namespace Merserwis\Plugin\System\BetterSearch\Render;

\defined('_JEXEC') or die;

final class Seo
{
    /** JSON safe inside <script>: "<", ">", "&", quotes as \u escapes. */
    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private string $head;

    private string $rest;

    public function __construct(string $body)
    {
        $pos = stripos($body, '</head>');
        if ($pos === false) {
            $this->head = '';
            $this->rest = $body;
        } else {
            $this->head = substr($body, 0, $pos);
            $this->rest = substr($body, $pos);
        }
    }

    public function body(): string
    {
        return $this->head . $this->rest;
    }

    public function hasHead(): bool
    {
        return $this->head !== '';
    }

    public static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The document title (the first <title> element of the head). */
    public function title(string $title): void
    {
        $html = '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';
        $new  = preg_replace('#<title\b[^>]*>.*?</title>#is', strtr($html, ['\\' => '\\\\', '$' => '\\$']), $this->head, 1, $count);
        if ($new !== null && $count) {
            $this->head = $new;
        } else {
            $this->add($html);
        }
    }

    /** A <meta name="…"> (or property="…" for Open Graph) element, replaced when the page has one. */
    public function meta(string $name, string $content, string $attribute = 'name'): void
    {
        $this->replaceOrAdd(
            '#<meta\b[^>]*\b' . $attribute . '\s*=\s*["\']' . preg_quote($name, '#') . '["\'][^>]*>#i',
            '<meta ' . $attribute . '="' . self::attr($name) . '" content="' . self::attr($content) . '">'
        );
    }

    /** The meta element is updated only when the page already has it (Open Graph of the theme). */
    public function metaIfPresent(string $name, string $content, string $attribute = 'property'): void
    {
        $pattern = '#<meta\b[^>]*\b' . $attribute . '\s*=\s*["\']' . preg_quote($name, '#') . '["\'][^>]*>#i';
        if (preg_match($pattern, $this->head)) {
            $this->meta($name, $content, $attribute);
        }
    }

    public function canonical(string $url): void
    {
        $this->replaceOrAdd('#<link\b[^>]*\brel\s*=\s*["\']canonical["\'][^>]*>#i', '<link rel="canonical" href="' . self::attr($url) . '">');
    }

    /** Structured data: one JSON-LD script element. */
    public function jsonLd(array $data): void
    {
        $json = json_encode($data, self::JSON_FLAGS);
        if (is_string($json)) {
            $this->add('<script type="application/ld+json">' . $json . '</script>');
        }
    }

    /** Appended at the end of the head. */
    public function add(string $html): void
    {
        if ($this->head !== '') {
            $this->head .= $html;
        }
    }

    private function replaceOrAdd(string $pattern, string $html): void
    {
        $count = 0;
        $new   = preg_replace($pattern, strtr($html, ['\\' => '\\\\', '$' => '\\$']), $this->head, -1, $count);
        if ($new !== null && $count) {
            // the first element stays (with the new value), any duplicates go
            $first = true;
            $this->head = preg_replace_callback($pattern, function ($m) use (&$first, $html) {
                if ($first) {
                    $first = false;

                    return $html;
                }

                return '';
            }, $this->head) ?? $new;
        } else {
            $this->add($html);
        }
    }

    /**
     * Title or description from a pattern: {query}, {count}, {site}. The query is cut to a length
     * search engines show.
     */
    public static function fill(string $pattern, string $query, int $count, string $site): string
    {
        $query = mb_strlen($query) > 60 ? rtrim(mb_substr($query, 0, 57)) . '…' : $query;

        return trim(strtr($pattern, ['{query}' => $query, '{count}' => (string) $count, '{site}' => $site]));
    }

    /** At most 16 characters (OpenSearch), cut at a word end rather than inside "Name (". */
    public static function shortName(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) <= 16) {
            return $name;
        }
        $cut   = mb_substr($name, 0, 16);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space >= 6 ? mb_substr($cut, 0, $space) : $cut, " \t-–—(:,;.");
    }

    /** OpenSearch description of the site search (browsers offer it as a search engine). */
    public static function openSearchXml(string $shortName, string $description, string $template, string $icon): string
    {
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">'
            . '<ShortName>' . $x(self::shortName($shortName)) . '</ShortName>'
            . '<Description>' . $x(mb_substr($description, 0, 1024)) . '</Description>'
            . '<InputEncoding>UTF-8</InputEncoding>'
            . ($icon !== '' ? '<Image width="16" height="16" type="image/x-icon">' . $x($icon) . '</Image>' : '')
            . '<Url type="text/html" method="get" template="' . $x($template) . '"/>'
            . '</OpenSearchDescription>';
    }
}
