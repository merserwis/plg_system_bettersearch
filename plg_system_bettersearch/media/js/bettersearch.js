/**
 * Better Search for Gridbox — live results under the search fields, keyboard navigation, the
 * full-screen search on phones and "load more" on the results page.
 *
 * The Gridbox search fields keep their markup; their own handlers are kept from running by
 * listeners in the capture phase (window), which see every event first.
 */
(function () {
    'use strict';

    var cfgEl = document.getElementById('bettersearch-config');
    if (!cfgEl) {
        return;
    }
    var cfg;
    try {
        cfg = JSON.parse(cfgEl.textContent);
    } catch (e) {
        return;
    }

    var panel = null, body = null, head = null, headInput = null, backdrop = null;
    var current = null;          // the input the panel belongs to
    var timer = 0, controller = null, lastQuery = null, active = -1, full = false;
    var cache = Object.create(null);

    // an invalid selector typed in the settings must not break every event on the page
    try {
        document.querySelector(cfg.selector);
    } catch (e) {
        cfg.selector = '.ba-item-store-search input, .ba-item-search input, input.bettersearch-input';
    }

    function matches(el) {
        return el && el.nodeType === 1 && el.tagName === 'INPUT' && el.matches(cfg.selector);
    }

    // where the finger went down: a scroll that ends over the field is not a tap on it
    var touchStart = null;

    function wrapperOf(input) {
        return input.closest('.ba-search-wrapper') || input.closest('.bettersearch-form') || input;
    }

    // ------------------------------------------------------------------ results page address

    function resultsUrl(input, q) {
        var base = cfg.resultsUrl || (input && input.dataset && input.dataset.searchUrl) || '';
        if (!base) {
            base = cfg.fallbackUrl || '';
            base += (base.indexOf('?') >= 0 ? '&' : '?') + 'query=';
            return base + encodeURIComponent(q);
        }
        if (cfg.resultsUrl) {
            // a configured address: add the query parameter
            if (/[?&]query=$/.test(base)) {
                return base + encodeURIComponent(q);
            }
            return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'query=' + encodeURIComponent(q);
        }
        // Gridbox gives "…?query=" (or "…&search=")
        return base + encodeURIComponent(q);
    }

    function submit(input, q) {
        q = (q || '').trim();
        if (!q) {
            return;
        }
        remember(q);
        window.location.href = resultsUrl(input, q);
    }

    // ------------------------------------------------------------------ recent searches (this browser only)

    var RECENT_KEY = 'bettersearch-recent';

    function recentList() {
        if (!cfg.recent) {
            return [];
        }
        try {
            var list = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(list) ? list.filter(function (q) {
                return typeof q === 'string' && q;
            }).slice(0, cfg.recentLimit) : [];
        } catch (e) {
            return [];
        }
    }

    function saveRecent(list) {
        try {
            if (list.length) {
                window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, cfg.recentLimit)));
            } else {
                window.localStorage.removeItem(RECENT_KEY);
            }
        } catch (e) {
        }
    }

    /** A search the visitor made goes first in their list (the same phrase once, any letter case). */
    function remember(q) {
        q = String(q || '').replace(/\s+/g, ' ').trim().slice(0, 100);
        if (!cfg.recent || q.replace(/[^0-9a-zÀ-ɏ]/gi, '').length < cfg.minChars) {
            return;
        }
        var lower = q.toLowerCase();
        saveRecent([q].concat(recentList().filter(function (x) {
            return x.toLowerCase() !== lower;
        })));
    }

    function forget(q) {
        saveRecent(recentList().filter(function (x) {
            return x !== q;
        }));
    }

    /** The panel with the visitor's last searches, for an empty field. False when there are none. */
    function showRecent() {
        var list = recentList();
        if (!list.length) {
            return false;
        }
        build();
        lastQuery = null;
        panel.setAttribute('data-bs-view', 'recent');
        body.innerHTML = '<div class="bs-live-body"><div class="bs-section bs-section-recent"><div class="bs-section-title bs-recent-head"><span>' + esc(cfg.texts.recent)
            + '</span><button type="button" class="bs-recent-clear">' + esc(cfg.texts.clearRecent) + '</button></div><ul class="bs-pop" role="presentation">'
            + list.map(function (q, i) {
                return '<li role="presentation" class="bs-recent-row"><a class="bs-opt bs-sugg bs-recent" role="option" id="bs-opt-' + i + '" style="--i:' + i + '" href="#" data-bs-q="'
                    + esc(q) + '"><span class="bs-recent-icon" aria-hidden="true"></span><span class="bs-sugg-text">' + esc(q) + '</span></a>'
                    + '<button type="button" class="bs-recent-del" data-bs-forget="' + esc(q) + '" aria-label="' + esc(cfg.texts.removeRecent + ': ' + q) + '">&times;</button></li>';
            }).join('') + '</ul></div></div>';
        active = -1;
        options().forEach(function (opt) {
            opt.setAttribute('tabindex', '-1');
        });
        open();
        return true;
    }

    // ------------------------------------------------------------------ panel

    function build() {
        if (panel) {
            return;
        }
        panel = document.createElement('div');
        panel.className = 'bs-live';
        panel.id = 'bs-live';
        panel.setAttribute('role', 'listbox');
        panel.innerHTML = '<div class="bs-full-head"><input type="search" autocomplete="off" enterkeyhint="search" aria-controls="bs-live">'
            + '<button type="button" class="bs-close" aria-label="' + esc(cfg.texts.close) + '">&times;</button></div><div class="bs-live-wrap"></div>';
        document.body.appendChild(panel);
        head = panel.querySelector('.bs-full-head');
        headInput = head.querySelector('input');
        if (voiceOn) {
            head.insertBefore(micButton(), head.querySelector('.bs-close'));
        }
        headInput.placeholder = (current && current.placeholder) || cfg.texts.placeholder || '';
        body = panel.querySelector('.bs-live-wrap');
        body.style.display = 'contents';

        panel.addEventListener('mousemove', function (e) {
            var opt = e.target.closest('.bs-opt');
            if (opt) {
                setActive(options().indexOf(opt), false);
            }
        });
        panel.addEventListener('mousedown', function (e) {
            // keep the focus in the field while clicking a result
            if (!e.target.closest('input')) {
                e.preventDefault();
            }
        });
        head.querySelector('.bs-close').addEventListener('click', close);
        panel.addEventListener('click', function (e) {
            var del = e.target.closest('[data-bs-forget]');
            var clear = e.target.closest('.bs-recent-clear');
            if (!del && !clear) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            if (clear) {
                saveRecent([]);
            } else {
                forget(del.getAttribute('data-bs-forget'));
            }
            if (!showRecent()) {
                if (full) {
                    body.innerHTML = '';
                } else {
                    close();
                }
            }
            var field = full ? headInput : current;
            if (field) {
                field.focus();
            }
        });
        headInput.addEventListener('input', function () {
            if (current) {
                current.value = headInput.value;
            }
            schedule(headInput.value);
        });
        headInput.addEventListener('keydown', keydown);
    }

    function esc(s) {
        return String(s || '').replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function isMobile() {
        return cfg.mobile === 'fullscreen' && window.innerWidth <= cfg.mobileMax;
    }

    function open() {
        build();
        full = isMobile();
        panel.classList.toggle('is-full', full);
        if (full) {
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.className = 'bs-live-backdrop';
                backdrop.addEventListener('click', close);
            }
            document.body.appendChild(backdrop);
            document.documentElement.style.overflow = 'hidden';
        }
        if (!panel.classList.contains('is-open')) {
            panel.classList.add('is-open');
            requestAnimationFrame(function () {
                panel.classList.add('is-shown');
            });
        }
        if (current) {
            current.setAttribute('aria-expanded', 'true');
        }
        position();
    }

    function close() {
        if (!panel || !panel.classList.contains('is-open')) {
            return;
        }
        if (recField === headInput) {
            stopListening();
        }
        panel.classList.remove('is-open', 'is-shown', 'is-full');
        panel.removeAttribute('data-bs-view');
        if (backdrop && backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
        document.documentElement.style.overflow = '';
        if (current) {
            current.setAttribute('aria-expanded', 'false');
            current.removeAttribute('aria-activedescendant');
        }
        if (full && current) {
            current.value = headInput.value;
        }
        full = false;
        active = -1;
    }

    function position() {
        if (!panel || !current || full || !panel.classList.contains('is-open')) {
            return;
        }
        var rect = wrapperOf(current).getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var width = rect.width;
        if (cfg.widthMode === 'fixed') {
            width = cfg.width;
        } else if (cfg.widthMode === 'wide') {
            width = Math.max(rect.width, cfg.width);
        }
        width = Math.min(width, vw - 16);
        var left = rect.left;
        if (cfg.align === 'right') {
            left = rect.right - width;
        } else if (cfg.align === 'center') {
            left = rect.left + rect.width / 2 - width / 2;
        }
        left = Math.max(8, Math.min(left, vw - width - 8));
        var top = rect.bottom + cfg.offset;
        panel.style.position = 'fixed';
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
        panel.style.width = width + 'px';
        var room = window.innerHeight - top - 12;
        var list = panel.querySelector('.bs-live-body');
        if (list) {
            list.style.maxHeight = 'min(var(--bs-max-h), ' + Math.max(160, room - (panel.querySelector('.bs-all') ? 46 : 0)) + 'px)';
        }
        // the field scrolled out of view: nothing to point at
        if (rect.bottom < 0 || rect.top > window.innerHeight) {
            close();
        }
    }

    // ------------------------------------------------------------------ fetching

    function schedule(value) {
        clearTimeout(timer);
        var q = (value || '').trim();
        if (q.replace(/[^0-9a-zÀ-ɏ]/gi, '').length < cfg.minChars) {
            lastQuery = null;
            if (controller) {
                controller.abort();
            }
            // an empty field: the visitor's last searches, if any
            if (!q && document.activeElement === (full ? headInput : current) && showRecent()) {
                return;
            }
            if (full) {
                body.innerHTML = '';
            } else {
                close();
            }
            return;
        }
        timer = setTimeout(function () {
            fetchResults(q);
        }, cfg.debounce);
    }

    function fetchResults(q) {
        if (q === lastQuery && panel && panel.classList.contains('is-open')) {
            return;
        }
        lastQuery = q;
        if (cache[q]) {
            render(cache[q], q);
            return;
        }
        if (controller) {
            controller.abort();
        }
        controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var wrap = current ? wrapperOf(current) : null;
        if (wrap) {
            wrap.classList.add('bs-loading');
        }
        if (panel) {
            panel.classList.add('is-loading');
        }
        fetch(cfg.ajax + '&task=live&q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        }).then(function (r) {
            return r.json();
        }).then(function (json) {
            var data = json && json.data && json.data[0];
            if (!data || typeof data.html !== 'string') {
                return;
            }
            cache[q] = data;
            if (q === lastQuery) {
                render(data, q);
            }
        }).catch(function () {
        }).then(function () {
            if (wrap) {
                wrap.classList.remove('bs-loading');
            }
            if (panel) {
                panel.classList.remove('is-loading');
            }
        });
    }

    function render(data, q) {
        build();
        // the panel was open with the last searches: the results arrive with the appearing effect again
        var replay = panel.classList.contains('is-shown') && panel.getAttribute('data-bs-view') !== 'results';
        panel.setAttribute('data-bs-view', 'results');
        body.innerHTML = data.html;
        if (replay) {
            panel.classList.remove('is-shown');
            void panel.offsetWidth;
            panel.classList.add('is-shown');
        }
        var all = body.querySelector('.bs-all');
        if (all) {
            all.href = resultsUrl(current, q);
        }
        active = -1;
        options().forEach(function (opt) {
            opt.setAttribute('tabindex', '-1');
        });
        open();
    }

    function options() {
        return panel ? Array.prototype.slice.call(panel.querySelectorAll('.bs-opt')) : [];
    }

    function setActive(i, scroll) {
        var opts = options();
        opts.forEach(function (o) {
            o.classList.remove('is-active');
            o.removeAttribute('aria-selected');
        });
        active = i;
        var field = full ? headInput : current;
        if (i >= 0 && opts[i]) {
            opts[i].classList.add('is-active');
            opts[i].setAttribute('aria-selected', 'true');
            if (field) {
                field.setAttribute('aria-activedescendant', opts[i].id);
            }
            if (scroll !== false) {
                opts[i].scrollIntoView({ block: 'nearest' });
            }
        } else if (field) {
            field.removeAttribute('aria-activedescendant');
        }
    }

    // ------------------------------------------------------------------ keyboard

    function keydown(e) {
        var field = e.currentTarget && e.currentTarget.tagName === 'INPUT' ? e.currentTarget : current;
        var opts = options();
        var openNow = panel && panel.classList.contains('is-open');
        if (e.key === 'ArrowDown' && openNow && opts.length) {
            e.preventDefault();
            setActive(active + 1 >= opts.length ? 0 : active + 1);
        } else if (e.key === 'ArrowUp' && openNow && opts.length) {
            e.preventDefault();
            setActive(active - 1 < 0 ? opts.length - 1 : active - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (openNow && active >= 0 && opts[active] && opts[active].hasAttribute('data-bs-q')) {
                suggest(opts[active].getAttribute('data-bs-q'));
            } else if (openNow && active >= 0 && opts[active]) {
                clicked(opts[active], lastQuery);
                remember(lastQuery);
                window.location.href = opts[active].href;
            } else {
                submit(current || field, field.value);
            }
        } else if (e.key === 'Escape') {
            if (openNow) {
                e.preventDefault();
                close();
                if (current && !full) {
                    current.focus();
                }
            }
        }
    }

    // ------------------------------------------------------------------ taking over the fields

    function own(e) {
        // Gridbox listens on the field and its wrapper: stop the event before it gets there
        e.stopImmediatePropagation();
        e.stopPropagation();
    }

    function prepare(input) {
        if (input.dataset.bsReady) {
            return;
        }
        input.dataset.bsReady = '1';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', 'bs-live');
        if (!input.getAttribute('enterkeyhint')) {
            input.setAttribute('enterkeyhint', 'search');
        }
    }

    // ------------------------------------------------------------------ voice search

    var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    var MIC = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z"/></svg>';
    var rec = null, recField = null, recHolder = '', micObserver = null;

    /** A phone or a tablet — the device itself, not the width of the window (a narrow desktop window is not one). */
    function mobileDevice() {
        var ua = navigator.userAgent || '';
        if (navigator.userAgentData && navigator.userAgentData.mobile) {
            return true;
        }
        if (/Android|iPhone|iPad|iPod|Mobile|Silk|Kindle|Opera Mini|IEMobile/i.test(ua)) {
            return true;
        }
        // iPadOS presents itself as a Mac
        if (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) {
            return true;
        }
        return !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
    }

    /** The Permissions-Policy of the page may forbid the microphone (microphone=()): the browser then never asks the visitor. */
    function micAllowed() {
        var policy = document.permissionsPolicy || document.featurePolicy;
        try {
            return !policy || typeof policy.allowsFeature !== 'function' || policy.allowsFeature('microphone');
        } catch (e) {
            return true;
        }
    }

    // a browser whose speech recognition never started here (some Chromium browsers have it without
    // the service behind it): remembered in this browser, until its version changes
    var VOICE_OFF_KEY = 'bettersearch-voice-off';

    function voiceBroken() {
        try {
            return window.localStorage.getItem(VOICE_OFF_KEY) === navigator.userAgent;
        } catch (e) {
            return false;
        }
    }

    // the browser must recognise speech (not Firefox) on an HTTPS page; "mobile devices only" leaves computers out
    var voiceOn = !!(cfg.voice && Recognition && window.isSecureContext !== false && (!cfg.voice.mobileOnly || mobileDevice()));
    if (voiceOn && !micAllowed()) {
        voiceOn = false;
        if (window.console) {
            console.warn('Better Search: voice search is off on this page - its Permissions-Policy header forbids the microphone (microphone=()).');
        }
    }
    voiceOn = voiceOn && !voiceBroken();

    function micButton() {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'bs-voice';
        b.title = cfg.texts.voice;
        b.setAttribute('aria-label', cfg.texts.voice);
        b.setAttribute('aria-pressed', 'false');
        b.innerHTML = MIC;
        return b;
    }

    /**
     * The microphone sits at the end of the field, before the icons over it (the Gridbox magnifier
     * on the right side); the field gets the room for it.
     */
    function placeMic(input) {
        var btn = input.bsVoice;
        var host = input.parentNode;
        if (!btn || !host) {
            return;
        }
        var r = input.getBoundingClientRect();
        btn.hidden = !r.width || !voiceOn;
        if (!r.width) {
            return;
        }
        var h = host.getBoundingClientRect();
        var rtl = getComputedStyle(input).direction === 'rtl';
        var mid = r.left + r.width / 2;
        var edge = rtl ? r.left : r.right;
        Array.prototype.forEach.call(host.children, function (el) {
            if (el === input || el === btn) {
                return;
            }
            var c = el.getBoundingClientRect();
            if (!c.width || c.bottom < r.top || c.top > r.bottom) {
                return;
            }
            if (!rtl && c.left > mid && c.left < edge) {
                edge = c.left;
            } else if (rtl && c.right < mid && c.right > edge) {
                edge = c.right;
            }
        });
        btn.style.setProperty('--bs-voice-end', Math.max(0, (rtl ? edge - h.left : h.right - edge) + 4) + 'px');
        var need = (rtl ? edge - r.left : r.right - edge) + 40;
        var style = getComputedStyle(input);
        if ((parseFloat(rtl ? style.paddingLeft : style.paddingRight) || 0) < need) {
            input.style.setProperty(rtl ? 'padding-left' : 'padding-right', need + 'px', 'important');
        }
    }

    function addMic(input) {
        if (input.bsVoice || input === headInput || input.type === 'hidden' || !input.parentNode) {
            return;
        }
        var btn = micButton();
        btn.bsField = input;
        input.bsVoice = btn;
        input.parentNode.classList.add('bs-voice-host');
        input.parentNode.insertBefore(btn, input.nextSibling);
        placeMic(input);
        // a field shown later (a menu, a popup) or resized: placed again
        if (window.ResizeObserver) {
            micObserver = micObserver || new ResizeObserver(function (entries) {
                entries.forEach(function (en) {
                    placeMic(en.target);
                });
            });
            micObserver.observe(input);
        }
    }

    function scanMics() {
        try {
            document.querySelectorAll(cfg.selector).forEach(function (el) {
                if (el.tagName === 'INPUT') {
                    addMic(el);
                }
            });
        } catch (e) {
        }
    }

    function clean(text) {
        // the browser may end the sentence with a full stop or a question mark
        return String(text || '').replace(/\s+/g, ' ').replace(/[\s.,;:!?…。！？]+$/, '').trim();
    }

    function micState(on) {
        document.querySelectorAll('.bs-voice').forEach(function (b) {
            var mine = on && (b.bsField === current || (full && panel && panel.contains(b)));
            b.classList.toggle('is-listening', !!mine);
            b.setAttribute('aria-pressed', mine ? 'true' : 'false');
        });
    }

    /** A short message in place of the placeholder of the field (the field is empty while it shows). */
    function voiceNote(field, text, ms) {
        var holder = recHolder;
        field.placeholder = text;
        setTimeout(function () {
            if (field.placeholder === text) {
                field.placeholder = holder;
            }
        }, ms || 2800);
    }

    /**
     * This browser cannot recognise speech here: said in the field, the microphones go away and the
     * browser is remembered (until its version changes).
     */
    function giveUp(field) {
        try {
            window.localStorage.setItem(VOICE_OFF_KEY, navigator.userAgent);
        } catch (e) {
        }
        voiceOn = false;
        document.querySelectorAll('.bs-voice').forEach(function (b) {
            b.hidden = true;
        });
        voiceNote(field, cfg.texts.voiceUnsupported, 6000);
    }

    function listen(btn) {
        if (rec) {
            // a second tap: stop and search what was heard
            rec.stop();
            return;
        }
        var inHead = panel && panel.contains(btn);
        if (!inHead) {
            current = btn.bsField;
            if (cfg.live) {
                prepare(current);
            }
        }
        if (!current) {
            return;
        }
        // phones: the full-screen search opens, without the keyboard
        if (!inHead && cfg.live && isMobile()) {
            startFull(true);
        }
        var field = full ? headInput : current;
        var heard = '', r, started = false;
        try {
            r = rec = new Recognition();
        } catch (e) {
            rec = null;
            return;
        }
        rec.lang = cfg.voice.lang || document.documentElement.lang || '';
        rec.interimResults = true;
        rec.continuous = false;
        rec.maxAlternatives = 1;
        recField = field;
        recHolder = field.placeholder;
        field.placeholder = cfg.texts.voiceListening;
        rec.onstart = rec.onaudiostart = function () {
            started = true;
        };
        rec.onresult = function (e) {
            var text = '';
            for (var i = 0; i < e.results.length; i++) {
                text += e.results[i][0].transcript;
            }
            heard = text;
            field.value = text;
            if (full && current) {
                current.value = text;
            }
        };
        rec.onerror = function (e) {
            var err = e && e.error;
            if (err === 'aborted' || r.bsAborted) {
                return;
            }
            // no speech service behind the browser's recognition (Brave and the like), while online
            if (err === 'service-not-allowed' || (err === 'network' && !started && navigator.onLine !== false)) {
                r.bsAborted = true;
                giveUp(field);
                return;
            }
            voiceNote(field, err === 'not-allowed' ? cfg.texts.voiceDenied : cfg.texts.voiceError);
        };
        rec.onend = function () {
            clearTimeout(watchdog);
            clearTimeout(limit);
            rec = null;
            recField = null;
            micState(false);
            if (field.placeholder === cfg.texts.voiceListening) {
                field.placeholder = recHolder;
            }
            var q = clean(heard);
            // closed while listening: nothing is searched
            if (!q || r.bsAborted) {
                return;
            }
            field.value = q;
            if (full && current) {
                current.value = q;
            }
            if (!cfg.live || cfg.voice.submit) {
                submit(current || field, q);
                return;
            }
            // a computer: the field keeps the focus, so the arrows and Enter work at once
            if (!full && !mobileDevice()) {
                field.focus();
            }
            fetchResults(q);
        };
        try {
            rec.start();
        } catch (e) {
            rec = null;
            field.placeholder = recHolder;
            return;
        }
        micState(true);
        // the recognition never started (no question about the microphone, no sound): this browser
        // cannot do it here; a visitor answering the question of the browser about the microphone
        // gets more time
        var watchdog = 0, limit = 0;
        var arm = function (ms) {
            watchdog = setTimeout(function () {
                if (started || rec !== r) {
                    return;
                }
                r.bsAborted = true;
                try {
                    r.abort();
                } catch (e) {
                }
                rec = null;
                micState(false);
                giveUp(field);
            }, ms);
        };
        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'microphone' }).then(function (p) {
                arm(p.state === 'prompt' ? 20000 : 6000);
            }, function () {
                arm(12000);
            });
        } else {
            arm(12000);
        }
        // nobody stopped it: a quarter of a minute is enough for a search
        limit = setTimeout(function () {
            if (rec === r) {
                r.stop();
            }
        }, 15000);
    }

    function stopListening() {
        if (rec) {
            rec.bsAborted = true;
            rec.abort();
        }
    }

    if (voiceOn) {
        // the microphone is inside the Gridbox field: its own handlers of the field must not see the tap
        window.addEventListener('mousedown', function (e) {
            if (e.target.closest && e.target.closest('.bs-voice')) {
                e.preventDefault();
            }
        }, true);
        window.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.bs-voice');
            if (!btn) {
                return;
            }
            e.preventDefault();
            own(e);
            listen(btn);
        }, true);

        scanMics();
        // fields added later (menus, popups, the module loaded by a page builder)
        if (window.MutationObserver) {
            var scanTimer = 0;
            new MutationObserver(function (list) {
                // the live results themselves change all the time
                if (panel && list.every(function (m) {
                    return panel.contains(m.target);
                })) {
                    return;
                }
                clearTimeout(scanTimer);
                scanTimer = setTimeout(scanMics, 300);
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (cfg.live) {
        window.addEventListener('input', function (e) {
            if (!matches(e.target)) {
                return;
            }
            own(e);
            current = e.target;
            prepare(current);
            if (isMobile()) {
                startFull();
                return;
            }
            schedule(current.value);
        }, true);

        window.addEventListener('keydown', function (e) {
            if (!matches(e.target)) {
                return;
            }
            own(e);
            current = e.target;
            prepare(current);
            keydown(e);
        }, true);

        window.addEventListener('keyup', function (e) {
            if (matches(e.target)) {
                own(e);
            }
        }, true);

        window.addEventListener('touchstart', function (e) {
            var t = e.changedTouches && e.changedTouches[0];
            touchStart = t ? [t.clientX, t.clientY] : null;
        }, { capture: true, passive: true });

        window.addEventListener('touchend', function (e) {
            if (!matches(e.target) || !isMobile()) {
                return;
            }
            var t = e.changedTouches && e.changedTouches[0];
            if (touchStart && t && (Math.abs(t.clientX - touchStart[0]) > 12 || Math.abs(t.clientY - touchStart[1]) > 12)) {
                return;
            }
            // the tap must not focus the original field: the full-screen field takes the keyboard
            e.preventDefault();
            own(e);
            current = e.target;
            prepare(current);
            startFull();
        }, { capture: true, passive: false });

        window.addEventListener('focusin', function (e) {
            if (!matches(e.target)) {
                return;
            }
            current = e.target;
            prepare(current);
            if (isMobile()) {
                startFull();
            } else if (current.value.trim() && lastQuery === current.value.trim() && panel && body.innerHTML) {
                open();
            } else if (!current.value.trim()) {
                showRecent();
            }
        }, true);

        window.addEventListener('click', function (e) {
            var t = e.target;
            // the magnifier icon of a Gridbox search field: search (Gridbox would clear the field)
            var icon = t.closest && t.closest('.ba-search-wrapper > i');
            if (icon) {
                var input = icon.parentNode.querySelector('input');
                if (matches(input)) {
                    own(e);
                    if (cfg.iconSubmit && input.value.trim()) {
                        submit(input, input.value);
                    } else {
                        input.focus();
                    }
                    return;
                }
            }
            if (matches(t) || (t.closest && t.closest('.ba-search-wrapper') && matches(t.closest('.ba-search-wrapper').querySelector('input')))) {
                own(e);
                return;
            }
            if (panel && !panel.contains(t)) {
                close();
            }
        }, true);

        window.addEventListener('resize', function () {
            if (panel && panel.classList.contains('is-open')) {
                if (full !== isMobile()) {
                    close();
                } else {
                    position();
                }
            }
        });
        window.addEventListener('scroll', function () {
            if (panel && panel.classList.contains('is-open') && !full) {
                requestAnimationFrame(position);
            }
        }, { passive: true });

        // the search field of the module submits through the same address
        document.addEventListener('submit', function (e) {
            var input = e.target.querySelector && e.target.querySelector('input.bettersearch-input');
            if (input && matches(input)) {
                e.preventDefault();
                submit(input, input.value);
            }
        }, true);
    }

    /**
     * Phones: the search opens over the page with its own field. Mobile browsers show the keyboard
     * only for a field focused inside the handler of the tap itself, so the focus moves here at once
     * (never in a timer). Voice search opens it without the keyboard (noFocus).
     */
    function startFull(noFocus) {
        build();
        headInput.value = current.value;
        headInput.placeholder = current.placeholder || cfg.texts.placeholder || '';
        if (!panel.classList.contains('is-open')) {
            body.innerHTML = '';
            lastQuery = null;
        }
        open();
        if (!headInput.value.trim() && !noFocus) {
            showRecent();
        }
        if (noFocus) {
            // the keyboard of the field tapped before goes away
            if (document.activeElement === current) {
                current.blur();
            }
            schedule(headInput.value);
            return;
        }
        headInput.focus({ preventScroll: true });
        var n = headInput.value.length;
        try {
            headInput.setSelectionRange(n, n);
        } catch (err) {
        }
        if (document.activeElement === headInput && current !== headInput) {
            current.blur();
        }
        schedule(headInput.value);
    }

    // ------------------------------------------------------------------ suggestions and clicks

    /** A suggested phrase: it goes into the field and is searched. */
    function suggest(q) {
        var field = full ? headInput : current;
        if (!field || !q) {
            return;
        }
        field.value = q;
        if (full && current) {
            current.value = q;
        }
        field.focus();
        fetchResults(q);
    }

    /**
     * A result opened from a search: counted for the query (the conversion statistics), and
     * remembered in a first-party cookie (product id => query, nothing personal) so that putting
     * it into the cart later is counted for the same query.
     */
    function clicked(link, q) {
        var id = link && (link.getAttribute('data-bs-id') || (link.closest && link.closest('[data-bs-id]') && link.closest('[data-bs-id]').getAttribute('data-bs-id')));
        q = String(q || '').trim();
        if (!cfg.track || !id || !q) {
            return;
        }
        try {
            var body = new FormData();
            body.append('q', q);
            body.append('id', id);
            if (navigator.sendBeacon) {
                navigator.sendBeacon(cfg.ajax + '&task=track', body);
            } else {
                fetch(cfg.ajax + '&task=track', { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
            }
            var map = {};
            var m = document.cookie.match(/(?:^|;\s*)bs_src=([^;]*)/);
            if (m) {
                try {
                    map = JSON.parse(decodeURIComponent(m[1])) || {};
                } catch (e) {
                    map = {};
                }
            }
            delete map[id];
            map[id] = q.slice(0, 120);
            var keys = Object.keys(map);
            while (keys.length > 20) {
                delete map[keys.shift()];
            }
            document.cookie = 'bs_src=' + encodeURIComponent(JSON.stringify(map)) + ';path=/;max-age=' + (30 * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
        } catch (e) {
        }
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t.closest) {
            return;
        }
        var sugg = t.closest('[data-bs-q]');
        if (sugg && panel && panel.contains(sugg)) {
            e.preventDefault();
            suggest(sugg.getAttribute('data-bs-q'));
            return;
        }
        var item = t.closest('a.bs-item[data-bs-id]');
        if (item && panel && panel.contains(item)) {
            clicked(item, lastQuery);
            remember(lastQuery);
            return;
        }
        var card = t.closest('.bettersearch-results .bsr-card a');
        if (card) {
            var root = card.closest('.bettersearch-results');
            clicked(card, root ? root.getAttribute('data-query') : '');
        }
    }, true);

    // ------------------------------------------------------------------ add to cart

    /**
     * "Add to cart" of a result: the same request as the Gridbox button of a product page (one piece,
     * no variant). The Gridbox cart of the page is then refreshed and opened, if the page has one.
     */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-bs-cart]');
        if (!btn) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        if (btn.disabled) {
            return;
        }
        var id = btn.getAttribute('data-bs-cart');
        var root = btn.closest('.bettersearch-results');
        var q = root ? root.getAttribute('data-query') : lastQuery;
        // counted as a conversion of the query (the cookie names the query of this product)
        clicked(btn, q);
        var label = btn.textContent;
        btn.disabled = true;
        var data = new FormData();
        data.append('id', id);
        data.append('quantity', '1');
        data.append('variation', '');
        data.append('extra_options', '{}');
        data.append('booking', '{}');
        fetch(cfg.cart, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) {
                throw new Error(r.status);
            }
            btn.classList.add('is-done');
            btn.textContent = '✓ ' + cfg.texts.added;
            var gb = window.app;
            if (gb && gb.storeCart && typeof gb.storeCart.updateCartTotal === 'function') {
                try {
                    gb.storeCart.updateCartTotal();
                } catch (err) {
                }
            }
            var cartLink = document.querySelector('.ba-item-cart a');
            if (cfg.cartAfter === 'cart' && cartLink) {
                close();
                cartLink.click();
            }
        }).catch(function () {
            btn.textContent = cfg.texts.cartError;
        }).then(function () {
            setTimeout(function () {
                btn.disabled = false;
                btn.classList.remove('is-done');
                btn.textContent = label;
            }, 2500);
        });
    }, true);

    // ------------------------------------------------------------------ results page: load more

    (function () {
        var page = document.querySelector('.bettersearch-results[data-query]');
        if (page) {
            remember(page.getAttribute('data-query'));
        }
    })();

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.bsr-more');
        if (!btn || btn.disabled) {
            return;
        }
        e.preventDefault();
        var root = btn.closest('.bettersearch-results');
        var grid = root && root.querySelector('.bsr-grid');
        if (!grid) {
            return;
        }
        var page = parseInt(btn.dataset.page, 10) || 2;
        var pages = parseInt(btn.dataset.pages, 10) || page;
        var qs = btn.dataset.search || '';
        btn.disabled = true;
        fetch(cfg.ajax + '&task=more&bs_page=' + page + (qs ? '&' + qs : ''), { credentials: 'same-origin' })
            .then(function (r) {
                return r.json();
            })
            .then(function (json) {
                var data = json && json.data && json.data[0];
                if (!data || typeof data.html !== 'string') {
                    return;
                }
                // the result set may have shrunk meanwhile: the server says which page it sent
                var got = parseInt(data.page, 10) || page;
                if (got < page || !data.html) {
                    btn.parentNode.parentNode.removeChild(btn.parentNode);
                    return;
                }
                grid.insertAdjacentHTML('beforeend', data.html);
                pages = parseInt(data.pages, 10) || pages;
                btn.dataset.page = String(got + 1);
                if (got + 1 > pages) {
                    btn.parentNode.parentNode.removeChild(btn.parentNode);
                }
                var nav = root.querySelector('.bsr-pages');
                if (nav) {
                    nav.querySelectorAll('.bsr-page').forEach(function (a) {
                        a.classList.toggle('is-active', a.textContent.trim() === String(page));
                    });
                }
            })
            .catch(function () {
            })
            .then(function () {
                btn.disabled = false;
            });
    });
})();
