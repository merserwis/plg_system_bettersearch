## Better Search for Gridbox 1.0.0

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

**Install:** download `pkg_bettersearch-1.0.0.zip` below and upload it in *System → Install → Extensions*. Later versions appear in *System → Update → Extensions*.
