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
    var WRITES = { sync: 1, clearlog: 1, clearthumbs: 1, clearcache: 1 };

    function call(task, params, form) {
        var url = opts.ajax + '&task=admin_' + task;
        Object.keys(params || {}).forEach(function (k) {
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        });
        var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (WRITES[task]) {
            init.method = 'POST';
            init.body = new FormData();
        }
        if (form) {
            init.method = 'POST';
            // only the plugin settings: the form's own option/task fields would redirect the request
            init.body = new FormData();
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

        function search() {
            var q = input.value.trim();
            if (!q) {
                found.hidden = true;
                return;
            }
            call('products', { q: q }).then(function (data) {
                var items = data.items || [];
                found.innerHTML = items.length ? items.map(function (t) {
                    titles[t.id] = t;
                    return '<li><button type="button" data-add="' + t.id + '">' + esc(t.title)
                        + (t.sku ? ' <small>' + esc(t.sku) + '</small>' : '') + ' <small class="text-muted">#' + t.id + ' · ' + esc(t.app)
                        + (t.published ? '' : ' · ' + esc(T.PICKER_UNPUBLISHED)) + '</small></button></li>';
                }).join('') : '<li class="bs-picker-empty">' + esc(T.PICKER_EMPTY) + '</li>';
                found.hidden = false;
            }).catch(function (err) {
                found.innerHTML = '<li class="bs-picker-empty">' + esc(err.message) + '</li>';
                found.hidden = false;
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 250);
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

    function initPickers(scope) {
        (scope || document).querySelectorAll('.bs-picker').forEach(initPicker);
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
                    }).join(' ') + ' · ' + r.ms + ' ms'
                    + (r.corrected ? ' · ' + esc(T.TOOLS_CORRECTED) + ': <b>' + esc(r.corrected) + '</b>' : '') + '</p>';
                if (r.rows.length) {
                    html += '<table class="table table-sm table-striped bs-test-table"><thead><tr><th>#</th><th>ID</th><th></th><th>'
                        + esc(T.TOOLS_SCORE) + '</th><th>' + esc(T.TOOLS_WHY) + '</th></tr></thead><tbody>'
                        + r.rows.map(function (row, i) {
                            return '<tr' + (row.pinned ? ' class="table-info"' : '') + '><td>' + (i + 1) + '</td><td>' + row.id + '</td><td>' + esc(row.title)
                                + (row.sku ? '<br><small class="text-muted">' + esc(row.sku) + '</small>' : '') + '</td><td>'
                                + (row.pinned ? esc(T.TOOLS_PINNED) : row.score.toFixed(1)) + '</td><td><small>' + row.reasons.map(esc).join('<br>') + '</small></td></tr>';
                        }).join('') + '</tbody></table>';
                }
                out.innerHTML = html;
            }).catch(function (e) {
                out.innerHTML = '<p class="text-danger">' + esc(e.message) + '</p>';
            });
        }

        function statsTable(rows) {
            if (!rows.length) {
                return '<p class="text-muted">' + esc(T.TOOLS_NO_DATA) + '</p>';
            }
            return '<div class="bs-stats-wrap"><table class="table table-sm table-striped bs-stats-table"><thead><tr><th></th><th>' + esc(T.TOOLS_SEARCHES)
                + '</th><th>' + esc(T.TOOLS_RESULTS_COL) + '</th><th>' + esc(T.TOOLS_LAST) + '</th></tr></thead><tbody>'
                + rows.map(function (r) {
                    return '<tr><td><a href="#" data-bs-try="' + esc(r.query) + '">' + esc(r.query) + '</a></td><td>' + r.searches + '</td><td>'
                        + (parseInt(r.results, 10) === 0 ? '<span class="badge bg-danger">0</span>' : r.results) + '</td><td><small>' + esc(r.last_at) + '</small></td></tr>';
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
                    }).join('') + '</div>' + statsTable(tabs.filter(function (t) { return t[0] === statsView; })[0][2]);
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

    function start() {
        initPickers(document);
        document.querySelectorAll('.bs-tools').forEach(initTools);
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
