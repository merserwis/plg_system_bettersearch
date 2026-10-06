/**
 * Better Search for Gridbox — administrator: the switch between basic and advanced settings, and
 * the Appearance tab (theme gallery, visual theme editor, CSS editor). The editors write the hidden
 * setting "theme_custom" (JSON {"<theme>": {"vars": {...}, "css": "..."}}); every change reaches the
 * live preview (preview.js listens to the form).
 */
(() => {
  'use strict';

  const opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_bettersearch') : null;
  const COLOR = /^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%a-z]+\)|transparent|var\(--[a-z0-9-]+\))$/i;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // ================================================================ basic / advanced settings

  function initMode() {
    const box = document.querySelector('.bs-mode');
    const form = box && box.closest('form');
    if (!form) return;
    const tabs = form.querySelector('joomla-tab');
    const group = box.closest('.control-group');
    if (tabs) {
      tabs.parentNode.insertBefore(box, tabs);
      if (group) group.hidden = true;
    }

    const apply = () => {
      const checked = box.querySelector('input:checked');
      const basic = !checked || checked.value !== 'advanced';
      form.classList.toggle('bs-basic', basic);
      if (!tabs) return;
      let activeHidden = false;
      tabs.querySelectorAll('[role="tab"][aria-controls]').forEach((button) => {
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) return;
        const empty = basic && !panel.querySelector('.control-group:not(.bs-adv)');
        button.hidden = empty;
        if (empty && button.getAttribute('aria-selected') === 'true') activeHidden = true;
      });
      if (activeHidden) {
        const first = [...tabs.querySelectorAll('[role="tab"][aria-controls]')].find((b) => !b.hidden);
        if (first) first.click();
      }
    };
    box.addEventListener('change', (e) => { e.stopPropagation(); apply(); });
    apply();
    // tabs may be built after this script: once more when they exist
    setTimeout(apply, 400);
  }

  // ================================================================ themes

  function call(task, form) {
    const body = new FormData();
    new FormData(form).forEach((value, key) => {
      if (key.indexOf('jform[params]') === 0) body.append(key, value);
    });
    return fetch(opts.ajax + '&task=admin_' + task, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((json) => {
        const data = json && json.data && json.data[0];
        if (!data || data.error) throw new Error((data && data.error) || (json && json.message) || 'error');
        return data;
      });
  }

  const MOCK_SHADOWS = {
    none: 'none', soft: '0 2px 7px rgba(0,0,0,.09)', strong: '0 8px 18px rgba(0,0,0,.2)',
    float: '0 12px 24px -10px rgba(15,23,42,.4),0 1px 3px rgba(15,23,42,.08)', hard: '3px 3px 0 var(--m-border)',
    glow: '0 0 0 1px color-mix(in srgb,var(--m-accent) 35%,transparent),0 6px 16px color-mix(in srgb,var(--m-accent) 30%,transparent)',
  };
  const GOOGLE_POPULAR = ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Nunito', 'Raleway', 'Source Sans 3', 'Work Sans',
    'DM Sans', 'Manrope', 'Plus Jakarta Sans', 'Outfit', 'Figtree', 'Rubik', 'Karla', 'Mulish', 'Barlow', 'IBM Plex Sans', 'Noto Sans',
    'PT Sans', 'Ubuntu', 'Oswald', 'Space Grotesk', 'Playfair Display', 'Merriweather', 'Lora', 'Roboto Slab', 'JetBrains Mono'];
  // weights tried in turn: Google refuses a request with a weight the font does not have
  const GOOGLE_WEIGHTS = ['400;500;600;700;800', '400;500;600;700', '400;600;700', '400;700', '400'];
  const loadedFonts = new Set();
  function loadFont(spec) {
    if (!spec || loadedFonts.has(spec)) return;
    loadedFonts.add(spec);
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' + spec.replace(/ /g, '+') + '&display=swap';
    document.head.appendChild(link);
  }

  function initThemes() {
    const root = document.querySelector('.bs-themes');
    const form = root && root.closest('form');
    const hidden = form && form.querySelector('[name="jform[params][theme_custom]"]');
    if (!root || !hidden) return;
    const cfg = JSON.parse(root.dataset.config || '{}');
    const T = cfg.texts || {};
    const editor = root.querySelector('.bs-theme-editor');
    const back = root.querySelector('.bs-theme-back');
    let custom = {};
    try { custom = JSON.parse(hidden.value || '{}') || {}; } catch (e) { custom = {}; }
    if (typeof custom !== 'object' || Array.isArray(custom)) custom = {};
    let view = 'visual';
    const FONTS = cfg.fonts || {};
    const fontOf = (t) => (t.font === 'google' && t.google_font ? '"' + t.google_font + '",system-ui,sans-serif' : (FONTS[t.font] || 'inherit'));
    const accentOf = (t) => (t.accent_site === '1' ? (cfg.primary || t.accent || '#1a73e8') : (t.accent || '#1a73e8'));
    let checkTimer = null;
    let checkSeq = 0;

    const current = () => (root.querySelector('.bs-theme-radio:checked') || {}).value || 'default';
    const entry = (key) => (custom[key] = custom[key] || {});
    const tokensOf = (key) => Object.assign({}, cfg.presets[key] || {}, (custom[key] && custom[key].vars) || {});

    function save() {
      Object.keys(custom).forEach((key) => {
        const e = custom[key];
        if (e.vars && !Object.keys(e.vars).length) delete e.vars;
        if (typeof e.css === 'string' && !e.css.trim()) delete e.css;
        if (!Object.keys(e).length) delete custom[key];
      });
      hidden.value = Object.keys(custom).length ? JSON.stringify(custom) : '';
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function mock(card, t) {
      const st = card.querySelector('.bs-theme-mock').style;
      const set = (k, v) => st.setProperty(k, v);
      const op = t.bg_opacity == null ? 100 : +t.bg_opacity;
      set('--m-bg', op < 100 ? 'color-mix(in srgb,' + (t.bg || '#fff') + ' ' + op + '%,transparent)' : (t.bg || '#fff'));
      set('--m-surface', t.surface || '#eef1f4');
      set('--m-text', t.text || '#1f2328');
      set('--m-muted', t.muted || '#6b7280');
      set('--m-accent', accentOf(t));
      set('--m-on', t.on_accent || '#fff');
      set('--m-hover', t.hover || '#f3f4f6');
      set('--m-border', t.border || '#e5e7eb');
      set('--m-img', t.img_bg || '#fff');
      set('--m-r', Math.round((+t.radius || 0) * 0.55) + 'px');
      set('--m-bw', (t.border_width == null ? 1 : +t.border_width) + 'px');
      set('--m-shadow', MOCK_SHADOWS[t.shadow] || 'none');
      set('--m-ff', fontOf(t));
      if (t.font === 'google') loadFont(t.google_spec);
      set('--m-tw', t.title_weight || '600');
      card.classList.toggle('is-glass', (+t.blur || 0) > 0);
    }

    function mocks() {
      root.querySelectorAll('.bs-theme-card').forEach((card) => mock(card, tokensOf(card.dataset.theme)));
    }

    // ---- visual editor controls

    function control(token, kind, value, preset) {
      const id = 'bs-te-' + token;
      const changed = String(value) !== String(preset);
      let input = '';
      if (kind === 'color') {
        const hex = /^#[0-9a-f]{6}$/i.test(value) ? value : (/^#[0-9a-f]{3}$/i.test(value) ? '#' + value.slice(1).replace(/./g, '$&$&') : '#ffffff');
        input = '<span class="bs-te-color' + (value ? '' : ' is-empty') + '"><span class="bs-te-sw" style="--sw:' + esc(value || 'transparent') + '"><input type="color" id="' + id + '" value="' + hex + '" data-part="picker"></span>'
          + '<input type="text" class="form-control form-control-sm" value="' + esc(value) + '" title="' + esc(value) + '" placeholder="' + esc(T.AS_SITE) + '" data-part="text" spellcheck="false" aria-label="' + esc(T['T_' + token]) + '"></span>';
      } else if (kind === 'bool') {
        input = '<label class="bs-te-switch"><input type="checkbox" id="' + id + '" data-part="bool"' + (value === '1' ? ' checked' : '') + '><span aria-hidden="true"></span>'
          + '<small>' + esc(T.ACCENT_SITE_HINT.replace('%s', cfg.primary || '—')) + '</small></label>';
      } else if (kind === 'gfamily') {
        input = '<span class="bs-te-gfont"><input type="text" class="form-control form-control-sm" id="' + id + '" list="bs-te-gfonts" value="' + esc(value) + '" placeholder="Inter, Roboto, Lora…" spellcheck="false" autocomplete="off">'
          + '<datalist id="bs-te-gfonts">' + GOOGLE_POPULAR.map((f) => '<option value="' + esc(f) + '">').join('') + '</datalist>'
          + '<small class="bs-te-gstate" aria-live="polite"></small><small class="bs-te-gnote">' + esc(T.GOOGLE_NOTE) + '</small></span>';
      } else if (Array.isArray(kind)) {
        if (kind.length <= 3) {
          input = '<span class="bs-te-seg" role="radiogroup" aria-label="' + esc(T['T_' + token]) + '">' + kind.map((o) => '<button type="button" class="' + (o === String(value) ? 'is-on' : '')
            + '" data-value="' + o + '" aria-pressed="' + (o === String(value)) + '">' + esc(T['O_' + o] || o) + '</button>').join('') + '</span>';
        } else {
          input = '<select class="form-select form-select-sm" id="' + id + '">' + kind.map((o) => '<option value="' + o + '"' + (o === String(value) ? ' selected' : '') + '>'
            + esc(T['O_' + o] || o) + '</option>').join('') + '</select>';
        }
      } else {
        const [, min, max] = kind.split(':');
        input = '<span class="bs-te-range"><input type="range" id="' + id + '" min="' + min + '" max="' + max + '" step="1" value="' + esc(value) + '">'
          + '<output>' + esc(value) + (token === 'bg_opacity' ? ' %' : ' px') + '</output></span>';
      }
      return '<div class="bs-te-row' + (changed ? ' is-changed' : '') + '" data-token="' + token + '"><label for="' + id + '">' + esc(T['T_' + token] || token) + '</label>'
        + '<div class="bs-te-input">' + input + '<button type="button" class="bs-te-reset" title="' + esc(T.TOKEN_RESET) + '" aria-label="' + esc(T.TOKEN_RESET) + '">↺</button></div></div>';
    }

    function setToken(key, token, value) {
      const e = entry(key);
      e.vars = e.vars || {};
      const preset = (cfg.presets[key] || {})[token];
      if (String(value) === String(preset)) delete e.vars[token];
      else e.vars[token] = typeof preset === 'number' ? Number(value) : value;
      const row = editor.querySelector('.bs-te-row[data-token="' + token + '"]');
      if (row) row.classList.toggle('is-changed', String(value) !== String(preset));
      updateHead(key);
      deps(key);
      mock(root.querySelector('.bs-theme-card[data-theme="' + key + '"]'), tokensOf(key));
      save();
    }

    // rows that depend on another one: the Google font name, the accent colour under "as in Gridbox"
    function deps(key) {
      const t = tokensOf(key);
      const g = editor.querySelector('.bs-te-row[data-token="google_font"]');
      if (g) g.hidden = t.font !== 'google';
      const a = editor.querySelector('.bs-te-row[data-token="accent"]');
      if (a) {
        a.classList.toggle('is-site', t.accent_site === '1');
        a.querySelectorAll('input').forEach((i) => { i.disabled = t.accent_site === '1'; });
        a.querySelector('.bs-te-sw').style.setProperty('--sw', accentOf(t));
      }
    }

    // a Google font: find the weights it has (and whether it exists at all)
    async function checkGoogle(key, family) {
      const row = editor.querySelector('.bs-te-row[data-token="google_font"]');
      const state = row && row.querySelector('.bs-te-gstate');
      const mine = ++checkSeq;
      family = family.trim().replace(/\s+/g, ' ');
      if (!family) {
        setToken(key, 'google_spec', '');
        if (state) { state.className = 'bs-te-gstate text-muted'; state.textContent = T.GOOGLE_EMPTY; }
        return;
      }
      if (!/^[A-Za-z0-9][A-Za-z0-9 ]{0,59}$/.test(family)) {
        if (state) { state.className = 'bs-te-gstate text-danger'; state.textContent = '✗ ' + T.GOOGLE_FAIL.replace('%s', family); }
        return;
      }
      if (state) { state.className = 'bs-te-gstate text-muted'; state.textContent = T.GOOGLE_CHECKING; }
      for (const w of GOOGLE_WEIGHTS) {
        const spec = family + ':wght@' + w;
        try {
          const r = await fetch('https://fonts.googleapis.com/css2?family=' + spec.replace(/ /g, '+') + '&display=swap', { mode: 'cors', credentials: 'omit' });
          if (mine !== checkSeq) return;
          if (r.ok) {
            loadFont(spec);
            setToken(key, 'google_spec', spec);
            if (state) { state.className = 'bs-te-gstate text-success'; state.textContent = '✓ ' + T.GOOGLE_OK.replace('%s', family).replace('%w', w.replace(/;/g, ', ')); }
            return;
          }
        } catch (e) {
          break;
        }
      }
      if (mine !== checkSeq) return;
      setToken(key, 'google_spec', '');
      if (state) { state.className = 'bs-te-gstate text-danger'; state.textContent = '✗ ' + T.GOOGLE_FAIL.replace('%s', family); }
    }

    function updateHead(key) {
      const reset = editor.querySelector('.bs-te-reset-all');
      const e = custom[key] || {};
      if (reset) reset.disabled = !((e.vars && Object.keys(e.vars).length) || (e.css && e.css.trim()));
      const dot = editor.querySelector('[data-view="css"] .bs-te-dot');
      if (dot) dot.hidden = !(e.css && e.css.trim());
    }

    function visualPane(key) {
      if (key === 'default') return '<div class="alert alert-info mb-0">' + esc(T.EDITOR_DEFAULT) + '</div>';
      const t = tokensOf(key);
      const preset = cfg.presets[key] || {};
      return Object.keys(cfg.groups).map((g) => '<fieldset class="bs-te-group"><legend>' + esc(T['G_' + g]) + '</legend><div class="bs-te-grid">'
        + cfg.groups[g].map((token) => control(token, cfg.tokens[token], t[token] == null ? '' : t[token], preset[token] == null ? '' : preset[token])).join('')
        + '</div></fieldset>').join('');
    }

    function cssPane(key) {
      const css = (custom[key] && custom[key].css) || '';
      return '<p class="small text-muted">' + esc(T.CSS_HELP) + '</p>'
        + '<div class="bs-te-cssbar"><button type="button" class="btn btn-sm btn-outline-primary" data-css="insert">⤓ ' + esc(T.CSS_INSERT) + '</button>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" data-css="clear">' + esc(T.CSS_CLEAR) + '</button>'
        + '<span class="bs-te-cssstate small" aria-live="polite"></span></div>'
        + '<div class="bs-te-code"><pre class="bs-te-lines" aria-hidden="true"></pre><textarea class="bs-te-css" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" aria-label="CSS"'
        + ' placeholder=".bs-live { --bs-accent: #e11d48; }&#10;.bettersearch-results .bsr-card { border-width: 2px; }">' + esc(css) + '</textarea></div>'
        + '<details class="bs-te-cheat"><summary>' + esc(T.CSS_SELECTORS) + '</summary><pre>'
        + esc('.bs-live                      podpowiedzi / live results\n.bs-live .bs-opt              wiersz / row\n.bs-live .bs-title            nazwa / name\n.bs-live .bs-price            cena / price\n.bs-live .bs-section-title    nagłówek sekcji / section title\n.bs-live .bs-all              „Pokaż wszystkie” / “Show all”\n\n.bettersearch-results              strona wyników / results page\n.bettersearch-results .bsr-card    karta / card\n.bettersearch-results .bsr-title   nazwa / name\n.bettersearch-results .bsr-price   cena / price\n.bettersearch-results .bsr-btn     przycisk / button\n.bettersearch-results .bsr-chip    filtr / filter chip\n\n--bs-bg --bs-text --bs-muted --bs-accent --bs-border --bs-radius --bs-shadow\n--bsr-card-bg --bsr-text --bsr-title --bsr-accent --bsr-border --bsr-radius --bsr-shadow')
        + '</pre></details>';
    }

    function render() {
      const key = current();
      const name = cfg.names[key] || key;
      if (back) back.hidden = key === 'default';
      root.querySelectorAll('.bs-theme-card').forEach((c) => c.classList.toggle('is-checked', c.dataset.theme === key));
      editor.innerHTML = '<div class="bs-te-head"><h3>' + esc(T.EDITOR_TITLE.replace('%s', name)) + '</h3>'
        + '<div class="bs-te-views" role="tablist">'
        + '<button type="button" role="tab" data-view="visual" aria-selected="' + (view === 'visual') + '">🎨 ' + esc(T.MODE_VISUAL) + '</button>'
        + '<button type="button" role="tab" data-view="css" aria-selected="' + (view === 'css') + '">&lt;/&gt; ' + esc(T.MODE_CSS) + ' <span class="bs-te-dot" title="' + esc(T.CSS_ACTIVE) + '" hidden></span></button></div>'
        + '<button type="button" class="btn btn-sm btn-link bs-te-reset-all">' + esc(T.RESET) + '</button></div>'
        + '<div class="bs-te-body">' + (view === 'visual' ? visualPane(key) : cssPane(key)) + '</div>';
      updateHead(key);
      if (view === 'css') bindCss(key);
      else if (key !== 'default') {
        deps(key);
        const t = tokensOf(key);
        if (t.font === 'google' && t.google_font) checkGoogle(key, t.google_font);
        else if (t.font === 'google') { const st = editor.querySelector('.bs-te-gstate'); if (st) st.textContent = T.GOOGLE_EMPTY; }
      }
    }

    // ---- CSS editor

    function bindCss(key) {
      const area = editor.querySelector('.bs-te-css');
      const lines = editor.querySelector('.bs-te-lines');
      const state = editor.querySelector('.bs-te-cssstate');
      const check = () => {
        const n = area.value.split('\n').length;
        lines.textContent = Array.from({ length: n }, (_, i) => i + 1).join('\n');
        const code = area.value.replace(/\/\*[\s\S]*?\*\//g, '').replace(/"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/g, '');
        const open = (code.match(/{/g) || []).length;
        const close = (code.match(/}/g) || []).length;
        state.className = 'bs-te-cssstate small ' + (open === close ? 'text-success' : 'text-danger');
        state.textContent = area.value.trim() === '' ? '' : (open === close ? '✓ ' + T.CSS_OK : '⚠ ' + T.CSS_BRACES.replace('%d', open).replace('%d', close))
          + ' · ' + T.CSS_CHARS.replace('%d', area.value.length);
      };
      const store = () => {
        entry(key).css = area.value;
        updateHead(key);
        save();
      };
      area.addEventListener('scroll', () => { lines.scrollTop = area.scrollTop; });
      area.addEventListener('input', (e) => { e.stopPropagation(); check(); store(); });
      area.addEventListener('change', (e) => e.stopPropagation());
      area.addEventListener('keydown', (e) => {
        if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
          e.preventDefault();
          const s = area.selectionStart;
          area.setRangeText('  ', s, area.selectionEnd, 'end');
          check();
          store();
        }
      });
      check();
    }

    // ---- events

    editor.addEventListener('click', (e) => {
      const key = current();
      const tab = e.target.closest('[data-view]');
      if (tab) {
        view = tab.dataset.view;
        render();
        return;
      }
      if (e.target.closest('.bs-te-reset-all')) {
        if (window.confirm(T.RESET_CONFIRM.replace('%s', cfg.names[key] || key))) {
          delete custom[key];
          save();
          mocks();
          render();
        }
        return;
      }
      const reset = e.target.closest('.bs-te-reset');
      if (reset) {
        const token = reset.closest('.bs-te-row').dataset.token;
        setToken(key, token, (cfg.presets[key] || {})[token]);
        if (token === 'google_font') setToken(key, 'google_spec', (cfg.presets[key] || {}).google_spec || '');
        render();
        return;
      }
      const seg = e.target.closest('.bs-te-seg button');
      if (seg) {
        seg.parentNode.querySelectorAll('button').forEach((b) => { b.classList.toggle('is-on', b === seg); b.setAttribute('aria-pressed', b === seg); });
        setToken(key, seg.closest('.bs-te-row').dataset.token, seg.dataset.value);
        return;
      }
      const css = e.target.closest('[data-css]');
      if (css) {
        const area = editor.querySelector('.bs-te-css');
        if (css.dataset.css === 'clear') {
          if (area.value.trim() && window.confirm(T.CSS_CLEAR_CONFIRM)) {
            area.value = '';
            area.dispatchEvent(new Event('input'));
          }
          return;
        }
        if (area.value.trim() && !window.confirm(T.CSS_INSERT_CONFIRM)) return;
        css.disabled = true;
        call('theme_css', form).then((r) => {
          area.value = (area.value.trim() ? area.value.replace(/\s*$/, '\n\n') : '') + r.css;
          area.dispatchEvent(new Event('input'));
          area.focus();
        }).catch((err) => window.alert(err.message)).finally(() => { css.disabled = false; });
      }
    });

    const onValue = (e) => {
      const row = e.target.closest('.bs-te-row');
      if (!row) return;
      e.stopPropagation();
      const key = current();
      const token = row.dataset.token;
      const kind = cfg.tokens[token];
      if (kind === 'bool') {
        setToken(key, token, e.target.checked ? '1' : '0');
        return;
      }
      if (kind === 'gfamily') {
        const family = e.target.value.trim().replace(/\s+/g, ' ');
        if ((tokensOf(key).google_font || '') !== family) setToken(key, token, family);
        clearTimeout(checkTimer);
        checkTimer = setTimeout(() => checkGoogle(key, family), e.type === 'change' ? 0 : 700);
        return;
      }
      if (kind === 'color') {
        const wrap = row.querySelector('.bs-te-color');
        const text = row.querySelector('[data-part="text"]');
        const picker = row.querySelector('[data-part="picker"]');
        if (e.target === picker) {
          text.value = picker.value;
        } else {
          const v = text.value.trim();
          const ok = v === '' || COLOR.test(v);
          text.classList.toggle('is-invalid', !ok);
          if (!ok) return;
          if (/^#[0-9a-f]{6}$/i.test(v)) picker.value = v;
        }
        wrap.classList.toggle('is-empty', text.value.trim() === '');
        row.querySelector('.bs-te-sw').style.setProperty('--sw', text.value.trim() || 'transparent');
        text.title = text.value.trim();
        setToken(key, token, text.value.trim());
      } else if (Array.isArray(kind)) {
        setToken(key, token, e.target.value);
      } else {
        row.querySelector('output').textContent = e.target.value + (token === 'bg_opacity' ? ' %' : ' px');
        setToken(key, token, e.target.value);
      }
    };
    editor.addEventListener('input', onValue);
    editor.addEventListener('change', onValue);

    root.querySelector('.bs-theme-grid').addEventListener('change', () => render());
    if (back) {
      back.addEventListener('click', () => {
        const radio = root.querySelector('.bs-theme-radio[value="default"]');
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        radio.focus();
      });
    }

    mocks();
    render();
  }

  // ================================================================ order of the result sections

  function initOrder() {
    document.querySelectorAll('.bs-order').forEach((box) => {
      const list = box.querySelector('.bs-order-list');
      const input = box.querySelector('input[type="hidden"]');
      let dragged = null;
      const sync = (changed) => {
        [...list.children].forEach((li, i) => { li.querySelector('.bs-order-pos').textContent = (i + 1) + '.'; });
        if (!changed) return;
        const keys = [...list.children].map((li) => li.dataset.key).join(',');
        input.value = keys === box.dataset.default ? '' : keys;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      };
      list.addEventListener('dragstart', (e) => {
        dragged = e.target.closest('.bs-order-item');
        if (!dragged) return;
        dragged.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragged.dataset.key);
      });
      list.addEventListener('dragover', (e) => {
        if (!dragged) return;
        e.preventDefault();
        const over = e.target.closest('.bs-order-item');
        if (!over || over === dragged) return;
        const r = over.getBoundingClientRect();
        list.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
      });
      list.addEventListener('dragend', () => {
        if (!dragged) return;
        dragged.classList.remove('is-dragging');
        dragged = null;
        sync(true);
      });
      list.addEventListener('click', (e) => {
        const b = e.target.closest('[data-move]');
        if (!b) return;
        const li = b.closest('.bs-order-item');
        if (b.dataset.move === '-1' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
        else if (b.dataset.move === '1' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
        else return;
        sync(true);
        b.focus();
      });
      box.querySelector('.bs-order-reset').addEventListener('click', () => {
        const items = Object.fromEntries([...list.children].map((li) => [li.dataset.key, li]));
        box.dataset.default.split(',').forEach((k) => { if (items[k]) list.appendChild(items[k]); });
        sync(true);
      });
      sync(false);
    });
  }

  function start() {
    if (!opts) return;
    initMode();
    initThemes();
    initOrder();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
