## Better Search for Gridbox 1.3.0

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

**Install:** download `pkg_bettersearch-1.3.0.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
