## Better Search for Gridbox 1.2.0

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

**Install:** download `pkg_bettersearch-1.2.0.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
