<?php
/**
 * Salt & snow run banner (SnowRunGate, 2026-11-03) — on the schedule surfaces.
 *
 * The daily route stops are hidden from every schedule and cannot be started until a run is open.
 * This strip says so ("15 route stops hidden") and lets the office or the winter crew open or end
 * the run; everyone else sees the state only. Renders nothing when there are no active route plans
 * (summer, or a company without snow contracts), and nothing at all on any failure.
 *
 * Set before including:  $snowRunBanner = ['date' => 'Y-m-d'];   (defaults to today)
 * Needs: a logged-in session ($user from getCurrentUser()), window.MW_CSRF_TOKEN (appstack_head.php).
 */
if (empty($user) || !is_array($user)) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Schedule/Services/SnowRunGate.php';
    $__srDate = (string)($snowRunBanner['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $__srDate)) $__srDate = date('Y-m-d');
    $__sr = (new SnowRunGate(getDB()))->status($__srDate, (string)($user['role'] ?? ''), (int)($user['id'] ?? 0));
} catch (Throwable $e) {
    error_log('[snow-run-banner] ' . $e->getMessage());
    return;
}
if (empty($__sr['ready']) || empty($__sr['has_routes'])) {
    return;
}
$__srActive  = !empty($__sr['active']);
$__srKind    = (string)($__sr['kind'] ?? 'salt');
$__srSince   = !empty($__sr['started_at']) ? date('g:i a', strtotime((string)$__sr['started_at'])) : '';
$__srBy      = trim((string)($__sr['started_by_name'] ?? ''));
$__srHidden  = (int)($__sr['hidden_route_stops'] ?? 0);
$__srControl = !empty($__sr['may_control']);
$__srIsToday = $__srDate === date('Y-m-d');
?>
<div class="mw-snow-run<?php echo $__srActive ? ' is-active' : ''; ?>" id="mwSnowRun" data-date="<?php echo htmlspecialchars($__srDate); ?>">
    <div class="mw-snow-run-text">
        <span class="mw-snow-run-icon" aria-hidden="true">❄</span>
        <?php if ($__srActive): ?>
            <strong><?php echo $__srKind === 'snow' ? 'Snow run' : 'Salt run'; ?> on</strong>
            <?php if ($__srSince !== ''): ?>since <?php echo htmlspecialchars($__srSince); ?><?php endif; ?>
            <?php if ($__srBy !== ''): ?>(<?php echo htmlspecialchars($__srBy); ?>)<?php endif; ?>
            <span class="mw-snow-run-note">— route stops are on the schedule and can be started</span>
        <?php else: ?>
            <strong>Salt &amp; snow route</strong>
            <?php if ($__srHidden > 0): ?>
                · <?php echo $__srHidden; ?> route stop<?php echo $__srHidden === 1 ? '' : 's'; ?> hidden<?php echo $__srIsToday ? '' : ' that day'; ?>
            <?php endif; ?>
            <span class="mw-snow-run-note">— shown and clockable only while a run is on</span>
        <?php endif; ?>
    </div>
    <?php if ($__srControl): ?>
    <div class="mw-snow-run-actions">
        <?php if ($__srActive): ?>
            <button type="button" class="mw-snow-run-btn mw-snow-run-end" data-snow-run="end">End run</button>
        <?php else: ?>
            <button type="button" class="mw-snow-run-btn" data-snow-run="start" data-kind="salt">Start salt run</button>
            <button type="button" class="mw-snow-run-btn" data-snow-run="start" data-kind="snow">Start snow run</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php if ($__srControl): ?>
<script>
(function () {
    var box = document.getElementById('mwSnowRun');
    if (!box) return;
    function post(body, isRetry) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch('/crm/api/snow-routes.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Server error (' + r.status + ')' }; })
                .then(function (d) {
                    if (r.status === 403 && !isRetry) {
                        return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
                            .then(function (t) { return t.json(); })
                            .then(function (c) { if (c && c.token) window.MW_CSRF_TOKEN = c.token; return post(body, true); });
                    }
                    return d;
                });
        });
    }
    box.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-snow-run]');
        if (!btn) return;
        var mode = btn.dataset.snowRun === 'end' ? 'end_run' : 'start_run';
        if (mode === 'end_run' && !window.confirm('End the run? Route stops not yet started will hide again.')) return;
        var all = box.querySelectorAll('[data-snow-run]');
        all.forEach(function (b) { b.disabled = true; });
        post({ mode: mode, kind: btn.dataset.kind || 'salt', date: box.dataset.date }).then(function (d) {
            if (d && d.ok) { window.location.reload(); return; }
            all.forEach(function (b) { b.disabled = false; });
            window.alert((d && d.error) || 'Could not update the run — try again.');
        }).catch(function () {
            all.forEach(function (b) { b.disabled = false; });
            window.alert('No connection — try again.');
        });
    });
})();
</script>
<?php endif; ?>
