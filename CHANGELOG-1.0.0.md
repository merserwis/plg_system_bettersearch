# Better Search for Gridbox 1.0.0 (2026-10-01)

First release.

- Own search index of Gridbox pages (store products, optionally blogs and other apps), kept up to date automatically after each page view (every 10 minutes by default, in batches), with update/rebuild in the administrator.
- Model codes match however they are typed (`MI 3155` = `MI3155` = `MI-3155`), Polish word endings, typo correction, partial matches, synonyms, stopwords, field weights, popularity, cut-off of weak matches.
- Full-text index for descriptions (MySQL/MariaDB), automatic fallback when not available.
- Query rules (pin in a set order / hide), product and category boosts, excluded categories and products, products out of stock (no difference / at the end / hidden).
- Live results under the Gridbox search fields: categories, products grouped by app, keyboard navigation, full screen on phones, many layout and style settings.
- Results page in the Gridbox results element: category and app filters, sorting, page numbers and “load more”, grid or list, many layout and style settings, prices after store sales in the visitor's currency.
- WebP thumbnails of product images.
- Test console with score explanation, search statistics, index status.
- Module with a search field; administrator menu entry.
- Texts: English; Polish for the texts shown on the site.

Tested: Joomla 6.1.3, PHP 8.5, Gridbox 2.20.3.1, MySQL 8.0 — 636 products from merserwis.pl (and 4 452 for the speed test: 30–70 ms per uncached query).
