## Better Search for Gridbox 1.4.3

### 🐛 Fixed: help tooltips

- The **“?”** beside the option names still showed no explanation on some sites, neither on hover nor on a click.
  - The tooltip was marked as `role="tooltip"`; the Joomla administrator template applies its own rules to such elements and squeezed it to the width of the icon.
  - A click on the “?” in browsers that focus a button on click (Chrome, Edge on Windows) showed the tooltip and hid it again at once.
- The tooltip is now part of the “?” itself and shown by CSS: on hover, on keyboard focus (Tab) and on a click (it then stays open until a click elsewhere or Escape). No script positions it, so no administrator template can move or hide it. It is 22rem wide and never wider than the window.

---

**Tested on:** Joomla 6.1.3 (Atum), PHP 8.5.10, Gridbox 2.20.3.1: hover with a real mouse on a scrolled page, click, second click, click elsewhere, Escape, phone width.

**Install:** download `pkg_bettersearch-1.4.3.zip` below and upload it in *System → Install → Extensions*, or update in *System → Update → Extensions*.
