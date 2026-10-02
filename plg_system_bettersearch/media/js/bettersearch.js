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
        window.location.href = resultsUrl(input, q);
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
        panel.classList.remove('is-open', 'is-shown', 'is-full');
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
        body.innerHTML = data.html;
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
     * (never in a timer).
     */
    function startFull() {
        build();
        headInput.value = current.value;
        headInput.placeholder = current.placeholder || cfg.texts.placeholder || '';
        if (!panel.classList.contains('is-open')) {
            body.innerHTML = '';
            lastQuery = null;
        }
        open();
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
            return;
        }
        var card = t.closest('.bettersearch-results .bsr-card a');
        if (card) {
            var root = card.closest('.bettersearch-results');
            clicked(card, root ? root.getAttribute('data-query') : '');
        }
    }, true);

    // ------------------------------------------------------------------ results page: load more

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
