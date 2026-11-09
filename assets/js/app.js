/* Shared behaviour: menu toggles, tabs, dismissible alerts, confirm prompts, print buttons.
   Plain JavaScript, no dependencies. Pages work without it (progressive enhancement). */
(function () {
  'use strict';

  // Menu toggles (public nav and staff sidebar): button[aria-controls] shows/hides the target.
  document.querySelectorAll('.nav-toggle').forEach(function (btn) {
    var target = document.getElementById(btn.getAttribute('aria-controls'));
    if (!target) { return; }
    btn.addEventListener('click', function () {
      var open = target.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // Tabs: <div data-tabs> with .tab buttons (role=tab, aria-controls) and .tab-panel elements.
  document.querySelectorAll('[data-tabs]').forEach(function (root) {
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = document.getElementById(t.getAttribute('aria-controls'));
        if (panel) { panel.hidden = !on; }
      });
      if (focus) { tab.focus(); }
    }
    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { select(tab, false); });
      tab.addEventListener('keydown', function (ev) {
        var next = null;
        if (ev.key === 'ArrowRight') { next = tabs[(i + 1) % tabs.length]; }
        if (ev.key === 'ArrowLeft')  { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
        if (ev.key === 'Home') { next = tabs[0]; }
        if (ev.key === 'End')  { next = tabs[tabs.length - 1]; }
        if (next) { ev.preventDefault(); select(next, true); }
      });
    });
    var current = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0];
    if (current) { select(current, false); }
  });

  // Dismissible alerts and print buttons (event delegation).
  document.addEventListener('click', function (ev) {
    var close = ev.target.closest('[data-dismiss="alert"]');
    if (close) {
      var alertEl = close.closest('.alert');
      if (alertEl) { alertEl.remove(); }
      return;
    }
    if (ev.target.closest('[data-print]')) { window.print(); }
  });

  // Confirm before risky actions: <form data-confirm="Cancel this order?"> or <a data-confirm="...">.
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); }
  });
  document.addEventListener('click', function (ev) {
    var link = ev.target.closest('a[data-confirm]');
    if (link && !window.confirm(link.getAttribute('data-confirm'))) { ev.preventDefault(); }
  });
})();
