## Better Search for Gridbox 1.1.0

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

**Install:** download `pkg_bettersearch-1.1.0.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
