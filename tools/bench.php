<?php

/**
 * Regression bench of the search engine, on a Joomla site with Better Search (run inside it, as its CLI):
 * every query of a list searched as a guest of the site with the saved settings, for each sort, and
 * printed as JSON lines (total, mode, the first ids, whether the candidate limit was reached, time).
 * Two runs of the same list (before and after a change of the engine) are compared by tools/bench_diff.py.
 *
 *   php bench.php queries.txt [sorts=relevance,price] [settings.json overriding saved settings] > run.jsonl
 *
 * Environment: BS_HOST the site's own host (www.example.com), BS_SRC another copy of the plugin's src
 * (e.g. of the version before the change), JOOMLA_ROOT the site, BS_EXPLAIN=n the score and its reasons
 * of the first n results (and only the first sort).
 *
 * queries.txt: one query per line; lines starting with # are skipped.
 */

const _JEXEC = 1;

// a CLI run has no request: the site address for the code that asks for it. It matters: products found by
// their old names (Joomla redirects) count only for redirects of the site's own address (BS_HOST)
$_SERVER['HTTP_HOST'] = getenv('BS_HOST') ?: 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$root = getenv('JOOMLA_ROOT') ?: '/var/www/html';
define('JPATH_BASE', $root);
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\BetterSearch\Engine\Normalizer;
use Merserwis\Plugin\System\BetterSearch\Engine\Searcher;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
Factory::$application = $container->get(\Joomla\Console\Application::class);
\JLoader::registerNamespace('Merserwis\\Plugin\\System\\BetterSearch', getenv('BS_SRC') ?: JPATH_PLUGINS . '/system/bettersearch/src');

$db     = $container->get(DatabaseInterface::class);
$saved  = $db->setQuery("SELECT params FROM #__extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'bettersearch'")->loadResult();
$params = new Registry((string) $saved);
if (!empty($argv[3])) {
    $params->merge(new Registry((string) file_get_contents($argv[3])));
}
$sorts    = array_filter(explode(',', $argv[2] ?? 'relevance,price'));
$language = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
$levels   = array_map('intval', Access::getAuthorisedViewLevels(0)) ?: [1];
$words    = trim((string) $params->get('stopwords', ''));
$norm     = new Normalizer($words === '' ? [] : array_map('trim', explode(',', $words)));

foreach (file($argv[1] ?? 'php://stdin', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $q = trim($line);
    if ($q === '' || $q[0] === '#') {
        continue;
    }
    foreach ($sorts as $sort) {
        $ms = [];
        for ($i = 0; $i < 2; $i++) {
            // a new searcher each time: nothing of the first run is kept for the second (but the database's own cache)
            $searcher = new Searcher($db, $params, $norm, $levels, $language);
            $result   = $searcher->search($q, $sort, [], (bool) getenv('BS_EXPLAIN'));
            $ms[]     = $result['ms'];
        }
        $items = $result['items'];
        echo json_encode([
            'q' => $q, 'sort' => $sort, 'total' => $result['total'], 'mode' => $result['mode'], 'corrected' => $result['corrected'],
            'truncated' => !empty($result['truncated']), 'ms' => min($ms),
            'ids' => array_map(fn ($it) => (int) $it['id'], array_slice($items, 0, 30)),
            'prices' => array_map(fn ($it) => $it['price'] ?? null, array_slice($items, 0, 10)),
        ] + (getenv('BS_EXPLAIN') ? ['why' => array_map(fn ($it) => [$it['id'], mb_substr($it['title'], 0, 50), $it['score'], $it['strong'] ?? null,
            implode('; ', $it['reasons'])], array_slice($items, 0, (int) getenv('BS_EXPLAIN')))] : []), JSON_UNESCAPED_UNICODE | (getenv('BS_EXPLAIN') ? JSON_PRETTY_PRINT : 0)), "\n";
        if (getenv('BS_EXPLAIN')) {
            break;
        }
    }
}
