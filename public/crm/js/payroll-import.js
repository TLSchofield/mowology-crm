/**
 * Payroll import page (/crm/accounting/payroll-import.php), 2026-10-07.
 * Reads /crm/api/payroll-import.php; books a month only when Tim approves it (the server rebuilds
 * the bank matches and moves only lines it proposed — the browser sends the ticked ids).
 */
(function () {
    'use strict';
    var API = '/crm/api/payroll-import.php';
    var ROLE = {
        remittance: 'CRA remittance (Wave)', net_pay: 'Net pay', shareholder: 'Shareholder transfer',
        fee: 'Wave fee', repayment: 'Repayment', personal: 'Personal?', waiting: 'Waiting'
    };
    var data = null;

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) {
        if (v === null || v === undefined) return '—';
        var n = Number(v);
        return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function cls(v) { return Math.abs(Number(v || 0)) < 0.01 ? 'mw-pi-ok' : 'mw-pi-off'; }
    function ymLabel(ym) { var p = ym.split('-'); return new Date(Number(p[0]), Number(p[1]) - 1, 1).toLocaleString('en-CA', { month: 'long', year: 'numeric' }); }
    function say(msg, kind) {
        var el = $('pi-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        el.scrollIntoView({ block: 'nearest' });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function done(d) {
        if (!d) { say('No answer from the server.', 'danger'); return; }
        load(d.message || d.error || '', d.ok ? 'success' : 'warning');
    }

    function load(msg, kind) {
        return fetch(API + '?mode=report', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'Could not read payroll.', 'danger'); return; }
                data = d;
                render();
                say(msg || '', kind);
            })
            .catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() {
        $('pi-problems').innerHTML = (data.account_problems || []).length
            ? '<div class="alert alert-warning">' + data.account_problems.map(esc).join('<br>') + '</div>' : '';
        var runs = data.runs || [];
        $('pi-months').innerHTML = runs.length ? runs.map(month).join('') : '<div class="text-muted small mb-3">No payroll imported yet — add September\'s report above.</div>';
        renderShareholder();
        runs.forEach(function (r) { if (r.status === 'preview') updateClearing(r.id); });
    }

    function totalsTable(t) {
        var rows = [
            ['Gross wages', t.wages], ['Employee withholdings', t.withholdings],
            ['  federal tax', t.fed], ['  provincial tax', t.prov], ['  EI', t.ei], ['  CPP', t.cpp], ['  CPP2', t.cpp2],
            ['Net pay (wages − withholdings)', t.net], ['Employer EI + CPP', t.employer], ['Remittance due to CRA', t.remittance]
        ];
        return '<table class="table table-sm mb-0 mw-pi-table"><tbody>' + rows.map(function (r) {
            var strong = /^(Net pay|Remittance)/.test(r[0]);
            return '<tr' + (strong ? ' class="mw-pi-strong"' : '') + '><td>' + esc(r[0]).replace(/^ {2}/, '&nbsp;&nbsp;&nbsp;') + '</td><td class="mw-pi-num">' + money(r[1]) + '</td></tr>';
        }).join('') + '</tbody></table>';
    }

    function entryTable(e) {
        return '<table class="table table-sm mb-0 mw-pi-table"><thead><tr><th>Account</th><th class="mw-pi-num">Debit</th><th class="mw-pi-num">Credit</th></tr></thead><tbody>'
            + e.lines.map(function (l) {
                var n = (data.accounts || {})[l.account];
                return '<tr><td>' + esc(l.account) + ' ' + esc(n || '') + '<div class="small text-muted">' + esc(l.description) + '</div></td><td class="mw-pi-num">'
                    + (l.debit ? money(l.debit) : '') + '</td><td class="mw-pi-num">' + (l.credit ? money(l.credit) : '') + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    function movesTable(moves, runId, editable) {
        if (!moves.length) return '<div class="p-2 small text-muted">No bank lines found for this month.</div>';
        return '<div class="mw-pi-scroll"><table class="table table-sm mb-0 mw-pi-table"><thead><tr>' + (editable ? '<th></th>' : '')
            + '<th>Date</th><th>Bank line</th><th>What</th><th>Move</th><th class="mw-pi-num">Amount</th></tr></thead><tbody>'
            + moves.map(function (m) {
                return '<tr>' + (editable ? '<td><input type="checkbox" class="pi-tick" data-run="' + runId + '" value="' + m.id + '"' + (m.checked ? ' checked' : '') + ' aria-label="Move this line"></td>' : '')
                    + '<td class="text-nowrap">' + esc(m.date) + '</td><td class="mw-pi-desc">' + esc(m.description) + '</td>'
                    + '<td>' + esc(ROLE[m.role] || m.role) + (m.employee ? '<div class="small text-muted">' + esc(m.employee) + '</div>' : '') + '</td>'
                    + '<td class="text-nowrap small">' + (m.already ? 'already on ' + esc(m.to) : esc(m.from || '?') + ' → ' + esc(m.to || '')) + '</td>'
                    + '<td class="mw-pi-num">' + money(m.amount) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function matchSummary(p) {
        var out = [];
        var r = p.remittance;
        out.push('<li>CRA remittance ' + money(r.target) + ': ' + (r.status === 'match'
            ? '<span class="mw-pi-ok">Wave debits add up exactly</span>'
            : '<span class="mw-pi-off">' + (r.status === 'none' ? 'no Wave debits found' : 'Wave debits this month come to ' + money(r.found) + ' (' + money(r.diff) + ' apart) — not ticked') + '</span>') + '</li>');
        (p.employees || []).forEach(function (e) {
            var s = e.status === 'match' ? '<span class="mw-pi-ok">e-Transfers add up</span>'
                : e.status === 'close' ? '<span class="mw-pi-off">close (' + money(e.diff) + ' apart) — check, not ticked</span>'
                : e.status === 'differs' ? '<span class="mw-pi-off">' + e.candidates + ' e-Transfer' + (e.candidates === 1 ? '' : 's') + ' found, none add up — left for you</span>'
                : '<span class="mw-pi-off">no e-Transfer found</span>';
            out.push('<li>' + esc(e.name) + ' net ' + money(e.target) + ': ' + s + '</li>');
        });
        if (p.shareholder) {
            var sh = p.shareholder;
            out.push('<li>' + esc(sh.name) + ' (shareholder) net ' + money(sh.net) + ': transfers to himself ' + money(sh.transfers)
                + (sh.ids.length ? ' → ' + money(Math.min(sh.transfers, sh.net)) + ' is pay, ' + money(sh.sweep) + ' to Due from Shareholder' : ' — none found, his net stays in clearing') + '</li>');
        }
        return '<ul class="small mb-2">' + out.join('') + '</ul>';
    }

    function hoursTable(h) {
        return h.map(function (e) {
            return '<div class="mb-2"><strong class="small">' + esc(e.name) + '</strong>' + (e.user_id ? '' : ' <span class="small text-muted">— no CRM user with this name, nothing to compare</span>')
                + '<table class="table table-sm mb-0 mw-pi-table"><thead><tr><th>Payday</th><th>Pay period</th><th class="mw-pi-num">Wave hours</th><th class="mw-pi-num">Time clock</th><th class="mw-pi-num">Difference</th></tr></thead><tbody>'
                + e.rows.map(function (r) {
                    return '<tr><td>' + esc(r.payday) + '</td><td>' + esc(r.period) + '</td><td class="mw-pi-num">' + r.wave.toFixed(2) + '</td><td class="mw-pi-num">'
                        + (r.crm === null ? '—' : r.crm.toFixed(2)) + '</td><td class="mw-pi-num ' + (r.diff === null ? '' : (Math.abs(r.diff) < 1 ? 'mw-pi-ok' : 'mw-pi-off')) + '">'
                        + (r.diff === null ? '' : r.diff.toFixed(2)) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }).join('');
    }

    function month(r) {
        var p = r.proposal;
        var booked = r.status === 'booked';
        var badge = booked ? '<span class="mw-pi-badge">Booked</span>' : (r.estimate ? '<span class="mw-pi-badge is-off">Estimate — split by hours</span>' : '<span class="mw-pi-badge">Ready to book</span>');
        var h = '<div class="card mw-card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><h5 class="card-title mb-0">'
            + esc(ymLabel(r.month)) + ' ' + badge + '</h5><span class="small text-muted">' + r.employees.length + ' employees · last payday ' + esc(r.last_payday)
            + (r.source_name ? ' · ' + esc(r.source_name) : '') + '</span></div><div class="card-body">';
        if (r.warnings && r.warnings.length) h += '<div class="alert alert-warning small">' + r.warnings.map(esc).join('<br>') + '</div>';
        if (r.estimate && !booked) h += '<div class="alert alert-warning small">This report covers several months and has no dollars per payday, so this month is each employee\'s total split by their paid hours. Download ' + esc(ymLabel(r.month)) + ' on its own for exact figures.</div>';
        h += '<div class="row g-3"><div class="col-lg-4"><h6 class="small text-muted text-uppercase">The month</h6>' + totalsTable(r.totals) + '</div>'
            + '<div class="col-lg-8"><h6 class="small text-muted text-uppercase">Journal entry' + (booked ? ' (posted)' : ' Penny will post') + '</h6>' + entryTable(r.entry) + '</div></div>';

        h += '<h6 class="small text-muted text-uppercase mt-3">Bank lines that paid it</h6>';
        if (booked) {
            h += movesTable((r.booked_moves || []).map(function (m) { return { id: m.id, date: (m.date || '').slice(0, 10), description: m.description, role: m.role, employee: m.employee, amount: Number(m.amount), from: '', to: 'moved', already: false }; }), r.id, false);
            var c = r.clearing_ledger || {};
            h += '<p class="small mt-2 mb-0">This month on the books: 2310 Source deductions <span class="' + cls(c.source_deductions) + '">' + money(c.source_deductions)
                + '</span> · 2520 Net pay clearing <span class="' + cls(c.net_pay) + '">' + money(c.net_pay) + '</span> (credit balance moved this month; ~$0 is right).</p>';
        } else if (p) {
            h += matchSummary(p) + movesTable(p.moves, r.id, !r.bookable);
            h += '<p class="small mt-2 mb-0" id="pi-clear-' + r.id + '"></p>';
        }
        h += '<details class="mt-3 mw-pi-details"><summary class="small">Hours — Wave against the CRM time clock (nothing changes)</summary><div class="mt-2">' + hoursTable(r.hours || []) + '</div></details>';

        h += '<div class="mw-pi-actions mt-3">';
        if (booked) {
            h += '<button type="button" class="btn btn-sm btn-outline-secondary" data-undo="' + r.id + '">Undo this month</button>';
        } else if (r.bookable) {
            h += '<span class="small text-muted">' + esc(r.bookable) + '</span><button type="button" class="btn btn-sm btn-outline-secondary" data-discard="' + r.id + '">Remove</button>';
        } else {
            if (r.estimate) h += '<label class="small mb-0"><input type="checkbox" id="pi-est-' + r.id + '"> Book the estimate anyway</label>';
            h += '<button type="button" class="btn btn-sm btn-primary" data-approve="' + r.id + '"' + ((data.account_problems || []).length ? ' disabled' : '') + '>Approve — book ' + esc(ymLabel(r.month)) + '</button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-discard="' + r.id + '">Remove</button>';
        }
        return h + '</div></div></div>';
    }

    /** Live: what 2310 / 2520 hold for the month with the current ticks. */
    function updateClearing(runId) {
        var r = (data.runs || []).filter(function (x) { return x.id === runId; })[0];
        var el = $('pi-clear-' + runId);
        if (!r || !r.proposal || !el) return;
        var ticked = ticks(runId);
        var remit = 0, net = 0, sh = 0;
        r.proposal.moves.forEach(function (m) {
            if (ticked.indexOf(m.id) < 0) return;
            if (m.role === 'remittance') remit += m.amount;
            if (m.role === 'net_pay') net += m.amount;
            if (m.role === 'shareholder') sh += m.amount;
        });
        var sweep = r.proposal.shareholder && sh > 0 ? sh - r.proposal.shareholder.net : 0;
        var sd = r.totals.remittance - remit;
        var np = r.totals.net - net - sh + sweep;
        el.innerHTML = 'After booking: 2310 Source deductions <span class="' + cls(sd) + '">' + money(sd) + '</span> · 2520 Net pay clearing <span class="' + cls(np) + '">'
            + money(np) + '</span>' + (sweep ? ' · ' + money(sweep) + ' to 1300 Due from Shareholder' : '') + ' (what\'s left owing for the month; ~$0 is right — anything else is for you to look at).';
    }

    function ticks(runId) {
        return Array.prototype.slice.call(document.querySelectorAll('.pi-tick[data-run="' + runId + '"]'))
            .filter(function (c) { return c.checked; }).map(function (c) { return Number(c.value); });
    }

    // ── Shareholder ─────────────────────────────────────────────────────────
    function renderShareholder() {
        var s = data.shareholder_account || {};
        var L = s.ledger || { months: [] };
        var p = s.proposals || { repayments: [], personal: [], waiting: [] };
        var names = data.employees || [];
        var cur = data.shareholder || '';
        var h = '<div class="card mw-card mb-3"><div class="card-body">';
        h += '<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><label class="small mb-0" for="pi-sh-name">The shareholder on the payroll report:</label>'
            + '<select id="pi-sh-name" class="form-select form-select-sm w-auto"><option value="">— choose —</option>'
            + names.map(function (n) { var sel = n === cur || (!cur && n === data.suggested_shareholder); return '<option' + (sel ? ' selected' : '') + '>' + esc(n) + '</option>'; }).join('')
            + '</select><button type="button" class="btn btn-sm btn-outline-primary" id="pi-sh-save">Save</button>'
            + (cur ? '' : '<span class="small mw-pi-off">Not set — his transfers can\'t be matched yet.</span>') + '</div>';
        h += '<div class="mw-pi-goal mb-2"><strong>Goal:</strong> ' + esc(s.goal || '') + '</div>';
        h += '<p class="small text-muted">' + esc(s.year_end_note || '') + '</p>';
        if (L.opening_note) h += '<div class="alert alert-info small">' + esc(L.opening_note) + '</div>';
        h += '<div class="mw-pi-scroll"><table class="table table-sm mw-pi-table mb-0"><thead><tr><th>Month</th><th class="mw-pi-num">Withdrawals</th><th class="mw-pi-num">Beyond net pay</th>'
            + '<th class="mw-pi-num">Personal charges</th><th class="mw-pi-num">Repayments</th><th class="mw-pi-num">Clearing</th><th class="mw-pi-num">Other</th><th class="mw-pi-num">Balance <span class="text-muted">(− = company owes him)</span></th></tr></thead><tbody>'
            + '<tr class="mw-pi-strong"><td>Opening ' + esc(L.months.length ? L.months[0].month.slice(0, 4) : '') + '</td><td colspan="6" class="small text-muted">books ' + money(L.opening) + ' · filed balance sheet ' + money(L.filed_opening) + '</td><td class="mw-pi-num">' + money(L.opening) + '</td></tr>'
            + L.months.map(function (m) {
                return '<tr><td>' + esc(ymLabel(m.month)) + '</td><td class="mw-pi-num">' + money(m.withdrawal) + '</td><td class="mw-pi-num">' + money(m.payroll) + '</td><td class="mw-pi-num">'
                    + money(m.personal) + '</td><td class="mw-pi-num">' + money(m.repayment) + '</td><td class="mw-pi-num">' + money(m.clearing) + '</td><td class="mw-pi-num">' + money(m.other)
                    + '</td><td class="mw-pi-num"><strong>' + money(m.balance) + '</strong></td></tr>';
            }).join('') + '</tbody></table></div></div></div>';

        var props = p.repayments.concat(p.personal);
        h += '<div class="card mw-card mb-3"><div class="card-header"><h5 class="card-title mb-0">Penny\'s proposals for 1300</h5></div><div class="card-body p-0">';
        h += props.length ? movesTable(props, 'sh', true) + '<div class="p-2 mw-pi-actions"><button type="button" class="btn btn-sm btn-primary" id="pi-sh-apply">Move the ticked lines to 1300</button>'
            + '<span class="small text-muted">Repayments are ticked; "Personal?" charges are only suggestions — tick the ones that were personal.</span></div>'
            : '<div class="p-2 small text-muted">No repayments or personal charges to propose.</div>';
        if (p.waiting.length) {
            var tot = p.waiting.reduce(function (a, w) { return a + w.amount; }, 0);
            h += '<div class="p-2 small border-top">' + p.waiting.length + ' transfer' + (p.waiting.length === 1 ? '' : 's') + ' to the shareholder (' + money(tot)
                + ') wait for their month\'s payroll report — net pay first, the rest goes to 1300 when the month is booked.</div>';
        }
        if ((s.batches || []).length) {
            h += '<div class="p-2 border-top small">Moved: ' + s.batches.map(function (b) {
                return esc(b.moved_at) + ' — ' + b.n + ' line' + (Number(b.n) === 1 ? '' : 's') + ' ' + money(b.total) + ' <button type="button" class="btn btn-link btn-sm p-0" data-sh-undo="' + esc(b.batch_id) + '">undo</button>';
            }).join(' · ') + '</div>';
        }
        h += '</div></div>';
        $('pi-shareholder').innerHTML = h;

        var cl = s.clearings || [];
        $('pi-clearings').innerHTML = cl.length ? '<ul class="small mb-0">' + cl.map(function (c) {
            return '<li>' + esc(c.entry_date) + ' — ' + esc(c.clearing_type) + ' ' + money(c.amount) + (Number(c.withholdings) ? ' (+ ' + money(c.withholdings) + ' withheld)' : '')
                + (c.note ? ' · ' + esc(c.note) : '') + ' <button type="button" class="btn btn-link btn-sm p-0" data-cl-undo="' + c.id + '">undo</button></li>';
        }).join('') + '</ul>' : '';
    }

    // ── Events ──────────────────────────────────────────────────────────────
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList && t.classList.contains('pi-tick') && t.getAttribute('data-run') !== 'sh') updateClearing(Number(t.getAttribute('data-run')));
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        var id;
        if ((id = b.getAttribute('data-approve'))) {
            var est = $('pi-est-' + id);
            b.disabled = true;
            say('Booking…', 'info');
            post({ mode: 'approve', run_id: Number(id), tx_ids: ticks(id), book_estimate: !!(est && est.checked) }).then(done).catch(function () { b.disabled = false; say('Could not reach the server.', 'danger'); });
        } else if ((id = b.getAttribute('data-undo'))) {
            b.disabled = true;
            post({ mode: 'undo', run_id: Number(id) }).then(done);
        } else if ((id = b.getAttribute('data-discard'))) {
            post({ mode: 'discard', run_id: Number(id) }).then(done);
        } else if ((id = b.getAttribute('data-sh-undo'))) {
            post({ mode: 'sh_undo', batch_id: id }).then(done);
        } else if ((id = b.getAttribute('data-cl-undo'))) {
            post({ mode: 'clearing_undo', id: Number(id) }).then(done);
        } else if (b.id === 'pi-sh-save') {
            post({ mode: 'set_shareholder', name: $('pi-sh-name').value }).then(done);
        } else if (b.id === 'pi-sh-apply') {
            b.disabled = true;
            post({ mode: 'sh_apply', tx_ids: ticks('sh') }).then(done);
        } else if (b.id === 'pi-paste') {
            var text = $('pi-text').value;
            if (!text.trim()) { say('Paste the report text first.', 'warning'); return; }
            say('Reading…', 'info');
            post({ mode: 'upload_text', text: text }).then(done);
        }
    });

    $('pi-upload').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = $('pi-file').files[0];
        if (!f) { say('Choose the Wave report PDF.', 'warning'); return; }
        var fd = new FormData();
        fd.append('mode', 'upload');
        fd.append('csrf_token', window.MW_CSRF_TOKEN || '');
        fd.append('report', f);
        say('Reading the report…', 'info');
        fetch(API, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
            $('pi-file').value = '';
            done(d);
        }).catch(function () { say('Could not reach the server.', 'danger'); });
    });

    $('pi-clearing').addEventListener('submit', function (e) {
        e.preventDefault();
        post({
            mode: 'clearing_add', type: $('pi-cl-type').value, date: $('pi-cl-date').value,
            amount: Number($('pi-cl-amount').value || 0), withholdings: Number($('pi-cl-wh').value || 0), note: $('pi-cl-note').value
        }).then(function (d) { if (d && d.ok) $('pi-cl-amount').value = ''; done(d); });
    });

    load();
})();
