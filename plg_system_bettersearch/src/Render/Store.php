<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Gridbox store data for the result cards: product prices after store sales, in the currency the
 * visitor picked, formatted like Gridbox; product and category links with the Itemid Gridbox
 * would choose; intro images.
 */

namespace Merserwis\Plugin\System\BetterSearch\Render;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

final class Store
{
    private DatabaseInterface $db;

    private CMSApplicationInterface $app;

    /** @var int[] */
    private array $levels;

    /** @var object|null|false */
    private $currency = false;

    private ?array $sales = null;

    private ?array $menu = null;

    /** @var array<int, object>|null */
    private ?array $categories = null;

    /** @var array<int, string> */
    private array $appTitles = [];

    /** @var array<int, string> */
    private array $appTypes = [];

    /** Administrator preview: links lead nowhere, image addresses are absolute (the site, not /administrator). */
    private bool $preview = false;

    public function setPreview(bool $preview): void
    {
        $this->preview = $preview;
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    /** A Joomla link routed for the site (or "#" in the preview). */
    public function route(string $link): string
    {
        return $this->preview ? '#' : Route::_($link, false);
    }

    public function __construct(DatabaseInterface $db, CMSApplicationInterface $app, array $levels)
    {
        $this->db     = $db;
        $this->app    = $app;
        $this->levels = array_map('intval', $levels) ?: [1];
    }

    /** Memoised category menu items and resolved intro images of this request. */
    private array $catItemids = [];

    /** @var array<int, string> */
    private array $uploads = [];

    /** @var array<int, array{0: string, 1: string}> routed product and category links, by page id */
    private array $links = [];

    private bool $linksDirty = false;

    /** @var object|null Joomla cache controller holding the routed links */
    private $linkCache = null;

    private string $linkKey = '';

    /** Links already built in earlier requests: a Gridbox route costs several queries each. */
    public function setLinkCache($cache, string $key): void
    {
        $this->linkCache = $cache;
        $this->linkKey   = $key;
        $hit = $cache->get($key);
        $this->links = is_array($hit) ? $hit : [];
    }

    /** Writes the links built in this request back to the cache (once, at the end of the request). */
    public function saveLinks(): void
    {
        if ($this->linkCache && $this->linksDirty) {
            // bounded: the products shown most recently
            if (count($this->links) > 4000) {
                $this->links = array_slice($this->links, -3000, null, true);
            }
            try {
                $this->linkCache->store($this->links, $this->linkKey);
            } catch (\Throwable $e) {
            }
            $this->linksDirty = false;
        }
    }

    /**
     * Only pages a visitor may see now: the same rules as the search (published, live dates,
     * language, access level, not trashed) — a page unpublished after a result was cached is dropped.
     */
    private function visible($query, string $alias = 'p'): void
    {
        $db   = $this->db;
        $now  = $db->quote(gmdate('Y-m-d H:i:s'));
        $null = $db->quote($db->getNullDate());
        $lang = $this->app->getLanguage()->getTag();
        $query->where("$alias.published = 1")
            ->where("$alias.created <= " . $now)
            ->where("($alias.end_publishing = " . $null . " OR $alias.end_publishing >= " . $now . ')')
            ->where("$alias.language IN (" . $db->quote($lang) . ', ' . $db->quote('*') . ')')
            ->where("$alias.page_access IN (" . implode(',', $this->levels) . ')')
            ->where("$alias.page_category <> " . $db->quote('trashed'));
    }

    /**
     * Everything a result card shows, for the given page ids (order kept).
     *
     * @param int[] $ids
     *
     * @return array<int, object>
     */
    public function items(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $db    = $this->db;
        $list  = implode(',', $ids);
        $query = $db->createQuery()
            ->select(['p.id', 'p.title', 'p.app_id', 'p.page_category', 'p.intro_image', 'p.image_alt', 'p.hits', 'p.created',
                'i.excerpt', 'i.in_stock', 'd.sku', 'd.price', 'd.sale_price', 'd.stock', 'd.variations', 'd.product_type'])
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->leftJoin($db->quoteName('#__bettersearch_items', 'i') . ' ON i.id = p.id')
            ->leftJoin($db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id')
            ->where('p.id IN (' . $list . ')');
        $this->visible($query);
        $rows = $db->setQuery($query)->loadObjectList('id') ?: [];
        if (!$rows) {
            return [];
        }
        // uploaded field images are stored by number: one lookup for the whole page of results
        $this->loadUploads(array_filter(array_map(fn ($r) => trim((string) $r->intro_image), $rows), 'is_numeric'));

        $mapped = [];
        $query  = $db->createQuery()
            ->select(['page_id', 'category_id'])
            ->from($db->quoteName('#__gridbox_category_page_map'))
            ->where('page_id IN (' . $list . ')');
        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $mapped[(int) $row->page_id][] = (int) $row->category_id;
        }

        $cats = $this->categories();
        $out  = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if (!$row) {
                continue;
            }
            $catId           = (int) $row->page_category;
            $row->id         = (int) $row->id;
            $row->app_id     = (int) $row->app_id;
            $row->category   = $cats[$catId]->title ?? '';
            $row->categoryId = $catId;
            if ($this->preview) {
                $row->link    = '#';
                $row->catLink = '#';
            } elseif (isset($this->links[$row->id])) {
                [$row->link, $row->catLink] = $this->links[$row->id];
            } else {
                $row->link    = $this->route($this->pageLink($row->id, $row->app_id, $catId));
                $row->catLink = $catId > 0 ? $this->route($this->categoryLink($row->app_id, $catId)) : '';
                $this->links[$row->id] = [$row->link, $row->catLink];
                $this->linksDirty      = true;
            }
            // an address that is not an image address (e.g. climbing out of its folder) is no image
            $row->image      = $this->introImage((string) $row->intro_image);
            if ($row->image !== '' && $this->imageUrl($row->image) === '') {
                $row->image = '';
            }
            $row->isProduct  = $this->appType($row->app_id) === 'products' && $row->product_type !== null;
            // store sales on categories apply to the product's own category and its parents (as Gridbox does)
            $row->prices     = $row->isProduct ? $this->prices($row, $this->categoryPathIds($catId)) : null;
            $out[$id]        = $row;
        }

        return $out;
    }

    // ---------------------------------------------------------------- prices

    /**
     * Regular and current price, formatted (null when the product has no price).
     *
     * @return array{regular: string, price: string, sale: bool, from: bool}|null
     */
    private function prices(object $row, array $categories): ?array
    {
        $candidates = [['', (string) $row->price, (string) $row->sale_price]];
        $variations = json_decode((string) $row->variations);
        if (is_object($variations)) {
            foreach ($variations as $key => $variation) {
                if (is_object($variation)) {
                    $candidates[] = [(string) $key, (string) ($variation->price ?? ''), (string) ($variation->sale_price ?? '')];
                }
            }
        }

        $best    = null;
        $values  = [];
        foreach ($candidates as [$variation, $price, $sale]) {
            if (!is_numeric($price) || (float) $price <= 0) {
                continue;
            }
            $current  = is_numeric($sale) && (float) $sale > 0 ? (float) $sale : $this->salePrice((float) $price, (int) $row->id, $variation, $categories);
            $values[] = $current;
            if ($best === null || $current < $best[1]) {
                $best = [(float) $price, $current];
            }
        }
        if ($best === null) {
            return null;
        }

        return [
            'regular' => $this->format($best[0]),
            'price'   => $this->format($best[1]),
            'sale'    => $best[1] < $best[0],
            'from'    => count(array_unique(array_map(fn ($v) => round($v, 2), $values))) > 1,
        ];
    }

    /** Price after the store sales: every applicable sale is computed from the regular price and the last one wins (Gridbox's rule). */
    private function salePrice(float $price, int $productId, string $variation, array $categories): float
    {
        $result = $price;
        foreach ($this->sales() as $sale) {
            if (empty($sale->discount)) {
                continue;
            }
            $applies = match ((string) $sale->applies_to) {
                '*'        => true,
                'category' => (bool) array_intersect_key($sale->catSet, array_flip($categories)),
                'product'  => isset($sale->prodSet[$productId . '|' . $variation]),
                default    => false,
            };
            if ($applies) {
                $result = $price - ($sale->unit === '%' ? $price * ((float) $sale->discount / 100) : (float) $sale->discount);
            }
        }

        return $result;
    }

    private function sales(): array
    {
        if ($this->sales !== null) {
            return $this->sales;
        }
        $this->sales = [];
        try {
            $db    = $this->db;
            $tz    = new \DateTimeZone((string) $this->app->get('offset', 'UTC') ?: 'UTC');
            $now   = $db->quote((new \DateTime('now', $tz))->format('Y-m-d H:i:s'));
            $null  = $db->quote($db->getNullDate());
            $query = $db->createQuery()
                ->select('*')
                ->from($db->quoteName('#__gridbox_store_sales'))
                ->where('published = 1')
                ->where('(publish_down = ' . $null . ' OR publish_down IS NULL OR publish_down >= ' . $now . ')')
                ->where('(publish_up = ' . $null . ' OR publish_up IS NULL OR publish_up <= ' . $now . ')')
                ->where('access IN (' . implode(',', $this->levels) . ')')
                ->order('id ASC');
            $this->sales = $db->setQuery($query)->loadObjectList('id') ?: [];
            if ($this->sales) {
                // the product / category maps of all sales in one query, indexed for O(1) checks
                foreach ($this->sales as $sale) {
                    $sale->catSet  = [];
                    $sale->prodSet = [];
                }
                $query = $db->createQuery()
                    ->select(['sale_id', 'item_id', 'variation'])
                    ->from($db->quoteName('#__gridbox_store_sales_map'))
                    ->where('sale_id IN (' . implode(',', array_map('intval', array_keys($this->sales))) . ')');
                foreach ($db->setQuery($query)->loadObjectList() ?: [] as $m) {
                    $sale = $this->sales[(int) $m->sale_id] ?? null;
                    if ($sale) {
                        $sale->catSet[(int) $m->item_id] = true;
                        $sale->prodSet[(int) $m->item_id . '|' . (string) $m->variation] = true;
                    }
                }
                $this->sales = array_values($this->sales);
            }
        } catch (\Throwable $e) {
            $this->sales = [];
        }

        return $this->sales;
    }

    /** The store currency the visitor sees (default, the language's, or the one picked in the currency switcher). */
    private function currency(): ?object
    {
        if ($this->currency !== false) {
            return $this->currency;
        }
        $this->currency = null;
        try {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName('key'))
                ->from($this->db->quoteName('#__gridbox_api'))
                ->where($this->db->quoteName('service') . ' = ' . $this->db->quote('store'));
            $store = json_decode((string) $this->db->setQuery($query)->loadResult());
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($store)) {
            return null;
        }

        $list     = is_object($store->currencies ?? null) ? (array) ($store->currencies->list ?? []) : [];
        $currency = null;
        foreach ($list as $item) {
            if (!empty($item->default)) {
                $currency = $item;
            }
        }
        $lang = $this->app->getLanguage()->getTag();
        foreach ($list as $item) {
            if (($item->language ?? '') === $lang) {
                $currency = $item;
            }
        }
        $code = $this->app->getInput()->cookie->getString('gridbox-currency', '');
        foreach ($list as $item) {
            if ($code !== '' && ($item->code ?? '') === $code) {
                $currency = $item;
            }
        }

        $this->currency = $currency ?? (is_object($store->currency ?? null) ? $store->currency : null);

        // with automatic exchange rates Gridbox takes the rate from the fetched rates, not from the stored currency
        if ($this->currency && !empty($store->currencies->auto) && isset($this->currency->code)) {
            try {
                $query = $this->db->createQuery()
                    ->select($this->db->quoteName('key'))
                    ->from($this->db->quoteName('#__gridbox_api'))
                    ->where($this->db->quoteName('service') . ' = ' . $this->db->quote('exchangerates_data'));
                $rates = json_decode((string) $this->db->setQuery($query)->loadResult());
                $rate  = $rates->rates->{$this->currency->code} ?? null;
                if (is_numeric($rate) && (float) $rate > 0) {
                    $this->currency       = clone $this->currency;
                    $this->currency->rate = (float) $rate;
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->currency;
    }

    public function format(float $price): string
    {
        $c        = $this->currency();
        $decimals = max(0, min(4, (int) ($c->decimals ?? 2)));
        $rate     = (float) ($c->rate ?? 1) ?: 1.0;
        $number   = number_format(round($price * $rate, $decimals), $decimals, (string) ($c->separator ?? '.'), (string) ($c->thousand ?? ' '));
        $symbol   = trim((string) ($c->symbol ?? ''));
        if ($symbol === '') {
            return $number;
        }

        return ($c->position ?? '') === 'left-currency-position' ? $symbol . "\u{00A0}" . $number : $number . "\u{00A0}" . $symbol;
    }

    // ---------------------------------------------------------------- links

    /** Link of a Gridbox page in an app, with the Itemid Gridbox would pick (GridboxHelper::getGridboxPageLinks). */
    public function pageLink(int $id, int $appId, int $categoryId): string
    {
        $itemId = $this->menuItem(fn ($q) => ($q['view'] ?? '') === 'page' && (int) ($q['id'] ?? 0) === $id);
        $link   = $appId > 0
            ? 'index.php?option=com_gridbox&view=page&blog=' . $appId . '&category=' . $categoryId . '&id=' . $id
            : 'index.php?option=com_gridbox&view=page&id=' . $id;
        if ($itemId === 0 && $appId > 0) {
            $itemId = $this->categoryItemid($appId, $categoryId);
        }

        return $link . '&Itemid=' . ($itemId ?: $this->defaultItemid());
    }

    public function categoryLink(int $appId, int $categoryId): string
    {
        $itemId = $this->categoryItemid($appId, $categoryId);

        return 'index.php?option=com_gridbox&view=blog&app=' . $appId . '&id=' . $categoryId . '&Itemid=' . ($itemId ?: $this->defaultItemid());
    }

    /** Menu item of the category, else of its nearest parent, else of the whole app. */
    private function categoryItemid(int $appId, int $categoryId): int
    {
        return $this->catItemids[$appId . ':' . $categoryId] ??= $this->findCategoryItemid($appId, $categoryId);
    }

    private function findCategoryItemid(int $appId, int $categoryId): int
    {
        $cats  = $this->categories();
        $id    = $categoryId;
        $guard = 0;
        while ($id > 0 && $guard++ < 50) {
            $found = $this->menuItem(fn ($q) => ($q['view'] ?? '') === 'blog' && (int) ($q['app'] ?? 0) === $appId && isset($q['id']) && (int) $q['id'] === $id);
            if ($found) {
                return $found;
            }
            $id = $cats[$id]->parent ?? 0;
        }

        return $this->menuItem(fn ($q) => ($q['view'] ?? '') === 'blog' && (int) ($q['app'] ?? 0) === $appId && (int) ($q['id'] ?? 0) === 0);
    }

    private function defaultItemid(): int
    {
        $home = $this->app->getMenu('site')->getDefault();

        return $home ? (int) $home->id : 0;
    }

    private function menuItem(callable $match): int
    {
        if ($this->menu === null) {
            // AbstractMenu::getItems() reads a deprecated user getter in Joomla 6: filter here
            $componentId = (int) ComponentHelper::getComponent('com_gridbox')->id;
            $lang        = $this->app->getLanguage()->getTag();
            $this->menu  = array_values(array_filter(
                $this->app->getMenu('site')->getMenu(),
                fn ($item) => (int) $item->component_id === $componentId && in_array((int) $item->access, $this->levels, true)
                    && in_array((string) $item->language, ['*', $lang], true) && (int) ($item->published ?? 1) === 1
            ));
        }
        foreach ($this->menu as $item) {
            if ($match($item->query ?? [])) {
                return (int) $item->id;
            }
        }

        return 0;
    }

    // ---------------------------------------------------------------- categories and apps

    /** @return array<int, object> every Gridbox category (id, title, parent, app_id, published, access, language) */
    public function categories(): array
    {
        if ($this->categories === null) {
            $query = $this->db->createQuery()
                ->select(['id', 'title', 'parent', 'app_id', 'published', 'access', 'language', 'image'])
                ->from($this->db->quoteName('#__gridbox_categories'))
                ->order('order_list ASC, id ASC');
            $this->categories = [];
            foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $row->id     = (int) $row->id;
                $row->parent = (int) $row->parent;
                $row->app_id = (int) $row->app_id;
                $this->categories[$row->id] = $row;
            }
        }

        return $this->categories;
    }

    /**
     * Categories a visitor can reach (published, visible, whole parent chain too) in the given apps.
     *
     * @param int[] $apps
     *
     * @return array<int, object>
     */
    public function visibleCategories(array $apps): array
    {
        $lang = $this->app->getLanguage()->getTag();
        $all  = $this->categories();
        $ok   = fn ($c) => (int) $c->published === 1 && in_array((int) $c->access, $this->levels, true)
            && in_array((string) $c->language, ['*', $lang], true);
        $out  = [];
        foreach ($all as $id => $cat) {
            if (!in_array($cat->app_id, $apps, true) || !$ok($cat)) {
                continue;
            }
            $parent = $cat->parent;
            $guard  = 0;
            while ($parent > 0 && isset($all[$parent]) && $ok($all[$parent]) && $guard++ < 50) {
                $parent = $all[$parent]->parent;
            }
            if ($parent === 0) {
                $out[$id] = $cat;
            }
        }

        return $out;
    }

    /** @return int[] the category and its parents (the category first) */
    public function categoryPathIds(int $id): array
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

    /** Link of a Gridbox system page (search results) with the Itemid Gridbox would pick. */
    public function systemLink(int $id): string
    {
        $itemId = $this->menuItem(fn ($q) => ($q['view'] ?? '') === 'system' && (int) ($q['id'] ?? 0) === $id);

        return 'index.php?option=com_gridbox&view=system&id=' . $id . '&Itemid=' . ($itemId ?: $this->defaultItemid());
    }

    /** Names of the category and its parents, top first. */
    public function categoryPath(int $id): array
    {
        $cats  = $this->categories();
        $path  = [];
        $guard = 0;
        while ($id > 0 && isset($cats[$id]) && $guard++ < 50) {
            array_unshift($path, $cats[$id]->title);
            $id = $cats[$id]->parent;
        }

        return $path;
    }

    public function appTitle(int $appId): string
    {
        $this->loadApps();

        return $this->appTitles[$appId] ?? '';
    }

    public function appType(int $appId): string
    {
        $this->loadApps();

        return $this->appTypes[$appId] ?? '';
    }

    /** @return array<int, object> Gridbox apps (id, title, type) */
    public function apps(): array
    {
        $query = $this->db->createQuery()
            ->select(['id', 'title', 'type'])
            ->from($this->db->quoteName('#__gridbox_app'))
            ->order('order_list ASC, id ASC');

        return $this->db->setQuery($query)->loadObjectList('id') ?: [];
    }

    private function loadApps(): void
    {
        if ($this->appTitles) {
            return;
        }
        foreach ($this->apps() as $id => $app) {
            $this->appTitles[(int) $id] = (string) $app->title;
            $this->appTypes[(int) $id]  = (string) $app->type;
        }
    }

    // ---------------------------------------------------------------- images

    /** Intro image path (Gridbox stores uploaded field images by number). */
    public function introImage(string $image): string
    {
        $image = trim($image);
        if ($image === '' || !is_numeric($image)) {
            return $image;
        }
        $this->loadUploads([$image]);

        return $this->uploads[(int) $image] ?? '';
    }

    /** Paths of uploaded field images by number, loaded once for the ids not seen yet. */
    private function loadUploads(array $ids): void
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), fn ($id) => $id > 0 && !array_key_exists($id, $this->uploads)));
        if (!$ids) {
            return;
        }
        foreach ($ids as $id) {
            $this->uploads[$id] = '';
        }
        try {
            $query = $this->db->createQuery()
                ->select(['id', 'app_id', 'filename'])
                ->from($this->db->quoteName('#__gridbox_fields_desktop_files'))
                ->where('id IN (' . implode(',', $ids) . ')');
            foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $file) {
                $this->uploads[(int) $file->id] = 'components/com_gridbox/assets/uploads/app-' . (int) $file->app_id . '/' . $file->filename;
            }
        } catch (\Throwable $e) {
        }
    }

    /** Address of an image for the page (site-relative paths become root-relative, segments encoded). */
    public function imageUrl(string $image, bool $absolute = false): string
    {
        $image = trim($image);
        if ($image === '' || preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $image)) {
            return $image;
        }
        $image = preg_replace('/#.*$/', '', $image) ?? $image;
        $parts = array_map(fn ($s) => rawurldecode($s), explode('/', ltrim($image, '/')));
        // a path that climbs out of its folder is not an image address
        if (in_array('..', $parts, true) || preg_match('/[\x00-\x1F]/', $image)) {
            return '';
        }
        $path = implode('/', array_map('rawurlencode', $parts));

        return ($absolute || $this->preview ? rtrim(Uri::root(), '/') : Uri::root(true)) . '/' . $path;
    }
}
