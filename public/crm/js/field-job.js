/**
 * Field Job — "Add a job on the spot" overlay (crew / Capacitor app).
 *
 * Entry points (rendered server-side, gated by $canFieldCreate):
 *   .mw-fj-fab        — floating action button (mobile "mission control" view)
 *   .mw-fj-empty-btn  — empty-state CTA when no stops today
 * Both call window.MwFieldJob.open().
 *
 * Flow:
 *   locating -> pick (search / nearby list / "+ New") -> branch (existing
 *   property: add visit to today's route OR start a new job) -> job form
 *   (existing property, or new client) -> result
 *
 * Talks to /crm/api/nearby-properties.php (property picker) and
 * /crm/api/field-job.php (add_visit / create_job / create_client_job) via
 * the shared MwApi helper (mw-api.js).
 */
(function () {
  'use strict';

  var SERVICE_TYPES = ['Lawn', 'Landscape', 'Hedge', 'Garden', 'Cleanup', 'Snow'];

  var overlayEl = null;
  var panelEl = null;
  var titleEl = null;
  var backBtnEl = null;
  var searchDebounce = null;

  var state = null;

  // ── Guards ────────────────────────────────────────────────────────────
  if (!window.MwApi) {
    window.MwFieldJob = {
      open: function () {
        alert('This feature isn’t available right now. Please reload the page and try again.');
      }
    };
    return;
  }

  var csrf = (document.querySelector('.mw-mc-container') || {}).dataset
    ? document.querySelector('.mw-mc-container').dataset.csrf
    : (window.MW_CSRF || '');
  MwApi.setToken(csrf || '');

  // ── Helpers ───────────────────────────────────────────────────────────

  function esc(s) {
    var el = document.createElement('span');
    el.textContent = s == null ? '' : String(s);
    return el.innerHTML;
  }

  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function uuid() {
    if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function getLocation() {
    return new Promise(function (resolve) {
      if (window.MwNative && window.MwNative.geo) {
        window.MwNative.geo.getCurrentPosition()
          .then(function (pos) { resolve({ lat: pos.lat, lng: pos.lng }); })
          .catch(function () { resolve({ lat: null, lng: null }); });
      } else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function (pos) { resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }); },
          function () { resolve({ lat: null, lng: null }); },
          { timeout: 6000, maximumAge: 60000 }
        );
      } else {
        resolve({ lat: null, lng: null });
      }
    });
  }

  function fetchNearby(query) {
    return MwApi.post('/crm/api/nearby-properties.php', { lat: state.lat, lng: state.lng, q: query })
      .then(function (data) { state.results = (data && data.properties) || []; })
      .catch(function () { state.results = []; });
  }

  // ── Overlay chrome ───────────────────────────────────────────────────

  function ensureOverlay() {
    if (overlayEl) return;

    overlayEl = document.createElement('div');
    overlayEl.className = 'mw-fj-overlay';
    overlayEl.id = 'mwFjOverlay';
    overlayEl.innerHTML =
      '<div class="mw-fj-sheet">' +
      '  <div class="mw-fj-head">' +
      '    <button type="button" class="mw-fj-close" id="mwFjBack">&times;</button>' +
      '    <div class="mw-fj-title" id="mwFjTitle">Add a Job</div>' +
      '    <div class="mw-fj-head-spacer"></div>' +
      '  </div>' +
      '  <div class="mw-fj-panel" id="mwFjPanel"></div>' +
      '</div>';
    document.body.appendChild(overlayEl);

    panelEl = overlayEl.querySelector('#mwFjPanel');
    titleEl = overlayEl.querySelector('#mwFjTitle');
    backBtnEl = overlayEl.querySelector('#mwFjBack');

    backBtnEl.addEventListener('click', goBack);
    overlayEl.addEventListener('click', function (e) {
      if (e.target === overlayEl) closeOverlay(); // tap outside the sheet
    });

    panelEl.addEventListener('click', onPanelClick);
    panelEl.addEventListener('input', onPanelInput);
  }

  function showOverlay() { overlayEl.classList.add('show'); }
  function closeOverlay() { overlayEl.classList.remove('show'); }

  function goBack() {
    if (state.screen === 'branch') { state.screen = 'pick'; render(); return; }
    if (state.screen === 'newjob') { state.screen = 'branch'; render(); return; }
    if (state.screen === 'newclient') { state.screen = 'pick'; render(); return; }
    closeOverlay();
  }

  // ── Open ─────────────────────────────────────────────────────────────

  function open() {
    ensureOverlay();
    state = {
      screen: 'locating',
      lat: null, lng: null, query: '',
      results: [], property: null, mode: null,
      selectedService: '', recurring: false, freq: 'weekly',
      resultOk: false, resultMsg: ''
    };
    showOverlay();
    render();

    getLocation()
      .then(function (pos) { state.lat = pos.lat; state.lng = pos.lng; return fetchNearby(''); })
      .then(function () { state.screen = 'pick'; render(); });
  }

  // ── Render ───────────────────────────────────────────────────────────

  function render() {
    backBtnEl.innerHTML = (state.screen === 'pick' || state.screen === 'locating' || state.screen === 'result') ? '&times;' : '&#8249;';

    if (state.screen === 'locating') { titleEl.textContent = 'Add a Job'; panelEl.innerHTML = renderLocating(); }
    else if (state.screen === 'pick') { titleEl.textContent = 'Add a Job'; panelEl.innerHTML = renderPick(); focusSearch(); }
    else if (state.screen === 'branch') { titleEl.textContent = 'Select'; panelEl.innerHTML = renderBranch(); }
    else if (state.screen === 'newjob') { titleEl.textContent = 'New Job'; panelEl.innerHTML = renderJobForm(); }
    else if (state.screen === 'newclient') { titleEl.textContent = 'New Client'; panelEl.innerHTML = renderJobForm(); }
    else if (state.screen === 'result') { titleEl.textContent = 'Add a Job'; panelEl.innerHTML = renderResult(); }
  }

  function focusSearch() {
    var input = panelEl.querySelector('#mwFjSearchInput');
    if (input) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
  }

  function renderLocating() {
    return '' +
      '<div class="mw-fj-locating">' +
      '  <div class="mw-fj-radar"><span></span><span></span><span></span></div>' +
      '  <div class="mw-fj-locating-text">Finding nearby jobs…</div>' +
      '</div>';
  }

  function renderPropRow(p) {
    var sub = p.contact_name ? esc(p.contact_name) : 'No client on file';
    if (p.plan_title) sub += ' &middot; ' + esc(p.plan_title);
    var dist = (p.distance_km != null) ? '<div class="mw-fj-dist">' + p.distance_km + 'km</div>' : '';
    var pillCls = p.has_active_plan ? 'mw-fj-pill mw-fj-pill-go' : 'mw-fj-pill mw-fj-pill-muted';
    var pillText = p.has_active_plan ? 'Active job' : 'No job';
    var nearCls = (p.distance_km != null && p.distance_km <= 0.15) ? ' mw-fj-prop-near' : '';

    return '' +
      '<button type="button" class="mw-fj-prop' + nearCls + '" data-fj-action="pick-property" data-property-id="' + p.property_id + '">' +
      '  <div class="mw-fj-prop-main">' +
      '    <div class="mw-fj-prop-addr">' + esc(p.address) + '</div>' +
      '    <div class="mw-fj-prop-sub">' + sub + '</div>' +
      '  </div>' +
      dist +
      '  <div class="' + pillCls + '">' + pillText + '</div>' +
      '</button>';
  }

  function renderResultsList() {
    if (!state.results.length) {
      return '<div class="mw-fj-note">No nearby properties found. Try a different search, or add a brand-new client.</div>';
    }
    return '<div class="mw-fj-list">' + state.results.map(renderPropRow).join('') + '</div>';
  }

  function renderPick() {
    var locNote = (state.lat == null)
      ? '<div class="mw-fj-note">Location isn’t available — search by address instead.</div>'
      : '';
    return '' +
      '<div class="mw-fj-pick-top">' +
      '  <input type="text" class="mw-fj-input mw-fj-search" id="mwFjSearchInput" placeholder="Search address or client" value="' + esc(state.query) + '">' +
      '  <button type="button" class="mw-fj-primary mw-fj-newbtn" data-fj-action="new-client">+ New</button>' +
      '</div>' +
      locNote +
      '<div class="mw-fj-results" id="mwFjResults">' + renderResultsList() + '</div>';
  }

  function renderBranch() {
    var p = state.property;
    var body;
    if (p.has_active_plan) {
      body = '' +
        '<button type="button" class="mw-fj-primary" data-fj-action="add-visit">Add a visit today</button>' +
        '<div class="mw-fj-or">or</div>' +
        '<button type="button" class="mw-fj-alt mw-fj-full" data-fj-action="new-job">Start a brand-new job here</button>';
    } else {
      body = '<button type="button" class="mw-fj-primary" data-fj-action="new-job">Start a new job here</button>';
    }
    return '' +
      '<div class="mw-fj-branch-addr">' + esc(p.address) + '</div>' +
      body +
      '<button type="button" class="mw-fj-link" data-fj-action="back-to-pick">&#8249; Back to search</button>';
  }

  function renderJobForm() {
    var isNew = state.mode === 'new';
    var clientFields = isNew ? (
      '<div class="mw-fj-section-label">Client</div>' +
      '<div class="mw-fj-grid2">' +
      '  <input class="mw-fj-input" id="mwFjFirstName" placeholder="First name">' +
      '  <input class="mw-fj-input" id="mwFjLastName" placeholder="Last name">' +
      '</div>' +
      '<input class="mw-fj-input" id="mwFjPhone" placeholder="Phone" type="tel">' +
      '<input class="mw-fj-input" id="mwFjAddress" placeholder="Property address">'
    ) : '';

    var chips = SERVICE_TYPES.map(function (s) {
      var on = s === state.selectedService ? ' on' : '';
      return '<button type="button" class="mw-fj-chip' + on + '" data-fj-chip="' + esc(s) + '">' + esc(s) + '</button>';
    }).join('');

    var freqDisplay = state.recurring ? '' : ' style="display:none;"';
    var oneTimeOn = state.recurring ? '' : ' on';
    var recurringOn = state.recurring ? ' on' : '';

    return '' +
      clientFields +
      '<div class="mw-fj-section-label">Service</div>' +
      '<div class="mw-fj-chips">' + chips + '</div>' +

      '<div class="mw-fj-section-label">Schedule</div>' +
      '<div class="mw-fj-seg">' +
      '  <button type="button" class="mw-fj-seg-btn' + oneTimeOn + '" data-fj-seg="onetime">One-time</button>' +
      '  <button type="button" class="mw-fj-seg-btn' + recurringOn + '" data-fj-seg="recurring">Recurring</button>' +
      '</div>' +
      '<div class="mw-fj-freqwrap" id="mwFjFreqWrap"' + freqDisplay + '>' +
      '  <div class="mw-fj-seg mw-fj-seg-sm">' +
      '    <button type="button" class="mw-fj-seg-btn' + (state.freq === 'weekly' ? ' on' : '') + '" data-fj-freq="weekly">Weekly</button>' +
      '    <button type="button" class="mw-fj-seg-btn' + (state.freq === 'biweekly' ? ' on' : '') + '" data-fj-freq="biweekly">Biweekly</button>' +
      '    <button type="button" class="mw-fj-seg-btn' + (state.freq === 'monthly' ? ' on' : '') + '" data-fj-freq="monthly">Monthly</button>' +
      '  </div>' +
      '</div>' +

      '<div class="mw-fj-section-label">Price per visit</div>' +
      '<div class="mw-fj-pricewrap">' +
      '  <span class="mw-fj-price-cur">$</span>' +
      '  <input class="mw-fj-input mw-fj-price" id="mwFjPrice" type="number" inputmode="decimal" placeholder="0.00">' +
      '</div>' +

      '<div class="mw-fj-section-label">Notes</div>' +
      '<textarea class="mw-fj-input mw-fj-textarea" id="mwFjNotes" placeholder="Anything the office should know"></textarea>' +

      '<div class="mw-fj-hint" id="mwFjHint"></div>' +
      '<button type="button" class="mw-fj-primary mw-fj-full" data-fj-action="submit-job">Save Job</button>';
  }

  function renderResult() {
    var iconCls = state.resultOk ? 'mw-fj-result-check' : 'mw-fj-result-x';
    var icon = state.resultOk ? '&#10003;' : '&#10005;';
    return '' +
      '<div class="mw-fj-result">' +
      '  <div class="' + iconCls + '">' + icon + '</div>' +
      '  <div class="mw-fj-result-title">' + esc(state.resultMsg) + '</div>' +
      '  <button type="button" class="mw-fj-primary mw-fj-full" data-fj-action="close-result">Done</button>' +
      '</div>';
  }

  function showResult(ok, msg) {
    state.screen = 'result';
    state.resultOk = ok;
    state.resultMsg = msg;
    render();
  }

  // ── Event handling ───────────────────────────────────────────────────

  function onPanelInput(e) {
    if (e.target.id !== 'mwFjSearchInput') return;
    var value = e.target.value;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(function () {
      fetchNearby(value).then(function () {
        var results = panelEl.querySelector('#mwFjResults');
        if (results) results.innerHTML = renderResultsList();
      });
    }, 300);
  }

  function onPanelClick(e) {
    var chip = e.target.closest('[data-fj-chip]');
    if (chip) {
      state.selectedService = chip.dataset.fjChip;
      panelEl.querySelectorAll('[data-fj-chip]').forEach(function (b) { b.classList.toggle('on', b === chip); });
      return;
    }

    var seg = e.target.closest('[data-fj-seg]');
    if (seg) {
      state.recurring = seg.dataset.fjSeg === 'recurring';
      panelEl.querySelectorAll('[data-fj-seg]').forEach(function (b) { b.classList.toggle('on', b === seg); });
      var wrap = panelEl.querySelector('#mwFjFreqWrap');
      if (wrap) wrap.style.display = state.recurring ? '' : 'none';
      return;
    }

    var freq = e.target.closest('[data-fj-freq]');
    if (freq) {
      state.freq = freq.dataset.fjFreq;
      panelEl.querySelectorAll('[data-fj-freq]').forEach(function (b) { b.classList.toggle('on', b === freq); });
      return;
    }

    var btn = e.target.closest('[data-fj-action]');
    if (!btn) return;
    var action = btn.dataset.fjAction;

    if (action === 'pick-property') {
      var id = parseInt(btn.dataset.propertyId, 10);
      state.property = state.results.filter(function (p) { return p.property_id === id; })[0] || null;
      if (state.property) { state.screen = 'branch'; render(); }
    } else if (action === 'back-to-pick') {
      state.screen = 'pick'; render();
    } else if (action === 'new-client') {
      state.mode = 'new'; state.selectedService = ''; state.recurring = false; state.freq = 'weekly';
      state.screen = 'newclient'; render();
    } else if (action === 'new-job') {
      state.mode = 'existing'; state.selectedService = ''; state.recurring = false; state.freq = 'weekly';
      state.screen = 'newjob'; render();
    } else if (action === 'add-visit') {
      submitAddVisit(btn);
    } else if (action === 'submit-job') {
      submitJob(btn);
    } else if (action === 'close-result') {
      closeOverlay();
      if (state.resultOk) location.reload();
    }
  }

  // ── API calls ────────────────────────────────────────────────────────

  function submitAddVisit(btn) {
    btn.disabled = true;
    btn.textContent = 'Adding…';
    MwApi.post('/crm/api/field-job.php', {
      action: 'add_visit',
      plan_id: state.property.plan_id,
      date: todayISO(),
      client_request_id: uuid()
    }).then(function (data) {
      if (data && data.success) showResult(true, 'Visit added to today’s route!');
      else showResult(false, (data && data.error) || 'Could not add the visit.');
    }).catch(function (err) {
      showResult(false, (err && err.serverError) || 'Network error — please try again.');
    });
  }

  function submitJob(btn) {
    var hint = panelEl.querySelector('#mwFjHint');
    if (hint) hint.textContent = '';

    if (!state.selectedService) {
      if (hint) hint.textContent = 'Pick a service type.';
      return;
    }

    var payload = {
      action: state.mode === 'existing' ? 'create_job' : 'create_client_job',
      service_type: state.selectedService,
      notes: (panelEl.querySelector('#mwFjNotes') || {}).value || '',
      recurring: state.recurring ? 1 : 0,
      frequency: state.freq,
      price: (panelEl.querySelector('#mwFjPrice') || {}).value || '',
      date: todayISO(),
      client_request_id: uuid()
    };

    if (state.mode === 'existing') {
      payload.property_id = state.property.property_id;
    } else {
      var firstName = ((panelEl.querySelector('#mwFjFirstName') || {}).value || '').trim();
      var address = ((panelEl.querySelector('#mwFjAddress') || {}).value || '').trim();
      if (!firstName || !address) {
        if (hint) hint.textContent = 'Name and address are required.';
        return;
      }
      payload.first_name = firstName;
      payload.last_name = (panelEl.querySelector('#mwFjLastName') || {}).value || '';
      payload.phone = (panelEl.querySelector('#mwFjPhone') || {}).value || '';
      payload.property_address = address;
      payload.lat = state.lat;
      payload.lng = state.lng;
    }

    btn.disabled = true;
    btn.textContent = 'Saving…';

    MwApi.post('/crm/api/field-job.php', payload)
      .then(function (data) {
        if (data && data.success) showResult(true, 'Job created!');
        else showResult(false, (data && data.error) || 'Could not save the job.');
      })
      .catch(function (err) {
        showResult(false, (err && err.serverError) || 'Network error — please try again.');
      });
  }

  // ── Bootstrap ────────────────────────────────────────────────────────
  window.MwFieldJob = { open: open };
})();
