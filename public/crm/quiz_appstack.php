<?php
/**
 * /public/crm/quiz_appstack.php
 * Knowledge Quiz Hub — mastery progress, Study / Test per category, leaderboard.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$isAdmin    = ($user['role'] ?? '') === 'admin';
$pageTitle  = 'Knowledge Quiz';
$activePage = 'quiz';
$csrfToken  = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
$monthLabel = date('F Y');
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0 fw-bold">Knowledge Quiz</h1>
        <p class="text-muted mb-0 small"><?php echo htmlspecialchars($monthLabel); ?> Monthly Challenge</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="/crm/quiz-progress_appstack.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="bar-chart-2" style="width:14px;height:14px;"></i> My Progress
        </a>
        <?php if ($isAdmin): ?>
        <a href="/crm/quiz-admin_appstack.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="settings" style="width:14px;height:14px;"></i> Manage Quiz
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Rank Tier + Streak Banner ──────────────────────────────────────────────── -->
<div class="mw-rank-banner mb-4" id="rankBanner" style="display:none">
    <div class="mw-rank-tier-badge" id="rankTierBadge"></div>
    <div class="mw-rank-meta" id="rankMeta"></div>
    <div class="mw-rank-streak" id="rankStreak" style="display:none"></div>
</div>

<!-- ── Personal Stats Bar ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center mw-quiz-stat-card">
            <div class="card-body py-3">
                <div class="mw-quiz-stat-num" id="statGames">–</div>
                <div class="mw-quiz-stat-label">Games This Month</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center mw-quiz-stat-card">
            <div class="card-body py-3">
                <div class="mw-quiz-stat-num" id="statPoints">–</div>
                <div class="mw-quiz-stat-label">Monthly Points</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center mw-quiz-stat-card">
            <div class="card-body py-3">
                <div class="mw-quiz-stat-num" id="statMastered">–</div>
                <div class="mw-quiz-stat-label">Questions Mastered</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center mw-quiz-stat-card">
            <div class="card-body py-3">
                <div class="mw-quiz-stat-num" id="statRank">–</div>
                <div class="mw-quiz-stat-label">Monthly Rank</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Badges Strip ───────────────────────────────────────────────────────── -->
<div class="mw-badges-strip mb-4" id="badgesStrip" style="display:none">
    <div class="mw-badges-strip-label">Badges</div>
    <div class="mw-badges-strip-icons" id="badgesIcons"></div>
    <a href="/crm/quiz-progress_appstack.php" class="mw-badges-strip-all">View all →</a>
</div>

<!-- ── Category Grid ─────────────────────────────────────────────────────── -->
<h5 class="fw-bold mb-3">Choose a Category</h5>
<div class="row g-3 mb-4" id="categoryGrid">
    <div class="col-12 text-center py-4 text-muted">
        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading categories…
    </div>
</div>

<!-- Review All Due + Quick 5 -->
<div class="text-center mb-5">
    <div class="d-flex justify-content-center gap-3 flex-wrap">
        <button class="btn mw-btn-green btn-lg px-4 mw-quiz-mix-btn" onclick="startTest(null, 10)" id="reviewAllBtn" disabled>
            <i data-feather="refresh-cw" style="width:18px;height:18px;"></i>
            &nbsp;Review All Due
        </button>
        <button class="btn btn-outline-secondary btn-lg px-4" onclick="startTest(null, 5)" title="Quick 5-question session">
            <i data-feather="zap" style="width:18px;height:18px;"></i>
            &nbsp;Quick 5
        </button>
    </div>
    <div class="text-muted small mt-2">Review All Due · 10 questions &nbsp;|&nbsp; Quick 5 · 5 questions</div>
</div>

<!-- ── Mini Leaderboard ───────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
            <i data-feather="award" style="width:16px;height:16px;color:var(--mw-orange);"></i>
            <h5 class="card-title mb-0"><?php echo htmlspecialchars($monthLabel); ?> Leaderboard</h5>
        </div>
        <a href="/crm/quiz-admin_appstack.php?tab=leaderboard" class="btn btn-sm btn-outline-secondary">Full View</a>
    </div>
    <div class="card-body p-0">
        <div id="miniLeaderboard">
            <div class="text-center py-4 text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading…
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrfToken); ?>;
const CURRENT_USER_ID = <?php echo (int)$user['id']; ?>;

// ── Load stats ───────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=my_stats');
        const d = await r.json();
        if (!d.success) return;

        document.getElementById('statGames').textContent    = d.games_played;
        document.getElementById('statPoints').textContent   = d.monthly_points;
        document.getElementById('statMastered').textContent = d.total_mastered;
        document.getElementById('statRank').textContent     = d.monthly_rank ? '#' + d.monthly_rank : '–';

        // Rank tier banner
        if (d.rank_tier) {
            const t = d.rank_tier;
            document.getElementById('rankTierBadge').innerHTML =
                `<span class="mw-rank-tier mw-rank-tier-${t.tier}">${t.icon} ${escHtml(t.name)}</span>`;
            const progress = t.next_at
                ? `<span class="mw-rank-progress">${d.total_mastered}/${t.next_at} mastered to ${escHtml(t.next_name)}</span>`
                : `<span class="mw-rank-progress text-success">Maximum rank achieved!</span>`;
            document.getElementById('rankMeta').innerHTML = progress;
            document.getElementById('rankBanner').style.display = '';
        }

        // Streak
        if (d.current_streak > 0) {
            const streakEl = document.getElementById('rankStreak');
            streakEl.innerHTML = `<span class="mw-streak-badge">🔥 ${d.current_streak}-day streak</span>`;
            streakEl.style.display = '';
        }

        // Badges strip
        if (d.badge_count > 0) {
            fetch('/crm/api/quiz.php?action=user_badges')
                .then(r => r.json())
                .then(bd => {
                    if (!bd.success) return;
                    const earned = bd.badges.filter(b => b.earned).slice(0, 8);
                    if (!earned.length) return;
                    document.getElementById('badgesIcons').innerHTML =
                        earned.map(b => `<span class="mw-badge-icon" title="${escHtml(b.name)}">${b.icon}</span>`).join('');
                    document.getElementById('badgesStrip').style.display = '';
                })
                .catch(() => {});
        }

    } catch(e) {}
}

// ── Load categories ───────────────────────────────────────────────────────────
async function loadCategories() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=categories');
        const d = await r.json();
        if (!d.success) return;

        const grid = document.getElementById('categoryGrid');
        if (!d.categories.length) {
            grid.innerHTML = '<div class="col-12 text-center text-muted py-4">No categories yet. <a href="/crm/quiz-admin_appstack.php">Add some questions!</a></div>';
            return;
        }

        let anyDue = false;

        const cols = d.categories.map(cat => {
            const total    = parseInt(cat.question_count) || 0;
            const mastered = parseInt(cat.mastered_count) || 0;
            const learning = parseInt(cat.learning_count) || 0;
            const unseen   = parseInt(cat.unseen_count) || 0;
            const due      = parseInt(cat.due_count) || 0;

            if (due > 0) anyDue = true;

            const mastPct  = total > 0 ? Math.round((mastered / total) * 100) : 0;
            const hasQ     = total > 0;

            const hasStudy = hasQ && (unseen > 0 || due > 0);
            const studyLabel = due > 0 ? `Study (${due} due)` : `Study (${unseen} new)`;

            const statLine = total === 0
                ? '<span class="text-muted">No questions yet</span>'
                : `<span class="text-success">${mastered} mastered</span>` +
                  (learning > 0 ? ` · <span class="text-primary">${learning} learning</span>` : '') +
                  (unseen   > 0 ? ` · <span class="text-muted">${unseen} new</span>`          : '');

            return `<div class="col-sm-6 col-lg-4">
                <div class="mw-quiz-category-card" style="--cat-colour:${cat.colour}">
                    <div class="mw-quiz-cat-top">
                        <div class="mw-quiz-cat-icon">
                            <i data-feather="${cat.icon}"></i>
                        </div>
                        <div class="mw-quiz-cat-body">
                            <div class="mw-quiz-cat-name">${escHtml(cat.name)}</div>
                            <div class="mw-quiz-cat-desc">${escHtml(cat.description)}</div>
                        </div>
                    </div>
                    <div class="mw-mastery-bar-wrap">
                        <div class="mw-mastery-bar" style="width:${mastPct}%"></div>
                    </div>
                    <div class="mw-quiz-cat-stat-line">${statLine}</div>
                    <div class="mw-quiz-cat-btns">
                        <a href="/crm/quiz-learn_appstack.php?category_id=${cat.id}"
                           class="btn btn-sm btn-outline-secondary mw-quiz-cat-study-btn ${!hasStudy ? 'disabled' : ''}">
                            <i data-feather="book-open" style="width:13px;height:13px;"></i>
                            ${studyLabel}
                        </a>
                        <button class="btn btn-sm mw-btn-green mw-quiz-cat-test-btn"
                                onclick="startTest(${cat.id})" ${!hasQ ? 'disabled' : ''}>
                            <i data-feather="play" style="width:13px;height:13px;"></i> Test
                        </button>
                    </div>
                </div>
            </div>`;
        });

        grid.innerHTML = cols.join('');
        if (typeof feather !== 'undefined') feather.replace();

        if (anyDue || d.categories.some(c => parseInt(c.question_count) > 0)) {
            document.getElementById('reviewAllBtn').removeAttribute('disabled');
        }

    } catch(e) {
        document.getElementById('categoryGrid').innerHTML = '<div class="col-12 text-danger">Failed to load categories.</div>';
    }
}

// ── Start test session ────────────────────────────────────────────────────────
async function startTest(categoryId, sessionLength) {
    const len = sessionLength || 10;
    try {
        const r = await fetch('/crm/api/quiz.php?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category_id: categoryId, mode: 'test', session_length: len, csrf_token: CSRF }),
        });
        const d = await r.json();
        if (!d.success) { alert(d.error || 'Could not start quiz'); return; }
        window.location.href = `/crm/quiz-play_appstack.php?session_id=${d.session_id}`;
    } catch(e) {
        alert('Connection error. Please try again.');
    }
}

// ── Mini leaderboard ──────────────────────────────────────────────────────────
async function loadLeaderboard() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=leaderboard');
        const d = await r.json();
        if (!d.success) return;

        const lb = document.getElementById('miniLeaderboard');
        if (!d.leaderboard.length) {
            lb.innerHTML = '<div class="text-center py-4 text-muted small">No games played yet this month. Be the first!</div>';
            return;
        }

        const medals = ['🥇','🥈','🥉'];
        const rows = d.leaderboard.slice(0, 5).map((p, i) => {
            const isMe  = parseInt(p.id) === CURRENT_USER_ID;
            const prize = p.prize_awarded ? ' <span class="badge bg-warning text-dark ms-1">🏆 Winner</span>' : '';
            return `<tr class="${isMe ? 'mw-quiz-lb-me' : ''}">
                <td class="ps-3 py-2 fw-bold">${medals[i] || '#'+(i+1)}</td>
                <td class="py-2">${escHtml(p.full_name)}${prize}${isMe ? ' <span class="badge bg-success ms-1">You</span>' : ''}</td>
                <td class="py-2 text-end fw-bold text-success">${p.monthly_points} pts</td>
                <td class="py-2 text-end text-muted small d-none d-md-table-cell">${p.games_played} games</td>
            </tr>`;
        });

        lb.innerHTML = `<table class="table table-hover mb-0">
            <thead class="table-light"><tr>
                <th class="ps-3" style="width:48px;">#</th>
                <th>Crew Member</th><th class="text-end">Points</th>
                <th class="text-end d-none d-md-table-cell">Games</th>
            </tr></thead>
            <tbody>${rows.join('')}</tbody>
        </table>`;
    } catch(e) {}
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadStats();
loadCategories();
loadLeaderboard();
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
