# Changelog

All changes of **Better Search for Gridbox**, newest first. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_bettersearch/releases) with its installation package.

## 1.4.3 — 2026-10-02

### 🐛 Fixed: help tooltips

- The **“?”** beside the option names still showed no explanation on some sites, neither on hover nor on a click.
  - The tooltip was marked as `role="tooltip"`; the Joomla administrator template applies its own rules to such elements and squeezed it to the width of the icon.
  - A click on the “?” in browsers that focus a button on click (Chrome, Edge on Windows) showed the tooltip and hid it again at once.
- The tooltip is now part of the “?” itself and shown by CSS: on hover, on keyboard focus (Tab) and on a click (it then stays open until a click elsewhere or Escape). No script positions it, so no administrator template can move or hide it. It is 22rem wide and never wider than the window.

---

**Tested on:** Joomla 6.1.3 (Atum), PHP 8.5.10, Gridbox 2.20.3.1: hover with a real mouse on a scrolled page, click, second click, click elsewhere, Escape, phone width.

## 1.4.2 — 2026-10-02

Fixes for the help tooltips and the Search Console import, and fewer languages.

### 🐛 Fixed: help tooltips

- Hovering a **“?”** showed nothing on a scrolled settings page: the tooltip was placed relative to the window while the Joomla administrator template scrolls the page itself, so it appeared off the screen. It now appears right under the “?”, wherever the page is scrolled.

### 🐛 Fixed: Search Console import

- **“Duplicate entry … for key 'PRIMARY'”** when fetching or importing queries: Google reports “pomiar pętli zwarcia” and “pomiar petli zwarcia” as two queries, while the database treats them as the same text. Such queries are now added up (clicks and impressions summed, position averaged by impressions) instead of stopping the import.

### 🌍 Languages

- The extension now ships **English (default), Polish, Ukrainian and German**. Czech, Slovak, Lithuanian, French, Hindi, Chinese, Arabic and Spanish were removed; sites in those languages show English. Their files left by 1.3.0–1.4.1 are removed on update.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1 with 636 products. Tooltips with a real mouse on a scrolled page; a CSV with the same query with and without accents; update from 1.4.1 with files of removed languages present. Ranking identical to 1.4.1 in all 41 test queries.

## 1.4.1 — 2026-10-02

Fixes for the Google Search Console tab and the update, and a more comfortable administrator: help tooltips, an instant product finder and a settings file.

### 🐛 Fixed: Google Search Console

- **“The search is temporarily unavailable” in the Google Search Console tab** (fetching from Google and importing a CSV file). The tables of 1.4.0 could be missing after an update — see below. They are now created on every install and update, and also on first use when still missing.
- **The list stayed empty after a CSV import** although the queries were saved. The list now shows them right after the import or fetch.
- In the administrator tools an error now shows its **cause** (e.g. a database message) instead of the general visitors' message; the details also go to the Joomla log.

### 🐛 Fixed: updates

- When the schema record of the extension was missing in Joomla, Joomla ran every update script from the start. The script of 1.2.0 (adding a column that already existed) then stopped the whole update, so the tables of 1.4.0 were never created. That change is now made by the installer only when the column or index is missing, and every update script can safely run again.

### ❓ Help tooltips

- Every option with a description has a **“?”** beside its name: the description appears when the mouse is over it, on a click, or with the keyboard (Tab). The inline help of Joomla still works.

### ⚡ Faster product finder

- In **Ranking & exclusions** (query rules, product boosts, excluded products) the product list is loaded once and filtered in the browser: results appear while typing, with no request per keystroke. Model codes match however they are typed (“MI-3155” = “mi 3155”).
- The list of found products is shown **above the other sections** (it was hidden under *Category boosts*) and fits the window.

### 📁 Settings file and reset

- **Index & tools → Settings file:** **export** the settings of the form (also unsaved ones) to a JSON file, **import** them (only known settings, saved at once; settings not in the file stay as they are), or **reset** everything to the defaults.
- The Search Console key is never exported or imported; a reset keeps the Search Console connection.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1 with 636 products. Update from 1.4.0 with and without Joomla's schema record (tables of 1.4.0 missing): tables created, settings kept. CSV import through the file button with the tables missing; tooltips, product finder (results in ~80 ms) and settings export / import / reset in a real browser. Ranking identical to 1.4.0 in all 41 test queries.

## 1.4.0 — 2026-10-02

Learn from what shoppers search for: act on queries without results, see which searches end in the cart, compare with Google, and help shoppers with suggestions and filters.

### 🔍 Queries without results → synonyms and redirects

- In **Search statistics → Queries without results**, every query has two buttons: **Synonym** (the phrase will also find another word, e.g. “megger” → “miernik izolacji”) and **Redirect** (the phrase opens a page). The entry goes into the dictionary of the form; save the settings to use it.
- **Synonym editor:** the synonym dictionary is now a table — groups of words that mean the same, or one-way “also finds” — with a filter and a counter. It can still be edited as text (the stored format is unchanged).
- **Redirects (new):** a phrase, written in any way (“MI-3155” = “mi 3155”), opens a chosen page instead of the results — e.g. a brand, a service or a category page. The live results show it as the first option. Addresses: a path of the site or an http(s) address (nothing else is accepted). Redirected searches are counted in the statistics.

### 💡 Suggestions

- **Popular searches** that start like the typed text appear above the live results (most searched first, only searches that found something and were made at least twice — a one-off typo is not suggested to others). A click or Enter searches for it.
- **“Did you mean…”**: when nothing is found, searches with results that are written almost the same are suggested — in the live results and on the results page.

### 🧮 Filters beside the results

- **Brand** (any Gridbox list field, e.g. *Manufacturer*), **price from–to** (in the visitor's currency, current price after sales), **sale only** and **in stock only** — each with the number of results it leaves; filters that would change nothing are not shown.
- As a **sidebar** (folded on phones) or **above the results**. A plain GET form: works without JavaScript; filtered pages are `noindex` and their links `nofollow`.

### 📈 Conversions

- **Clicks** on results (live and results page) and products **put into the cart** after such a click are counted per query and per product.
- **Tools → Conversions:** for 7 / 30 / 90 / 365 days — searches, clicks, click rate, cart additions and cart rate per query, and the products clicked and put into the cart most.
- No personal data: one row per day, query and product. A first-party cookie (`bs_src`: product number → query, 30 days) links the cart to the search. Bots are not counted. It can be switched off (*Conversion statistics*).

### 🔗 Google Search Console

- A new **Google Search Console** tab: the queries people use on Google to find the site, with clicks, impressions and position.
- **Automatic, once a day,** with a Google service account (its JSON key in the settings; the account is added as a user of the Search Console property), or **import a CSV file** exported from Search Console (any language of the headings).
- **Check here** searches each Google query with this search (as a guest) and lists those that find nothing here first — with the same *Synonym* and *Redirect* buttons.

### ⚡ Instant reindex

- Saving, publishing, unpublishing or trashing a page or product in Gridbox (editor or lists) updates the index **right after that request** — the change is searchable at once, without waiting for the next check.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1 with 636 products. Ranking identical to 1.3.1 in all 41 test queries; all new features tested on the site, in the administrator and in a real browser (desktop and phone); no PHP warnings. Updating from 1.3.x keeps all settings and the index (two new tables are added). The Search Console connection was tested up to Google's answer with a test account; a real service account key is needed to fetch real data.

## 1.3.1 — 2026-10-02

A fix release: prices, currencies and publishing dates are now handled exactly as Gridbox handles them.

### 🏷️ Fixed: store sales

- When **several store sales apply** to a product, Gridbox uses the **first one** (the oldest sale, lowest ID) and ignores the others. Better Search used the *last* one, so the live results and the results page could show a different price than the product page. Example: with a −5 % sale on the whole store and a later −50 % sale on a category, a product at 8 603 zł showed 4 301,50 zł instead of 8 172,85 zł.
- The 1.2.0 release notes described the old, wrong rule ("the last applicable sale wins"). The correct rule is: **the first applicable sale wins**, computed from the regular price, and category sales apply to the product's own category and its parents.

### 💱 Fixed: currency choice

The currency is now chosen exactly like Gridbox does:

- the **language currency** applies only with *Associations* on, and the first matching currency is used;
- the currency picked in the **currency switcher** also uses the first match;
- with **automatic exchange rates**, only non-default currencies take the fetched rate.

With a single store currency nothing changes.

### 🕒 Fixed: publishing dates

- Product publishing dates (*created* and *end publishing*) are compared in the **site time zone**, like Gridbox does. Before, they were compared in UTC, so a product scheduled to appear or to end showed up or disappeared in the search one or two hours off (in Poland).

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1. Ranking identical to 1.3.0 (41 test queries). Overlapping store sales checked against Gridbox's own rule. Updating from 1.3.0 keeps all settings and the index; no database changes.

## 1.3.0 — 2026-10-02

The plugin speaks 12 languages, the results page gets SEO settings, and the regular price is crossed out again in the live results.

### 🏷️ Fixed: sale prices in the live results

- When a product is on sale, the live results showed both prices but the regular one was **not crossed out** (the panel's style reset removed the line from the `<del>` element). The regular price is crossed out again — explicitly, so no theme can remove it — in the live results and on the results page.
- Both prices are now **named for screen readers** (“Regular price:” / “Sale price:”), invisibly.

### 🔎 SEO of the results page

A new **SEO** tab:

- **Robots:** `noindex, follow` by default — results stay out of the index while their product links are followed. With `index, follow`, sorted, filtered, further and empty pages still get `noindex, follow`.
- **Canonical address:** the results of the query alone — without sorting, filters, page numbers, tracking or unknown parameters. It replaces a canonical address set by the theme (which often points to the home page).
- **Title and meta description** with the query and the number of results (patterns with `{query}`, `{count}`, `{site}`; the site name as in the Global Configuration; “page 2” on further pages). Open Graph and Twitter titles of the theme follow.
- **Structured data:** schema.org `SearchResultsPage` with an `ItemList` of the products shown (positions, names, canonical addresses).
- **`rel="nofollow"`** on sorting, filter and page links, so robots do not crawl their endless combinations.
- **OpenSearch:** browsers can add the shop search to their search engines.
- **WebSite SearchAction** on the home page (off by default — Google no longer shows a search box from it).
- The search endpoints answer with **`X-Robots-Tag: noindex, nofollow`**.

### 🌍 12 languages

- **English, Polish, Ukrainian, German, Czech, Slovak, Lithuanian, French, Hindi, Chinese (Simplified), Arabic, Spanish** — for the settings (the administrator's language) and for the texts shoppers see (the site language). Any other language shows English, also text by text where a translation lacks one.
- The files are installed for the languages of the site and also kept in the plugin folder, so a language added later is found too.
- **Right-to-left** layouts (Arabic): the live results and the results page use logical directions.

### 🔒 Security

- Every value the SEO settings write into the page is escaped for its place (title, attributes, XML of OpenSearch); structured data is encoded so it cannot close its script element; the canonical address is built from a whitelist of parameters, so a link cannot smuggle its own parameters into it.
- Patterns in titles and descriptions are filled in one pass: `{query}` typed by a visitor is not expanded again.
- Translations are checked automatically against English: the same keys, the same placeholders and HTML, valid for PHP's parser.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1. Ranking identical to 1.2.0; updating from 1.2.0 keeps all settings and the index.

## 1.2.0 — 2026-10-02

A security, correctness and performance release after a full review of the code with penetration tests (XSS, SQL injection, CSRF, path traversal, CSS injection, resource exhaustion). No injection was found; the findings below were fixed. Updating from 1.1.0 keeps all settings; the index is rebuilt once in the background.

### 🔒 Security hardening

- **Visibility is current:** cached results could show a product for up to 15 minutes after it was unpublished, restricted to a user group or expired. Every cache key now carries a visibility stamp of the pages (refreshed every minute), and the result cards are loaded with the same visibility rules as the search.
- **Typo correction vocabulary** is built only from products a guest can see, so a correction never points at an unpublished or restricted product.
- **Resource use is bounded:** at most 8 words of a query are searched and 3 corrected; correction candidates are bucketed by length and first letter (10–30× fewer comparisons); the vocabulary is loaded only when a correction is tried; one search engine instance per request.
- **Error messages** of the database are no longer shown to visitors (logged to Joomla's log instead; shown in the page only with Debug on). The diagnostic comment no longer carries the version.
- **Search statistics** keep at most 20 000 rows (the rarely searched, oldest ones go) and ignore noise (control characters, very long strings).
- State-changing administrator tools are sent as POST; the preview's inline script escapes `<`; a guest's view levels come from Joomla's own computation.

### 🐛 Fixed

- **Gridbox items filter broken:** the plugin took the `query` parameter over on every Gridbox page, but Gridbox uses the same parameter for its *Items filter* element (`brand__metrel`). A landing page with a product grid and a filter rendered unfiltered, with a search block injected. Only the Gridbox search pages (or a page with a search results element) are handled now, and the filter grammar is never treated as a search.
- **Searches with `<`:** `<1 kV`, `Przewód <50 V` lost everything after the `<` (PHP's `strip_tags`). Tags are removed by a pattern that keeps such text — in queries and in the index.
- **Broad queries on large stores:** the candidate limit (3 000) was applied in an arbitrary order, so on a store with more matching products the best match could be left out. Candidates are now ordered by code / name / field matches before the limit.
- **Store sales:** the *last* applicable sale wins, computed from the regular price, and category sales apply to the product's own category and its parents — exactly as Gridbox prices the product. Automatic exchange rates are honoured.
- **Pinned products** keep the administrator's order also under name / price / date sorting.
- **Category chips** count the same products that a click on the chip shows.
- A `%` typed in an administrator text (e.g. “100% match”) no longer breaks the search (no more `sprintf`).
- Images whose path climbs out of its folder are not emitted; thumbnails of a moved site stay valid (hash from the site-relative path).
- Products with variations are “available” when any variation is in stock (the base stock is ignored, as in Gridbox); category-type fields are indexed by their option ids.
- *Load more* stops cleanly when the result set shrank meanwhile; an invalid selector typed in the settings no longer breaks the page's events; a scroll that ends over the search field no longer opens the full-screen search on phones.
- Names sort in the order of the site language (ł after l) when PHP intl is present.
- Full-text search respects the server's `innodb_ft_min_token_size`; the full-text index is created apart from the table, so a server that refuses it still gets a working (slower) search.

### ⚡ Performance

- **Results page:** 60 % fewer database queries on repeated views (routed product and category links are cached per language and access level; one query for uploaded images and for all store sales).
- **Every page:** the plugin reads its settings once per request and only when a search field is on the page; the index check runs only after ordinary pages (never after live-search requests), behind a 30-second file gate, after the response has been sent, with the session closed, and the check itself is one atomic claim (parallel requests never run two).
- **Thumbnails:** existing copies are found with file stats only (no image decoding per request); the original is decoded once for both sizes; a time budget per page view is a setting (default 0.5 s, live results 0.25 s).
- **Index upkeep:** the quick check no longer reads the page layouts (large blobs) — it relies on Gridbox's save time, and a daily deep pass compares the layouts by checksum; sub-queries are limited to the indexed apps and fields; statements are bounded in size.
- **Cache:** expired cache files are removed on every index check (Joomla never does this on the site), all keys are dropped when the index changes, cached results hold only what the pages need (≈ 70 % smaller), the live key no longer varies by device.
- Candidates are scored as they stream from the database; sort keys are computed once; the highlighter folds each distinct character once.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1 — ranking identical to 1.0.0 for all 41 regression queries; fresh install, update from 1.1.0 (settings and index kept), uninstall; no PHP warnings or deprecations from the extension with full error reporting.

## 1.1.0 — 2026-10-01

A live preview in the settings, appearing effects for the live results, a fix for phones and clearer search statistics.

### 👀 Live preview in the settings

The *Live results*, *Results page* and *Texts & images* tabs have a **preview column** that follows every change in the form, before you save.

- **Real products** of your store — names, prices, images, categories — as a **guest of the site** sees them.
- **Live results:** the panel opened under a search field of a mock page header, at its configured width, alignment and distance; on a phone the **full-screen search**. A **Replay** button shows the appearing effects again.
- **Results page:** the results block under the Gridbox headline, with filters, sorting and cards.
- **Desktop, Tablet and Phone:** rendered at the real device width (1280 / 820 / 390 px) and scaled to fit, so columns per device and responsive rules apply as on the site.
- Any **query for the preview**; it starts with the most searched query that has results.

### ✨ Appearing effects

- **The panel:** *Fade in*, *Slide up* (default, as in 1.0.0), *Slide down*, *Zoom in*, *Flip down*, *Unroll from the top* — or none.
- **The results inside it:** *Fade in*, *Fade in from below*, *Slide in from the left*, *Zoom in*, *Sharpen from blur* — or none (default). They appear **one after another** and play again each time new results come while typing.
- **Durations** of both effects and the **delay between results** are adjustable.
- Visitors who asked their system for **less motion** see no effect.

### 📱 Fixed: the search on phones

- Tapping the search field on a phone did not show the cursor and the keyboard: the full-screen search moved the focus to its own field a moment later, and mobile browsers open the keyboard only during the tap itself.
- The full-screen field now takes the focus **within the tap**, so the keyboard opens at once; the original field is not focused at all.

### 📊 Search statistics

- *Most searched*, *Without results* and *Recent* are now **tabs with one full-width table**, with the number of queries on each tab (red when some queries found nothing).
- Long queries wrap inside their column; numbers are aligned.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1. Ranking identical to 1.0.0 for all 41 regression queries; updating from 1.0.0 keeps all settings and the index.

## 1.0.0 — 2026-10-01

The first release: a replacement for the Balbooa Gridbox store search that finds products the way shoppers type them, with manual ranking and full control over the look.

### 🔎 Model codes, however they are typed

- **`MI 3155`, `MI3155`, `MI-3155` and `mi.3155` find the same product**, even when the description writes only one of these forms. Neighbouring parts of a code are joined and compared with the product text without spaces, dashes or dots.
- A short word after another one is its ending written apart: **`eurotest xd`** finds *EurotestXD*; **`5 kv`** finds *5kV*.
- **Fallbacks** when nothing is found: the parts of a code as separate words, **typo correction** (`eurotset` → *eurotest*) and **partial matches** with a note.
- **Polish word endings** (`mierników` → *miernik*), **synonyms** (two-way and one-way) and ignored words.

### 🗂️ Own search index

- Names, product codes (also of **variants**), chosen **product fields**, categories with their **parent categories**, tags and — with a lower weight — descriptions.
- Kept **up to date automatically** after each page view, in batches; publishing, access and dates are checked live and need no reindex.
- **Full-text index** for descriptions when the server allows it: 30–70 ms per uncached query with 4 452 products.

### 📌 Manual ranking and exclusions

- **Query rules:** pin products to the top of chosen queries **in the order you set** (drag and drop), or hide them. The query is matched exactly, by its start or anywhere.
- **Product boosts** and **category boosts**, positive or negative.
- **Excluded categories** (with or without subcategories) and **excluded products**; products **out of stock** shown normally, at the end or hidden.
- **Weights** per field, a factor for model numbers, popularity and a cut-off of weak matches.

### ⚡ Live results

- Under **every Gridbox search field**: matching categories, products grouped by app, price, category, code, availability and a *show all results* button.
- **Keyboard navigation** and ARIA combobox semantics; a **full-screen search on phones**.
- Width, height, alignment, list or grid, image position, size, shape and fit, colours, radius, shadow, font size.

### 📄 Results page

- In the **Gridbox results element** of the search page — Gridbox does no search work there.
- **Category and app filters** with counts, **sorting** (best match, name, price, newest, most viewed), **page numbers and / or *load more***.
- **Grid or list**, columns for desktop / tablet / phone, image top / left / right, card style, hover effect, text alignment and what each card shows.
- **Prices** after store sales and in the visitor's currency; **WebP thumbnails**.

### 🧰 Administration

- **Index & tools:** status, update and rebuild with a progress bar, cache and thumbnail clean-up.
- **Test console:** results as a guest sees them, with the score and its reasons, using unsaved settings.
- **Search statistics:** most searched queries and **queries without results**.
- **`mod_bettersearch`** module with a search field, an administrator menu entry, English texts and Polish texts for the site.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1 — 636 real products of a measurement equipment store (4 452 in the speed test).
