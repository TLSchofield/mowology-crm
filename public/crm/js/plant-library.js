/**
 * Plant library page (/crm/plants/library.php) — review, edit and approve care cards (PlantCareService).
 * Tabs by status; cards grouped (hedges, shrubs…); approve one or a whole group; every fact's source linked.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-plant-library');
    if (!root) return;
    var CSRF = root.getAttribute('data-csrf') || '';
    var API = '/crm/api/plants.php';
    var S = { tab: 'draft', data: null, editing: null };
    var TABS = [['draft', 'To review'], ['approved', 'Approved'], ['queued', 'Being written'], ['writing', 'Writing now'], ['failed', 'Failed'], ['rejected', 'Thrown out']];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function toast(m, k) { if (window.mwToast) window.mwToast(m, k); else window.alert(m); }
    function post(body) {
        body.csrf_token = CSRF;
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
    }
    function months() { return (S.data && S.data.months) || ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; }
    function when(t) { var m = months(); return +t.from === +t.to ? m[t.from] : m[t.from] + '–' + m[t.to]; }

    function load() {
        fetch(API + '?mode=library', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { root.innerHTML = '<div class="card"><div class="card-body text-muted">' + esc(d.error || 'The library is not set up yet.') + '</div></div>'; return; }
            S.data = d;
            var c = d.counts || {};
            if (!(c[S.tab] > 0) && S.tab === 'draft' && c.approved > 0) S.tab = 'approved';
            render();
        });
    }

    function all() {
        var out = [];
        Object.keys(S.data.groups || {}).forEach(function (g) { (S.data.groups[g] || []).forEach(function (c) { out.push(c); }); });
        return out;
    }

    function render() {
        var d = S.data, c = d.counts || {}, total = all().length;
        var building = (c.queued || 0) > 0;
        var head = '<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center justify-content-between">'
            + '<div class="mb-2 mb-md-0"><b>' + total + '</b> plants · ' + (c.approved || 0) + ' approved · ' + (c.draft || 0) + ' to review'
            + (building ? ' · ' + c.queued + ' being written (usually within the hour — refresh to collect)' : '')
            + '<div class="small text-muted">Written from trusted gardening sources by Claude; spent so far $' + Number(d.spent_usd || 0).toFixed(2) + ' USD.</div></div>'
            + (d.can_edit ? '<button type="button" class="btn btn-primary mw-pl-build"' + (building ? ' disabled' : '') + '>'
                + (total ? 'Write any missing cards' : 'Build the library (about 150 Vancouver plants)') + '</button>' : '')
            + '</div></div>';
        var tabs = '<ul class="nav nav-tabs mb-3">' + TABS.map(function (t) {
            return '<li class="nav-item"><a href="#" class="nav-link' + (S.tab === t[0] ? ' active' : '') + '" data-tab="' + t[0] + '">' + esc(t[1]) + ' <span class="badge badge-light">' + (c[t[0]] || 0) + '</span></a></li>';
        }).join('') + '</ul>';
        var body = '';
        Object.keys(d.groups_named || {}).forEach(function (g) {
            var cards = (d.groups[g] || []).filter(function (x) { return x.status === S.tab; });
            if (!cards.length) return;
            body += '<div class="card mb-3"><div class="card-header d-flex align-items-center justify-content-between"><h5 class="card-title mb-0">' + esc(d.groups_named[g]) + ' <small class="text-muted">' + cards.length + '</small></h5>'
                + (S.tab === 'draft' && d.can_edit ? '<button type="button" class="btn btn-sm btn-outline-primary" data-approve-group="' + esc(g) + '">Approve all ' + cards.length + '</button>' : '')
                + '</div><div class="card-body p-0"><div class="list-group list-group-flush">' + cards.map(cardHtml).join('') + '</div></div></div>';
        });
        if (!body) body = '<div class="card"><div class="card-body text-muted">Nothing here.</div></div>';
        root.innerHTML = head + tabs + body;
    }

    function cardHtml(x) {
        if (S.editing === +x.id) return editHtml(x);
        var k = x.card || {}, tasks = k.tasks || [];
        var facts = [k.sun, k.soil, k.size, k.hardiness].filter(Boolean).map(esc).join(' · ');
        var acts = '';
        if (S.data.can_edit) {
            if (x.status === 'draft') acts = '<button type="button" class="btn btn-sm btn-primary mr-1" data-approve="' + x.id + '">Approve</button>';
            if (x.status === 'draft' || x.status === 'approved') acts += '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" data-edit="' + x.id + '">Edit</button>';
            if (x.status !== 'queued') acts += '<button type="button" class="btn btn-sm btn-link" data-redo="' + x.id + '">Rewrite</button>';
            if (x.status === 'draft') acts += '<button type="button" class="btn btn-sm btn-link text-muted" data-reject="' + x.id + '">Throw out</button>';
        }
        return '<div class="list-group-item">'
            + '<div class="d-flex flex-wrap justify-content-between"><div><b>' + esc(x.common_name || x.scientific_name) + '</b> <i class="text-muted">' + esc(x.scientific_name) + '</i>'
            + (x.level === 'genus' ? ' <span class="badge badge-info">general — all ' + esc(x.scientific_name) + '</span>' : '')
            + (x.origin === 'identified' ? ' <span class="badge badge-secondary">found by crew</span>' : '')
            + (k.toxic_pets ? ' <span class="badge badge-danger" title="' + esc(k.toxic_note) + '">toxic to pets</span>' : '') + '</div>'
            + '<div class="text-nowrap">' + acts + '</div></div>'
            + (tasks.length ? '<ul class="mb-1 mt-2 pl-3">' + tasks.map(function (t) {
                return '<li><b>' + esc(t.task) + '</b> — ' + esc(when(t)) + (t.how ? ' <span class="text-muted">· ' + esc(t.how) + '</span>' : '') + '</li>';
            }).join('') + '</ul>' : (x.status === 'queued' ? '<div class="small text-muted mt-1">Being written…</div>' : ''))
            + (facts ? '<div class="small text-muted">' + facts + '</div>' : '')
            + (k.notes ? '<div class="small mt-1">' + esc(k.notes) + '</div>' : '')
            + ((x.sources || []).length ? '<div class="small mt-1">Sources: ' + x.sources.map(function (s) {
                return '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.title || s.url) + '</a>';
            }).join(' · ') + '</div>' : '')
            + '</div>';
    }

    function monthSel(name, v) {
        var m = months();
        return '<select class="form-control form-control-sm d-inline-block w-auto" data-f="' + name + '">' + m.slice(1).map(function (n, i) {
            return '<option value="' + (i + 1) + '"' + (+v === i + 1 ? ' selected' : '') + '>' + n + '</option>';
        }).join('') + '</select>';
    }
    function taskRow(t) {
        t = t || { task: '', from: 1, to: 1, how: '' };
        return '<div class="form-row mb-1 mw-pl-task"><div class="col-md-3"><input class="form-control form-control-sm" data-f="task" value="' + esc(t.task) + '" placeholder="Job"></div>'
            + '<div class="col-md-3">' + monthSel('from', t.from) + ' to ' + monthSel('to', t.to) + '</div>'
            + '<div class="col-md-5"><input class="form-control form-control-sm" data-f="how" value="' + esc(t.how) + '" placeholder="How (one line)"></div>'
            + '<div class="col-md-1"><button type="button" class="btn btn-sm btn-link text-muted" data-drop-task>×</button></div></div>';
    }
    function editHtml(x) {
        var k = x.card || {};
        var inp = function (f, label, v) { return '<div class="col-md-3 mb-2"><label class="small mb-0">' + label + '</label><input class="form-control form-control-sm" data-f="' + f + '" value="' + esc(v) + '"></div>'; };
        return '<div class="list-group-item mw-pl-edit" data-id="' + x.id + '">'
            + '<div class="form-row">' + inp('common_name', 'Name shown', x.common_name || '') + inp('sun', 'Sun', k.sun) + inp('soil', 'Soil', k.soil) + inp('size', 'Size', k.size) + '</div>'
            + '<label class="small mb-1">Jobs and months</label><div class="mw-pl-tasks">' + (k.tasks || []).map(taskRow).join('') + '</div>'
            + '<button type="button" class="btn btn-sm btn-link px-0 mb-2" data-add-task>+ Add a job</button>'
            + '<div class="form-row"><div class="col-md-3 mb-2"><div class="custom-control custom-checkbox mt-4"><input type="checkbox" class="custom-control-input" id="mw-pl-tox' + x.id + '" data-f="toxic_pets"' + (k.toxic_pets ? ' checked' : '') + '><label class="custom-control-label" for="mw-pl-tox' + x.id + '">Toxic to pets</label></div></div>'
            + inp('toxic_note', 'Toxicity note', k.toxic_note) + inp('hardiness', 'Hardiness', k.hardiness)
            + '<div class="col-md-12 mb-2"><label class="small mb-0">Notes for the crew</label><input class="form-control form-control-sm" data-f="notes" value="' + esc(k.notes) + '"></div></div>'
            + '<button type="button" class="btn btn-sm btn-primary" data-save="' + x.id + '">Save and approve</button> <button type="button" class="btn btn-sm btn-link" data-cancel>Cancel</button></div>';
    }

    function readEdit(box) {
        var v = function (f) { var el = box.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; };
        var tasks = Array.prototype.map.call(box.querySelectorAll('.mw-pl-task'), function (r) {
            return { task: r.querySelector('[data-f="task"]').value, from: +r.querySelector('[data-f="from"]').value, to: +r.querySelector('[data-f="to"]').value, how: r.querySelector('[data-f="how"]').value };
        }).filter(function (t) { return t.task.trim(); });
        return { common_name: v('common_name'), card: { tasks: tasks, toxic_pets: box.querySelector('[data-f="toxic_pets"]').checked, toxic_note: v('toxic_note'),
                 sun: v('sun'), soil: v('soil'), size: v('size'), hardiness: v('hardiness'), notes: v('notes') } };
    }

    root.addEventListener('click', function (e) {
        var t = e.target.closest('[data-tab],[data-approve],[data-approve-group],[data-edit],[data-redo],[data-reject],[data-save],[data-cancel],[data-add-task],[data-drop-task],.mw-pl-build');
        if (!t) return;
        e.preventDefault();
        var done = function (r) { toast(r.message || (r.ok ? 'Done' : 'Something went wrong'), r.ok ? 'success' : 'error'); S.editing = null; load(); };
        if (t.hasAttribute('data-tab')) { S.tab = t.getAttribute('data-tab'); S.editing = null; render(); }
        else if (t.hasAttribute('data-approve')) post({ mode: 'card_decide', id: +t.getAttribute('data-approve'), what: 'approve' }).then(done);
        else if (t.hasAttribute('data-approve-group')) {
            if (window.confirm('Approve every card in this group as written?')) post({ mode: 'group_approve', group: t.getAttribute('data-approve-group') }).then(done);
        }
        else if (t.hasAttribute('data-edit')) { S.editing = +t.getAttribute('data-edit'); render(); }
        else if (t.hasAttribute('data-cancel')) { S.editing = null; render(); }
        else if (t.hasAttribute('data-redo')) post({ mode: 'card_decide', id: +t.getAttribute('data-redo'), what: 'redo' }).then(done);
        else if (t.hasAttribute('data-reject')) post({ mode: 'card_decide', id: +t.getAttribute('data-reject'), what: 'reject' }).then(done);
        else if (t.hasAttribute('data-add-task')) t.parentNode.querySelector('.mw-pl-tasks').insertAdjacentHTML('beforeend', taskRow());
        else if (t.hasAttribute('data-drop-task')) t.closest('.mw-pl-task').remove();
        else if (t.hasAttribute('data-save')) {
            var body = readEdit(t.closest('.mw-pl-edit'));
            body.mode = 'card_decide'; body.id = +t.getAttribute('data-save'); body.what = 'approve';
            post(body).then(done);
        }
        else if (t.classList.contains('mw-pl-build')) {
            t.disabled = true; t.textContent = 'Sending…';
            post({ mode: 'library_build' }).then(done);
        }
    });

    load();
})();
