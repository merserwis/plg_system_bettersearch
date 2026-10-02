## Better Search for Gridbox 1.4.0

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

**Install:** download `pkg_bettersearch-1.4.0.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
