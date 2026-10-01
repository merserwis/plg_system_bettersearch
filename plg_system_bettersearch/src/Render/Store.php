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

    public function __construct(DatabaseInterface $db, CMSApplicationInterface $app, array $levels)
    {
        $this->db     = $db;
        $this->app    = $app;
        $this->levels = $levels ?: [1];
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
        $rows = $db->setQuery($query)->loadObjectList('id') ?: [];

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
            $row->link       = Route::_($this->pageLink($row->id, $row->app_id, $catId), false);
            $row->catLink    = $catId > 0 ? Route::_($this->categoryLink($row->app_id, $catId), false) : '';
            $row->image      = $this->introImage((string) $row->intro_image);
            $row->isProduct  = $this->appType($row->app_id) === 'products' && $row->product_type !== null;
            $row->prices     = $row->isProduct ? $this->prices($row, array_unique(array_merge([$catId], $mapped[$id] ?? []))) : null;
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

    /** Price after the first store sale that applies (Gridbox's own rule order). */
    private function salePrice(float $price, int $productId, string $variation, array $categories): float
    {
        foreach ($this->sales() as $sale) {
            if (empty($sale->discount)) {
                continue;
            }
            $applies = match ((string) $sale->applies_to) {
                '*'        => true,
                'category' => (bool) array_filter($sale->map, fn ($m) => in_array((int) $m->item_id, $categories, true)),
                'product'  => (bool) array_filter($sale->map, fn ($m) => (int) $m->item_id === $productId && (string) $m->variation === $variation),
                default    => false,
            };
            if ($applies) {
                return $price - ($sale->unit === '%' ? $price * ((float) $sale->discount / 100) : (float) $sale->discount);
            }
        }

        return $price;
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
            $this->sales = $db->setQuery($query)->loadObjectList() ?: [];
            foreach ($this->sales as $sale) {
                $query     = $db->createQuery()
                    ->select('*')
                    ->from($db->quoteName('#__gridbox_store_sales_map'))
                    ->where('sale_id = ' . (int) $sale->id);
                $sale->map = $db->setQuery($query)->loadObjectList() ?: [];
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

        return $this->currency = $currency ?? (is_object($store->currency ?? null) ? $store->currency : null);
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
        $link   = $appId > 0 && $categoryId > 0
            ? 'index.php?option=com_gridbox&view=page&blog=' . $appId . '&category=' . $categoryId . '&id=' . $id
            : 'index.php?option=com_gridbox&view=page&id=' . $id;
        if ($itemId === 0 && $appId > 0 && $categoryId > 0) {
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
        try {
            $query = $this->db->createQuery()
                ->select(['app_id', 'filename'])
                ->from($this->db->quoteName('#__gridbox_fields_desktop_files'))
                ->where('id = ' . (int) $image);
            $file = $this->db->setQuery($query)->loadObject();
        } catch (\Throwable $e) {
            return '';
        }

        return $file ? 'components/com_gridbox/assets/uploads/app-' . (int) $file->app_id . '/' . $file->filename : '';
    }

    /** Address of an image for the page (site-relative paths become root-relative, segments encoded). */
    public function imageUrl(string $image, bool $absolute = false): string
    {
        $image = trim($image);
        if ($image === '' || preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $image)) {
            return $image;
        }
        $image = preg_replace('/#.*$/', '', $image) ?? $image;
        $path  = implode('/', array_map(fn ($s) => rawurlencode(rawurldecode($s)), explode('/', ltrim($image, '/'))));

        return ($absolute ? rtrim(Uri::root(), '/') : Uri::root(true)) . '/' . $path;
    }
}
