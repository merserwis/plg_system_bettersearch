## Better Search for Gridbox 1.4.1

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

**Install:** download `pkg_bettersearch-1.4.1.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
