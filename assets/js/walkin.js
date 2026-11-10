/* Session 8 walk-in form: add rows, live availability, running total.
   Plain JavaScript. Without it the form still works with the rows already on the page;
   the server re-checks stock when the order is saved. */
(function () {
  'use strict';
  var body = document.getElementById('s8-cart-body');
  var tpl = document.getElementById('s8-row-tpl');
  var addBtn = document.getElementById('s8-add-row');
  var totalEl = document.getElementById('s8-total');
  var api = body ? body.getAttribute('data-api') : '';
  if (!body) { return; }

  function money(n) { return '\u20B1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  function describe(row, live) {
    var sel = row.querySelector('select');
    var qty = row.querySelector('input[type=number]');
    var out = row.querySelector('.s8-avail');
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { out.textContent = ''; out.className = 's8-avail'; return; }
    var tracks = opt.getAttribute('data-tracks') === '1';
    var avail = live ? live.available : parseInt(opt.getAttribute('data-available'), 10);
    var cap = parseInt(opt.getAttribute('data-cap'), 10);
    var q = parseInt(qty.value, 10) || 0;
    var msg = '', cls = 's8-avail';
    if (!tracks) { msg = 'Made to order / service: no stock count'; }
    else if (avail <= 0) { msg = 'Out of stock'; cls += ' s8-avail-out'; }
    else {
      msg = avail + ' available';
      if (q > avail) { msg += ' \u2013 only ' + avail + ' can be ordered'; cls += ' s8-avail-out'; }
    }
    if (q > cap && cap > 0) { msg += ' \u00B7 above the online limit of ' + cap + ' (allowed at the counter)'; if (cls === 's8-avail') { cls += ' s8-avail-over'; } }
    out.textContent = msg;
    out.className = cls;
  }

  function total() {
    var sum = 0;
    body.querySelectorAll('tr').forEach(function (row) {
      var sel = row.querySelector('select');
      var opt = sel && sel.options[sel.selectedIndex];
      var q = parseInt(row.querySelector('input[type=number]').value, 10) || 0;
      if (opt && opt.value) { sum += parseFloat(opt.getAttribute('data-price')) * q; }
    });
    if (totalEl) { totalEl.textContent = money(sum); }
  }

  function refresh(rows) {
    var ids = [];
    rows.forEach(function (r) { var v = r.querySelector('select').value; if (v) { ids.push(v); } });
    if (!ids.length || !api || !window.fetch) { rows.forEach(function (r) { describe(r, null); }); total(); return; }
    fetch(api + '?do=stock&ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        rows.forEach(function (r) {
          var v = r.querySelector('select').value;
          describe(r, data && data.stock && data.stock[v] ? data.stock[v] : null);
        });
        total();
      })
      .catch(function () { rows.forEach(function (r) { describe(r, null); }); total(); });
  }

  function allRows() { return Array.prototype.slice.call(body.querySelectorAll('tr')); }

  body.addEventListener('change', function (ev) { var r = ev.target.closest('tr'); if (r) { refresh([r]); } });
  body.addEventListener('input', function (ev) { if (ev.target.type === 'number') { describe(ev.target.closest('tr'), null); total(); } });
  body.addEventListener('click', function (ev) {
    var rm = ev.target.closest('[data-remove-row]');
    if (!rm) { return; }
    var r = rm.closest('tr');
    if (allRows().length > 1) { r.parentNode.removeChild(r); } else { r.querySelector('select').value = ''; r.querySelector('input[type=number]').value = ''; }
    total();
  });
  if (addBtn && tpl) {
    addBtn.addEventListener('click', function () {
      if (allRows().length >= parseInt(body.getAttribute('data-max'), 10)) { return; }
      body.appendChild(tpl.content.firstElementChild.cloneNode(true));
    });
  }
  refresh(allRows());
  setInterval(function () { refresh(allRows()); }, 30000);   // keep availability live while the form is open
})();
