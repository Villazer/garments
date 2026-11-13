// Staff panel: mobile menu toggle, confirm prompts, field-error accessibility (Session 10).
// Progressive enhancement only: without JavaScript the sidebar simply stays visible and forms still work.
(function () {
  'use strict';

  var btn = document.querySelector('.sp-menu-btn');
  var side = document.getElementById('sp-sidebar');
  if (btn && side) {
    btn.addEventListener('click', function () {
      var open = side.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // <button data-confirm="Are you sure?"> asks before submitting its form
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest ? ev.target.closest('[data-confirm]') : null;
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });

  // Link each error message to its field and focus the first one.
  var first = null;
  document.querySelectorAll('.sp-field-error, .inv-error').forEach(function (msg, i) {
    if (!msg.textContent.trim()) { return; }
    var control = null, node = msg.previousElementSibling;
    while (!control && node) {
      if (/^(INPUT|SELECT|TEXTAREA)$/.test(node.tagName)) { control = node; }
      else if (node.querySelector) { control = node.querySelector('input,select,textarea'); }
      node = control ? null : node.previousElementSibling;
    }
    if (!control && msg.parentElement) { control = msg.parentElement.querySelector('input,select,textarea'); }
    if (!control || control.type === 'hidden') { return; }
    if (!msg.id) { msg.id = 'fe-' + i; }
    control.setAttribute('aria-invalid', 'true');
    control.setAttribute('aria-describedby', ((control.getAttribute('aria-describedby') || '') + ' ' + msg.id).trim());
    if (!first) { first = control; }
  });
  if (first && first.focus) { first.focus(); }
})();
