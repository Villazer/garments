// Staff panel: mobile menu toggle and confirm prompts. Progressive enhancement only:
// without JavaScript the sidebar simply stays visible and forms still work.
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
})();
