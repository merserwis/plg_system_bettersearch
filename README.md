# Better Search for Gridbox

![Joomla 6](https://img.shields.io/badge/Joomla-6-1A3867) ![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4) ![License GPL-3.0](https://img.shields.io/badge/license-GPL--3.0-blue)

A replacement for the search of [Balbooa Gridbox](https://www.balbooa.com/joomla-page-builder) stores: live results under the search fields and the results page, served from an own search index.

## Why

Gridbox splits the query into words and looks for each of them separately. A model code typed in a different way than in the product name is not found, or finds unrelated products:

| Query | Gridbox | Better Search |
|---|---|---|
| `MI3155` | the product only if the name has `MI3155` | Metrel MI 3155 EurotestXD |
| `MI 3155` | every product containing “mi” and “3155” | Metrel MI 3155 EurotestXD |
| `MI-3155` | depends on the name | Metrel MI 3155 EurotestXD |

Better Search joins the parts of a model code (`MI 3155` → `mi3155`) and compares them with the product text without spaces, dashes or dots — whichever way the product description writes it.

## Features

**Search**
- Model codes match however they are typed: `MI 3155` = `MI3155` = `MI-3155` = `mi.3155`; `eurotest xd` = `EurotestXD`; `5 kv` = `5kV`.
- Searches names, product codes (also of variants), chosen product fields, categories (with parent categories), tags and — with a lower weight — descriptions.
- Polish word endings (`mierników` finds `miernik`), typo correction (`eurotset` → `eurotest`), partial matches when no product has all the words, synonyms (`multimetr, miernik uniwersalny`; one-way `rcd => wyłącznik różnicowoprądowy`).
- Weights of every field, popularity (page views), cut-off of weak matches.
- Visibility as in Gridbox: published, live dates, access levels, language, subscription add-ons hidden.

**Manual ranking and exclusions**
- Query rules: pin products to the top of chosen queries in a set order (drag and drop), or hide them; the query is matched exactly, by its start or anywhere.
- Product boosts and category boosts (positive or negative points).
- Excluded categories (with or without subcategories) and excluded products.
- Products out of stock: no difference, at the end, or hidden.

**Live results**
- Under every Gridbox search field (store search and search elements) and under the field of the included module.
- Categories matching the query, products grouped by app (store, blog…), “show all results” button.
- Keyboard navigation (arrows, Enter, Escape), ARIA combobox/listbox.
- Full-screen search on phones (own field above the on-screen keyboard).
- Width (as the field / at least / fixed), maximum height, alignment, distance, list or grid, image left/right/top, image size, shape, fit and background, price, category, code, availability, short description, colours, radius, shadow, font size.

**Results page**
- Replaces the list of the Gridbox search results page (Gridbox does no search work then); the Gridbox headline gets the query, or an own heading.
- Category and app filters with counts, sorting (best match, name, price, newest, most viewed — options to choose), page numbers and/or “load more”.
- Grid or list, columns for desktop / tablet / phone, gap, maximum width, image top/left/right with width, proportions, fit, shape; card colours, border, radius, padding, shadow, hover effect; text alignment; what each card shows.
- Prices after store sales, in the currency picked by the visitor, formatted like Gridbox.
- Small WebP copies of product images (made once, kept in `media/plg_system_bettersearch/thumbs`).

**Administration**
- Index status, update and rebuild with a progress bar.
- Test console: results as a guest sees them, with the score of each result and its reasons; uses the settings of the form before they are saved.
- Search statistics: most searched queries, queries without results, recent ones.
- All texts shown on the site can be changed; English and Polish texts included.

## Requirements

- Joomla 6, PHP 8.2+ (tested with Joomla 6.1.3 and PHP 8.5; Joomla 5 is not tested)
- Balbooa Gridbox (tested with 2.20.3.1)
- MySQL / MariaDB. A full-text index is used for descriptions when the server allows it; otherwise the plugin searches without it.

## Installation

1. Install `pkg_bettersearch-<version>.zip` in *System → Install → Extensions*. The plugin is enabled on a fresh install.
2. Open *Components → Better Search for Gridbox* (or the plugin *System - Better Search for Gridbox*).
3. *Search engine → Product fields to search*: choose the fields that describe products (manufacturer, model, parameters). Leave out fields shared by many products, such as contact persons.
4. *Index & tools → Update index*. The index is also built automatically on the first visits and then kept up to date (a check every 10 minutes by default).
5. Search in a Gridbox search field of the site.

The package contains:
- `plg_system_bettersearch` — index, live results, results page, administrator tools;
- `mod_bettersearch` — a search field for places without a Gridbox search element (for example through the Gridbox element *Joomla Module*);
- `com_bettersearch` — the administrator menu entry that opens the plugin settings.

## Updates

The package registers an update server: new versions appear in *System → Update → Extensions*.

## How it works

- Gridbox saves products without notifying other extensions. The plugin compares a signature of every product (name, save time, categories, codes, prices, stock, fields, tags) with the index after the page has been sent to the visitor, and indexes new and changed products (in batches). Visibility is checked at search time, so publishing or unpublishing needs no reindex.
- The results page: the plugin takes the query away from Gridbox before Gridbox renders the page and puts its own results into the Gridbox results element.
- Live results: the script of the plugin takes over the Gridbox search fields; Gridbox's own live search does not run.
- Results are cached per query, sort order and visitor access levels; every index update and every change of settings starts afresh.

## Notes

- With a page cache (Gridbox performance cache, Joomla System - Page Cache or a proxy) results pages are cached like other pages: clear the cache after larger catalogue changes.
- Changing the settings of the tab *Search engine* marks all products for indexing; the index is rebuilt gradually, or at once with *Rebuild everything*.

## License

GNU General Public License version 3 or later. See [LICENSE](LICENSE).

Project & support: [github.com/merserwis](https://github.com/merserwis/)
