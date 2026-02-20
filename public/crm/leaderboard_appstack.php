<?php
/**
 * /public/crm/leaderboard_appstack.php
 * Crew Team Leaderboard — weekly scores, badges, team management.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$canManageTeams = userHasPermission('team.edit');
$canRecalculate = userHasPermission('settings.edit');

$pageTitle  = 'Leaderboard';
$activePage = 'leaderboard';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0 fw-bold">🏆 Crew Leaderboard</h1>
        <p class="text-muted mb-0 small" id="weekLabel">Loading…</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <input type="week" id="weekPicker" class="form-control form-control-sm" style="width:160px;" title="Jump to week">
        <?php if ($canRecalculate): ?>
        <button class="btn btn-sm btn-outline-secondary" id="recalcBtn" onclick="recalculate()">
            <i data-feather="refresh-cw" style="width:14px;height:14px;"></i> Recalculate
        </button>
        <?php endif; ?>
        <?php if ($canManageTeams): ?>
        <button class="btn btn-sm mw-btn-green" onclick="showManageTeams()">
            <i data-feather="users" style="width:14px;height:14px;"></i> Manage Teams
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Leaderboard Cards ─────────────────────────────────────────── -->
<div id="leaderboardCards" class="row g-3 mb-4">
    <div class="col-12 text-center py-5 text-muted">
        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading leaderboard…
    </div>
</div>

<!-- ── Badge Cabinet ─────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i data-feather="award" style="width:16px;height:16px;color:var(--mw-orange);"></i>
        <h5 class="card-title mb-0">Badge Cabinet</h5>
        <span class="text-muted small ms-2">All badges teams can earn</span>
    </div>
    <div class="card-body">
        <div id="badgeCabinet" class="mw-badge-cabinet">
            <div class="text-muted small">Loading badges…</div>
        </div>
    </div>
</div>

<!-- ── How Scoring Works ──────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">How Scoring Works</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4 col-sm-6">
                <div class="mw-score-explain-card">
                    <div class="mw-score-explain-pct">25%</div>
                    <div class="mw-score-explain-label">OCR Accuracy</div>
                    <p class="mw-score-explain-desc">Average receipt scan confidence. Higher when receipts are clear and well-lit.</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="mw-score-explain-card">
                    <div class="mw-score-explain-pct">20%</div>
                    <div class="mw-score-explain-label">Submission Speed</div>
                    <p class="mw-score-explain-desc">Receipts submitted within 4 hours of purchase. Submit same-day to score full points.</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="mw-score-explain-card">
                    <div class="mw-score-explain-pct">20%</div>
                    <div class="mw-score-explain-label">Photo Rate</div>
                    <p class="mw-score-explain-desc">Percentage of expenses with a receipt photo attached. Always snap the receipt.</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="mw-score-explain-card">
                    <div class="mw-score-explain-pct">20%</div>
                    <div class="mw-score-explain-label">Anomaly-Free</div>
                    <p class="mw-score-explain-desc">Expenses with no unusual flags (duplicate vendor, odd amount, etc.).</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="mw-score-explain-card">
                    <div class="mw-score-explain-pct">15%</div>
                    <div class="mw-score-explain-label">Budget Adherence</div>
                    <p class="mw-score-explain-desc">Jobs where actual material spend is under the quoted estimate.</p>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ═══════ Manage Teams Modal ═══════════════════════════════════════ -->
<?php if ($canManageTeams): ?>
<div class="modal fade" id="manageTeamsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Crew Teams</h5>
                <button type="button" class="btn-close" data-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="manageTeamsBody">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn mw-btn-green" onclick="showCreateTeamForm()">
                    <i data-feather="plus" style="width:14px;height:14px;"></i> New Team
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<script>
(function() {
    var CSRF = '<?php echo generateCSRFToken(); ?>';
    var CAN_MANAGE = <?php echo $canManageTeams ? 'true' : 'false'; ?>;
    var CAN_RECALC = <?php echo $canRecalculate ? 'true' : 'false'; ?>;
    var currentWeek = null;

    // ── Init ────────────────────────────────────────────────────────
    function init() {
        // Set week picker to current ISO week
        var today = new Date();
        var dayOfWeek = today.getDay() || 7; // 0=Sun → 7
        var monday = new Date(today);
        monday.setDate(today.getDate() - (dayOfWeek - 1));
        currentWeek = monday.toISOString().slice(0, 10);

        var picker = document.getElementById('weekPicker');
        if (picker) {
            picker.value = toWeekInputValue(currentWeek);
            picker.addEventListener('change', function() {
                currentWeek = fromWeekInputValue(picker.value);
                loadLeaderboard();
            });
        }

        loadLeaderboard();
        loadBadgeCabinet();
    }

    // ── Leaderboard ────────────────────────────────────────────────
    async function loadLeaderboard() {
        var cards = document.getElementById('leaderboardCards');
        cards.innerHTML = '<div class="col-12 text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>';

        try {
            var r = await fetch('/crm/api/gamification.php?action=leaderboard&week=' + currentWeek);
            var d = await r.json();
            if (!d.success) throw new Error(d.error);

            var label = document.getElementById('weekLabel');
            if (label) label.textContent = 'Week of ' + formatWeekDate(d.week);

            if (!d.leaderboard.length) {
                cards.innerHTML = '<div class="col-12"><div class="alert alert-info">No teams set up yet. ' +
                    (CAN_MANAGE ? '<a href="#" onclick="showManageTeams()">Create your first team →</a>' : 'Ask an admin to set up teams.') + '</div></div>';
                return;
            }

            cards.innerHTML = d.leaderboard.map(function(team, idx) {
                return buildTeamCard(team, idx);
            }).join('');

            if (window.feather) feather.replace();
        } catch(e) {
            cards.innerHTML = '<div class="col-12"><div class="alert alert-danger">Error: ' + esc(e.message) + '</div></div>';
        }
    }

    function buildTeamCard(team, idx) {
        var score  = parseFloat(team.total_score || 0);
        var rank   = team.rank || (idx + 1);
        var rankLabel = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '#' + rank;
        var scoreClass = score >= 80 ? 'mw-lb-score-good' : score >= 50 ? 'mw-lb-score-ok' : 'mw-lb-score-low';
        var hasData    = (team.expenses_submitted || 0) > 0;
        var colour     = team.team_colour || '#2D8659';
        var emoji      = team.team_emoji ? team.team_emoji + ' ' : '';

        // Score bar
        var barFill    = Math.round(score);
        var barClass   = score >= 80 ? 'bg-success' : score >= 50 ? 'bg-warning' : 'bg-danger';

        // Component breakdown
        var components = hasData ? [
            { label: 'OCR',     val: team.score_ocr_accuracy,  weight: '25%' },
            { label: 'Speed',   val: team.score_speed,          weight: '20%' },
            { label: 'Photos',  val: team.score_photos,         weight: '20%' },
            { label: 'Clean',   val: team.score_anomaly_free,   weight: '20%' },
            { label: 'Budget',  val: team.score_budget,         weight: '15%' },
        ] : [];

        var componentHtml = components.map(function(c) {
            var pct = parseFloat(c.val || 0);
            var cc  = pct >= 80 ? 'bg-success' : pct >= 50 ? 'bg-warning' : 'bg-danger';
            return '<div class="mw-lb-component">' +
                '<span class="mw-lb-comp-label">' + c.label + '<small>' + c.weight + '</small></span>' +
                '<div class="progress mw-lb-comp-bar"><div class="progress-bar ' + cc + '" style="width:' + pct + '%;"></div></div>' +
                '<span class="mw-lb-comp-val">' + Math.round(pct) + '</span>' +
            '</div>';
        }).join('');

        // Members
        var membersHtml = (team.members || []).length
            ? '<div class="mw-lb-members">' + (team.members || []).map(function(m) {
                return '<span class="mw-lb-member-chip">' + esc(m) + '</span>';
              }).join('') + '</div>'
            : '<p class="text-muted small mb-0"><em>No members assigned</em></p>';

        // Recent badges
        var badgesHtml = '';
        if ((team.recent_badges || []).length) {
            badgesHtml = '<div class="mw-lb-badges">' +
                team.recent_badges.map(function(b) {
                    return '<span class="mw-lb-badge-chip" style="border-color:' + esc(b.colour) + ';" title="' + esc(b.name) + '">' +
                        '<i data-feather="' + esc(b.icon) + '" style="width:11px;height:11px;color:' + esc(b.colour) + ';"></i> ' + esc(b.name) +
                    '</span>';
                }).join('') +
                (team.badge_count > 3 ? '<span class="mw-lb-badge-more">+' + (team.badge_count - 3) + '</span>' : '') +
            '</div>';
        }

        // Stats row
        var statsHtml = hasData
            ? '<div class="mw-lb-stats">' +
                '<span title="Expenses submitted">' + (team.expenses_submitted || 0) + ' receipts</span>' +
                (team.avg_submission_hours != null ? '<span title="Avg submit lag">⏱ ' + parseFloat(team.avg_submission_hours).toFixed(1) + 'h avg</span>' : '') +
              '</div>'
            : '<p class="text-muted small mb-1"><em>No expense activity this week</em></p>';

        return '<div class="col-lg-6 col-xl-4">' +
            '<div class="card mw-lb-card" style="border-top: 3px solid ' + colour + ';">' +
                '<div class="card-body">' +
                    '<div class="mw-lb-card-header">' +
                        '<div class="mw-lb-rank">' + rankLabel + '</div>' +
                        '<div class="mw-lb-team-info">' +
                            '<h5 class="mw-lb-team-name">' + emoji + esc(team.team_name) + '</h5>' +
                        '</div>' +
                        '<div class="mw-lb-score ' + scoreClass + '">' + Math.round(score) + '</div>' +
                    '</div>' +
                    '<div class="progress mw-lb-main-bar mb-3"><div class="progress-bar ' + barClass + '" style="width:' + barFill + '%;"></div></div>' +
                    componentHtml +
                    '<hr class="my-2">' +
                    statsHtml +
                    membersHtml +
                    badgesHtml +
                '</div>' +
            '</div>' +
        '</div>';
    }

    // ── Badge Cabinet ──────────────────────────────────────────────
    async function loadBadgeCabinet() {
        try {
            var r = await fetch('/crm/api/gamification.php?action=badges');
            var d = await r.json();
            if (!d.success) return;

            var tierOrder  = { platinum: 0, gold: 1, silver: 2, bronze: 3 };
            var tierLabels = { platinum: '🌟 Platinum', gold: '🥇 Gold', silver: '🥈 Silver', bronze: '🥉 Bronze' };

            // Group by tier
            var byTier = {};
            d.badges.forEach(function(b) {
                if (!byTier[b.tier]) byTier[b.tier] = [];
                byTier[b.tier].push(b);
            });

            var html = '';
            Object.keys(tierOrder).sort(function(a,b){ return tierOrder[a]-tierOrder[b]; }).forEach(function(tier) {
                if (!byTier[tier]) return;
                html += '<div class="mw-badge-tier-group">';
                html += '<h6 class="mw-badge-tier-label">' + tierLabels[tier] + '</h6>';
                html += '<div class="mw-badge-grid">';
                byTier[tier].forEach(function(b) {
                    html += '<div class="mw-badge-item" title="' + esc(b.criteria || b.description || '') + '">' +
                        '<div class="mw-badge-icon" style="color:' + esc(b.colour) + ';border-color:' + esc(b.colour) + ';">' +
                            '<i data-feather="' + esc(b.icon) + '" style="width:20px;height:20px;"></i>' +
                        '</div>' +
                        '<div class="mw-badge-name">' + esc(b.name) + '</div>' +
                    '</div>';
                });
                html += '</div></div>';
            });

            document.getElementById('badgeCabinet').innerHTML = html;
            if (window.feather) feather.replace();
        } catch(e) { /* non-critical */ }
    }

    // ── Recalculate ────────────────────────────────────────────────
    window.recalculate = async function() {
        var btn = document.getElementById('recalcBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
        try {
            var r = await fetch('/crm/api/gamification.php?action=recalculate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, week: currentWeek }),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            loadLeaderboard();
        } catch(e) {
            alert('Error: ' + e.message);
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="refresh-cw" style="width:14px;height:14px;"></i> Recalculate'; if (window.feather) feather.replace(); }
        }
    };

    // ── Manage Teams ───────────────────────────────────────────────
    window.showManageTeams = async function() {
        if (!CAN_MANAGE) return;
        var body = document.getElementById('manageTeamsBody');
        if (!body) return;
        body.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
        $('#manageTeamsModal').modal('show');

        try {
            var r = await fetch('/crm/api/gamification.php?action=teams');
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            renderManageTeams(d.teams, d.unassigned);
        } catch(e) {
            body.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
    };

    function renderManageTeams(teams, unassigned) {
        var body = document.getElementById('manageTeamsBody');
        if (!teams.length) {
            body.innerHTML = '<p class="text-muted">No teams yet. Create your first team using the button below.</p>';
            return;
        }

        var html = teams.map(function(t) {
            var memberChips = (t.members || []).map(function(m) {
                return '<span class="mw-lb-member-chip">' + esc(m.full_name) +
                    ' <button type="button" class="mw-chip-remove" onclick="removeMember(' + t.id + ',' + m.id + ')" title="Remove">×</button>' +
                '</span>';
            }).join('');

            // Build dropdown of unassigned users
            var addOpts = unassigned.map(function(u) {
                return '<option value="' + u.id + '">' + esc(u.full_name) + '</option>';
            }).join('');

            return '<div class="mw-mt-team-row" id="team-row-' + t.id + '">' +
                '<div class="d-flex align-items-center gap-2 mb-2">' +
                    '<span class="mw-mt-colour-dot" style="background:' + esc(t.colour || '#2D8659') + ';"></span>' +
                    '<strong>' + esc(t.emoji || '') + ' ' + esc(t.name) + '</strong>' +
                    '<span class="badge bg-secondary ms-1">' + (t.members || []).length + ' members</span>' +
                '</div>' +
                '<div class="mw-lb-members mb-2">' + (memberChips || '<em class="text-muted small">No members</em>') + '</div>' +
                (addOpts ? '<div class="d-flex gap-2 mt-1">' +
                    '<select id="addUserSel-' + t.id + '" class="form-select form-select-sm" style="max-width:200px;">' +
                        '<option value="">Add member…</option>' + addOpts +
                    '</select>' +
                    '<button class="btn btn-sm mw-btn-green" onclick="addMember(' + t.id + ')">Add</button>' +
                '</div>' : '') +
            '</div>';
        }).join('<hr>');

        body.innerHTML = html;
    }

    window.addMember = async function(teamId) {
        var sel = document.getElementById('addUserSel-' + teamId);
        if (!sel || !sel.value) return;
        var userId = parseInt(sel.value);
        try {
            var r = await fetch('/crm/api/gamification.php?action=add_member', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, team_id: teamId, user_id: userId }),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            showManageTeams(); // reload
        } catch(e) { alert('Error: ' + e.message); }
    };

    window.removeMember = async function(teamId, userId) {
        if (!confirm('Remove this member from the team?')) return;
        try {
            var r = await fetch('/crm/api/gamification.php?action=remove_member', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, team_id: teamId, user_id: userId }),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            showManageTeams(); // reload
        } catch(e) { alert('Error: ' + e.message); }
    };

    window.showCreateTeamForm = async function() {
        var name   = prompt('Team name:');
        if (!name || !name.trim()) return;
        var colour = prompt('Team colour (hex, e.g. #2D8659):', '#2D8659') || '#2D8659';
        var emoji  = prompt('Team emoji (optional):', '') || '';
        try {
            var r = await fetch('/crm/api/gamification.php?action=create_team', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, name: name.trim(), colour: colour, emoji: emoji }),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            showManageTeams();
            loadLeaderboard();
        } catch(e) { alert('Error: ' + e.message); }
    };

    // ── Week picker helpers ────────────────────────────────────────
    function toWeekInputValue(mondayDate) {
        // Convert YYYY-MM-DD (Monday) to YYYY-Www format for <input type="week">
        var d = new Date(mondayDate + 'T00:00:00');
        var year = d.getFullYear();
        // Calculate ISO week number
        var jan4 = new Date(year, 0, 4);
        var dayOfYear = Math.floor((d - new Date(year, 0, 1)) / 86400000) + 1;
        var week = Math.ceil((dayOfYear + (new Date(year, 0, 1).getDay() || 7) - 1) / 7);
        return year + '-W' + String(week).padStart(2, '0');
    }

    function fromWeekInputValue(weekVal) {
        // Convert YYYY-Www to Monday date YYYY-MM-DD
        if (!weekVal) return currentWeek;
        var parts  = weekVal.split('-W');
        var year   = parseInt(parts[0]);
        var week   = parseInt(parts[1]);
        // Jan 4 is always in week 1
        var jan4   = new Date(year, 0, 4);
        var monday = new Date(jan4);
        monday.setDate(jan4.getDate() - (jan4.getDay() || 7) + 1 + (week - 1) * 7);
        return monday.toISOString().slice(0, 10);
    }

    function formatWeekDate(mondayDate) {
        if (!mondayDate) return '—';
        var d = new Date(mondayDate + 'T00:00:00');
        var end = new Date(d);
        end.setDate(d.getDate() + 6);
        var opts = { month: 'short', day: 'numeric' };
        return d.toLocaleDateString('en-CA', opts) + ' – ' + end.toLocaleDateString('en-CA', opts) + ', ' + d.getFullYear();
    }

    function esc(s) {
        if (!s && s !== 0) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    // ── Boot ───────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
