## Better Search for Gridbox 1.3.1

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

**Install:** download `pkg_bettersearch-1.3.1.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
