## Better Search for Gridbox 1.4.2

Fixes for the help tooltips and the Search Console import, and fewer languages.

### 🐛 Fixed: help tooltips

- Hovering a **“?”** showed nothing on a scrolled settings page: the tooltip was placed relative to the window while the Joomla administrator template scrolls the page itself, so it appeared off the screen. It now appears right under the “?”, wherever the page is scrolled.

### 🐛 Fixed: Search Console import

- **“Duplicate entry … for key 'PRIMARY'”** when fetching or importing queries: Google reports “pomiar pętli zwarcia” and “pomiar petli zwarcia” as two queries, while the database treats them as the same text. Such queries are now added up (clicks and impressions summed, position averaged by impressions) instead of stopping the import.

### 🌍 Languages

- The extension now ships **English (default), Polish, Ukrainian and German**. Czech, Slovak, Lithuanian, French, Hindi, Chinese, Arabic and Spanish were removed; sites in those languages show English. Their files left by 1.3.0–1.4.1 are removed on update.

---

**Tested on:** Joomla 6.1.3, PHP 8.5.10, MySQL 8.0, Gridbox 2.20.3.1 with 636 products. Tooltips with a real mouse on a scrolled page; a CSV with the same query with and without accents; update from 1.4.1 with files of removed languages present. Ranking identical to 1.4.1 in all 41 test queries.

**Install:** download `pkg_bettersearch-1.4.2.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
