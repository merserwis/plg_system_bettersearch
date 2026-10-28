# Changelog

All changes of **Better Search for Gridbox**, newest first. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_bettersearch/releases) with its installation package.

## 1.8.0 — 2026-10-28

### 🧭 An administrator of its own, like Admin Tools

The settings were one plugin form with 13 tabs and about 290 options, all at once. They are now an administrator of their own under **Components → Better Search for Gridbox**, laid out like Akeeba Admin Tools:

- **Control Panel**: the tools as tiles in sections (*Search*, *Results*, *Insights*, *Shop* — only with a Gridbox store —, *Configuration*), each with a dot that says whether it is on; on the side the state of the search (version, the pages in the index and those waiting), shortcuts (quick setup, configuration, update the index, test a search, clear the cache, open Gridbox) and a reminder to check the site.
- **The sidebar menu** lists *Index*, *Search Engine*, *Live Results*, *Results Page*, *Statistics* and *Configuration*; the tools that have settings beside their overview (*Index*, *Search Engine*, *Statistics*) have a cog that opens them.
- **Pages of the tools**: *Index* (index status and buttons · indexing · what is indexed), *Search Engine* (test console · matching · ranking weights · synonyms and redirects), *Rules*, *Live Results*, *Results Page*, *Appearance*, *Statistics* (search statistics and conversions · collecting · e-mail report · Google Search Console) and, with a store, *Products*.
- **Configuration**: what is set once and forgotten, on one page with a vertical tab each — *Voice search*, *SEO*, *Texts & images*, *Settings file* and *General*.
- **Quick Setup**: 15 switches, each marked *recommended* or *optional*, with a sentence on what it does. *Turn on the recommended* switches on the recommended ones and leaves the optional ones as they are; only the switches you change are saved. A fresh install offers it first.
- **The basic / advanced switch is on every page**, the live preview, the theme editor, the pickers and the tools work as before, and the settings are still the plugin's, so nothing changes on the site. Saving a page keeps the settings of all the other pages. Opening the plugin in Joomla's plugin manager leads to the control panel (the plugin's own form opens only when the component is off, or for an administrator who may edit plugins but not open the component).
- The statistics send *Add synonym*, *Redirect* and a click on a query to the Search Engine page, where the dictionaries and the test console are.

### ⚠️ Check your site after every change

After installing, after updating and after every save, a message asks to check on the site itself that what was turned on or changed works as it should: type in a search field, open the live results and the results page. The control panel and the quick setup say the same.

### 🔍 Code audit (Codex) — security, bugs and speed

The whole plugin and the new administrator were reviewed by Codex, file by file; every finding was checked against the code before it was fixed.

**Security and privacy**
- **An ordinary logged-in member can no longer start an index check with POST requests to Gridbox.** The update of the index right after a change is for people who may change Gridbox content, and a row of saves is one check (at most every 5 seconds); what is skipped is found by the next check.
- Live results kept for a few minutes no longer show a product past its end of publishing: the moment a page is published or expires changes the cache of the results.
- The daily search counts are bounded like the search log (150 000 rows), so a robot typing endless phrases cannot grow the table.
- The e-mail report goes to the Super Users that may still administer the site and receive system e-mails; before, it also went to those who had switched system e-mails off when nobody else was left.

**Bugs**
- Conversions: the searches are counted for the same days as the clicks and carts (the click rate was diluted by the older searches of the phrase), and a click or a cart belongs to the same day, in the time zone of the site, as the search.
- A settings file with a switch turned off (`false`) is imported.
- Google Search Console: the import replaces the old queries only when the new ones are in (all or nothing; figures too big for the columns are cut instead of failing the import), the same query typed in two ways is added up, and *Days* fetches that many days (it was one more).
- The search field module works with a results page address without SEF (`index.php?option=…`): the form keeps the address's parameters.
- *Update the index in the layout check*: when more pages changed their layout than one check indexes, the rest is no longer left for the next day.
- A search for a standard with a value (`EN 61557 1000 V`) keeps the standard in the words searched, so products without it are no longer listed.
- Words that InnoDB's full-text index ignores (`about`, …) are found in descriptions; a category left out *without its subcategories* leaves out the products that have it as an additional category too; a text with a literal “<” (`<50 V`) is indexed whole; the list of tags and categories of a product no longer gets cut by the server's `group_concat_max_len`; a redirect to another site no longer makes a local product with the same alias a “replacement”.
- Thumbnails: the memory needed to turn a picture is checked before it is made, a path with a NUL character is refused instead of breaking the search, pictures of a site in a subfolder get a right address, and mirrored phone photos (EXIF 2, 4, 5, 7) are made upright — the lighter copies already made stay until the original changes or *Remove small images* is used.
- Live results (script): a slow answer can no longer replace the results of a newer phrase or open a panel that was closed; the microphone stops when the panel is closed, Escape on a phone does not open the search again, a phrase can be tried again after a failed request, picking a suggestion is not overwritten by the search of the text typed before it, and the browser keeps answers for a minute only (and apart for each currency); the cookie that links a cart to the search stays under the size limit.
- Settings pickers: products beyond the first 30 000 are found, the titles of the saved ones are asked for in batches, an Enter on a closed list picks nothing, and the preview of the results page can shrink again.
- “Did you mean” suggestions have their own ids (screen readers).

**Speed**
- The results of a page no longer run an unused query; the options of a product field are read once per request; the redirects are scanned once per request.

## 1.7.1 — 2026-10-20

### 🐞 Fixes

- **Live results showed only 3 products although “Products shown” was 8.** When *Order of the result sections* put the pages or a blog above the store, the limit *Products shown* went to the first section with results (e.g. *Pages* with one hit), and the store got the limit of *Items of other apps* (3). Now *Products shown* always goes to the store, wherever it is in the order; the other apps show *Items of other apps*. Without store results for a phrase, the first section gets the larger limit, as before. The order of the sections does not change.

## 1.7.0 — 2026-10-15

### ✨ New

- **Voice search.** A microphone button at the end of the search fields (Gridbox search elements and the Better Search module): the visitor taps it, says what they are looking for and the spoken words are searched. While it listens the button turns red and the field says *Listening… say what you are looking for*; a second tap stops it.
- **On all devices or on mobile devices only** — new tab *Voice search* in the settings, setting *Voice search*: *Off* (default) / *On all devices* / *Mobile devices only (phones and tablets)*. Phones and tablets are recognised by the device (also an iPad that presents itself as a Mac), not by the width of the window, so a narrow window on a computer shows no microphone.
- **After speaking:** *Show the live results* (default) — the words go into the field and the live results appear, so the visitor can correct them; on phones the full-screen search opens without the on-screen keyboard — or *Open the results page* at once. Without the live results the results page always opens, so voice search works with the live results switched off too.
- **Language of speech** (advanced): the language tag the browser listens for; empty — the language of the page. A full stop or question mark the browser adds at the end is left out.
- The speech recognition of the browser is used (Chrome, Edge, Safari, Samsung Internet). Where the browser cannot recognise speech (Firefox, a site without HTTPS) no microphone is shown. Chromium browsers that have the recognition without Google's speech service behind it (Vivaldi, Brave and the like) are found on the first try — the recognition never starts or reports a network error: the field says *This browser cannot recognise speech – use the microphone of the keyboard*, the microphone goes away and stays away in that browser (until its version changes). Listening stops by itself after 15 s. A blocked microphone or speech that was not recognised is said in the field for a moment. Chrome sends the speech to Google for recognition; the site receives only the recognised words, like a typed search.
- **The microphone in the Permissions-Policy header** — new option *Allow the microphone in the Permissions-Policy header* (on by default): many sites send `Permissions-Policy: microphone=()` (e.g. from *System - HTTP Headers*), which forbids the microphone so that the browser does not even ask. With voice search on, the plugin sends `microphone=(self)` instead — the microphone for the pages of the site only — right before the headers go out, so also on pages sent from a page cache (MerTools). A header added by the web server or Cloudflare cannot be changed by the plugin; the tab checks the header the site really sends to visitors and says where to change it. A page whose header still forbids the microphone shows no microphone.
- The microphone sits before the Gridbox magnifier when it is on the same side and the field gets room for it; fields shown later (menus, popups) get it too. Right-to-left layouts are supported. In the full-screen search on phones the field, the microphone and the close button are on one line also when the template gives fields a margin.

- **Number of pages waiting on the “Update index” button** in the administrator's top bar, as of the last check (like the number of unread messages of Joomla); it counts down while the button indexes and disappears when nothing is left. The tooltip says the same.
- **Why pages wait** — the Tools tab shows the waiting pages by reason: *new*, *changed*, *after a change of the index settings*, *changed page layout* (the daily check of the layouts), *removed from Gridbox*.

### 🐞 Fixes

- **Thousands of pages waiting for indexing after a small change.** Until now a single new or renamed **tag** or **category** — anywhere in Gridbox, also a tag typed in a blog post or a category of another app — and any change of a field definition marked **every page of the index** for indexing. On a shop with ~4,600 products the queue then took hours (300 pages per check), and it started again with the next new tag. Now category and tag names belong to the signature of each page: renaming, adding or moving a category or a tag reindexes only the pages that have it (with its subcategories when a parent category changes); a new tag that no page has yet reindexes nothing. Every page is marked only after a change of the index settings (tab *Search engine*) or of the options of an indexed field.
- **Prices and stock without reindexing.** A page whose price or stock alone changed — an order lowered the stock, a price list was imported, the stock of a variant changed — gets its price and availability in the index at once, in the same check, without indexing its text again and without waiting in the queue. Changed variant codes are still indexed as words.

### ⚙️ Update

- No reindex after the update: the pages that were up to date with the signatures of the earlier version take over the new ones on the first check (one comparison of all pages), the pages that were waiting keep waiting. The index gets a new column `vsig` (price and stock signature).

## 1.6.11 — 2026-10-14

### ✨ New

- **Orders placed on the site in the e-mail report.** The report has a new section *Orders placed on the site*: the Gridbox store orders that visitors placed in the period (not those created in the store panel), compared with the period before — **orders**, **cart value** (the products after the discount, without shipping, in the prices of the store: net when the store adds the tax), **average cart** and **from the search** (orders with a product put into the cart from the search results, with the value of those products).
- Below the tiles: the **orders of the period** (number, date, status with its colour from the store settings, items, cart value, the phrases that brought their products, up to *Phrases in the report* rows) and the **phrases that led to orders**, by the value of their products. Works in the preview and *Send now* for any period.
- Refunded orders are listed (struck through) but left out of the sums; orders in another currency are summed separately (the change of the value is shown when both periods are in one currency). On a site without the Gridbox store the section is left out.
- **How an order is put down to the search:** when a product goes into the cart from the search results (a conversion counted since 1.4.0), the plugin now also notes the Gridbox cart it went into — also a cart opened by this very product. An order placed from that cart counts for the search, also when the payment gateway confirms it later. Orders are attributed from the update to 1.6.11 on; the totals of the orders work for any period. Staff left out of the statistics are not noted. The notes are kept for a year (new table `#__bettersearch_carts`).
- Can be turned off: *Orders placed on the site* (tab *E-mail report*, on by default).

### 🐞 Fixes

- Uninstalling the plugin also removes its table of searches per day (`#__bettersearch_daily`), left behind since 1.5.0.

## 1.6.10 — 2026-10-13

### ✨ New

- **“Update index” button in the administrator's top bar.** The top bar of the administrator, next to the Joomla version, has an **Update index** button: one click brings the search index up to date from any screen — the same steps as *Update index* on the *Index & tools* tab (new, changed and removed pages), without opening the plugin. The progress is shown on the button (*Indexing… 400 / 644*) and a Joomla message says how many pages were indexed and removed, or that the index is already up to date.
- Shown only to users who may manage plugins; the request needs the form token. On narrow screens the button moves into the *more* (⋯) menu of the bar with the other items. Can be turned off: *Update index button in the top bar* (tab *Search engine*, on by default).
- **Close goes to the Gridbox panel.** **Close**, **Save & Close** and **Save** then **Close** in the Better Search settings now go to the Gridbox panel instead of the list of plugins, as in MerTools (when Gridbox is installed; otherwise the list of plugins as before).

## 1.6.9 — 2026-10-12

### 🐞 Fixes

- **“{searchterms}” in the search report.** The OpenSearch description of the site (what lets a browser add a search of your site to its address bar) gives the results address as `/search?query={searchTerms}`; the browser puts the typed words in place of `{searchTerms}`. Some crawlers that present themselves as an ordinary browser open that address as it is, so the report counted a search for “{searchterms}”. A query that is only such an unfilled template — `{searchTerms}`, `{search_term_string}` (the Google sitelinks search box) and the like, also URL-encoded — is no longer logged, searched or offered a “Did you mean”: the results page shows no results at once, without a database query. Such entries logged by earlier versions are removed from the report when the update is installed.

## 1.6.8 — 2026-10-09

### ⚡ Lighter Tools tab

- Opening the **Tools** tab no longer compares every page with the index. That comparison (the page signatures) reads all pages of the indexed apps: on a shop with about 4,600 products it took up to 6 s on the server and showed up in the hosting's slow query log. The tab now shows the figures of the last check — every update of the index keeps them — marked *as of the last check*, with a **Count now** link for the exact count.
- Each step of **Update index** no longer counts the signatures a second time: it shows the figures of the update it has just made.
- Tip: Gridbox's tables have no indexes besides the primary key; with an index on `#__gridbox_store_product_data.product_id` the signature query itself is about 20× faster (MerTools for Gridbox 0.0.26 creates and keeps these indexes).

## 1.6.7 — 2026-10-07

### 🌍 Word endings and ignored words in every language

- **Word endings** for **English, German, French, Czech and Dutch** besides Polish: “Messgeräten” finds *Messgerät*, “appareils” *appareil*, “multimetrů” *multimetr*, “zoekopdrachten” *zoekopdracht*, “batteries” *battery*. The Polish rules (with English plurals) are always used, because the products of a shop are often not in the language Joomla runs in; the rules of the site language are added to them. Searches give the same results as before plus the new forms. No reindex is needed — the endings are applied to the query.
- **Ignored words**: an empty list (the new default) uses a built-in list — Polish and English words plus those of the site language (e.g. *für, und, mit* in German, *pour, avec, l'* in French, *na, pro, nebo* in Czech, *voor, met, van* in Dutch). Sites that kept the old default list get the built-in list automatically; an own list is kept as it is.
- **Typo correction** and **synonyms** already worked in every language: typos are corrected against the words of your own products, and synonyms are your own dictionary.

## 1.6.6 — 2026-10-07

### 🌍 Languages

- The extension now ships **English (default), German, Polish, French, Czech and Dutch** — the settings follow the language of the Joomla administrator and the texts on the site the language of the site; any other language shows English, also text by text where a translation lacks one.
- **French, Czech and Dutch** translated in full (plugin settings, tools, the search module and every text shoppers see).
- **German and Polish** reviewed against the English texts and corrected.
- **Ukrainian removed**; its files left by earlier versions are removed on update, so those sites show English.

### 🐞 Fixes

- *Tools → Report*: the label in front of the recipients said “To” (in German “Bis”) — one text was used for both the recipients and the end date of the period. They now have separate texts.

## 1.6.5 — 2026-10-06

### 🚫 Only published items in the pickers

- The product and page pickers (tab *Ranking & exclusions*: rules, boosted products, excluded products and pages; also the actions in the statistics) list and find **only published products and pages**. Unpublished ones are left out, as the search on the site never shows them anyway.
- Items chosen earlier that have been unpublished since stay in their lists, marked *unpublished*, so they can still be removed.

## 1.6.4 — 2026-10-06

### ✍️ How the query is marked — one choice in Appearance

- **New option *Query in the results*** (tab *Appearance*, under the themes): the words of the query in the names of the results (e.g. “Metrel”) can be **bold**, **highlighted** (colour and background of the highlight), **underlined**, any of them together, all three, or **not marked**. Works with every theme; the preview shows it at once.
- It replaces the switches *Mark the query in results*, *Query in bold* and *Query highlighted* of the tab *Texts & images*; their settings are kept until the new option is saved. The highlight colours of the Basic theme moved along to *Appearance*.
- The theme editor no longer underlines the names of settings overridden by the own CSS (the note under the setting stays).

### 🎬 Appearing effects of the live panel

- **Fix:** the effect of the panel was played when the field got the focus — with the short list of the last searches — and not again when the results came, so it was hardly ever seen. Now the results arrive with the effect as well.
- The movements are more visible (slides 28 px instead of 10–12, zoom from 88 %, flip 28°; the effects of the results likewise).
- The preview says when the administrator's own system asks for less motion (Windows: *Animation effects* off): browsers then play no effects — by design, for those visitors only.

## 1.6.3 — 2026-10-06

### 🔁 Old product names through redirects

- **New option *Find products by redirects*** (tab *Search engine*, on by default): when a product is replaced by another one and its address is redirected to the new product in Joomla (*System → Redirects*), a search for the old name — e.g. `AAA` — shows the new product `BBB`.
- Such results carry the badge **“Replaced”** (tab *Products*: on/off and colour; tab *Texts & images*: own text). On hover the badge names the old product (“Replaces: AAA”).
- The old name is the title of the old product while it is still in Gridbox (unpublished or in the trash), otherwise the words of its old address; the *Note* of the redirect counts as one more name (e.g. the old model code). Chains of redirects (A → B → C) are followed; addresses with or without the domain, `.html` and non-SEF `index.php?option=com_gridbox&view=page&id=…` are understood.
- A product that matches the query better by its own name is shown as before, without the badge. Changed redirects reach the results within a minute; no reindexing is needed.

### ✍️ Bold and highlight of the query separately

- Tab *Texts & images*: two new switches under *Mark the query in results* — **Query in bold** and **Query highlighted** (colour and background of the highlight). Each can be turned off on its own, also with a theme.

### 🎨 Theme editor: settings overridden by the own CSS

- **Fix:** changing e.g. the shadow or the font in the visual editor showed no effect when the theme's own CSS (tab *CSS*) set the same values — typically after *Insert theme CSS*, which copies all values of the theme into the own CSS, where they win.
- The editor now marks such settings (“Set in the own CSS: `--bs-shadow`”) and says how many there are; changing a setting removes its value from the own CSS, and *Remove them from the own CSS* removes all of them at once. The rest of the own CSS stays.
- *Insert theme CSS* adds a note that the inserted values win over the visual editor.
- Polish texts: “czcionka” renamed to “font”.

## 1.6.2 — 2026-10-06

### 🧑‍💼 Statistics of customers only

- **New option *Leave out of the statistics (IP addresses)*** (tab *Plugin*): searches, clicks on results and products put into the cart from these addresses are not counted — in the search statistics, the conversions and the e-mail report — so the company's own staff does not distort what customers search. Addresses (`83.1.2.3`, `2001:db8::1`), networks (`91.200.10.0/24`, `2001:db8::/48`) and IPv4 addresses with `*` (`10.48.*`), one per line or separated by commas; text after `#` is a comment.
- Under the list: **your address as the site sees it**, whether it is counted, and a button **Add my address**.
- **Works behind Cloudflare and other proxies:** the visitor's address is read from `CF-Connecting-IP`, `X-Real-IP` or `X-Forwarded-For` when present (advanced option *Visitor address from*; *Connection only* for sites without a proxy).
- **Advanced:** *Leave out logged-in users of the groups* — searches of logged-in members of chosen user groups (e.g. staff) are not counted, from any address.
- The addresses are only compared, never stored. The search itself works the same for everybody.

### 📅 E-mail report for any period

- The *E-mail report* tab has a **period with a calendar (from – to)**: *Preview* and *Send now* prepare the report for any days of the last 400 (the statistics are kept that long), both days included, compared with the period of the same length before.
- Quick choices: **last 7 days, last 30 days, previous month, this month, this year**, and *Automatic period* (the period of the regular report, filled in at first).
- The subject of the e-mail names the period. The regular automatic report keeps its own schedule and period.

## 1.6.1 — 2026-10-06

### ↕️ Order of the result sections

- **New option *Order of the result sections*** (tab *Live results*): drag the matching categories, each app (store, blog…) and the pages into the order in which they appear in the live results — e.g. pages first, then products, then blog posts. The first section gets the most results (*Products shown*), the others *Items of other apps*. Keyboard navigation follows the order on the screen; the arrows ↑ ↓ work without a mouse; *Default order* goes back to the order of earlier versions (categories, the searched apps, pages).
- The **app filters of the results page** follow the same order.
- **New option *Group by the order of the sections*** (tab *Results page*, off by default): with *Best match* the results page lists the results of the first section first, then the next one, by relevance inside each.

### 🎨 Accent as in Gridbox

- **Every theme can keep the accent colour of the site:** the switch *Accent as in Gridbox* uses the primary colour of the Gridbox theme (`--primary`) instead of the theme's own accent. Colours the theme derives from its accent — prices, highlights, the hovered row — follow it too, unless you changed them. The preview and the gallery show the site's real colour.

### 🔤 More fonts, Google Fonts

- **14 font choices instead of 6**, all of them safe system font stacks (nothing is downloaded): system, neo-grotesque, humanist, geometric, classical humanist, rounded, industrial, transitional serif, old-style serif, slab serif, didone, monospace, handwritten — or the font of the site.
- **Google Fonts:** type the name of any Google font (suggestions of popular ones included); the editor checks that it exists and which weights it has, and the preview shows it at once. On the site it is loaded with `display=swap`. A note reminds that the visitors' browsers then connect to Google.

### 🌫️ Background opacity

- A new slider **Background opacity** (0–100 %) for the live results panel and the result cards, with any background colour. The Glass theme now uses a white background at 72 % instead of a fixed translucent colour, so its translucency can be adjusted.

### 🎨 Theme editor on wide screens

- **The visual editor no longer squeezes its controls into three narrow columns** on wide screens: colour values (`#2563eb`, `rgba(255,255,255,.72)`) and the names in the lists were cut off and the switches folded into a column. It now uses at most two roomy columns, the switches stay in one row.
- **Swatches show the real colour, also a translucent one** (e.g. the Glass theme), on a checkerboard; the full value is shown on hover over the field.
- The badge *as before* of the Basic theme no longer sticks out of its card in longer translations.

## 1.6.0 — 2026-10-06

### 🎨 Themes

- **New tab *Appearance* with a theme gallery.** Five modern looks for the live results and the results page, each with a miniature: **Minimal** (clean lines, hairline borders, outlined buttons), **Soft** (large rounded corners, airy shadows, pill buttons), **Glass** (frosted translucent panel with a blurred background), **Dark** (dark surfaces, sky-blue accent) and **Bold** (thick black outlines, hard offset shadows, vivid orange).
- **Nothing changes after the update.** The theme **Basic** is selected by default: it is the look of earlier versions, driven by the colour and shape settings of the *Live results* and *Results page* tabs — the rendered result is identical, pixel for pixel. *Back to the Basic theme* returns to it at any time; the colour settings of those tabs are kept untouched while another theme is active (they are hidden there, with a note).
- **Visual theme editor:** every part of the theme — 15 colours (accent, text on accent, backgrounds, texts, names, prices, hover, borders, images, highlight, fields), corners of the panel, cards and buttons, border width, panel and card shadows (soft, strong, floating, hard offset, glow), the card hover effect (lift, shadow, zoom, border, glow, shift), font, weight of names, section titles, button style (solid, outlined, tinted) and the glass blur. Changed values are marked and can be reset one by one or all together; the miniature in the gallery follows the changes.
- **CSS editor:** own CSS for each theme (also for Basic), added after the theme so it wins over it — paste or write code with line numbers, a brace check and Tab indenting. *Insert theme CSS* puts the theme's variables and rules into the editor to change them as code. Selectors of the results page written with `.bettersearch-results` get the block's id automatically, so they win over the plugin's own rules.
- **Live preview beside the editors:** every change — a colour, a slider, a line of CSS — shows at once in the preview of the live results and of the results page (desktop, tablet, phone).
- Changes are kept **per theme**: trying another theme and coming back loses nothing.

### 🎚️ Basic and advanced settings

- A switch **Basic / Advanced** above the tabs. *Basic* shows the settings most sites need; *Advanced* shows all of them (weights, timings, sizes, texts, synchronisation, Google Search Console…). Tabs left without a setting are hidden. Hidden settings keep their values; the choice is saved with the settings.

## 1.5.3 — 2026-10-05

### 🏷️ Product badges in the results

- The badges set on the products in Gridbox — e.g. **“New!”, “Recommended”, “Bestseller”** — now appear in the search results, in their own colours and in the order set on the product: in the top corner of the cards on the results page and above the name in the live results. The sale badge shows the discount as on the product (“- 15%”); a product without a sale price gets none.
- *Featured* from a query rule stays first, next to them.
- New options on the *Products* tab: **Product badges** (results page and live results, one of them, or hidden) and **Badges per product at most** (3).

## 1.5.2 — 2026-10-05

### 🐞 Fixes

- **Pages appear in the results right after switching *Search pages too* on.** The index is updated in steps of a few hundred items; after a settings change (or an update that reads values anew) every product was indexed again first, and the pages — never indexed before — waited behind thousands of products, so for a while no page could be found. Items missing from the index now go first, then pages edited since, and only then the items indexed again (they stay searchable meanwhile).

### 🩺 Diagnostics

- The index **Status** shows a table for every searched app: pages in Gridbox, how many a visitor cannot see and why (unpublished, outside the publishing dates, another language, not for guests) and how many are **in the index** — a row is marked when pages are missing from it.
- **The plugin's own log file:** errors go to `administrator/logs/plg_system_bettersearch.php`, and the Status shows the latest lines.

## 1.5.1 — 2026-10-05

The technical values are read by the same rules as in Better Categories for Gridbox 1.6.0.

### 🐞 Fixes

- **Numbers of standards and model names are no longer read as values.** “Tests to PN-EN 62446 up to 1000 V DC” was indexed as the voltage range 1000…62 446 V, so the product was found for any voltage up to 62 kV. Designations of standards (PN-EN, PN-HD, EN, IEC, ISO, DIN, VDE, BS, UL…), numbers glued to a model name with a hyphen (“C-4A”, “APS-1102A”) and “ranges” written from a larger number down to a smaller positive one (“5 700~1000 A” in a table) are skipped. “PN 16 bar”, “DC-150 kHz” and ranges to negative values (“0 ~ -32 V”) still read as values.

### 🌡️ HVACR values

- New units in products and queries: **relative humidity** (%RH), **air velocity** (m/s), **flow** (m³/h; l/min and l/h converted), **concentration** (ppm) and **irradiance** (W/m²).
- **Pressure in any unit is one value:** Pa, hPa, kPa, MPa, mbar, bar and psi — “16 bar” finds “1,6 MPa” and “232 psi”.
- **°F is converted to °C**: “68 °F” finds “20 °C”.
- The index format changed: after the update every product is indexed again (automatically, or at once with *Update index*).

## 1.5.0 — 2026-10-05

Technical values searched as values, featured products for chosen phrases, pages in the results, recent searches, availability with the delivery time, buttons to the cart and to a quote, and a regular e-mail report of what shoppers search for.

### 🔢 Technical values

- Values with a unit are compared as numbers, not as words: **`1000 V` = `1 kV` = `1000V`**, `200 GΩ`, `50 Hz`, `10 mA`, **`CAT IV` = `kat. IV`**, **`IP67`**. Units V, A, W, VA, Wh, Ah, Hz, Ω, F, °C, dB, lx, Pa, bar, m with the prefixes p, n, µ, m, k, M, G.
- **Ranges:** a range in the query (`-20…50 °C`, `0-600 V`, `od -10 do 40 °C`) finds the products whose range covers it (`-20…+60 °C`); a single value finds the products whose range contains it (`600 V` → a meter for `0–1000 V`).
- More precise: `5 kV` no longer finds `2,5 kV` (the comma used to split “2,5kV” into “2” and “5kv”).
- When no product has the value, the query is searched as words, as before. The test console shows the values read from the query and why each result matched. Option *Technical values* and a weight on the *Search engine* tab.
- The index format changed: after the update every product is indexed again (automatically, or at once with *Update index*).

### ⭐ Featured products

- A query rule with the new action **Feature** puts the chosen products at the top for its phrases (e.g. your own meter first for “miernik uniwersalny”), in the order you set, and **marks them**: a badge (“Recommended”, own text), a frame on the results page and a tint in the live results, in a colour of your choice (*Products* tab).

### 📄 Pages in the results

- **Search pages too** (*Plugin* tab): single Gridbox pages — Pages and single-page apps, e.g. services, contact, about us — are found as their own group **“Pages”** (live results and a filter on the results page), without price or cart.
- **Excluded pages** (*Ranking & exclusions*): pages that never show up, e.g. thank-you pages or the privacy policy.

### 🕘 Recent searches

- A click into an empty search field shows the visitor's **last searches** (5 by default, up to 20); a click searches again, “×” removes one, “Clear” removes all — on phones in the full-screen search too.
- Kept only in the visitor's own browser (`localStorage`); nothing is sent to the site.

### 📦 Products: availability, cart, quote (off by default)

- **Availability and delivery time:** *available*, **last items** (from a quantity you set) or *out of stock* from the Gridbox stock, optionally with the quantity (“Available (12 pcs)”), and the delivery time from a Gridbox product field or from a text for products in and out of stock (“Shipped within 24 h”, “On order: 7–14 days”). In the live results, on the results page, or both.
- **“Add to cart”:** puts one piece into the Gridbox cart without leaving the results, then opens the Gridbox cart of the page (or only confirms on the button). Products with variants or extra options get **“Choose options”** (a link to the product); products out of stock or without a price get no button. Counted in the conversion statistics like any cart addition after a search.
- **“Ask for a quote”:** a link to your contact page with the product (`/kontakt?produkt={title}`, also `{sku}`, `{id}`, `{url}`, `{query}`), any https:// or `mailto:` address, or by default an e-mail to the site with the product in the subject — for products without a price, out of stock, either, or all.

### 📧 E-mail report

- A new **E-mail report** tab: **every week, every two weeks or every month** a summary goes to the addresses you enter (or the Super Users): searches, different phrases, searches without results — each compared with the period before — plus the clicks on results and cart additions; the **most searched phrases** with their results and change, the **phrases without results** and the **phrases that led to the cart**.
- Sent with the first visit of the site after 7:00 site time on Monday or on the 1st of the month, once per period, through the mail settings of the Global Configuration. **Preview** and **Send now** in the settings.
- Searches are now also counted per day (kept for 400 days, no personal data).

### 🔗 Google Search Console

- The table of Google queries **sorts by any column** — query, clicks, impressions, **CTR** (new column), position, and *results here* after a check — with a click on its heading (again: the other direction); a **filter** and 100 / 200 / 500 / 1 000 rows; the count of queries shown and in total.

### 🔧 Under the hood

- New column `t_params` in the index and table `#__bettersearch_daily`; both are added by the update and, should the update script not run, by the plugin itself.
- 127 new texts in English, Polish, Ukrainian and German.

---

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
