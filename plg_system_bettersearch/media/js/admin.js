/**
 * Better Search for Gridbox — administrator: product pickers (search, drag to order) and the tools
 * tab (index, test console, statistics).
 */
(function () {
    'use strict';

    var opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_bettersearch') : null;
    if (!opts) {
        return;
    }
    var T = opts.texts || {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // tasks that change something are sent as POST; the others as GET
    var WRITES = { sync: 1, clearlog: 1, clearthumbs: 1, clearcache: 1, gsc_import: 1, report_send: 1 };

    function fmt(text, values) {
        var i = 0;
        return String(text || '').replace(/%[sd]/g, function () {
            return values[i++];
        });
    }

    function call(task, params, form) {
        var url = opts.ajax + '&task=admin_' + task;
        var post = (params && params._post) || null;
        Object.keys(params || {}).forEach(function (k) {
            if (k !== '_post') {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }
        });
        var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (WRITES[task] || post) {
            init.method = 'POST';
            init.body = new FormData();
            Object.keys(post || {}).forEach(function (k) {
                init.body.append(k, post[k]);
            });
        }
        if (form) {
            init.method = 'POST';
            // only the plugin settings: the form's own option/task fields would redirect the request
            init.body = init.body || new FormData();
            new FormData(form).forEach(function (value, key) {
                if (key.indexOf('jform[params]') === 0) {
                    init.body.append(key, value);
                }
            });
        }
        return fetch(url, init).then(function (r) {
            return r.json();
        }).then(function (json) {
            var data = json && json.data && json.data[0];
            if (!data) {
                throw new Error((json && json.message) || 'empty response');
            }
            if (data.error) {
                throw new Error(data.error);
            }
            return data;
        });
    }

    // ================================================================ product picker

    var titles = {};

    function initPicker(root) {
        if (root.dataset.ready) {
            return;
        }
        root.dataset.ready = '1';
        var hidden = root.querySelector('.bs-picker-value');
        var list = root.querySelector('.bs-picker-list');
        var input = root.querySelector('.bs-picker-input');
        var found = root.querySelector('.bs-picker-found');
        var multiple = root.dataset.multiple === '1';
        var scope = root.dataset.scope || 'products';
        var timer = 0;

        function ids() {
            return hidden.value.split(',').map(function (v) {
                return parseInt(v, 10);
            }).filter(function (v) {
                return v > 0;
            });
        }

        function save(list2) {
            hidden.value = list2.join(',');
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            draw();
        }

        function draw() {
            var current = ids();
            if (!current.length) {
                list.innerHTML = '<li class="bs-picker-empty">' + esc(T.PICKER_NONE) + '</li>';
                return;
            }
            list.innerHTML = current.map(function (id, i) {
                var t = titles[id] || { title: '#' + id, sku: '' };
                return '<li draggable="' + (multiple ? 'true' : 'false') + '" data-id="' + id + '">'
                    + (multiple ? '<span class="bs-picker-handle" aria-hidden="true">⋮⋮</span><span class="bs-picker-pos">' + (i + 1) + '.</span>' : '')
                    + '<span class="bs-picker-title">' + esc(t.title) + (t.sku ? ' <small>' + esc(t.sku) + '</small>' : '') + ' <small class="text-muted">#' + id + '</small></span>'
                    + (multiple ? '<button type="button" class="btn btn-sm btn-link" data-move="-1" title="' + esc(T.PICKER_UP) + '">↑</button>'
                        + '<button type="button" class="btn btn-sm btn-link" data-move="1" title="' + esc(T.PICKER_DOWN) + '">↓</button>' : '')
                    + '<button type="button" class="btn btn-sm btn-link text-danger" data-remove title="' + esc(T.PICKER_REMOVE) + '">✕</button></li>';
            }).join('');
        }

        function loadTitles() {
            var missing = ids().filter(function (id) {
                return !titles[id];
            });
            if (!missing.length) {
                draw();
                return;
            }
            call('titles', { ids: missing.join(',') }).then(function (data) {
                (data.items || []).forEach(function (t) {
                    titles[t.id] = t;
                });
                draw();
            }).catch(draw);
        }

        list.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-id]');
            if (!li) {
                return;
            }
            var id = parseInt(li.dataset.id, 10);
            var current = ids();
            var i = current.indexOf(id);
            if (e.target.closest('[data-remove]')) {
                current.splice(i, 1);
                save(current);
            } else if (e.target.closest('[data-move]')) {
                var j = i + parseInt(e.target.closest('[data-move]').dataset.move, 10);
                if (j >= 0 && j < current.length) {
                    current.splice(j, 0, current.splice(i, 1)[0]);
                    save(current);
                }
            }
        });

        // drag and drop ordering
        var dragged = null;
        list.addEventListener('dragstart', function (e) {
            dragged = e.target.closest('li[data-id]');
            if (dragged) {
                dragged.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', dragged.dataset.id);
            }
        });
        list.addEventListener('dragover', function (e) {
            if (!dragged) {
                return;
            }
            e.preventDefault();
            var over = e.target.closest('li[data-id]');
            if (over && over !== dragged) {
                var r = over.getBoundingClientRect();
                list.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
            }
        });
        list.addEventListener('dragend', function () {
            if (!dragged) {
                return;
            }
            dragged.classList.remove('is-dragging');
            dragged = null;
            save(Array.prototype.map.call(list.querySelectorAll('li[data-id]'), function (li) {
                return parseInt(li.dataset.id, 10);
            }));
        });

        // the list of results is fixed to the window: no section below it (or a scrolling table) hides it
        function place() {
            if (found.hidden) {
                return;
            }
            var r = input.getBoundingClientRect();
            var below = window.innerHeight - r.bottom - 8;
            found.style.position = 'fixed';
            found.style.left = r.left + 'px';
            found.style.width = Math.max(r.width, 280) + 'px';
            found.style.right = 'auto';
            if (below < 160 && r.top > below) {
                found.style.top = 'auto';
                found.style.bottom = (window.innerHeight - r.top + 2) + 'px';
                found.style.maxHeight = Math.min(320, r.top - 8) + 'px';
            } else {
                found.style.bottom = 'auto';
                found.style.top = (r.bottom + 2) + 'px';
                found.style.maxHeight = Math.min(320, below) + 'px';
            }
        }
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);

        function show(items) {
            found.innerHTML = items.length ? items.map(function (t) {
                titles[t.id] = t;
                return '<li><button type="button" data-add="' + t.id + '">' + esc(t.title)
                    + (t.sku ? ' <small>' + esc(t.sku) + '</small>' : '') + ' <small class="text-muted">#' + t.id + ' · ' + esc(t.app)
                    + (t.published ? '' : ' · ' + esc(T.PICKER_UNPUBLISHED)) + '</small></button></li>';
            }).join('') : '<li class="bs-picker-empty">' + esc(T.PICKER_EMPTY) + '</li>';
            found.hidden = false;
            place();
        }

        function search() {
            var q = input.value.trim();
            if (!q) {
                found.hidden = true;
                return;
            }
            // every product is loaded once per page and filtered here: no request per keystroke
            allProducts(scope).then(function (list) {
                if (input.value.trim() === q) {
                    show(filterProducts(list, q));
                }
            }).catch(function () {
                serverSearch(q);
            });
        }

        function serverSearch(q) {
            call('products', { q: q, scope: scope }).then(function (data) {
                var items = data.items || [];
                found.innerHTML = items.length ? items.map(function (t) {
                    titles[t.id] = t;
                    return '<li><button type="button" data-add="' + t.id + '">' + esc(t.title)
                        + (t.sku ? ' <small>' + esc(t.sku) + '</small>' : '') + ' <small class="text-muted">#' + t.id + ' · ' + esc(t.app)
                        + (t.published ? '' : ' · ' + esc(T.PICKER_UNPUBLISHED)) + '</small></button></li>';
                }).join('') : '<li class="bs-picker-empty">' + esc(T.PICKER_EMPTY) + '</li>';
                found.hidden = false;
                place();
            }).catch(function (err) {
                found.innerHTML = '<li class="bs-picker-empty">' + esc(err.message) + '</li>';
                found.hidden = false;
                place();
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 60);
        });
        input.addEventListener('focus', function () {
            allProducts(scope).catch(function () {
            });
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                // never submit the plugin form from the picker
                e.preventDefault();
                var first = found.querySelector('[data-add]');
                if (first) {
                    first.click();
                }
            } else if (e.key === 'Escape') {
                found.hidden = true;
            }
        });
        found.addEventListener('click', function (e) {
            var b = e.target.closest('[data-add]');
            if (!b) {
                return;
            }
            var id = parseInt(b.dataset.add, 10);
            var current = multiple ? ids() : [];
            if (current.indexOf(id) < 0) {
                current.push(id);
            }
            save(current);
            input.value = '';
            found.hidden = true;
            input.focus();
        });
        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                found.hidden = true;
            }
        });

        loadTitles();
    }

    // ---------------------------------------------------------------- all products, filtered in the browser

    var productLists = {};

    function allProducts(scope) {
        scope = scope || 'products';
        var productList = productLists[scope];
        if (!productList) {
            productList = productLists[scope] = call('products_all', { scope: scope }).then(function (r) {
                return (r.items || []).map(function (p) {
                    var t = { id: p[0], title: p[1], sku: p[2], app: (r.apps || {})[p[3]] || '', published: p[4] };
                    t.key = fold(t.title + ' ' + t.sku + ' ' + t.id);
                    t.compact = t.key.replace(/ /g, '');
                    return t;
                });
            });
            productList.catch(function () {
                delete productLists[scope];
            });
        }
        return productList;
    }

    // lower case, without diacritics, separators as spaces ("MI-3155" = "mi 3155" = "mi3155")
    function fold(s) {
        return String(s || '').toLowerCase().replace(/ł/g, 'l').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, ' ').trim();
    }

    function filterProducts(list, q) {
        var words = fold(q).split(' ').filter(Boolean);
        if (!words.length) {
            return [];
        }
        var compact = words.join('');
        var out = [];
        for (var i = 0; i < list.length && out.length < 40; i++) {
            var p = list[i];
            var ok = String(p.id) === q.trim() || p.compact.indexOf(compact) >= 0 || words.every(function (w) {
                return p.key.indexOf(w) >= 0;
            });
            if (ok) {
                out.push(p);
            }
        }
        return out;
    }

    function initPickers(scope) {
        (scope || document).querySelectorAll('.bs-picker').forEach(initPicker);
    }

    // ================================================================ dictionary editors (synonyms, redirects)

    function actionButtons(q) {
        return '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-addsyn="' + esc(q) + '">' + esc(T.TOOLS_ADD_SYNONYM) + '</button> '
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-addred="' + esc(q) + '">' + esc(T.TOOLS_ADD_REDIRECT) + '</button>';
    }

    // "add synonym" / "redirect" from a report: a prompt, then a new line in the dictionary of the form
    document.addEventListener('click', function (e) {
        var syn = e.target.closest && e.target.closest('[data-bs-addsyn]');
        var red = e.target.closest && e.target.closest('[data-bs-addred]');
        if (!syn && !red) {
            return;
        }
        e.preventDefault();
        var q = (syn || red).getAttribute(syn ? 'data-bs-addsyn' : 'data-bs-addred');
        var answer = window.prompt(fmt(syn ? T.TOOLS_SYN_PROMPT : T.TOOLS_RED_PROMPT, [q]), '');
        if (!answer || !answer.trim()) {
            return;
        }
        document.dispatchEvent(new CustomEvent('bs-dict-add', { detail: syn
            ? { mode: 'synonyms', left: q + ', ' + answer.trim() }
            : { mode: 'redirects', left: q, url: answer.trim() } }));
        (syn || red).closest('tr').classList.add('table-success');
        Joomla.renderMessages({ message: [T.TOOLS_DICT_ADDED] });
    });

    function initDict(root) {
        var mode = root.getAttribute('data-bs-dict');
        var area = document.querySelector('[name="jform[params][' + root.getAttribute('data-target') + ']"]');
        if (!area) {
            return;
        }
        var group = area.closest('.control-group') || area.parentNode;
        var rows = [];
        var asText = false;

        function parse() {
            rows = area.value.split(/\r?\n/).filter(function (l) {
                return l.trim() !== '';
            }).map(function (line) {
                if (line.trim().charAt(0) === '#') {
                    return { raw: line };
                }
                if (mode === 'redirects') {
                    var p = line.split('=>');
                    var right = (p.slice(1).join('=>') || '').split('|');
                    return { left: p[0].trim(), url: (right[0] || '').trim(), label: right.slice(1).join('|').trim() };
                }
                var i = line.indexOf('=>');
                return i < 0 ? { left: line.trim(), oneway: false, right: '' } : { left: line.slice(0, i).trim(), oneway: true, right: line.slice(i + 2).trim() };
            });
        }

        function write() {
            area.value = rows.map(function (r) {
                if (r.raw !== undefined) {
                    return r.raw;
                }
                if (mode === 'redirects') {
                    return r.left && r.url ? r.left + ' => ' + r.url + (r.label ? ' | ' + r.label : '') : '';
                }
                return r.left ? (r.oneway && r.right ? r.left + ' => ' + r.right : r.left) : '';
            }).filter(function (l) {
                return l !== '';
            }).join('\n');
            area.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function draw() {
            var filter = (root.querySelector('.bs-dict-filter') || {}).value || '';
            var head = mode === 'redirects'
                ? '<th>' + esc(T.TOOLS_DICT_PHRASES) + '</th><th>' + esc(T.TOOLS_DICT_URL) + '</th><th>' + esc(T.TOOLS_DICT_LABEL) + '</th>'
                : '<th>' + esc(T.TOOLS_DICT_WORDS) + '</th><th>' + esc(T.TOOLS_DICT_ONEWAY) + '</th><th>' + esc(T.TOOLS_DICT_ALSO) + '</th>';
            var html = '<div class="bs-dict-bar"><input type="search" class="form-control form-control-sm bs-dict-filter" placeholder="' + esc(T.TOOLS_DICT_FILTER) + '" value="' + esc(filter) + '">'
                + '<button type="button" class="btn btn-sm btn-primary" data-dict="add">+ ' + esc(T.TOOLS_DICT_ADD) + '</button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-dict="text">' + esc(T.TOOLS_DICT_TEXT) + '</button>'
                + '<span class="small text-muted">' + esc(fmt(T.TOOLS_DICT_COUNT, [rows.length])) + '</span></div>';
            html += '<div class="bs-stats-wrap"><table class="table table-sm bs-dict-table"><thead><tr>' + head + '<th></th></tr></thead><tbody>';
            rows.forEach(function (r, i) {
                var text = JSON.stringify(r).toLowerCase();
                if (filter && text.indexOf(filter.toLowerCase()) < 0) {
                    return;
                }
                if (r.raw !== undefined) {
                    html += '<tr data-i="' + i + '"><td colspan="3"><input class="form-control form-control-sm" data-k="raw" value="' + esc(r.raw) + '"></td>';
                } else if (mode === 'redirects') {
                    html += '<tr data-i="' + i + '"><td><input class="form-control form-control-sm" data-k="left" value="' + esc(r.left) + '" placeholder="sonel, sonel mierniki"></td>'
                        + '<td><input class="form-control form-control-sm" data-k="url" value="' + esc(r.url) + '" placeholder="/oferta/…"></td>'
                        + '<td><input class="form-control form-control-sm" data-k="label" value="' + esc(r.label) + '"></td>';
                } else {
                    html += '<tr data-i="' + i + '"><td><input class="form-control form-control-sm" data-k="left" value="' + esc(r.left) + '" placeholder="multimetr, miernik uniwersalny"></td>'
                        + '<td><input type="checkbox" class="form-check-input" data-k="oneway"' + (r.oneway ? ' checked' : '') + '></td>'
                        + '<td><input class="form-control form-control-sm" data-k="right" value="' + esc(r.right) + '"' + (r.oneway ? '' : ' disabled') + '></td>';
                }
                html += '<td><button type="button" class="btn btn-sm btn-link text-danger" data-dict="del" aria-label="' + esc(T.PICKER_REMOVE) + '">✕</button></td></tr>';
            });
            html += '</tbody></table></div>';
            if (!rows.length) {
                html += '<p class="text-muted small">' + esc(T.TOOLS_NO_DATA) + '</p>';
            }
            root.innerHTML = html;
            var f = root.querySelector('.bs-dict-filter');
            if (document.activeElement === document.body && filter) {
                f.focus();
            }
        }

        function show() {
            group.hidden = !asText;
            if (asText) {
                root.innerHTML = '<button type="button" class="btn btn-sm btn-outline-secondary" data-dict="table">' + esc(T.TOOLS_DICT_TABLE) + '</button>';
            } else {
                parse();
                draw();
            }
        }

        root.addEventListener('input', function (e) {
            if (e.target.classList.contains('bs-dict-filter')) {
                var pos = e.target.selectionStart;
                draw();
                var f = root.querySelector('.bs-dict-filter');
                f.focus();
                f.setSelectionRange(pos, pos);
                return;
            }
            var tr = e.target.closest('tr[data-i]');
            if (!tr) {
                return;
            }
            var r = rows[parseInt(tr.dataset.i, 10)];
            var k = e.target.dataset.k;
            r[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
            if (k === 'oneway') {
                tr.querySelector('[data-k="right"]').disabled = !e.target.checked;
            }
            write();
        });
        root.addEventListener('click', function (e) {
            var b = e.target.closest('[data-dict]');
            if (!b) {
                return;
            }
            var a = b.dataset.dict;
            if (a === 'add') {
                rows.unshift(mode === 'redirects' ? { left: '', url: '', label: '' } : { left: '', oneway: false, right: '' });
                root.querySelector('.bs-dict-filter').value = '';
                draw();
                var first = root.querySelector('tbody input');
                if (first) {
                    first.focus();
                }
            } else if (a === 'del') {
                rows.splice(parseInt(b.closest('tr').dataset.i, 10), 1);
                write();
                draw();
            } else if (a === 'text') {
                asText = true;
                show();
            } else if (a === 'table') {
                asText = false;
                show();
            }
        });
        document.addEventListener('bs-dict-add', function (e) {
            var d = e.detail || {};
            if (d.mode !== mode) {
                return;
            }
            parse();
            rows.unshift(mode === 'redirects' ? { left: d.left, url: d.url, label: '' } : { left: d.left, oneway: false, right: '' });
            write();
            if (!asText) {
                draw();
            }
        });
        show();
    }

    // ================================================================ Google Search Console

    function initGsc(root) {
        var out = root.querySelector('.bs-gsc-out');
        var statusBox = root.querySelector('.bs-gsc-status');
        var form = root.closest('form');
        // the order of the table: sorted by the database (all queries), "found" sorted here (the rows shown)
        var view = { sort: 'impressions', dir: 'desc', limit: 200, filter: '', checked: false };
        var last = null;
        var COLS = [['query', T.TOOLS_GSC_QUERY, 'asc'], ['clicks', T.TOOLS_CONV_CLICKS, 'desc'], ['impressions', T.TOOLS_GSC_IMPR, 'desc'],
            ['ctr', T.TOOLS_GSC_CTR, 'desc'], ['position', T.TOOLS_GSC_POS, 'asc']];

        function params(extra) {
            var p = { sort: view.sort === 'found' ? 'impressions' : view.sort, dir: view.sort === 'found' ? 'desc' : view.dir, limit: view.limit, filter: view.filter };
            Object.keys(extra || {}).forEach(function (k) {
                p[k] = extra[k];
            });
            return p;
        }

        function draw(r) {
            last = r;
            var st = r.status || {};
            statusBox.innerHTML = st.at ? '<p class="' + (st.ok ? 'text-success' : 'text-danger') + '">' + esc(st.message) + ' <span class="text-muted">('
                + esc(fmt(T.TOOLS_GSC_LAST, [new Date(st.at * 1000).toLocaleString()])) + ')</span></p>' : '';
            var bar = '<div class="bs-gsc-bar"><input type="search" class="form-control form-control-sm bs-gsc-filter" placeholder="' + esc(T.TOOLS_GSC_FILTER) + '" value="' + esc(view.filter) + '">'
                + '<label class="small">' + esc(T.TOOLS_GSC_SHOW_ROWS) + ' <select class="form-select form-select-sm bs-gsc-limit">' + [100, 200, 500, 1000].map(function (n) {
                    return '<option value="' + n + '"' + (n === view.limit ? ' selected' : '') + '>' + n + '</option>';
                }).join('') + '</select></label>' + (r.total !== undefined ? '<span class="small text-muted">' + esc(fmt(T.TOOLS_GSC_COUNT, [(r.rows || []).length, r.total])) + '</span>' : '') + '</div>';
            if (!r.rows || !r.rows.length) {
                out.innerHTML = bar + '<p class="text-muted">' + esc(T.TOOLS_GSC_NONE) + '</p>';
                return;
            }
            var checked = r.rows[0].found !== undefined;
            view.checked = checked;
            var rows = r.rows.slice();
            if (view.sort === 'found' && checked) {
                // what Google brings people for and this search finds least: first (or last)
                var f = function (q) {
                    return q.found === null ? 1e9 : q.found;
                };
                rows.sort(function (a, b) {
                    return (view.dir === 'asc' ? f(a) - f(b) : f(b) - f(a)) || (b.impressions - a.impressions);
                });
            }
            var head = function (key, label) {
                var on = view.sort === key;
                return '<th aria-sort="' + (on ? (view.dir === 'asc' ? 'ascending' : 'descending') : 'none') + '"><button type="button" class="bs-sort' + (on ? ' is-on' : '')
                    + '" data-bs-sort="' + key + '">' + esc(label) + '<span class="bs-sort-arrow" aria-hidden="true">' + (on ? (view.dir === 'asc' ? '▲' : '▼') : '↕') + '</span></button></th>';
            };
            out.innerHTML = bar + '<div class="bs-stats-wrap"><table class="table table-sm table-striped bs-stats-table bs-gsc-table"><thead><tr>'
                + COLS.map(function (c) {
                    return head(c[0], c[1]);
                }).join('') + (checked ? head('found', T.TOOLS_GSC_FOUND) : '') + '<th></th></tr></thead><tbody>'
                + rows.map(function (q) {
                    var found = '';
                    if (checked) {
                        found = '<td>' + (q.found === null ? '…' : (q.found === 0 ? '<span class="badge bg-danger">0</span>' : q.found)) + '</td>';
                    }
                    return '<tr><td>' + esc(q.query) + '</td><td>' + esc(q.clicks) + '</td><td>' + esc(q.impressions) + '</td><td>' + esc(q.ctr) + ' %</td><td>' + esc(q.position) + '</td>' + found
                        + '<td class="bs-actions">' + actionButtons(q.query) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }

        function run(task, extra, withForm) {
            out.innerHTML = '<p>' + esc(T.TOOLS_WORKING) + '</p>';
            return call(task, params(extra), withForm ? form : null).then(function (r) {
                if (r.message && r.ok === false) {
                    Joomla.renderMessages({ error: [r.message] });
                } else if (r.message) {
                    Joomla.renderMessages({ message: [r.message] });
                }
                draw(r);
            }).catch(function (e) {
                out.innerHTML = '<p class="text-danger">' + esc(e.message) + '</p>';
            });
        }

        root.addEventListener('click', function (e) {
            var b = e.target.closest('[data-bs-tool]');
            if (!b) {
                return;
            }
            var tool = b.dataset.bsTool;
            if (tool === 'gsc_fetch') {
                run('gsc_fetch', {}, true);
            } else if (tool === 'gsc') {
                run('gsc');
            } else if (tool === 'gsc_check') {
                run('gsc', { check: 1 });
            }
        });
        // a click on a column heading sorts by it (again: the other direction)
        out.addEventListener('click', function (e) {
            var b = e.target.closest('[data-bs-sort]');
            if (!b) {
                return;
            }
            var key = b.dataset.bsSort;
            if (view.sort === key) {
                view.dir = view.dir === 'asc' ? 'desc' : 'asc';
            } else {
                view.sort = key;
                view.dir = key === 'found' ? 'asc' : COLS.filter(function (c) {
                    return c[0] === key;
                })[0][2];
            }
            if (key === 'found') {
                draw(last);
            } else {
                run('gsc', view.checked ? { check: 1 } : {});
            }
        });
        var filterTimer = 0;
        out.addEventListener('input', function (e) {
            if (!e.target.classList.contains('bs-gsc-filter')) {
                return;
            }
            clearTimeout(filterTimer);
            var value = e.target.value;
            filterTimer = setTimeout(function () {
                view.filter = value.trim();
                call('gsc', params(view.checked ? { check: 1 } : {})).then(function (r) {
                    draw(r);
                    var input = out.querySelector('.bs-gsc-filter');
                    if (input) {
                        input.focus();
                        input.setSelectionRange(input.value.length, input.value.length);
                    }
                }).catch(function () {
                });
            }, 350);
        });
        out.addEventListener('change', function (e) {
            if (e.target.classList.contains('bs-gsc-limit')) {
                view.limit = parseInt(e.target.value, 10) || 200;
                run('gsc', view.checked ? { check: 1 } : {});
            }
        });
        root.querySelector('.bs-gsc-file').addEventListener('change', function (e) {
            var file = e.target.files && e.target.files[0];
            if (!file) {
                return;
            }
            var reader = new FileReader();
            reader.onload = function () {
                run('gsc_import', { _post: { csv: String(reader.result || '') } });
                e.target.value = '';
            };
            reader.readAsText(file, 'UTF-8');
        });
        call('gsc', params()).then(draw).catch(function () {
        });
    }

    // ================================================================ e-mail report

    function initReport(root) {
        var out = root.querySelector('.bs-report-out');
        var statusBox = root.querySelector('.bs-report-status');
        var form = root.closest('form');

        function showStatus(r) {
            var st = r.status;
            statusBox.innerHTML = (r.to ? '<p>' + esc(T.TOOLS_REPORT_TO) + ': <b>' + esc(r.to) + '</b></p>' : '<p class="text-danger">' + esc(T.TOOLS_REPORT_NO_TO) + '</p>')
                + (st && st.at ? '<p class="' + (st.ok ? 'text-success' : 'text-danger') + '">' + esc(st.message) + ' <span class="text-muted">('
                    + esc(new Date(st.at * 1000).toLocaleString()) + ')</span></p>' : '');
        }

        root.addEventListener('click', function (e) {
            var b = e.target.closest('[data-bs-tool]');
            if (!b) {
                return;
            }
            var tool = b.dataset.bsTool;
            if (tool === 'report_preview') {
                out.innerHTML = '<p>' + esc(T.TOOLS_WORKING) + '</p>';
                call('report_preview', {}, form).then(function (r) {
                    showStatus(r);
                    out.innerHTML = '<p class="small text-muted">' + esc(fmt(T.TOOLS_REPORT_PERIOD, [r.period])) + '</p><iframe class="bs-report-frame" title="' + esc(T.TOOLS_REPORT_PERIOD.replace('%s', '')) + '"></iframe>';
                    var frame = out.querySelector('iframe');
                    frame.srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:16px;background:#fff">' + r.html + '</body>';
                    frame.addEventListener('load', function () {
                        try {
                            frame.style.height = (frame.contentDocument.body.scrollHeight + 40) + 'px';
                        } catch (err) {
                        }
                    });
                }).catch(function (err) {
                    out.innerHTML = '<p class="text-danger">' + esc(err.message) + '</p>';
                });
            } else if (tool === 'report_send') {
                if (!window.confirm(T.TOOLS_REPORT_CONFIRM)) {
                    return;
                }
                b.disabled = true;
                call('report_send', {}, form).then(function (r) {
                    Joomla.renderMessages(r.ok ? { message: [r.message] } : { error: [r.message] });
                    statusBox.insertAdjacentHTML('beforeend', '<p class="' + (r.ok ? 'text-success' : 'text-danger') + '">' + esc(r.message) + '</p>');
                }).catch(function (err) {
                    Joomla.renderMessages({ error: [err.message] });
                }).then(function () {
                    b.disabled = false;
                });
            }
        });
    }

    // ================================================================ tools

    function initTools(root) {
        var status = root.querySelector('.bs-status');
        var progress = root.querySelector('.bs-progress');
        var bar = root.querySelector('.bs-progress-bar');
        var form = root.closest('form');

        function showStatus(s) {
            status.innerHTML = '<table class="table table-sm bs-status-table"><tbody>'
                + '<tr><th>' + esc(T.TOOLS_ITEMS) + '</th><td>' + s.items + ' / ' + s.pages + '</td></tr>'
                + '<tr><th>' + esc(T.TOOLS_PENDING) + '</th><td>' + s.pending + (s.configOk ? '' : ' <span class="badge bg-warning text-dark">' + esc(T.TOOLS_CONFIG_CHANGED) + '</span>') + '</td></tr>'
                + '<tr><th>' + esc(T.TOOLS_APPS) + '</th><td>' + (s.apps || []).map(function (a) {
                    return esc(a.title) + ' <small class="text-muted">#' + a.id + '</small>';
                }).join(', ') + '</td></tr>'
                + '<tr><th>' + esc(T.TOOLS_CHECKED) + '</th><td>' + esc(s.checked) + '</td></tr>'
                + '<tr><th>' + esc(T.TOOLS_COMPLETE) + '</th><td>' + esc(s.complete) + '</td></tr>'
                + '</tbody></table>';
        }

        function refresh() {
            call('status').then(showStatus).catch(function (e) {
                status.textContent = e.message;
            });
        }

        function run(force) {
            var buttons = root.querySelectorAll('[data-bs-tool]');
            buttons.forEach(function (b) {
                b.disabled = true;
            });
            progress.hidden = false;
            bar.style.width = '0%';
            var started = null;
            var step = function (first) {
                return call('sync', { budget: 400, force: first && force ? 1 : 0 }).then(function (r) {
                    if (started === null) {
                        started = r.changed;
                    }
                    var done = started ? (started - r.remaining) / started : 1;
                    bar.style.width = Math.round(done * 100) + '%';
                    bar.textContent = (started - r.remaining) + ' / ' + started;
                    showStatus(r);
                    if (r.remaining > 0) {
                        return step(false);
                    }
                    return r;
                });
            };
            step(true).then(function () {
                bar.style.width = '100%';
                Joomla.renderMessages({ message: [T.TOOLS_DONE] });
            }).catch(function (e) {
                Joomla.renderMessages({ error: [e.message] });
            }).then(function () {
                buttons.forEach(function (b) {
                    b.disabled = false;
                });
                setTimeout(function () {
                    progress.hidden = true;
                }, 1500);
            });
        }

        function test() {
            var q = root.querySelector('.bs-test-q').value.trim();
            var out = root.querySelector('.bs-test-out');
            if (!q) {
                return;
            }
            out.innerHTML = '<p>' + esc(T.TOOLS_WORKING) + '</p>';
            call('test', { q: q, sort: root.querySelector('.bs-test-sort').value }, form).then(function (r) {
                var html = '<p class="bs-test-sum"><b>' + r.total + '</b> ' + esc(T.TOOLS_RESULTS) + ' · ' + esc(T.TOOLS_MODE) + ': <code>' + esc(r.mode) + '</code>'
                    + ' · ' + esc(T.TOOLS_GROUPS) + ': ' + r.groups.map(function (g) {
                        return '<code>' + esc(g) + '</code>';
                    }).join(' ') + ((r.params || []).length ? ' · ' + esc(T.TOOLS_PARAMS) + ': ' + r.params.map(function (g) {
                        return '<code>' + esc(g) + '</code>';
                    }).join(' ') : '') + ' · ' + r.ms + ' ms'
                    + (r.corrected ? ' · ' + esc(T.TOOLS_CORRECTED) + ': <b>' + esc(r.corrected) + '</b>' : '') + '</p>';
                if (r.rows.length) {
                    html += '<table class="table table-sm table-striped bs-test-table"><thead><tr><th>#</th><th>ID</th><th></th><th>'
                        + esc(T.TOOLS_SCORE) + '</th><th>' + esc(T.TOOLS_WHY) + '</th></tr></thead><tbody>'
                        + r.rows.map(function (row, i) {
                            return '<tr' + (row.featured ? ' class="table-warning"' : (row.pinned ? ' class="table-info"' : '')) + '><td>' + (i + 1) + '</td><td>' + row.id + '</td><td>' + esc(row.title)
                                + (row.sku ? '<br><small class="text-muted">' + esc(row.sku) + '</small>' : '') + '</td><td>'
                                + (row.featured ? esc(T.TOOLS_FEATURED) : (row.pinned ? esc(T.TOOLS_PINNED) : row.score.toFixed(1))) + '</td><td><small>' + row.reasons.map(esc).join('<br>') + '</small></td></tr>';
                        }).join('') + '</tbody></table>';
                }
                out.innerHTML = html;
            }).catch(function (e) {
                out.innerHTML = '<p class="text-danger">' + esc(e.message) + '</p>';
            });
        }

        function conversions() {
            var out = root.querySelector('.bs-conv-out');
            var days = root.querySelector('.bs-conv-days').value;
            out.innerHTML = '<p>' + esc(T.TOOLS_WORKING) + '</p>';
            call('conversions', { days: days }).then(function (r) {
                var pct = function (a, b) {
                    return b > 0 ? (Math.round(a / b * 1000) / 10) + ' %' : '–';
                };
                var html = '<p class="bs-test-sum">' + esc(fmt(T.TOOLS_CONV_TOTAL, [r.searches, r.clicks, r.carts])) + '</p>';
                if (!r.queries.length) {
                    out.innerHTML = html + '<p class="text-muted">' + esc(T.TOOLS_NO_DATA) + '</p>';
                    return;
                }
                html += '<div class="bs-stats-wrap"><table class="table table-sm table-striped bs-stats-table"><thead><tr><th>' + esc(T.TOOLS_CONV_QUERY) + '</th><th>'
                    + esc(T.TOOLS_SEARCHES) + '</th><th>' + esc(T.TOOLS_CONV_CLICKS) + '</th><th>' + esc(T.TOOLS_CONV_CTR) + '</th><th>' + esc(T.TOOLS_CONV_CARTS)
                    + '</th><th>' + esc(T.TOOLS_CONV_CART_RATE) + '</th></tr></thead><tbody>'
                    + r.queries.map(function (q) {
                        var s = parseInt(q.searches, 10) || 0, c = parseInt(q.clicks, 10) || 0, k = parseInt(q.carts, 10) || 0;
                        return '<tr><td><a href="#" data-bs-try="' + esc(q.query) + '">' + esc(q.query) + '</a></td><td>' + (s || '–') + '</td><td>' + c + '</td><td>'
                            + pct(c, s) + '</td><td>' + k + '</td><td>' + pct(k, c) + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
                if (r.items.length) {
                    html += '<h4 class="mt-3">' + esc(T.TOOLS_CONV_PRODUCTS) + '</h4><div class="bs-stats-wrap"><table class="table table-sm table-striped bs-stats-table"><thead><tr><th></th><th>'
                        + esc(T.TOOLS_CONV_CLICKS) + '</th><th>' + esc(T.TOOLS_CONV_CARTS) + '</th></tr></thead><tbody>'
                        + r.items.map(function (i) {
                            return '<tr><td>' + esc(i.title) + ' <small class="text-muted">#' + esc(i.item_id) + '</small></td><td>' + esc(i.clicks) + '</td><td>' + esc(i.carts) + '</td></tr>';
                        }).join('') + '</tbody></table></div>';
                }
                out.innerHTML = html;
            }).catch(function (e) {
                out.innerHTML = '<p class="text-danger">' + esc(e.message) + '</p>';
            });
        }

        function statsTable(rows, actions) {
            if (!rows.length) {
                return '<p class="text-muted">' + esc(T.TOOLS_NO_DATA) + '</p>';
            }
            return '<div class="bs-stats-wrap"><table class="table table-sm table-striped bs-stats-table"><thead><tr><th></th><th>' + esc(T.TOOLS_SEARCHES)
                + '</th><th>' + esc(T.TOOLS_RESULTS_COL) + '</th><th>' + esc(T.TOOLS_LAST) + '</th>' + (actions ? '<th></th>' : '') + '</tr></thead><tbody>'
                + rows.map(function (r) {
                    var n = parseInt(r.results, 10);
                    return '<tr><td><a href="#" data-bs-try="' + esc(r.query) + '">' + esc(r.query) + '</a></td><td>' + r.searches + '</td><td>'
                        + (n === 0 ? '<span class="badge bg-danger">0</span>' : (n < 0 ? '<span class="badge bg-info">→ ' + esc(T.TOOLS_REDIRECTED) + '</span>' : r.results))
                        + '</td><td><small>' + esc(r.last_at) + '</small></td>'
                        + (actions ? '<td class="bs-actions">' + actionButtons(r.query) + '</td>' : '') + '</tr>';
                }).join('') + '</tbody></table></div>';
        }

        var statsView = 'top';

        function stats(task) {
            var out = root.querySelector('.bs-stats-out');
            call(task).then(function (r) {
                // one full-width table at a time, switched with tabs
                var tabs = [['top', T.TOOLS_TOP, r.top], ['zero', T.TOOLS_ZERO, r.zero], ['recent', T.TOOLS_RECENT, r.recent]];
                var draw = function () {
                    out.innerHTML = '<div class="bs-stats-tabs" role="tablist">' + tabs.map(function (t) {
                        return '<button type="button" role="tab" aria-selected="' + (t[0] === statsView) + '" class="' + (t[0] === statsView ? 'is-active' : '')
                            + '" data-bs-stats="' + t[0] + '">' + esc(t[1]) + '<span class="badge ' + (t[0] === 'zero' && t[2].length ? 'bg-danger' : 'bg-secondary') + '">'
                            + t[2].length + '</span></button>';
                    }).join('') + '</div>' + (statsView === 'zero' ? '<p class="small text-muted">' + esc(T.TOOLS_ZERO_HELP) + '</p>' : '')
                        + statsTable(tabs.filter(function (t) { return t[0] === statsView; })[0][2], statsView === 'zero');
                };
                draw();
                out.onclick = function (e) {
                    var b = e.target.closest('[data-bs-stats]');
                    if (b) {
                        statsView = b.dataset.bsStats;
                        draw();
                    }
                };
            }).catch(function (e) {
                out.innerHTML = '<p class="text-danger">' + esc(e.message) + '</p>';
            });
        }

        root.addEventListener('click', function (e) {
            var tryQ = e.target.closest('[data-bs-try]');
            if (tryQ) {
                e.preventDefault();
                root.querySelector('.bs-test-q').value = tryQ.dataset.bsTry;
                test();
                root.querySelector('.bs-test-q').scrollIntoView({ block: 'center' });
                return;
            }
            var b = e.target.closest('[data-bs-tool]');
            if (!b) {
                return;
            }
            var tool = b.dataset.bsTool;
            if (tool === 'sync') {
                run(false);
            } else if (tool === 'rebuild') {
                if (window.confirm(T.TOOLS_REBUILD_CONFIRM)) {
                    run(true);
                }
            } else if (tool === 'test') {
                test();
            } else if (tool === 'stats') {
                stats('stats');
            } else if (tool === 'clearlog') {
                if (window.confirm(T.TOOLS_CLEAR_CONFIRM)) {
                    stats('clearlog');
                }
            } else if (tool === 'conversions') {
                conversions();
            } else if (tool === 'clearthumbs') {
                call('clearthumbs').then(function (r) {
                    Joomla.renderMessages({ message: [T.TOOLS_THUMBS_REMOVED.replace('%d', r.removed)] });
                });
            } else if (tool === 'clearcache') {
                call('clearcache').then(function () {
                    Joomla.renderMessages({ message: [T.TOOLS_CACHE_CLEARED] });
                });
            }
        });
        root.querySelector('.bs-test-q').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                test();
            }
        });

        refresh();
    }

    // ================================================================ help tooltips ("?" beside the option names)

    // The text sits next to its "?" and is shown by CSS (hover, keyboard focus) or by a click
    // (class is-open): no positioning script, so no administrator template can push it away.
    function initHelp() {
        var form = document.getElementById('style-form') || document.querySelector('form[name="adminForm"]');
        if (!form) {
            return;
        }
        var n = 0;

        function closeAll(except) {
            form.querySelectorAll('.bs-help-wrap.is-open').forEach(function (w) {
                if (w !== except) {
                    w.classList.remove('is-open');
                    w.querySelector('.bs-help').setAttribute('aria-expanded', 'false');
                }
            });
        }

        function add(scope) {
            scope.querySelectorAll('.control-group').forEach(function (g) {
                var head = g.querySelector('.control-label');
                var desc = g.querySelector('[id$="-desc"]');
                if (!head || !desc || !desc.textContent.trim() || head.querySelector('.bs-help-wrap')) {
                    return;
                }
                var wrap = document.createElement('span');
                wrap.className = 'bs-help-wrap';
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'bs-help';
                b.textContent = '?';
                b.setAttribute('aria-label', T.HELP || '?');
                b.setAttribute('aria-expanded', 'false');
                var tip = document.createElement('span');
                tip.className = 'bs-help-tip';
                tip.id = 'bs-help-tip-' + (++n);
                tip.innerHTML = (desc.querySelector('.form-text') || desc).innerHTML;
                b.setAttribute('aria-describedby', tip.id);
                wrap.appendChild(b);
                wrap.appendChild(tip);
                head.appendChild(wrap);
            });
        }

        form.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.bs-help');
            if (!b) {
                if (!(e.target.closest && e.target.closest('.bs-help-tip'))) {
                    closeAll(null);
                }
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var wrap = b.parentNode;
            var open = !wrap.classList.contains('is-open');
            closeAll(wrap);
            wrap.classList.toggle('is-open', open);
            b.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAll(null);
                if (document.activeElement && document.activeElement.classList.contains('bs-help')) {
                    document.activeElement.blur();
                }
            }
        });
        add(form);
        document.addEventListener('subform-row-add', function (e) {
            add((e.detail && e.detail.row) || e.target);
        });
    }

    // ================================================================ settings file, reset

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-bs-settings]');
        if (!b) {
            return;
        }
        e.preventDefault();
        var action = b.getAttribute('data-bs-settings');
        var form = b.closest('form');
        if (action === 'export') {
            call('settings_export', {}, form).then(function (r) {
                var blob = new Blob([JSON.stringify(r.data, null, 2)], { type: 'application/json' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = r.name;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    URL.revokeObjectURL(a.href);
                    a.remove();
                }, 1000);
                Joomla.renderMessages({ message: [fmt(T.TOOLS_SETTINGS_EXPORTED, [r.name])] });
            }).catch(function (err) {
                Joomla.renderMessages({ error: [err.message] });
            });
        } else if (action === 'reset') {
            if (window.confirm(T.TOOLS_SETTINGS_RESET_CONFIRM)) {
                call('settings_reset', { _post: { reset: '1' } }).then(function () {
                    window.location.reload();
                }).catch(function (err) {
                    Joomla.renderMessages({ error: [err.message] });
                });
            }
        }
    });

    document.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('bs-settings-file')) {
            return;
        }
        var file = e.target.files && e.target.files[0];
        e.target.value = '';
        if (!file) {
            return;
        }
        if (file.size > 1048576) {
            Joomla.renderMessages({ error: [T.TOOLS_SETTINGS_TOO_BIG] });
            return;
        }
        if (!window.confirm(T.TOOLS_SETTINGS_IMPORT_CONFIRM)) {
            return;
        }
        file.text().then(function (text) {
            return call('settings_import', { _post: { data: text } });
        }).then(function (r) {
            Joomla.renderMessages({ message: [fmt(T.TOOLS_SETTINGS_IMPORTED, [r.count])] });
            setTimeout(function () {
                window.location.reload();
            }, 900);
        }).catch(function (err) {
            Joomla.renderMessages({ error: [err.message] });
        });
    });

    function start() {
        initHelp();
        initPickers(document);
        document.querySelectorAll('.bs-tools').forEach(function (root) {
            if (root.querySelector('.bs-report-out')) {
                initReport(root);
            } else if (root.querySelector('.bs-gsc-out')) {
                initGsc(root);
            } else {
                initTools(root);
            }
        });
        document.querySelectorAll('[data-bs-dict]').forEach(initDict);
        // rows added to a subform (rules, boosts) bring new pickers
        document.addEventListener('subform-row-add', function (e) {
            var row = (e.detail && e.detail.row) || e.target;
            initPickers(row);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
