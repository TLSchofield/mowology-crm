/**
 * Move a stop to another date — mobile schedule cards (admin/manager).
 *
 * The card footer's Move button (partials/job-card.php) opens #mw-move-sheet
 * (jobs/schedule.php). Posts to the same /crm/api/reschedule-stop.php the desktop
 * drag-and-drop uses, so capacity warnings and stop merging behave identically.
 */
(function () {
    'use strict';

    var sheet = document.getElementById('mw-move-sheet');
    if (!sheet) return; // not an admin/manager, or not the schedule page

    var dateInput  = document.getElementById('mw-move-date');
    var confirmBtn = document.getElementById('mw-move-confirm');
    var subEl      = document.getElementById('mw-move-sub');
    var warnEl     = document.getElementById('mw-move-warning');
    var chips      = sheet.querySelectorAll('.mw-move-chip');

    var current = null;   // { stopId, fromDate, card }
    var force   = false;  // set once the user has seen the capacity warning
    var busy    = false;

    function toast(msg, type) {
        if (window.mwToast) { window.mwToast(msg, type || 'success'); } else { alert(msg); }
    }

    function toISO(d) {
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    function parseISO(iso) {
        var p = (iso || '').split('-');
        return p.length === 3 ? new Date(+p[0], +p[1] - 1, +p[2]) : new Date();
    }

    function prettyDate(iso) {
        return parseISO(iso).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }

    function setDate(iso) {
        dateInput.value = iso;
        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function resetWarning() {
        force = false;
        warnEl.hidden = true;
        warnEl.textContent = '';
        confirmBtn.textContent = 'Move';
    }

    function refreshConfirm() {
        var ok = !!dateInput.value && current && dateInput.value !== current.fromDate;
        confirmBtn.disabled = !ok || busy;
        chips.forEach(function (chip) {
            var d = parseISO(current ? current.fromDate : '');
            d.setDate(d.getDate() + parseInt(chip.getAttribute('data-move-days'), 10));
            chip.classList.toggle('is-selected', toISO(d) === dateInput.value);
        });
    }

    function open(btn) {
        current = {
            stopId:   parseInt(btn.getAttribute('data-move-stop'), 10),
            fromDate: btn.getAttribute('data-move-from') || '',
            card:     btn.closest('.mw-mc-card')
        };
        var label = btn.getAttribute('data-move-label') || 'This stop';
        subEl.textContent = label + (current.fromDate ? ' — currently ' + prettyDate(current.fromDate) : '');
        busy = false;
        resetWarning();
        setDate('');
        refreshConfirm();
        sheet.hidden = false;
    }

    function close() {
        sheet.hidden = true;
        current = null;
    }

    function submit() {
        if (!current || busy || !dateInput.value) return;
        busy = true;
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Moving…';

        var newDate = dateInput.value;
        var csrf = (window.MW_SCHEDULE_STATE && MW_SCHEDULE_STATE.csrf) || window.MW_CSRF_TOKEN || window.MW_CSRF || '';

        fetch('/crm/api/reschedule-stop.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                stop_id: current.stopId,
                new_date: newDate,
                append_to_end: true,
                force: force,
                csrf_token: csrf
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            busy = false;
            if (d && d.warning) {
                // Soft capacity warning — show it and let a second tap override.
                force = true;
                warnEl.textContent = d.message || 'That day is over capacity.';
                warnEl.hidden = false;
                confirmBtn.textContent = 'Move anyway';
                confirmBtn.disabled = false;
                return;
            }
            if (d && d.success) {
                var card = current.card;
                close();
                toast('Moved to ' + prettyDate(newDate));
                if (card) {
                    card.classList.add('mw-mc-card-removing');
                    setTimeout(function () { card.remove(); }, 350);
                }
                return;
            }
            confirmBtn.textContent = force ? 'Move anyway' : 'Move';
            refreshConfirm();
            toast((d && d.error) || 'Could not move this stop. Please try again.', 'error');
        })
        .catch(function () {
            busy = false;
            confirmBtn.textContent = force ? 'Move anyway' : 'Move';
            refreshConfirm();
            toast('Network error — the stop was not moved.', 'error');
        });
    }

    // Capture phase, like Skip: beats the card's tap-to-expand handler.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.mw-mc-footer-btn-move') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        open(btn);
    }, true);

    sheet.addEventListener('click', function (e) {
        if (e.target.closest('[data-move-close]')) { close(); return; }
        var chip = e.target.closest('.mw-move-chip');
        if (chip && current) {
            var d = parseISO(current.fromDate);
            d.setDate(d.getDate() + parseInt(chip.getAttribute('data-move-days'), 10));
            setDate(toISO(d));
        }
    });

    dateInput.addEventListener('change', function () {
        resetWarning();
        refreshConfirm();
    });

    confirmBtn.addEventListener('click', submit);
})();
