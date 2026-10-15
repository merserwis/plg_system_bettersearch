/**
 * Better Search for Gridbox — the update-index button in the top bar of the administrator: runs the
 * steps of "Update index" (Tools tab) until nothing is left, the progress on the button, the result
 * as a Joomla message. Atum copies the button into its "more" menu on narrow screens, so clicks are
 * caught on the document.
 */
(function () {
  'use strict';

  var busy = false;

  function fmt(text, values) {
    var i = 0;
    return String(text || '').replace(/%[sd]/g, function () {
      return values[i++];
    });
  }

  function message(type, text) {
    if (window.Joomla && Joomla.renderMessages) {
      var m = {};
      m[type] = [text];
      Joomla.renderMessages(m);
    } else {
      window.alert(text);
    }
  }

  document.addEventListener('click', function (e) {
    var button = e.target.closest ? e.target.closest('[data-bettersearch-sync]') : null;
    if (!button) { return; }
    e.preventDefault();
    if (busy) { return; }
    var cfg = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_bettersearch.header') : null;
    if (!cfg || !cfg.ajax) { return; }
    var T = cfg.texts || {};
    busy = true;
    var buttons = [].slice.call(document.querySelectorAll('[data-bettersearch-sync]'));
    function show(icon, label, left) {
      buttons.forEach(function (b) {
        b.querySelector('.header-item-icon span').className = icon;
        b.querySelector('.header-item-text').textContent = label;
        // the pages still waiting; none left: the count goes away
        var count = b.querySelector('.header-item-count');
        if (count && left !== undefined) {
          count.textContent = String(left);
          count.hidden = !left;
          b.title = left ? T.HEADER_TITLE + ' — ' + fmt(T.HEADER_PENDING, [left]) : T.HEADER_TITLE;
        }
      });
    }
    show('icon-spinner icon-spin', fmt(T.HEADER_WORKING, [0, '…']));
    var started = null;
    var indexed = 0;
    var removed = 0;
    var left;
    function step() {
      var body = new FormData();
      return fetch(cfg.ajax + '&task=admin_sync&budget=400', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          var r = json && json.data && json.data[0];
          if (!r || r.error) { throw new Error((r && r.error) || (json && json.message) || T.HEADER_ERROR); }
          if (started === null) { started = r.changed; }
          indexed += r.indexed || 0;
          left = r.remaining;
          removed += r.removed || 0;
          show('icon-spinner icon-spin', fmt(T.HEADER_WORKING, [started - r.remaining, started]), r.remaining);
          return r.remaining > 0 ? step() : r;
        });
    }
    step().then(function () {
      message('message', indexed || removed ? fmt(T.HEADER_UPDATED, [indexed, removed]) : T.TOOLS_DONE);
    }).catch(function (err) {
      message('error', (err && err.message) || T.HEADER_ERROR);
    }).then(function () {
      busy = false;
      show('icon-sync', T.HEADER_LABEL, left);
    });
  });
})();
