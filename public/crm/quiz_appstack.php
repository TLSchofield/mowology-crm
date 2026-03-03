<?php
/**
 * /public/crm/quiz_appstack.php
 * Knowledge Quiz Hub — category selection, personal stats, monthly leaderboard.
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

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0 fw-bold">Knowledge Quiz</h1>
        <p class="text-muted mb-0 small"><?php echo htmlspecialchars($monthLabel); ?> Monthly Challenge</p>
    </div>
    <?php if ($isAdmin): ?>
    <a href="/crm/quiz-admin_appstack.php" class="btn btn-sm btn-outline-secondary">
        <i data-feather="settings" style="width:14px;height:14px;"></i> Manage Quiz
    </a>
    <?php endif; ?>
</div>

<!-- ── Personal Stats Bar ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4" id="statsRow">
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
                <div class="mw-quiz-stat-num" id="statAccuracy">–</div>
                <div class="mw-quiz-stat-label">Accuracy</div>
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

<!-- ── Category Grid ─────────────────────────────────────────────────────── -->
<h5 class="fw-bold mb-3">Choose a Category</h5>
<div class="row g-3 mb-4" id="categoryGrid">
    <div class="col-12 text-center py-4 text-muted">
        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading categories…
    </div>
</div>

<!-- All Categories Mix button -->
<div class="text-center mb-5">
    <button class="btn mw-btn-green btn-lg px-5 mw-quiz-mix-btn" onclick="startQuiz(null)" id="mixBtn" disabled>
        <i data-feather="shuffle" style="width:18px;height:18px;"></i>
        &nbsp;All Categories Mix
    </button>
    <div class="text-muted small mt-2">Random questions from all categories</div>
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

// ── Load stats ──────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=my_stats');
        const d = await r.json();
        if (!d.success) return;
        document.getElementById('statGames').textContent    = d.games_played;
        document.getElementById('statPoints').textContent   = d.monthly_points;
        document.getElementById('statAccuracy').textContent = d.accuracy_pct + '%';
        document.getElementById('statRank').textContent     = d.monthly_rank ? '#' + d.monthly_rank : '–';
    } catch(e) { /* silent */ }
}

// ── Load categories ─────────────────────────────────────────────────────────
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

        const cols = d.categories.map(cat => {
            const hasQ     = parseInt(cat.question_count) > 0;
            const disabled = hasQ ? '' : 'disabled';
            const noQLabel = hasQ ? `${cat.question_count} question${cat.question_count != 1 ? 's' : ''}` : 'No questions yet';
            return `<div class="col-sm-6 col-lg-4">
                <div class="mw-quiz-category-card" style="--cat-colour:${cat.colour}">
                    <div class="mw-quiz-cat-icon">
                        <i data-feather="${cat.icon}"></i>
                    </div>
                    <div class="mw-quiz-cat-body">
                        <div class="mw-quiz-cat-name">${escHtml(cat.name)}</div>
                        <div class="mw-quiz-cat-desc">${escHtml(cat.description)}</div>
                        <div class="mw-quiz-cat-count">${noQLabel}</div>
                    </div>
                    <button class="btn mw-btn-green mw-quiz-cat-play-btn" onclick="startQuiz(${cat.id})" ${disabled}>
                        <i data-feather="play" style="width:14px;height:14px;"></i> Play
                    </button>
                </div>
            </div>`;
        });
        grid.innerHTML = cols.join('');
        if (typeof feather !== 'undefined') feather.replace();

        // Enable mix button if any category has questions
        if (d.categories.some(c => parseInt(c.question_count) > 0)) {
            document.getElementById('mixBtn').removeAttribute('disabled');
        }
    } catch(e) {
        document.getElementById('categoryGrid').innerHTML = '<div class="col-12 text-danger">Failed to load categories.</div>';
    }
}

// ── Load leaderboard ────────────────────────────────────────────────────────
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

        const medals = ['🥇', '🥈', '🥉'];
        const rows = d.leaderboard.slice(0, 5).map((p, i) => {
            const medal    = medals[i] || `#${i+1}`;
            const isMe     = parseInt(p.id) === CURRENT_USER_ID;
            const accuracy = p.total_questions > 0 ? Math.round((p.total_correct / p.total_questions) * 100) : 0;
            const prize    = p.prize_awarded ? ' <span class="badge bg-warning text-dark ms-1">🏆 Winner</span>' : '';
            return `<tr class="${isMe ? 'mw-quiz-lb-me' : ''}">
                <td class="ps-3 py-2 fw-bold">${medal}</td>
                <td class="py-2">${escHtml(p.full_name)}${prize}${isMe ? ' <span class="badge bg-success ms-1">You</span>' : ''}</td>
                <td class="py-2 text-end fw-bold text-success">${p.monthly_points} pts</td>
                <td class="py-2 text-end text-muted small d-none d-md-table-cell">${p.games_played} games</td>
                <td class="py-2 text-end text-muted small d-none d-md-table-cell pe-3">${accuracy}%</td>
            </tr>`;
        });

        lb.innerHTML = `<table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width:48px;">#</th>
                    <th>Crew Member</th>
                    <th class="text-end">Points</th>
                    <th class="text-end d-none d-md-table-cell">Games</th>
                    <th class="text-end pe-3 d-none d-md-table-cell">Accuracy</th>
                </tr>
            </thead>
            <tbody>${rows.join('')}</tbody>
        </table>`;
    } catch(e) { /* silent */ }
}

// ── Start quiz ──────────────────────────────────────────────────────────────
async function startQuiz(categoryId) {
    try {
        const r = await fetch('/crm/api/quiz.php?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category_id: categoryId, csrf_token: CSRF }),
        });
        const d = await r.json();
        if (!d.success) {
            alert(d.error || 'Could not start quiz');
            return;
        }
        window.location.href = `/crm/quiz-play_appstack.php?session_id=${d.session_id}`;
    } catch(e) {
        alert('Connection error. Please try again.');
    }
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ────────────────────────────────────────────────────────────────────
loadStats();
loadCategories();
loadLeaderboard();
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
