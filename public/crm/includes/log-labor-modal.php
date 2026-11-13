<?php
/**
 * Log Day's Labor — modal + script: manually record clock-in/out for crew who didn't clock in digitally.
 * Shared by Timesheets (its button) and the classic-shell Schedule header. Expects $mwLogLaborStaff (getStaffMembers()) and
 * $mwLogLaborDate (Y-m-d, the date the form opens on). Open it with openLogLaborModal(). Posts action=admin_add_entry to
 * /crm/api/time-clock.php, which does the permission check.
 */
?>
<!-- ── Log Labor Modal ───────────────────────────────────────── -->
<div class="mw-modal-overlay" id="logLaborModal" style="display:none">
    <div class="mw-modal" style="max-width:480px">
        <h3 class="mw-modal-title">Log Day's Labor</h3>
        <p class="text-muted small mb-3">Manually record clock-in/out for crew members who didn't clock in digitally.</p>

        <div id="logLaborError" class="alert alert-danger d-none mb-3"></div>
        <div id="logLaborSuccess" class="alert alert-success d-none mb-3"></div>

        <div class="form-group">
            <label class="form-label">Date</label>
            <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#llDate" aria-haspopup="true" aria-expanded="false">
                <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span class="mw-datepicker-date" data-mw-dp-label></span>
                <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <input type="date" id="llDate" class="form-control" hidden
                   value="<?php echo htmlspecialchars($mwLogLaborDate ?? date('Y-m-d')); ?>"
                   max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-row">
            <div class="form-group col-6">
                <label class="form-label">Clock In</label>
                <input type="time" id="llClockIn" class="form-control" value="08:00">
            </div>
            <div class="form-group col-6">
                <label class="form-label">Clock Out</label>
                <input type="time" id="llClockOut" class="form-control" value="16:00">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Crew Members</label>
            <div id="llCrewList" class="border rounded p-2" style="max-height:180px;overflow-y:auto">
                <?php foreach (($mwLogLaborStaff ?? []) as $member): ?>
                <label class="d-flex align-items-center gap-2 mb-1" style="cursor:pointer;font-weight:normal">
                    <input type="checkbox" class="ll-crew-checkbox" value="<?php echo (int)$member['id']; ?>"
                           data-name="<?php echo htmlspecialchars($member['full_name'], ENT_QUOTES); ?>">
                    <span><?php echo htmlspecialchars($member['full_name']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Notes (optional)</label>
            <input type="text" id="llNotes" class="form-control" placeholder="e.g., May 21 route — forgot to clock in">
        </div>

        <div class="mw-modal-actions">
            <button type="button" class="btn btn-warning" id="llSubmitBtn" onclick="submitLogLabor()">Log Hours</button>
            <button type="button" class="btn btn-secondary" onclick="closeLogLaborModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
function openLogLaborModal() {
    document.getElementById('logLaborError').classList.add('d-none');
    document.getElementById('logLaborSuccess').classList.add('d-none');
    document.getElementById('logLaborModal').style.display = 'flex';
}
function closeLogLaborModal() {
    document.getElementById('logLaborModal').style.display = 'none';
}
document.getElementById('logLaborModal').addEventListener('click', function(e) {
    if (e.target === this) closeLogLaborModal();
});

function submitLogLabor() {
    var date      = document.getElementById('llDate').value;
    var clockIn   = document.getElementById('llClockIn').value;
    var clockOut  = document.getElementById('llClockOut').value;
    var notes     = document.getElementById('llNotes').value;
    var errEl     = document.getElementById('logLaborError');
    var okEl      = document.getElementById('logLaborSuccess');
    var btn       = document.getElementById('llSubmitBtn');

    var checked = Array.from(document.querySelectorAll('.ll-crew-checkbox:checked'));
    if (!date || !clockIn || !clockOut) {
        errEl.textContent = 'Date, clock-in and clock-out are required.';
        errEl.classList.remove('d-none'); return;
    }
    if (checked.length === 0) {
        errEl.textContent = 'Select at least one crew member.';
        errEl.classList.remove('d-none'); return;
    }
    errEl.classList.add('d-none');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var names = checked.map(function(cb) { return cb.dataset.name; });
    var promises = checked.map(function(cb) {
        return fetch('/crm/api/time-clock.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || ''},
            body: JSON.stringify({
                action: 'admin_add_entry',
                user_id: parseInt(cb.value),
                date: date,
                clock_in_time: clockIn,
                clock_out_time: clockOut,
                notes: notes
            })
        }).then(function(r) { return r.json(); });
    });

    Promise.all(promises).then(function(results) {
        var errors = results.filter(function(r) { return r.error; });
        btn.disabled = false;
        btn.textContent = 'Log Hours';
        if (errors.length) {
            errEl.textContent = errors.map(function(r) { return r.error; }).join('; ');
            errEl.classList.remove('d-none');
        } else {
            var hrs = ((new Date('1970-01-01T'+clockOut+':00') - new Date('1970-01-01T'+clockIn+':00')) / 3600000).toFixed(1);
            okEl.textContent = '✓ Logged ' + hrs + 'h for: ' + names.join(', ');
            okEl.classList.remove('d-none');
            document.querySelectorAll('.ll-crew-checkbox:checked').forEach(function(cb) { cb.checked = false; });
            setTimeout(closeLogLaborModal, 2500);
        }
    }).catch(function(e) {
        btn.disabled = false;
        btn.textContent = 'Log Hours';
        errEl.textContent = 'Network error. Try again.';
        errEl.classList.remove('d-none');
    });
}
</script>
