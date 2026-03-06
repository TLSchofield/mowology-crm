<?php
/**
 * /public/crm/quiz-progress_appstack.php
 * Knowledge Quiz — My Progress
 * Shows rank tier, streak, badges, and category accuracy.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'My Progress';
$activePage = 'quiz';
$csrfToken  = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0 fw-bold">My Progress</h1>
        <p class="text-muted mb-0 small">Knowledge level, streaks, and earned badges</p>
    </div>
    <a href="/crm/quiz_appstack.php" class="btn btn-sm btn-outline-secondary">
        <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back to Quiz Hub
    </a>
</div>

<div class="row g-4">

    <!-- ── Left Column: Rank + Streak ── -->
    <div class="col-lg-4">

        <!-- Rank Tier Card -->
        <div class="card mb-4">
            <div class="card-body text-center py-4">
                <div id="rankTierIcon" class="mw-prog-rank-icon">🌱</div>
                <div id="rankTierName" class="mw-prog-rank-name">Lawn Apprentice</div>
                <div id="rankTierProgress" class="mw-prog-rank-progress text-muted small mt-1">Loading…</div>
                <div class="mw-prog-rank-bar-wrap mt-3">
                    <div class="mw-prog-rank-bar" id="rankTierBar" style="width:0%"></div>
                </div>
                <div class="row g-2 mt-3">
                    <div class="col-6">
                        <div class="mw-prog-mini-stat">
                            <div class="mw-prog-mini-num" id="progMastered">–</div>
                            <div class="mw-prog-mini-label">Mastered</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mw-prog-mini-stat">
                            <div class="mw-prog-mini-num" id="progAccuracy">–</div>
                            <div class="mw-prog-mini-label">Accuracy</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Streak Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0">🔥 Study Streak</h6>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <div class="mw-prog-streak-num" id="currentStreak">0</div>
                        <div class="text-muted small">Current Streak</div>
                    </div>
                    <div class="col-6">
                        <div class="mw-prog-streak-num text-muted" id="longestStreak">0</div>
                        <div class="text-muted small">Longest Streak</div>
                    </div>
                </div>
                <div class="text-center mt-3 text-muted small" id="streakTip">Play a quiz each day to build your streak!</div>
            </div>
        </div>

        <!-- Rank Ladder -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Rank Ladder</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush mw-prog-ladder" id="rankLadder">
                    <li class="list-group-item d-flex align-items-center">🌱 &nbsp;<span>Lawn Apprentice</span> <span class="ml-auto text-muted small">0 mastered</span></li>
                    <li class="list-group-item d-flex align-items-center">🌿 &nbsp;<span>Turf Technician</span> <span class="ml-auto text-muted small">10 mastered</span></li>
                    <li class="list-group-item d-flex align-items-center">🌳 &nbsp;<span>Plant Specialist</span> <span class="ml-auto text-muted small">25 mastered</span></li>
                    <li class="list-group-item d-flex align-items-center">🏅 &nbsp;<span>Landscape Expert</span> <span class="ml-auto text-muted small">50 mastered</span></li>
                    <li class="list-group-item d-flex align-items-center">🏆 &nbsp;<span>Master Groundskeeper</span> <span class="ml-auto text-muted small">100 mastered</span></li>
                </ul>
            </div>
        </div>

    </div>

    <!-- ── Right Column: Badges + Category Accuracy ── -->
    <div class="col-lg-8">

        <!-- Badges Grid -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <h6 class="card-title mb-0">Badges</h6>
                <span class="badge bg-success ms-1" id="badgeCountBadge" style="display:none"></span>
            </div>
            <div class="card-body">
                <div class="row g-3" id="badgesGrid">
                    <div class="col-12 text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Loading badges…
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Accuracy -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Accuracy by Category</h6>
            </div>
            <div class="card-body">
                <div id="categoryAccuracy">
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Loading…
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ── Load stats ────────────────────────────────────────────────────────────────
async function loadProgress() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=my_stats');
        const d = await r.json();
        if (!d.success) return;

        const mastered = d.total_mastered || 0;
        const accuracy = d.accuracy_pct   || 0;

        document.getElementById('progMastered').textContent = mastered;
        document.getElementById('progAccuracy').textContent = accuracy + '%';

        // Rank tier
        const t = d.rank_tier;
        if (t) {
            document.getElementById('rankTierIcon').textContent  = t.icon;
            document.getElementById('rankTierName').textContent  = t.name;

            // Highlight current rank in ladder
            document.querySelectorAll('#rankLadder .list-group-item').forEach((li, i) => {
                li.classList.toggle('mw-prog-ladder-current', i + 1 === t.tier);
            });

            if (t.next_at) {
                const from    = [0, 10, 25, 50][t.tier - 1] || 0;
                const span    = t.next_at - from;
                const done    = mastered - from;
                const pct     = Math.min(100, Math.round((done / span) * 100));
                document.getElementById('rankTierProgress').textContent =
                    `${mastered} / ${t.next_at} mastered → ${t.next_name}`;
                document.getElementById('rankTierBar').style.width = pct + '%';
            } else {
                document.getElementById('rankTierProgress').innerHTML =
                    '<span class="text-success fw-bold">Maximum rank achieved! 🏆</span>';
                document.getElementById('rankTierBar').style.width = '100%';
            }
        }

        // Streak
        const cs = d.current_streak || 0;
        const ls = d.longest_streak || 0;
        document.getElementById('currentStreak').textContent = cs;
        document.getElementById('longestStreak').textContent = ls;
        if (cs > 0) {
            document.getElementById('streakTip').textContent =
                cs >= 7  ? `🔥 ${cs} days strong! Keep it up!` :
                cs >= 3  ? `🌟 ${cs}-day streak — building momentum!` :
                           `Day ${cs} — keep going!`;
        }

        // Category accuracy
        renderCategoryAccuracy(d.category_accuracy || []);

    } catch(e) {
        console.error('loadProgress error', e);
    }
}

// ── Load badges ───────────────────────────────────────────────────────────────
async function loadBadges() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=user_badges');
        const d = await r.json();
        if (!d.success) return;

        const earned = d.badges.filter(b => b.earned).length;
        const total  = d.total;

        if (earned > 0) {
            const badgeBadge = document.getElementById('badgeCountBadge');
            badgeBadge.textContent    = `${earned} / ${total}`;
            badgeBadge.style.display  = '';
        }

        const grid = document.getElementById('badgesGrid');
        if (!d.badges.length) {
            grid.innerHTML = '<div class="col-12 text-muted text-center">No badges configured yet.</div>';
            return;
        }

        grid.innerHTML = d.badges.map(b => `
            <div class="col-sm-6 col-md-4">
                <div class="mw-badge-card ${b.earned ? 'mw-badge-earned' : 'mw-badge-locked'}">
                    <div class="mw-badge-card-icon">${b.icon}</div>
                    <div class="mw-badge-card-name">${escHtml(b.name)}</div>
                    <div class="mw-badge-card-desc">${escHtml(b.description)}</div>
                    ${b.earned
                        ? `<div class="mw-badge-card-earned-label">Earned ${formatDate(b.earned_at)}</div>`
                        : '<div class="mw-badge-card-locked-label">Not yet earned</div>'
                    }
                </div>
            </div>
        `).join('');

    } catch(e) {}
}

// ── Render category accuracy bars ─────────────────────────────────────────────
function renderCategoryAccuracy(cats) {
    const el = document.getElementById('categoryAccuracy');
    if (!cats.length) {
        el.innerHTML = '<div class="text-muted small text-center py-3">Play some quizzes to see your accuracy breakdown.</div>';
        return;
    }
    el.innerHTML = cats.map(c => {
        const pct = c.pct || 0;
        const colour = pct >= 80 ? 'var(--mw-green)' : pct >= 60 ? 'var(--mw-orange)' : '#dc3545';
        return `<div class="mw-prog-cat-row">
            <div class="mw-prog-cat-label">
                <span>${escHtml(c.name)}</span>
                <span class="mw-prog-cat-pct" style="color:${colour}">${pct}%</span>
            </div>
            <div class="mw-prog-acc-bar-wrap">
                <div class="mw-prog-acc-bar" style="width:${pct}%;background:${colour}"></div>
            </div>
            <div class="mw-prog-cat-sub text-muted small">${c.correct} correct of ${c.total} answers</div>
        </div>`;
    }).join('');
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDate(dt) {
    if (!dt) return '';
    const d = new Date(dt);
    return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
}

loadProgress();
loadBadges();
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
