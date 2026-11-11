/**
 * mw-ledger.js — keyboard tables and drill-down in place for the Ledger (2026-10-13).
 *
 *   <table data-mw-keytable [data-mw-detail-url="/crm/ledger/entry.php?id="]>
 *     <tbody><tr tabindex="0" data-id="123">…
 *
 *   /      focus the page's search box ([data-mw-ledger-search])
 *   j / k  next / previous row (also ArrowDown / ArrowUp while a row has focus)
 *   Enter  open the row's detail under it (fetches detail-url + id + '&part=1'); Enter/Esc again closes
 *   Links with data-mw-entry="N" inside a detail open entry N in that same panel.
 *   A table without a detail URL whose rows carry data-mw-row-href opens that page on Enter / click
 *   (the trial balance → the account's general ledger).
 *
 * Also: <select data-mw-autosubmit>, <button data-mw-confirm="…">, <button data-mw-copy="#input">.
 * No dependencies; works without it (rows link to full pages through the detail URL).
 */
(function () {
  'use strict';

  function rowsOf(table) {
    return Array.prototype.slice.call(table.querySelectorAll('tbody > tr.mw-ledger-row'));
  }

  function closeDetail(row) {
    var next = row.nextElementSibling;
    if (next && next.classList.contains('mw-ledger-detail-row')) {
      next.parentNode.removeChild(next);
      row.setAttribute('aria-expanded', 'false');
      return true;
    }
    return false;
  }

  function load(cell, url, id) {
    cell.innerHTML = '<div class="mw-ledger-loading" role="status">Loading entry #' + id + '…</div>';
    fetch(url + encodeURIComponent(id) + '&part=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        // Signed out (idle timeout): the server answers 401, or a redirect lands on the login page.
        // Go there — never paste the login page into the row.
        if (r.status === 401 || r.redirected) {
          window.location.href = '/loginAuth/login.php?timeout=1';
          return null;
        }
        return r.text().then(function (t) { return { ok: r.ok, text: t }; });
      })
      .then(function (res) {
        if (!res) return;
        cell.innerHTML = res.ok ? res.text : '<div class="mw-ledger-detail"><p>Could not open entry #' + id + '.</p></div>';
      })
      .catch(function () { cell.innerHTML = '<div class="mw-ledger-detail"><p>Could not open entry #' + id + ' — check the connection.</p></div>'; });
  }

  function toggleDetail(table, row) {
    var url = table.getAttribute('data-mw-detail-url');
    var id = row.getAttribute('data-id');
    if (!url || !id) return;
    if (closeDetail(row)) return;
    var cols = row.children.length;
    var tr = document.createElement('tr');
    tr.className = 'mw-ledger-detail-row';
    var td = document.createElement('td');
    td.colSpan = cols;
    tr.appendChild(td);
    row.parentNode.insertBefore(tr, row.nextSibling);
    row.setAttribute('aria-expanded', 'true');
    load(td, url, id);
  }

  function initTable(table) {
    var url = table.getAttribute('data-mw-detail-url');
    table.addEventListener('click', function (e) {
      var link = e.target.closest('a[data-mw-entry]');
      if (link && url) {
        e.preventDefault();
        var cell = link.closest('.mw-ledger-detail-row > td');
        if (cell) load(cell, url, link.getAttribute('data-mw-entry'));
        return;
      }
      if (e.target.closest('a, button, input, select, textarea, label, form')) return;
      var row = e.target.closest('tr.mw-ledger-row');
      if (!row) return;
      if (url) toggleDetail(table, row);
      else if (row.getAttribute('data-mw-row-href')) window.location.href = row.getAttribute('data-mw-row-href');
    });
    table.addEventListener('keydown', function (e) {
      var row = e.target.closest && e.target.closest('tr.mw-ledger-row');
      if (!row || e.target !== row) return;
      if (e.key === 'Enter') {
        e.preventDefault();
        if (url) toggleDetail(table, row);
        else if (row.getAttribute('data-mw-row-href')) window.location.href = row.getAttribute('data-mw-row-href');
      }
      else if (e.key === 'Escape') { closeDetail(row); }
    });
  }

  function move(delta) {
    var active = document.activeElement;
    var table = (active && active.closest && active.closest('table[data-mw-keytable]')) || document.querySelector('table[data-mw-keytable]');
    if (!table) return;
    var rows = rowsOf(table);
    if (!rows.length) return;
    var i = rows.indexOf(active && active.closest ? active.closest('tr.mw-ledger-row') : null);
    var next = i < 0 ? (delta > 0 ? 0 : rows.length - 1) : Math.max(0, Math.min(rows.length - 1, i + delta));
    rows[next].focus();
    rows[next].scrollIntoView({ block: 'nearest' });
  }

  document.addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    var t = e.target;
    var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
    if (typing) {
      if (e.key === 'Escape' && t.matches('[data-mw-ledger-search]')) t.blur();
      return;
    }
    if (e.key === '/') {
      var s = document.querySelector('[data-mw-ledger-search]');
      if (s) { e.preventDefault(); s.focus(); s.select(); }
    } else if (e.key === 'j' || (e.key === 'ArrowDown' && t.closest && t.closest('tr.mw-ledger-row'))) {
      e.preventDefault(); move(1);
    } else if (e.key === 'k' || (e.key === 'ArrowUp' && t.closest && t.closest('tr.mw-ledger-row'))) {
      e.preventDefault(); move(-1);
    }
  });

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('table[data-mw-keytable]'), initTable);
    Array.prototype.forEach.call(document.querySelectorAll('select[data-mw-autosubmit]'), function (s) {
      s.addEventListener('change', function () { if (s.form) s.form.submit(); });
    });
    document.addEventListener('click', function (e) {
      var c = e.target.closest('[data-mw-confirm]');
      if (c && !window.confirm(c.getAttribute('data-mw-confirm'))) { e.preventDefault(); e.stopPropagation(); }
      var cp = e.target.closest('[data-mw-copy]');
      if (cp) {
        var input = document.querySelector(cp.getAttribute('data-mw-copy'));
        if (!input) return;
        input.select();
        var done = function () { cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = 'Copy'; }, 1600); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
        else { document.execCommand('copy'); done(); }
      }
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
