/**
 * "Also pays as" card (public/crm/includes/payer-aliases-card.php) → /crm/api/payer-aliases.php.
 * Add = the owner's word; Remove = kept removed so Penny never learns it back.
 */
(function () {
  'use strict';
  var API = '/crm/api/payer-aliases.php';

  function toast(msg, type) {
    if (typeof window.mwToast === 'function') window.mwToast(msg, type || 'success', 3500);
  }

  function post(body) {
    return fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function init(card) {
    var list = card.querySelector('.mw-payer-aliases-list');
    var form = card.querySelector('.mw-payer-alias-add');

    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.mw-payer-alias-remove');
      if (!btn) return;
      var li = btn.closest('li');
      btn.disabled = true;
      post({ action: 'remove', id: parseInt(li.getAttribute('data-id'), 10) }).then(function (d) {
        if (!d.ok) { btn.disabled = false; toast(d.error || 'Could not remove it.', 'error'); return; }
        li.remove();
        toast('Removed. Penny won’t learn it back.');
      }).catch(function () { btn.disabled = false; toast('Could not reach the CRM.', 'error'); });
    });

    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var alias = form.alias.value.trim();
      if (!alias) return;
      var kind = form.kind.value;
      var btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      post({
        action: 'add', kind: kind, alias: alias, payer: card.getAttribute('data-payer'),
        contact_id: parseInt(card.getAttribute('data-contact-id'), 10) || null,
        company_id: parseInt(card.getAttribute('data-company-id'), 10) || null
      }).then(function (d) {
        btn.disabled = false;
        if (!d.ok) { toast(d.error || 'Could not add it.', 'error'); return; }
        var empty = list.querySelector('.mw-payer-aliases-empty');
        if (empty) empty.remove();
        var li = document.createElement('li');
        li.className = 'd-flex justify-content-between align-items-center py-1';
        li.setAttribute('data-id', d.id);
        var label = form.kind.options[form.kind.selectedIndex].text;
        var span = document.createElement('span');
        var strong = document.createElement('strong');
        strong.textContent = alias;
        var small = document.createElement('small');
        small.className = 'text-muted';
        small.textContent = ' · ' + label + ' · you said so';
        span.appendChild(strong); span.appendChild(small);
        var rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'btn btn-sm btn-link text-muted mw-payer-alias-remove'; rm.textContent = 'Remove';
        rm.setAttribute('aria-label', 'Remove ' + alias);
        li.appendChild(span); li.appendChild(rm);
        list.appendChild(li);
        form.alias.value = '';
        toast('Saved. Penny will use it on the next deposit.');
      }).catch(function () { btn.disabled = false; toast('Could not reach the CRM.', 'error'); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mw-payer-aliases').forEach(init);
  });
})();
