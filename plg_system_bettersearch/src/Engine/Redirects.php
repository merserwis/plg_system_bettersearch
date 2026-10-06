<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Redirects of Joomla (the Redirects component, #__redirect_links) as old names of products: a
 * product replaced by another one is usually removed and its address redirected to the new one.
 * A search for the old product's name then finds the new product, marked "Replaced".
 *
 * Each published redirect whose target (followed through chains of redirects) is an indexed page
 * gives an old name: the title of the page the old address belonged to (when it is still in
 * Gridbox, e.g. unpublished or trashed), else the words of the old address; the redirect's note
 * is one more name. The list is kept in #__bettersearch_state and built again when the redirects
 * or the index change.
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class Redirects
{
    /** Redirects read at most (the oldest ones beyond it are left out). */
    private const MAX = 20000;

    /** Redirect chains followed at most (A → B → C). */
    private const HOPS = 5;

    /** @var array<string, array>|null per request: signature => entries */
    private static ?array $memo = null;

    /**
     * Checksum of the published redirects; '' when there are none or the table is missing (the
     * Redirects component is not installed).
     */
    public static function signature(DatabaseInterface $db): string
    {
        try {
            $row = $db->setQuery('SELECT COUNT(*), IFNULL(SUM(CRC32(CONCAT_WS(' . $db->quote('|') . ', id, old_url, IFNULL(new_url, ' . $db->quote('') . '), comment))), 0)'
                . ' FROM ' . $db->quoteName('#__redirect_links') . ' WHERE published = 1')->loadRow();
        } catch (\Throwable $e) {
            return '';
        }

        return $row && (int) $row[0] > 0 ? substr(md5(implode('|', $row)), 0, 12) : '';
    }

    /**
     * Old names of the indexed pages.
     *
     * @return array<int, array{id: int, name: string, t: string, c: string}>
     */
    public static function entries(DatabaseInterface $db, Normalizer $norm): array
    {
        $sig = self::signature($db);
        if ($sig === '') {
            return [];
        }
        $state = self::state($db, 'version') ?? '0';
        $key   = $sig . '|' . $state;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $stored = json_decode((string) self::state($db, 'redirects'), true);
        if (is_array($stored) && ($stored['key'] ?? '') === $key && is_array($stored['list'] ?? null)) {
            return self::$memo[$key] = $stored['list'];
        }

        $list = self::build($db, $norm);
        try {
            $db->setQuery('REPLACE INTO ' . $db->quoteName('#__bettersearch_state') . ' (' . $db->quoteName('k') . ', ' . $db->quoteName('v') . ') VALUES ('
                . $db->quote('redirects') . ', ' . $db->quote(json_encode(['key' => $key, 'list' => $list], JSON_UNESCAPED_UNICODE)) . ')')->execute();
        } catch (\Throwable $e) {
        }

        return self::$memo[$key] = $list;
    }

    /** @return array<int, array{id: int, name: string, t: string, c: string}> */
    private static function build(DatabaseInterface $db, Normalizer $norm): array
    {
        try {
            $rows = $db->setQuery('SELECT old_url, new_url, comment FROM ' . $db->quoteName('#__redirect_links')
                . ' WHERE published = 1 AND new_url IS NOT NULL AND new_url <> ' . $db->quote('') . ' ORDER BY id DESC', 0, self::MAX)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        // old address => new address, to follow chains
        $next = [];
        foreach ($rows as $row) {
            $from = self::key((string) $row->old_url);
            if ($from !== '' && !isset($next[$from])) {
                $next[$from] = (string) $row->new_url;
            }
        }

        $links = [];
        foreach ($rows as $row) {
            $target = (string) $row->new_url;
            $seen   = [self::key((string) $row->old_url) => true];
            for ($hop = 0; $hop < self::HOPS; $hop++) {
                $k = self::key($target);
                if ($k === '' || isset($seen[$k]) || !isset($next[$k])) {
                    break;
                }
                $seen[$k] = true;
                $target   = $next[$k];
            }
            $old = self::page((string) $row->old_url);
            $new = self::page($target);
            if ($old === null || $new === null) {
                continue;
            }
            $links[] = ['old' => $old, 'new' => $new, 'note' => trim((string) $row->comment)];
        }
        if (!$links) {
            return [];
        }

        // pages by alias (old: any page, also unpublished or trashed; new: indexed pages only)
        $aliases = [];
        $ids     = [];
        foreach ($links as $link) {
            foreach (['old', 'new'] as $side) {
                if ($link[$side]['id'] > 0) {
                    $ids[] = $link[$side]['id'];
                } elseif ($link[$side]['alias'] !== '') {
                    $aliases[] = $link[$side]['alias'];
                }
            }
        }
        $byAlias = [];
        $byId    = [];
        foreach (array_chunk(array_values(array_unique($aliases)), 500) as $chunk) {
            $sql = 'SELECT p.id, p.title, p.page_alias, (i.id IS NOT NULL) AS indexed FROM ' . $db->quoteName('#__gridbox_pages', 'p')
                . ' LEFT JOIN ' . $db->quoteName('#__bettersearch_items', 'i') . ' ON i.id = p.id'
                . ' WHERE p.page_alias IN (' . implode(',', array_map([$db, 'quote'], $chunk)) . ') ORDER BY p.published DESC, p.id DESC';
            foreach ($db->setQuery($sql)->loadObjectList() ?: [] as $page) {
                $byAlias[mb_strtolower((string) $page->page_alias)][] = $page;
            }
        }
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $sql = 'SELECT p.id, p.title, p.page_alias, (i.id IS NOT NULL) AS indexed FROM ' . $db->quoteName('#__gridbox_pages', 'p')
                . ' LEFT JOIN ' . $db->quoteName('#__bettersearch_items', 'i') . ' ON i.id = p.id WHERE p.id IN (' . implode(',', array_map('intval', $chunk)) . ')';
            foreach ($db->setQuery($sql)->loadObjectList() ?: [] as $page) {
                $byId[(int) $page->id] = $page;
            }
        }
        $find = function (array $ref) use ($byAlias, $byId): array {
            if ($ref['id'] > 0) {
                return isset($byId[$ref['id']]) ? [$byId[$ref['id']]] : [];
            }

            return $byAlias[$ref['alias']] ?? [];
        };

        $out  = [];
        $seen = [];
        foreach ($links as $link) {
            // the target: an indexed page (with the same alias in several apps: the first indexed one)
            $target = null;
            foreach ($find($link['new']) as $page) {
                if ((int) $page->indexed) {
                    $target = $page;
                    break;
                }
            }
            if ($target === null) {
                continue;
            }
            $oldPages = $find($link['old']);
            $names    = [];
            if ($oldPages && (int) $oldPages[0]->id !== (int) $target->id) {
                $names[] = (string) $oldPages[0]->title;
            } elseif (!$oldPages) {
                $names[] = $link['old']['words'];
            }
            if ($link['note'] !== '') {
                $names[] = $link['note'];
            }
            foreach ($names as $name) {
                $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
                $c    = $norm->compact($name);
                if ($c === '' || isset($seen[$target->id . '|' . $c]) || $c === $norm->compact((string) $target->title)) {
                    continue;
                }
                $seen[$target->id . '|' . $c] = true;
                $out[] = ['id' => (int) $target->id, 'name' => mb_substr($name, 0, 200), 't' => $norm->indexTokens($name), 'c' => mb_substr($c, 0, 1000)];
            }
        }

        return $out;
    }

    /** An address without the scheme, host, suffix and trailing slash, for comparing addresses. */
    private static function key(string $url): string
    {
        $url   = trim($url);
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }
        $path = mb_strtolower(trim(rawurldecode((string) ($parts['path'] ?? '')), '/'));
        $path = preg_replace('#\.(html?|php)$#', '', $path) ?? $path;
        $path = preg_replace('#^index$#', '', $path) ?? $path;

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The page an address points at: a Gridbox page id (non-SEF address) or the alias of the last
     * part of the path, with the words of that part.
     *
     * @return array{id: int, alias: string, words: string}|null
     */
    private static function page(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (($query['option'] ?? '') === 'com_gridbox' && ($query['view'] ?? '') === 'page' && (int) ($query['id'] ?? 0) > 0) {
            return ['id' => (int) $query['id'], 'alias' => '', 'words' => ''];
        }
        $path  = trim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        $last  = (string) substr((string) strrchr('/' . $path, '/'), 1);
        $last  = preg_replace('#\.(html?|php)$#i', '', $last) ?? $last;
        if ($last === '' || $last === 'index') {
            return null;
        }

        return ['id' => 0, 'alias' => mb_strtolower($last), 'words' => trim(str_replace(['-', '_', '+'], ' ', $last))];
    }

    private static function state(DatabaseInterface $db, string $key): ?string
    {
        try {
            $value = $db->setQuery('SELECT v FROM ' . $db->quoteName('#__bettersearch_state') . ' WHERE k = ' . $db->quote($key))->loadResult();
        } catch (\Throwable $e) {
            return null;
        }

        return $value === null ? null : (string) $value;
    }
}
