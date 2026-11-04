(function () {
  'use strict';

  var root = document.getElementById('pennyOnboarding');
  if (!root) return;

  var API = '/api/settings/business-identity';
  var line = document.getElementById('itObPennyLine');
  var workspace = document.getElementById('itObWorkspace');
  var error = document.getElementById('itObError');
  var knowledge = document.getElementById('itObKnowledge');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var identity = null;
  var enteredName = '';
  var matches = [];

  // The knowledge glyph lives in ittai-knowledge-glyph.js (shared with onboarding's "About you", 2026-10-20).
  var KnowledgeGlyph = window.ItKnowledgeGlyph;

  var glyph = new KnowledgeGlyph(knowledge, { reducedMotion: reduced });

  function esc(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function escAttr(value) { return esc(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
  function setLine(message) { line.textContent = message; }
  function setError(message) { error.textContent = message || ''; error.hidden = !message; }
  function focusFirst() { var target = workspace.querySelector('input, select, button'); if (target) target.focus(); }
  function busy(on) { Array.prototype.forEach.call(workspace.querySelectorAll('button, input, select'), function (el) { el.disabled = on; }); }
  function calmError(err) {
    if (err && err.status === 403) return 'You do not have permission to change this business identity.';
    if (err && err.status === 401) return 'Your session has ended. Please sign in again.';
    return 'I couldn\'t save that just now.';
  }
  function api(mode, options) {
    options = options || {}; var method = options.method || 'GET'; var url = API + '?mode=' + encodeURIComponent(mode);
    if (options.query) url += '&' + new URLSearchParams(options.query).toString();
    var headers = { Accept: 'application/json' };
    if (method === 'POST') { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = csrf; }
    return fetch(url, { method: method, credentials: 'same-origin', headers: headers, body: options.body ? JSON.stringify(options.body) : undefined }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || data.ok === false) { var requestError = new Error(data.message || data.error || 'Request failed'); requestError.status = response.status; throw requestError; }
        return data;
      });
    });
  }
  function sameName(a, b) { return !!a && !!b && String(a).trim().toLocaleLowerCase() === String(b).trim().toLocaleLowerCase(); }
  function operatingName(record) { return record.operating_name || (record.operating_names && record.operating_names[0]) || ''; }
  function displayName(record) {
    var registryOperating = operatingName(record);
    var typed = enteredName || (identity && identity.entered_name) || '';
    if (registryOperating && !sameName(registryOperating, record.legal_name)) return registryOperating;
    if (typed && !sameName(typed, record.legal_name)) return typed;
    return record.legal_name || typed;
  }
  function field(label, value, extraClass) {
    if (value === undefined || value === null || value === '') return '';
    return '<div class="it-ob-detail' + (extraClass ? ' ' + extraClass : '') + '"><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>';
  }
  function statusField(record) {
    var status = record.status || record.registration_status;
    if (!status) return '';
    return '<div class="it-ob-detail"><dt>Status</dt><dd><span class="it-ob-status"><span class="it-ob-status-dot" aria-hidden="true"></span>' + esc(status) + '</span></dd></div>';
  }
  function sourceAttribution() {
    return '<div class="it-ob-source"><span>Source: BC Registries via OrgBook BC</span><details class="it-ob-source-info"><summary aria-label="About this source">i</summary><p>Contains information licensed under the <a href="https://www2.gov.bc.ca/gov/content/data/policy-standards/open-data/open-government-licence-bc" target="_blank" rel="noopener">Open Government Licence – British Columbia</a>.</p></details></div>';
  }
  function registryCard(record, withActions) {
    var extras = field('Legal structure', record.legal_structure) + field('Registered on', record.registered_on);
    var html = '<div class="it-ob-match-heading"><h2>' + esc(displayName(record)) + '</h2><p><span>Registered as</span> ' + esc(record.legal_name) + '</p></div>';
    html += '<article class="it-ob-card"><dl class="it-ob-details">' + field('Registration', record.registration_number) + statusField(record) + field('Jurisdiction', record.jurisdiction) + '</dl>';
    if (extras) html += '<details class="it-ob-more"><summary>More details</summary><dl class="it-ob-details">' + extras + '</dl></details>';
    html += '</article>' + sourceAttribution();
    if (withActions) {
      html += '<div class="it-ob-actions it-ob-confirm-actions"><button class="it-ob-button it-ob-button-primary" type="button" data-action="confirm">Yes, that\'s my business</button>';
      if (matches.length > 1) html += '<button class="it-ob-button it-ob-button-link" type="button" data-action="others">Show other matches</button>';
      html += '<button class="it-ob-button it-ob-button-link" type="button" data-action="manual">Enter details manually</button></div><div class="it-ob-confirm-error" role="alert" hidden></div>';
    }
    return html;
  }

  function showNameQuestion() {
    identity = null; enteredName = ''; matches = []; setError(''); glyph.setState(null, false);
    setLine("Hello! I'm Penny. I'll be looking after your books and helping you keep your business finances organized. Let's start with something simple. What's your business called?");
    workspace.innerHTML = '<form class="it-ob-form" id="itObNameForm"><div class="it-ob-field"><label for="itObBusinessName">Business name</label><input class="it-ob-input" id="itObBusinessName" name="name" type="text" maxlength="255" autocomplete="organization" required></div><div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="submit">Continue</button></div></form>';
    document.getElementById('itObNameForm').addEventListener('submit', start); focusFirst();
  }
  function start(event) {
    event.preventDefault(); setError(''); var input = document.getElementById('itObBusinessName'); enteredName = input.value.trim();
    if (!enteredName) { setError('Please enter the name you use for your business.'); input.focus(); return; }
    busy(true); api('start', { method: 'POST', body: { name: enteredName } }).then(function (data) { identity = data.identity || { entered_name: enteredName }; glyph.setState(data.knowledge && data.knowledge.stage || 'recognized', true); search(); }).catch(function (err) { busy(false); setError(calmError(err)); });
  }
  function search() {
    setError(''); setLine('Looking this up in the BC Registry…'); workspace.innerHTML = '<p class="it-ob-progress" role="status">Looking this up in the BC Registry…</p>';
    api('search', { query: { q: enteredName } }).then(renderSearch).catch(function () { renderSearch({ status: 'unavailable', matches: [] }); });
  }
  function renderSearch(data) {
    matches = (data.matches || []).slice(0, 5);
    if (data.status === 'matches' && matches.length === 1) return showMatch(matches[0]);
    if (data.status === 'matches' && matches.length > 1) return showMatches();
    if (data.status === 'none' || (data.status === 'matches' && !matches.length)) { setLine("I couldn't find a confident match. That's okay. We can enter your business details ourselves."); return showManual(); }
    setLine("I can't reach the BC Registry right now."); workspace.innerHTML = '<div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="button" data-action="retry">Try again</button><button class="it-ob-button it-ob-button-link" type="button" data-action="manual">Enter details manually</button></div>'; bindActions(); focusFirst();
  }
  function showMatches() {
    setLine('I found a few businesses that may be yours. Choose one to check the details.');
    workspace.innerHTML = '<div class="it-ob-match-list" role="list">' + matches.map(function (match, i) { var secondary = match.registration_number || match.jurisdiction || ''; return '<button type="button" class="it-ob-match-row" data-match="' + i + '" role="listitem"><span><span>' + esc(match.legal_name || operatingName(match)) + '</span>' + (secondary ? '<small>' + esc(secondary) + '</small>' : '') + '</span></button>'; }).join('') + '</div><div class="it-ob-actions"><button class="it-ob-button it-ob-button-link" type="button" data-action="manual">Enter details manually</button></div>';
    Array.prototype.forEach.call(workspace.querySelectorAll('[data-match]'), function (button) { button.addEventListener('click', function () { showMatch(matches[Number(button.getAttribute('data-match'))]); }); }); bindActions(); focusFirst();
  }
  function showMatch(record) {
    setLine('I found a possible match. Is this your business?'); workspace.innerHTML = registryCard(record, true);
    workspace.querySelector('[data-action="confirm"]').addEventListener('click', function () { confirmMatch(record); }); bindActions(); focusFirst();
  }
  function bindActions() {
    var retry = workspace.querySelector('[data-action="retry"]'); if (retry) retry.addEventListener('click', search);
    var manual = workspace.querySelector('[data-action="manual"]'); if (manual) manual.addEventListener('click', showManual);
    var others = workspace.querySelector('[data-action="others"]'); if (others) others.addEventListener('click', showMatches);
  }
  function confirmMatch(record) {
    setError(''); var button = workspace.querySelector('[data-action="confirm"]'); var failure = workspace.querySelector('.it-ob-confirm-error');
    failure.hidden = true; busy(true); button.textContent = 'Confirming…'; button.setAttribute('aria-busy', 'true');
    api('confirm', { method: 'POST', body: { record_id: record.record_id } }).then(function (data) {
      identity = data.identity; var persistedStage = data.knowledge && data.knowledge.stage || 'identified';
      return glyph.setState(persistedStage, true).then(showContactsStart);
    }).catch(function (err) {
      busy(false); button.textContent = "Yes, that's my business"; button.removeAttribute('aria-busy');
      failure.innerHTML = '<span>' + esc(calmError(err)) + '</span><button class="it-ob-button it-ob-button-link" type="button">Retry</button>'; failure.hidden = false;
      failure.querySelector('button').addEventListener('click', function () { confirmMatch(record); }); failure.querySelector('button').focus();
    });
  }
  function showContactsStart() {
    setLine("Perfect — I've identified your registered business. Let me see if I can find your website and contact details.");
    workspace.innerHTML = '<section class="it-ob-next-card" aria-labelledby="itObContactsTitle"><h2 id="itObContactsTitle">Website and contact details</h2><p class="it-ob-progress" role="status">Looking for contact details…</p></section>';
    return api('contacts').then(renderContacts).catch(renderContactsFallback);
  }
  function renderContacts(data) {
    var contacts = data.contacts || [];
    if (!contacts.length) return renderContactsFallback();
    var labels = { website: 'Website', phone: 'Phone', address: 'Address' };
    workspace.innerHTML = '<section class="it-ob-next-card" aria-labelledby="itObContactsTitle"><h2 id="itObContactsTitle">Website and contact details</h2><div class="it-ob-contact-list">' + contacts.map(function (contact) {
      return '<div class="it-ob-contact-row"><div><span class="it-ob-contact-label">' + esc(labels[contact.field] || contact.field) + '</span><strong>' + esc(contact.value) + '</strong><small>From your settings</small></div><div class="it-ob-contact-actions"><button class="it-ob-button it-ob-button-link" type="button">Looks right</button><button class="it-ob-button it-ob-button-link" type="button">Change</button></div></div>';
    }).join('') + '</div></section>';
  }
  function renderContactsFallback() { workspace.innerHTML = '<section class="it-ob-next-card" aria-labelledby="itObContactsTitle"><h2 id="itObContactsTitle">Website and contact details</h2><p>I\'ll look for your website and contact details next.</p></section>'; }
  function showManual() {
    setLine("We'll enter the details you know. You can update them later.");
    workspace.innerHTML = '<form class="it-ob-form" id="itObManualForm"><div class="it-ob-field"><label for="itObLegalName">Legal name</label><input class="it-ob-input" id="itObLegalName" name="legal_name" type="text" maxlength="255" required value="' + escAttr(enteredName) + '"></div><div class="it-ob-field"><label for="itObOperatingName">Operating name <span class="it-ob-muted">(optional)</span></label><input class="it-ob-input" id="itObOperatingName" name="operating_name" type="text" maxlength="255"></div><div class="it-ob-form-grid"><div class="it-ob-field"><label for="itObStructure">Legal structure</label><select class="it-ob-select" id="itObStructure" name="legal_structure"><option value="">Select if known</option><option>Sole proprietorship</option><option>Partnership</option><option>Corporation</option><option>Society</option><option>Cooperative</option><option>Other</option></select></div><div class="it-ob-field"><label for="itObJurisdiction">Jurisdiction</label><select class="it-ob-select" id="itObJurisdiction" name="jurisdiction"><option value="">Select if known</option><option value="BC">British Columbia</option><option value="Federal">Federal</option><option value="AB">Alberta</option><option value="Other">Other</option></select></div></div><div class="it-ob-field"><label for="itObRegistration">BC registration number <span class="it-ob-muted">(optional)</span></label><input class="it-ob-input" id="itObRegistration" name="registration_number" type="text" maxlength="100"><small>This is not your CRA Business Number.</small></div><div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="submit">Save business details</button></div></form>';
    document.getElementById('itObManualForm').addEventListener('submit', saveManual); focusFirst();
  }
  function saveManual(event) {
    event.preventDefault(); setError(''); var form = event.currentTarget; var body = {};
    ['legal_name', 'operating_name', 'legal_structure', 'jurisdiction', 'registration_number'].forEach(function (key) { var value = form.elements[key].value.trim(); if (value || key === 'legal_name') body[key] = value; });
    busy(true); api('manual', { method: 'POST', body: body }).then(function (data) { identity = data.identity; return glyph.setState(data.knowledge && data.knowledge.stage || 'identified', true).then(showContactsStart); }).catch(function (err) { busy(false); setError(calmError(err)); });
  }
  function showReturning(data) {
    identity = data.identity; enteredName = identity.entered_name || ''; glyph.setState(data.knowledge && data.knowledge.stage, false);
    setLine("Welcome back. Here's the business identity I have saved.");
    var html = '<article class="it-ob-card"><h2 class="it-ob-card-title">Your business</h2><dl class="it-ob-details">';
    html += field('Name', displayName(identity)) + field('Registered as', identity.legal_name) + field('Registration', identity.registration_number) + statusField(identity) + field('Jurisdiction', identity.jurisdiction) + field('Legal structure', identity.legal_structure) + '</dl></article>';
    html += data.can_edit ? '<button class="it-ob-change" type="button" data-action="change">Change</button>' : ''; workspace.innerHTML = html;
    var change = workspace.querySelector('[data-action="change"]'); if (change) change.addEventListener('click', showNameQuestion);
  }

  setLine('Let me check what I already know about your business.'); workspace.innerHTML = '<p class="it-ob-progress" role="status">Loading your business identity…</p>';
  api('current').then(function (data) { if (data.identity) showReturning(data); else if (data.can_edit === false) { setLine('No business identity has been added yet. An administrator with edit access can complete this step.'); workspace.innerHTML = ''; } else showNameQuestion(); }).catch(function (err) { setLine('I could not load your business identity just now.'); workspace.innerHTML = '<div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary" type="button" id="itObReload">Try again</button></div>'; setError(calmError(err)); document.getElementById('itObReload').addEventListener('click', function () { window.location.reload(); }); });
}());
