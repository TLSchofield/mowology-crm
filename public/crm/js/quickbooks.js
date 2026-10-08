/**
 * Settings → QuickBooks page (/crm/accounting/quickbooks.php), 2026-10-07.
 * Reads /crm/api/quickbooks.php. Connect goes through /crm/api/quickbooks/oauth-start.php.
 * Nothing on this page writes to QuickBooks; the push flag lives in the database only.
 */
(function () {
    'use strict';
    var API = '/crm/api/quickbooks.php';
    var status = null, report = null, accounts = null;

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) {
        if (v === null || v === undefined) return '—';
        var n = Number(v);
        return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function num(v) { return Number(v || 0).toLocaleString('en-CA'); }
    function say(msg, kind) {
        var el = $('qbo-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        el.scrollIntoView({ block: 'nearest' });
    }
    function get(mode, extra) {
        return fetch(API + '?mode=' + mode + (extra || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    // ── Connection card ─────────────────────────────────────────────────────
    function renderConnection() {
        var c = status.connection;
        $('qbo-env').textContent = c.environment === 'production' ? 'Production' : 'Sandbox';
        $('qbo-env').className = 'badge mw-qbo-env ' + (c.environment === 'production' ? 'mw-qbo-env-prod' : 'mw-qbo-env-sandbox');
        var h = '';
        if (!c.configured) {
            h += '<div class="mw-qbo-state mw-qbo-state-off">Not set up yet</div>';
            h += '<p class="small mb-2">Add these to <code>secrets.php</code> (values from the Intuit developer app — see the step-by-step in <code>docs/crm/quickbooks.md</code>): <code>' + c.missing.map(esc).join('</code>, <code>') + '</code>.</p>';
            h += '<p class="small text-muted mb-0">The redirect URI to register at Intuit: <code>' + esc(status.redirect_uri || 'https://mowology.ca/crm/api/quickbooks/oauth-callback.php') + '</code></p>';
        } else if (c.connected) {
            h += '<div class="mw-qbo-state mw-qbo-state-on">Connected</div>';
            h += '<dl class="mw-qbo-facts">';
            h += '<dt>Company</dt><dd>' + esc(c.company_name || '(read the file to see the name)') + '</dd>';
            h += '<dt>Company id (realm)</dt><dd><code>' + esc(c.realm_id) + '</code></dd>';
            if (c.country || c.home_currency) h += '<dt>Country / currency</dt><dd>' + esc(c.country || '?') + ' / ' + esc(c.home_currency || '?') + '</dd>';
            if (c.fiscal_year_start) h += '<dt>Fiscal year starts</dt><dd>' + esc(c.fiscal_year_start) + '</dd>';
            h += '<dt>Books closed through</dt><dd>' + (c.book_close_date ? esc(c.book_close_date) : '<span class="text-muted">not set</span>') + '</dd>';
            h += '<dt>Connected</dt><dd>' + esc(c.connected_at || '') + '</dd>';
            h += '<dt>Token refreshed</dt><dd>' + esc(c.last_refresh_at || '') + (c.refresh_days_left !== null ? ' <span class="text-muted">(' + c.refresh_days_left + ' days before it lapses unused)</span>' : '') + '</dd>';
            h += '</dl>';
            if (c.credential_problem) h += '<div class="alert alert-danger small mb-2">' + esc(c.credential_problem) + '</div>';
            h += '<div class="d-flex gap-2 flex-wrap">';
            h += '<a class="btn btn-sm btn-outline-primary" href="/crm/api/quickbooks/oauth-start.php?csrf_token=' + encodeURIComponent(window.MW_CSRF_TOKEN || '') + '">Reconnect</a>';
            h += '<button type="button" class="btn btn-sm btn-outline-danger" id="qbo-disconnect">Disconnect</button>';
            h += '</div>';
        } else {
            h += '<div class="mw-qbo-state mw-qbo-state-off">' + (c.status === 'error' ? 'Connection needs attention' : (c.status === 'disconnected' ? 'Disconnected' : 'Not connected')) + '</div>';
            if (c.last_error) h += '<div class="alert alert-warning small">' + esc(c.last_error) + '</div>';
            h += '<p class="small text-muted">You will be sent to QuickBooks to sign in and allow Mowology CRM. Pick the right company if you have more than one.</p>';
            h += '<a class="btn btn-primary" href="/crm/api/quickbooks/oauth-start.php?csrf_token=' + encodeURIComponent(window.MW_CSRF_TOKEN || '') + '">Connect to QuickBooks</a>';
        }
        $('qbo-connection').innerHTML = h;
        $('qbo-refresh').disabled = !c.connected;
        $('qbo-preview-btn').disabled = !c.connected;
        $('qbo-discovery-at').textContent = status.discovery_at ? 'read ' + status.discovery_at : '';
        $('qbo-push-state').textContent = status.push_enabled ? (status.dry_run ? 'on (dry run)' : 'ON — LIVE') : 'off';
        var dis = $('qbo-disconnect');
        if (dis) dis.addEventListener('click', function () {
            if (!confirm('Disconnect QuickBooks? The CRM forgets its tokens and tells Intuit to revoke them. Nothing in QuickBooks changes.')) return;
            post({ mode: 'disconnect' }).then(function (r) {
                if (!r.ok) { say(r.error || 'Could not disconnect', 'danger'); return; }
                say(r.revoked ? 'Disconnected and revoked at Intuit.' : 'Disconnected (Intuit did not confirm the revoke — you can also remove the app under QuickBooks → Apps).', 'success');
                load();
            });
        });
    }

    // ── Discovery report ────────────────────────────────────────────────────
    function renderDiscovery() {
        var box = $('qbo-discovery');
        if (!report) {
            box.innerHTML = '<div class="text-muted small">' + (status.connection.connected ? 'Not read yet — press “Read the file”.' : 'Connect first, then read the file.') + '</div>';
            return;
        }
        var c = report.company, h = '';
        if (report.warnings && report.warnings.length) {
            h += '<div class="mb-3">';
            report.warnings.forEach(function (w) { h += '<div class="alert alert-' + esc(w.level) + ' small py-2 mb-2">' + esc(w.text) + '</div>'; });
            h += '</div>';
        }
        h += '<dl class="mw-qbo-facts mw-qbo-facts-2">';
        h += '<dt>Company</dt><dd>' + esc(c.name || '—') + (c.legal_name && c.legal_name !== c.name ? ' <span class="text-muted">(' + esc(c.legal_name) + ')</span>' : '') + '</dd>';
        h += '<dt>Country</dt><dd>' + esc(c.country || '—') + '</dd>';
        h += '<dt>Plan</dt><dd>' + esc(c.sku || '—') + (c.subscription ? ' · ' + esc(c.subscription) : '') + '</dd>';
        h += '<dt>Fiscal year starts</dt><dd>' + esc(c.fiscal_year_start || '—') + '</dd>';
        h += '<dt>Home currency</dt><dd>' + esc(c.home_currency || '—') + (c.multicurrency ? ' · multicurrency ON' : '') + '</dd>';
        h += '<dt>Sales tax</dt><dd>' + (c.sales_tax_enabled ? 'on' : '<strong class="text-danger">off</strong>') + '</dd>';
        h += '<dt>Books closed through</dt><dd>' + esc(c.book_close_date || 'not set') + '</dd>';
        h += '<dt>Account numbers</dt><dd>' + (c.use_account_numbers ? 'on' : 'off') + '</dd>';
        h += '<dt>Report basis</dt><dd>' + esc(c.report_basis || '—') + '</dd>';
        h += '<dt>File created</dt><dd>' + esc(c.company_start_date || '—') + '</dd>';
        h += '<dt>Transactions</dt><dd>' + num(report.txn_total) + (report.first_txn ? ' · ' + esc(report.first_txn) + ' → ' + esc(report.last_txn) : '') + '</dd>';
        h += '</dl>';

        // counts by year
        var years = report.years || [], ents = Object.keys(report.counts || {});
        h += '<h6 class="mt-3 mb-2">Transactions per year</h6><div class="table-responsive"><table class="table table-sm mw-qbo-table mb-2"><thead><tr><th></th><th class="mw-qbo-num">before</th>';
        years.forEach(function (y) { h += '<th class="mw-qbo-num">' + esc(y) + '</th>'; });
        h += '<th>last</th></tr></thead><tbody>';
        ents.forEach(function (e) {
            var row = report.counts[e];
            if (row.total !== undefined) return;
            h += '<tr><td>' + esc(e) + '</td><td class="mw-qbo-num">' + num(row.before) + '</td>';
            years.forEach(function (y) { h += '<td class="mw-qbo-num' + (Number(row[y]) > 0 ? ' mw-qbo-has' : '') + '">' + num(row[y]) + '</td>'; });
            h += '<td class="small text-muted">' + esc((report.last || {})[e] || '') + '</td></tr>';
        });
        h += '<tr class="mw-qbo-strong"><td>all</td><td class="mw-qbo-num"></td>';
        years.forEach(function (y) { h += '<td class="mw-qbo-num">' + num((report.per_year || {})[y]) + '</td>'; });
        h += '<td></td></tr></tbody></table></div>';
        var lists = ents.filter(function (e) { return report.counts[e].total !== undefined; });
        if (lists.length) h += '<div class="small text-muted mb-3">' + lists.map(function (e) { return esc(e) + 's: ' + num(report.counts[e].total); }).join(' · ') + '</div>';

        // tax codes
        h += '<h6 class="mt-2 mb-2">Tax codes</h6>';
        if (!report.tax_codes || !report.tax_codes.length) h += '<div class="small text-muted mb-3">None.</div>';
        else {
            h += '<div class="table-responsive"><table class="table table-sm mw-qbo-table mb-3"><thead><tr><th>Code</th><th>On sales</th><th>On purchases</th><th></th></tr></thead><tbody>';
            report.tax_codes.forEach(function (t) {
                h += '<tr' + (t.active ? '' : ' class="text-muted"') + '><td>' + esc(t.name) + ' <span class="text-muted small">#' + esc(t.id) + '</span></td><td class="small">' + esc(t.sales.join(' + ') || '—') + '</td><td class="small">' + esc(t.purchase.join(' + ') || '—') + '</td><td class="small text-muted">' + (t.active ? '' : 'inactive') + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }

        // chart of accounts
        var byClass = report.accounts_by_class || {};
        h += '<details class="mw-qbo-details"><summary class="h6 mb-2">Chart of accounts — ' + num(report.accounts.length) + ' accounts (' + Object.keys(byClass).map(function (k) { return esc(k) + ' ' + byClass[k]; }).join(', ') + ')</summary>';
        h += '<div class="table-responsive mw-qbo-scroll"><table class="table table-sm mw-qbo-table mb-0"><thead><tr><th>#</th><th>Name</th><th>Type</th><th class="mw-qbo-num">Balance</th></tr></thead><tbody>';
        report.accounts.forEach(function (a) {
            h += '<tr' + (a.active ? '' : ' class="text-muted"') + '><td>' + esc(a.num || '') + '</td><td>' + esc(a.full_name || a.name) + '</td><td class="small">' + esc(a.classification || '') + ' · ' + esc(a.sub_type || a.type || '') + '</td><td class="mw-qbo-num">' + (a.balance === null ? '' : money(a.balance)) + '</td></tr>';
        });
        h += '</tbody></table></div></details>';
        box.innerHTML = h;
    }

    // ── Account map ─────────────────────────────────────────────────────────
    function renderMap() {
        var box = $('qbo-map');
        if (!accounts || !accounts.has_report) {
            box.innerHTML = '<div class="p-3 text-muted small">Needs the file read first.</div>';
            $('qbo-accept-all').disabled = true;
            return;
        }
        var qboByClass = {};
        accounts.qbo.forEach(function (a) { if (a.active === false) return; (qboByClass[a.classification || 'Other'] = qboByClass[a.classification || 'Other'] || []).push(a); });
        var options = '';
        Object.keys(qboByClass).sort().forEach(function (k) {
            options += '<optgroup label="' + esc(k) + '">';
            qboByClass[k].forEach(function (a) { options += '<option value="' + esc(a.id) + '">' + esc((a.num ? a.num + ' ' : '') + (a.full_name || a.name)) + '</option>'; });
            options += '</optgroup>';
        });
        var h = '<div class="table-responsive"><table class="table table-sm table-hover mw-qbo-table mb-0"><thead><tr><th>CRM account</th><th>QuickBooks account</th><th>Match</th><th></th></tr></thead><tbody>';
        var strong = 0, done = 0;
        accounts.crm.forEach(function (c) {
            var s = accounts.suggestions[c.id] || { best: null, candidates: [] };
            var conf = accounts.confirmed[c.id];
            var chosen = conf ? (conf.qbo_account_id || '') : (s.best && s.best.score >= accounts.auto_min ? s.best.qbo_id : '');
            if (conf) done++; else if (s.best && s.best.score >= accounts.auto_min) strong++;
            h += '<tr data-crm="' + c.id + '" class="' + (conf ? 'mw-qbo-confirmed' : '') + '">';
            h += '<td><code>' + esc(c.code) + '</code> ' + esc(c.name) + ' <span class="text-muted small">' + esc(c.type) + '</span></td>';
            h += '<td><select class="form-control form-control-sm mw-qbo-select" data-crm="' + c.id + '">';
            h += '<option value="">— no twin / do not push —</option>';
            if (s.candidates.length) {
                h += '<optgroup label="Suggested">';
                s.candidates.forEach(function (k) { h += '<option value="' + esc(k.qbo_id) + '" data-score="' + k.score + '" data-on="' + esc(k.matched_on) + '">' + esc((k.num ? k.num + ' ' : '') + k.name) + ' (' + k.score + (k.matched_on === 'number' ? ', number' : (k.matched_on === 'name?' ? ', other type!' : '')) + ')</option>'; });
                h += '</optgroup>';
            }
            h += options + '</select></td>';
            h += '<td class="small">' + (conf ? '<span class="mw-qbo-ok">confirmed</span>' + (conf.matched_on ? ' · ' + esc(conf.matched_on) : '') : (s.best ? (s.best.score >= accounts.auto_min ? '<span class="mw-qbo-ok">strong</span>' : 'weak') + ' ' + s.best.score : '<span class="text-muted">none</span>')) + '</td>';
            h += '<td class="text-right"><button type="button" class="btn btn-xs btn-outline-primary mw-qbo-confirm" data-crm="' + c.id + '">' + (conf ? 'Change' : 'Confirm') + '</button>' + (conf ? ' <button type="button" class="btn btn-xs btn-outline-secondary mw-qbo-clear" data-crm="' + c.id + '">Clear</button>' : '') + '</td>';
            h += '</tr>';
            setTimeout(function () { var sel = box.querySelector('select[data-crm="' + c.id + '"]'); if (sel) sel.value = chosen; }, 0);
        });
        h += '</tbody></table></div>';
        h += '<div class="p-2 small text-muted">' + done + ' of ' + accounts.crm.length + ' confirmed · ' + strong + ' strong suggestions waiting. A number match (CRM code = QuickBooks account number) scores 100; names alone are a guess — look before you confirm.</div>';
        box.innerHTML = h;
        $('qbo-accept-all').disabled = strong === 0;
        box.querySelectorAll('.mw-qbo-confirm').forEach(function (b) {
            b.addEventListener('click', function () {
                var crm = Number(b.getAttribute('data-crm'));
                var sel = box.querySelector('select[data-crm="' + crm + '"]');
                var opt = sel.options[sel.selectedIndex];
                post({ mode: 'map_confirm', crm_account_id: crm, qbo_account_id: sel.value || null, confidence: Number(opt.getAttribute('data-score') || 0), matched_on: opt.getAttribute('data-on') || 'manual' })
                    .then(function (r) { if (!r.ok) { say(r.error || 'Could not save', 'danger'); return; } loadAccounts(); });
            });
        });
        box.querySelectorAll('.mw-qbo-clear').forEach(function (b) {
            b.addEventListener('click', function () {
                post({ mode: 'map_clear', crm_account_id: Number(b.getAttribute('data-crm')) }).then(function () { loadAccounts(); });
            });
        });
    }

    // ── Push choices + dry run ──────────────────────────────────────────────
    function renderChoices() {
        var box = $('qbo-choices');
        if (!report) { box.innerHTML = '<div class="small text-muted">Read the file first — the tax codes and accounts to choose from come from it.</div>'; return; }
        var ch = status.choices || {};
        function sel(id, list, cur, label, blank) {
            var h = '<div class="col-md-4 mb-2"><label class="small mb-1">' + esc(label) + '</label><select class="form-control form-control-sm" id="' + id + '"><option value="">' + esc(blank || '— choose —') + '</option>';
            list.forEach(function (o) { h += '<option value="' + esc(o.id) + '"' + (String(cur || '') === String(o.id) ? ' selected' : '') + '>' + esc(o.label) + '</option>'; });
            return h + '</select></div>';
        }
        var taxes = (report.tax_codes || []).filter(function (t) { return t.active; }).map(function (t) { return { id: t.id, label: t.name + (t.purchase.length ? ' (' + t.purchase.join(' + ') + ')' : '') }; });
        var banks = (report.accounts || []).filter(function (a) { return a.active && (a.type === 'Bank' || a.type === 'Credit Card'); }).map(function (a) { return { id: a.id, label: (a.num ? a.num + ' ' : '') + (a.full_name || a.name) + ' · ' + a.type }; });
        var items = (report.items || []).map(function (i) { return { id: i.id, label: i.name + (i.income_account ? ' → ' + i.income_account : '') }; });
        var pf = ch.paid_from || {};
        var h = '<div class="row">';
        h += sel('qbo-c-gst', taxes, ch.tax_gst, 'Tax code: GST only (5 %)');
        h += sel('qbo-c-gstpst', taxes, ch.tax_gst_pst, 'Tax code: GST + PST BC (12 %)');
        h += sel('qbo-c-exempt', taxes, ch.tax_exempt, 'Tax code: exempt / zero-rated / out of scope');
        h += sel('qbo-c-bank', banks, pf.bank, 'Purchases paid by debit / e-transfer / cash come from');
        h += sel('qbo-c-cc', banks, pf.credit_card, 'Purchases paid by credit card come from');
        h += sel('qbo-c-cheque', banks, pf.cheque, 'Purchases paid by cheque come from', '— same as bank —');
        h += sel('qbo-c-item', items, ch.item, 'Service item on invoice lines');
        h += '<div class="col-md-4 mb-2"><label class="small mb-1">Dry run</label><select class="form-control form-control-sm" id="qbo-c-dry"><option value="1"' + (status.dry_run ? ' selected' : '') + '>on — build, never write</option><option value="0"' + (!status.dry_run ? ' selected' : '') + '>off — write when the push flag is on</option></select></div>';
        h += '</div><button type="button" class="btn btn-sm btn-outline-primary" id="qbo-save-choices">Save choices</button>';
        box.innerHTML = h;
        $('qbo-save-choices').addEventListener('click', function () {
            post({ mode: 'choices', tax_gst: $('qbo-c-gst').value, tax_gst_pst: $('qbo-c-gstpst').value, tax_exempt: $('qbo-c-exempt').value, item: $('qbo-c-item').value,
                   paid_from: { bank: $('qbo-c-bank').value, credit_card: $('qbo-c-cc').value, cheque: $('qbo-c-cheque').value }, dry_run: $('qbo-c-dry').value === '1' })
                .then(function (r) { if (!r.ok) { say(r.error || 'Could not save', 'danger'); return; } say('Choices saved.', 'success'); load(); });
        });
    }

    function renderPreview(p) {
        var box = $('qbo-preview'), h = '';
        if (p.missing && p.missing.length) h += '<div class="alert alert-warning small py-2">Still to choose: ' + esc(p.missing.join(', ')) + '.</div>';
        h += '<div class="small text-muted mb-2">Push flag: <strong>' + (p.enabled ? 'on' : 'off') + '</strong> · dry run: <strong>' + (p.dry_run ? 'on' : 'off') + '</strong> · pushes from ' + esc(p.push_from) + ' · books closed through ' + esc(p.book_close_date || 'not set') + ' · ' + p.tally.create + ' would be created, ' + p.tally.update + ' updated, ' + p.tally.skip + ' unchanged, ' + p.tally.blocked + ' held back.</div>';
        h += '<div class="table-responsive"><table class="table table-sm mw-qbo-table"><thead><tr><th>Expense</th><th>Date</th><th>Vendor</th><th class="mw-qbo-num">Total</th><th>Would become</th><th>Why not / notes</th></tr></thead><tbody>';
        (p.rows || []).forEach(function (r) {
            h += '<tr><td>#' + r.crm_id + (r.receipt ? ' <span class="text-muted small">+receipt</span>' : '') + '</td><td>' + esc(r.date) + '</td><td>' + esc(r.vendor || '') + '</td><td class="mw-qbo-num">' + money(r.total) + '</td>';
            h += '<td><span class="mw-qbo-action mw-qbo-action-' + esc(r.action) + '">' + esc(r.action) + '</span> ' + esc(r.qbo_type) + (r.qbo_id ? ' <code>' + esc(r.qbo_id) + '</code>' : '');
            if (r.payload) h += ' <details class="mw-qbo-details d-inline"><summary class="small">payload</summary><pre class="mw-qbo-pre">' + esc(JSON.stringify(r.payload, null, 1)) + '</pre></details>';
            h += '</td><td class="small">' + (r.reason ? '<span class="mw-qbo-off">' + esc(r.reason) + '</span>' : '') + (r.warnings && r.warnings.length ? '<div class="text-muted">' + r.warnings.map(esc).join('<br>') + '</div>' : '') + '</td></tr>';
        });
        if (!p.rows || !p.rows.length) h += '<tr><td colspan="6" class="text-muted">No approved expenses dated ' + esc(p.push_from) + ' or later.</td></tr>';
        h += '</tbody></table></div>';
        box.innerHTML = h;
    }

    // ── loading ─────────────────────────────────────────────────────────────
    function loadAccounts() {
        return get('accounts').then(function (r) { if (r.ok) { accounts = r; renderMap(); } });
    }
    function load() {
        return get('status').then(function (r) {
            if (!r.ok) { say(r.error || 'Could not load', 'danger'); return; }
            status = r;
            renderConnection();
            return get('discovery');
        }).then(function (r) {
            if (r && r.ok) { report = r.report; }
            renderDiscovery();
            renderChoices();
            return loadAccounts();
        }).then(function () {
            if (window.MW_QBO && window.MW_QBO.justConnected && status.connection.connected && !report) {
                window.MW_QBO.justConnected = false;
                say('Connected. Reading the company file now…', 'success');
                refreshDiscovery();
            }
        });
    }
    function refreshDiscovery() {
        var b = $('qbo-refresh');
        b.disabled = true; b.textContent = 'Reading…';
        post({ mode: 'refresh_discovery' }).then(function (r) {
            b.disabled = false; b.textContent = 'Read the file';
            if (!r.ok) { say(r.error || 'Could not read the file', 'danger'); return; }
            report = r.report;
            say('Read ' + num(report.accounts.length) + ' accounts, ' + num(report.tax_codes.length) + ' tax codes, ' + num(report.txn_total) + ' transactions.', 'success');
            load();
        }).catch(function () { b.disabled = false; b.textContent = 'Read the file'; say('The read failed — see the PHP log.', 'danger'); });
    }

    $('qbo-refresh').addEventListener('click', refreshDiscovery);
    $('qbo-accept-all').addEventListener('click', function () {
        post({ mode: 'map_accept_all' }).then(function (r) { if (!r.ok) { say(r.error || 'Could not confirm', 'danger'); return; } say('Confirmed ' + r.confirmed + ' strong matches.', 'success'); loadAccounts(); });
    });
    $('qbo-preview-btn').addEventListener('click', function () {
        var b = $('qbo-preview-btn'); b.disabled = true;
        get('preview', '&limit=25').then(function (r) { b.disabled = false; if (!r.ok) { say(r.error || 'Could not build the dry run', 'danger'); return; } renderPreview(r); });
    });
    load();
})();
