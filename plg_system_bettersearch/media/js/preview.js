/**
 * Better Search for Gridbox — live preview in the plugin settings. Sends the unsaved form values to
 * com_ajax (the plugin renders the live results or the results page itself) and shows the result in
 * an iframe at the real width of the chosen device, scaled to the column.
 */
(() => {
  'use strict';

  const WIDTHS = { desktop: 1280, tablet: 820, mobile: 390 };
  const PHONE_HEIGHT = 760;
  const BASE = 'html,body{margin:0}body{font:15px/1.5 Roboto,"Segoe UI",Arial,sans-serif;color:#333;background:#fff;--primary:#1a73e8}'
    + '.demo-bar{background:#f4f6f8;border-bottom:1px solid #e3e7eb;padding:14px 24px;display:flex;align-items:center;gap:24px}'
    + '.demo-logo{width:110px;height:26px;border-radius:4px;background:#d5dbe1;flex:none}'
    + '.demo-search{flex:0 1 420px;display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #cfd6dd;border-radius:6px;padding:8px 12px;color:#111}'
    + '.demo-search span{flex:1;white-space:nowrap;overflow:hidden}.demo-search span::after{content:"";display:inline-block;width:1px;height:1.1em;margin-left:1px;background:#111;vertical-align:-.15em;animation:caret 1s steps(1) infinite}'
    + '@keyframes caret{50%{opacity:0}}'
    + '.demo-page{padding:24px}.demo-lines div{height:12px;border-radius:6px;background:#eef1f4;margin:0 0 12px}'
    + '.demo-heading{margin:0 0 20px;font-size:30px;line-height:1.2}';

  const opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_bettersearch') : null;
  const form = () => document.getElementById('style-form') || document.querySelector('form[name="adminForm"]');
  const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  function liveDocument(r, device) {
    const l = r.layout || {};
    const script = '<script>(function(){var p=document.getElementById("bs-live"),w=' + JSON.stringify(l) + ';'
      + 'function place(){if(w.full)return;var f=document.querySelector(".demo-search").getBoundingClientRect(),vw=document.documentElement.clientWidth,'
      + 'width=w.widthMode==="fixed"?w.width:(w.widthMode==="wide"?Math.max(f.width,w.width):f.width);width=Math.min(width,vw-16);'
      + 'var left=w.align==="right"?f.right-width:(w.align==="center"?f.left+f.width/2-width/2:f.left);left=Math.max(8,Math.min(left,vw-width-8));'
      + 'p.style.left=left+"px";p.style.top=(f.bottom+w.offset)+"px";p.style.width=width+"px";}'
      + 'var html=p.innerHTML;window.bsReplay=function(){p.classList.remove("is-shown");p.innerHTML=html;void p.offsetWidth;p.classList.add("is-shown");};'
      + 'place();addEventListener("resize",place);requestAnimationFrame(function(){p.classList.add("is-shown")});})();<\/script>';
    const panel = l.full
      ? '<div class="bs-live is-open is-full" id="bs-live" style="position:fixed"><div class="bs-full-head"><input type="search" value="' + esc(r.q)
        + '" readonly><button type="button" aria-label="close">&times;</button></div>' + r.html + '</div>'
      : '<div class="bs-live is-open" id="bs-live" style="position:absolute">' + r.html + '</div>';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + BASE + r.css
      + '</style></head><body><div class="demo-bar"><div class="demo-logo"></div><div class="demo-search"><span>' + esc(r.q) + '</span>'
      + '<svg width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M10 2a8 8 0 0 1 6.32 12.9l5.39 5.4-1.42 1.4-5.39-5.38A8 8 0 1 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/></svg></div></div>'
      + '<div class="demo-page demo-lines">' + '<div style="width:70%"></div><div></div><div style="width:85%"></div>'.repeat(4) + '</div>'
      + panel + script + '</body></html>';
  }

  function pageDocument(r) {
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + BASE + r.css
      + '</style></head><body><div class="demo-page">' + (r.heading ? '<h1 class="demo-heading">' + esc(r.heading) + '</h1>' : '') + r.html + '</div></body></html>';
  }

  function setup(panel) {
    const frame = panel.querySelector('iframe');
    const box = panel.querySelector('.bs-preview-frame');
    const status = panel.querySelector('.bs-preview-status');
    const query = panel.querySelector('.bs-preview-query input');
    const replay = panel.querySelector('[data-bs-replay]');
    let view = panel.dataset.mode === 'page' ? 'page' : 'live';
    let device = 'desktop';
    let timer = null;
    let seq = 0;
    let controller = null;
    let dirty = true;
    let full = false;
    let liveWidth = 640;

    // the live panel does not depend on the screen width (except phones): a desktop preview as wide as the panel is easier to read
    const frameWidth = () => (view === 'live' && device === 'desktop') ? Math.min(WIDTHS.desktop, Math.max(760, liveWidth + 140)) : WIDTHS[device];

    const resize = () => {
      const width = frameWidth();
      const scale = Math.min(1, (box.clientWidth || width) / width);
      let height = 320;
      try {
        const doc = frame.contentDocument;
        if (full) {
          height = PHONE_HEIGHT;
        } else if (view === 'live') {
          const p = doc.getElementById('bs-live');
          height = Math.max(360, p ? p.getBoundingClientRect().bottom + 24 : 0, doc.documentElement.scrollHeight);
        } else {
          height = Math.max(320, doc.documentElement.scrollHeight + 4);
        }
      } catch (e) { /* not loaded yet */ }
      frame.style.width = width + 'px';
      frame.style.height = height + 'px';
      frame.style.transform = 'scale(' + scale + ')';
      frame.style.marginLeft = Math.max(0, (box.clientWidth - width * scale) / 2) + 'px';
      box.style.height = Math.ceil(height * scale) + 'px';
    };
    window.addEventListener('resize', resize);
    frame.addEventListener('load', () => { resize(); setTimeout(resize, 400); });

    function body() {
      const data = new FormData();
      new FormData(form()).forEach((value, key) => {
        if (key.indexOf('jform[params]') === 0) data.append(key, value);
      });
      return data;
    }

    async function refresh() {
      if (!visible(panel)) {
        dirty = true;
        return;
      }
      dirty = false;
      const mine = ++seq;
      if (controller) controller.abort();
      controller = new AbortController();
      status.textContent = (opts.texts && opts.texts.PREVIEW_UPDATING) || '…';
      try {
        const url = opts.ajax + '&task=admin_preview&mode=' + view + '&device=' + device + '&q=' + encodeURIComponent(query.value.trim());
        const response = await fetch(url, { method: 'POST', body: body(), credentials: 'same-origin', signal: controller.signal });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const json = await response.json();
        const r = json && json.data && json.data[0];
        if (mine !== seq) return;
        if (!r || r.error || typeof r.html !== 'string') {
          status.textContent = (r && r.error) || (json && json.message) || 'Preview unavailable.';
          return;
        }
        if (!query.value.trim()) query.value = r.q;
        full = view === 'live' && !!(r.layout && r.layout.full);
        if (r.layout) liveWidth = r.layout.widthMode === 'input' ? 420 : r.layout.width;
        // the frame gets its width before the document loads, so the panel is placed for that width
        frame.style.width = frameWidth() + 'px';
        replay.hidden = view !== 'live';
        frame.srcdoc = view === 'live' ? liveDocument(r, device) : pageDocument(r);
        status.textContent = (opts.texts && opts.texts.PREVIEW_COUNT ? opts.texts.PREVIEW_COUNT.replace('%d', r.count) : r.count);
      } catch (e) {
        if (e.name === 'AbortError') return;
        if (mine === seq) status.textContent = 'Preview unavailable: ' + e.message;
      }
    }

    const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };
    panel.bsRefresh = schedule;
    panel.bsShown = () => { if (dirty) schedule(); else resize(); };

    const group = (attr, set) => panel.querySelectorAll('[' + attr + ']').forEach((button) => {
      button.addEventListener('click', () => {
        panel.querySelectorAll('[' + attr + ']').forEach((b) => b.classList.replace('btn-primary', 'btn-outline-secondary'));
        button.classList.replace('btn-outline-secondary', 'btn-primary');
        set(button);
        refresh();
      });
    });
    group('data-bs-device', (b) => { device = b.dataset.bsDevice; });
    group('data-bs-view', (b) => { view = b.dataset.bsView; });
    replay.addEventListener('click', () => {
      try { frame.contentWindow.bsReplay(); } catch (e) { refresh(); }
    });
    query.addEventListener('input', (e) => { e.stopPropagation(); schedule(); });
    query.addEventListener('change', (e) => e.stopPropagation());
    query.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); refresh(); } });
    refresh();
  }

  function start() {
    const panels = [...document.querySelectorAll('.bs-preview')];
    const root = form();
    if (!opts || !panels.length || !root) return;
    panels.forEach(setup);
    const refreshAll = (e) => {
      if (e && e.target && e.target.closest && (e.target.closest('.bs-preview') || e.target.closest('.bs-tools'))) return;
      panels.forEach((p) => p.bsRefresh());
    };
    root.addEventListener('input', refreshAll);
    root.addEventListener('change', refreshAll);
    document.addEventListener('subform-row-add', refreshAll);
    document.addEventListener('subform-row-remove', refreshAll);
    document.addEventListener('joomla:updated', refreshAll);
    const shown = () => setTimeout(() => panels.forEach((p) => p.bsShown()), 60);
    document.addEventListener('joomla.tab.shown', shown);
    document.querySelectorAll('button[role="tab"]').forEach((t) => t.addEventListener('click', shown));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
