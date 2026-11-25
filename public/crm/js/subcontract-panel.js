/* Subcontract card on the visit page. */
(function () {
  'use strict';
  var card = document.getElementById('mw-sub');
  if (!card) return;
  var api = '/crm/api/subcontract.php';
  var visitId = parseInt(card.getAttribute('data-visit'), 10);
  var jobId = parseInt(card.getAttribute('data-job'), 10);
  var errorBox = document.getElementById('mw-sub-error');
  var $ = function (id) { return document.getElementById(id); };

  function showError(words) {
    errorBox.textContent = words || '';
    errorBox.hidden = !words;
  }

  function call(body) {
    return fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'The server did not answer properly. Nothing was changed.' }; });
    }).catch(function () { return { ok: false, error: 'No connection. Nothing was changed.' }; });
  }

  function billReceived() {
    return !!($('mw-sub-done') && $('mw-sub-done').checked && $('mw-sub-bill-received').checked);
  }

  function markEmailed(to) {
    $('mw-sub-send-to').closest('.input-group').style.display = 'none';
    $('mw-sub-share-status').textContent = 'Emailed to ' + to + '. Nothing more to do. Share or Copy is only if you also want to text it.';
  }

  function showLink(link) {
    $('mw-sub-share').hidden = false;
    $('mw-sub-link').value = link;
    $('mw-sub-share').scrollIntoView({ block: 'nearest' });
    if (!navigator.share) $('mw-sub-share-btn').hidden = true;
  }

  var form = $('mw-sub-form');
  if (form) {
    var company = $('mw-sub-company');
    $('mw-sub-done').addEventListener('change', function () { $('mw-sub-bill-row').hidden = !this.checked; });
    var emailButton = $('mw-sub-email-job');
    var linkButton = $('mw-sub-link-only');
    var address = function () {
      return company.value === '0' ? $('mw-sub-email').value.trim() : (company.options[company.selectedIndex].getAttribute('data-email') || '');
    };
    var syncNew = function () {
      $('mw-sub-new').hidden = company.value !== '0';
      var to = address();
      $('mw-sub-send-to').value = to;
      $('mw-sub-to').textContent = to ? 'It goes to ' + to + '.' : 'No email on file for this company. Use the link instead, or add a company with an email.';
    };
    company.addEventListener('change', syncNew);
    $('mw-sub-email').addEventListener('input', syncNew);
    syncNew();

    var create = function (thenEmail) {
      var to = address();
      if (thenEmail && !to) { showError('This company has no email on file. Use "Just create a link to share" instead.'); return; }
      emailButton.disabled = true;
      linkButton.disabled = true;
      showError('');
      $('mw-sub-send-to').value = to;
      call({
        action: 'create', visit_id: visitId, subcontractor_id: parseInt(company.value, 10),
        company_name: $('mw-sub-name').value, contact_name: $('mw-sub-contact').value, email: $('mw-sub-email').value, phone: $('mw-sub-phone').value,
        on_ittai: $('mw-sub-ittai').checked, agreed_amount: $('mw-sub-amount').value, note: $('mw-sub-note').value,
        share_client_name: $('mw-sub-share-name').checked, share_client_phone: $('mw-sub-share-phone').checked, already_done: $('mw-sub-done').checked,
        bill_received: billReceived()
      }).then(function (res) {
        if (!res.ok) { emailButton.disabled = false; linkButton.disabled = false; showError(res.error); return; }
        form.hidden = true;
        jobId = res.job_id;
        showLink(res.link);
        if (!thenEmail) return;
        $('mw-sub-share-status').textContent = 'Emailing…';
        call({ action: 'email_link', email: to, link: res.link, bill_received: billReceived() }).then(function (sent) {
          if (sent.ok) markEmailed(to);
          else { $('mw-sub-share-status').textContent = 'The job is created, but the email did not send. Copy the link instead.'; showError(sent.error); }
        });
      });
    };
    form.addEventListener('submit', function (ev) { ev.preventDefault(); create(true); });
    linkButton.addEventListener('click', function () { create(false); });
  }

  var confirmWords = {
    cancel: 'Cancel this job? Their link stops working.',
    new_link: 'Make a new link? The one you sent stops working.',
    accept: 'Accept the work? This completes the visit.'
  };
  card.querySelectorAll('[data-sub-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      var action = button.getAttribute('data-sub-action');
      if (confirmWords[action] && !window.confirm(confirmWords[action])) return;
      button.disabled = true;
      showError('');
      call({ action: action, job_id: jobId }).then(function (res) {
        if (!res.ok) { button.disabled = false; showError(res.error); return; }
        if (res.link) { button.disabled = false; showLink(res.link); return; }
        window.location.reload();
      });
    });
  });

  $('mw-sub-share-btn').addEventListener('click', function () {
    navigator.share({ title: 'A job for you', url: $('mw-sub-link').value }).catch(function () { /* she closed the sheet */ });
  });
  $('mw-sub-copy').addEventListener('click', function () {
    var input = $('mw-sub-link');
    var done = function () { $('mw-sub-share-status').textContent = 'Copied.'; };
    if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done);
    else { input.select(); document.execCommand('copy'); done(); }
  });
  $('mw-sub-email-btn').addEventListener('click', function () {
    var button = this;
    button.disabled = true;
    call({ action: 'email_link', email: $('mw-sub-send-to').value, link: $('mw-sub-link').value, bill_received: billReceived() }).then(function (res) {
      button.disabled = false;
      if (res.ok) markEmailed($('mw-sub-send-to').value);
      else showError(res.error);
    });
  });
})();
