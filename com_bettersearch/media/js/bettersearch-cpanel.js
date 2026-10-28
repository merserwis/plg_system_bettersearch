/**
 * Better Search for Gridbox — control panel: the figures of the index (pages indexed, pages waiting) and
 * the button "clear the caches". Both are answered by the system plugin (com_ajax, admin_status and
 * admin_clearcache), with the token of the page.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('com_bettersearch.cpanel') : null;

  function call(task) {
    return fetch(opts.ajax + '&task=admin_' + task + (task === 'status' ? '&light=1' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        var data = json && json.data && json.data[0];
        if (!data || data.error) { throw new Error((data && data.error) || (json && json.message) || 'error'); }
        return data;
      });
  }

  function fmt(text, args) {
    var i = 0;
    return String(text).replace(/%(\d\$)?[sd]/g, function () { return args[i++]; });
  }

  function start() {
    if (!opts) { return; }
    var box = document.querySelector('[data-bsc-index]');
    if (box) {
      call('status').then(function (s) {
        var text = fmt(opts.texts.INDEX_ITEMS, [s.items, s.pages]);
        text += ' · ' + (s.pending > 0 ? fmt(opts.texts.INDEX_PENDING, [s.pending]) : opts.texts.INDEX_PENDING_NONE);
        box.textContent = text;
      }).catch(function () { box.textContent = opts.texts.INDEX_ERROR; });
    }
    var clear = document.querySelector('[data-bsc-clear-cache]');
    if (clear) {
      clear.addEventListener('click', function () {
        clear.disabled = true;
        call('clearcache').then(function () {
          Joomla.renderMessages({ message: [opts.texts.CACHE_CLEARED] });
        }).catch(function (e) {
          Joomla.renderMessages({ error: [e.message] });
        }).then(function () { clear.disabled = false; });
      });
    }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
