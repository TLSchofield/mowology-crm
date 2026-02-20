<?php
/**
 * /crm/database_appstack.php
 * Database Authority Dashboard
 *
 * Admin-only page providing:
 * - Schema browser (from cached snapshot)
 * - Migration management (pending, execute, history)
 * - Drift detection (compare snapshots)
 * - Snapshot generation trigger
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('database.manage');

$pageTitle = 'Database Manager';
$activePage = 'database';
$csrfToken = generateCSRFToken();
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include 'includes/appstack_head.php'; ?>

<!-- Page Header -->
<div class="mw-page-header d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">Database Manager</h1>
        <p class="text-muted mb-0">Schema authority, migrations, and drift detection.</p>
    </div>
    <button class="btn btn-primary" id="btnGenerateSnapshot" onclick="generateSnapshot()">
        <i data-feather="refresh-cw" style="width:16px;height:16px;"></i> Generate Snapshot
    </button>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="overview-tab" data-toggle="tab" href="#tab-overview" role="tab">
            <i data-feather="activity" style="width:16px;height:16px;"></i> Overview
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="schema-tab" data-toggle="tab" href="#tab-schema" role="tab">
            <i data-feather="layers" style="width:16px;height:16px;"></i> Schema Browser
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="migrations-tab" data-toggle="tab" href="#tab-migrations" role="tab">
            <i data-feather="git-merge" style="width:16px;height:16px;"></i> Migrations
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="drift-tab" data-toggle="tab" href="#tab-drift" role="tab">
            <i data-feather="git-pull-request" style="width:16px;height:16px;"></i> Drift Detection
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="crons-tab" data-toggle="tab" href="#tab-crons" role="tab">
            <i data-feather="clock" style="width:16px;height:16px;"></i> Cron Jobs
        </a>
    </li>
</ul>

<!-- Alert container -->
<div id="dbAlertContainer"></div>

<!-- Tab Content -->
<div class="tab-content">

    <!-- ═══ OVERVIEW TAB ════════════════════════════════════════════ -->
    <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">

        <!-- DB Health -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card mw-db-stat-card">
                    <div class="card-body text-center">
                        <i data-feather="database" class="mb-2" style="width:28px;height:28px;color:var(--mw-green);"></i>
                        <h6 class="text-muted mb-1">Database</h6>
                        <p class="h5 mb-0" id="ovDbName">
                            <span class="spinner-border spinner-border-sm"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card mw-db-stat-card">
                    <div class="card-body text-center">
                        <i data-feather="server" class="mb-2" style="width:28px;height:28px;color:var(--mw-green);"></i>
                        <h6 class="text-muted mb-1">MySQL Version</h6>
                        <p class="h5 mb-0" id="ovMysqlVersion">
                            <span class="spinner-border spinner-border-sm"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card mw-db-stat-card">
                    <div class="card-body text-center">
                        <i data-feather="grid" class="mb-2" style="width:28px;height:28px;color:var(--mw-green);"></i>
                        <h6 class="text-muted mb-1">Tables</h6>
                        <p class="h5 mb-0" id="ovTableCount">
                            <span class="spinner-border spinner-border-sm"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card mw-db-stat-card">
                    <div class="card-body text-center">
                        <i data-feather="hard-drive" class="mb-2" style="width:28px;height:28px;color:var(--mw-green);"></i>
                        <h6 class="text-muted mb-1">Total Size</h6>
                        <p class="h5 mb-0" id="ovTotalSize">
                            <span class="spinner-border spinner-border-sm"></span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Schema Summary</h5></div>
                    <div class="card-body" id="ovSchemaSummary">
                        <div class="text-center py-3">
                            <span class="spinner-border spinner-border-sm"></span>
                            <p class="text-muted mt-2 mb-0 small">Loading snapshot...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Snapshot Info</h5></div>
                    <div class="card-body" id="ovSnapshotInfo">
                        <div class="text-center py-3">
                            <span class="spinner-border spinner-border-sm"></span>
                            <p class="text-muted mt-2 mb-0 small">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MySQL 5.7 Restrictions Reminder -->
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">MySQL 5.7 Restrictions</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-danger mb-2">Do NOT use:</h6>
                        <ul class="small mb-0">
                            <li>CTE (<code>WITH</code>)</li>
                            <li>Window functions (<code>ROW_NUMBER()</code>, <code>RANK()</code>)</li>
                            <li>JSON indexing / <code>JSON_EXTRACT()</code></li>
                            <li>CHECK constraints</li>
                            <li>Generated columns for JSON extraction</li>
                            <li>Recursive queries</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-success mb-2">Must use:</h6>
                        <ul class="small mb-0">
                            <li>Standard JOINs</li>
                            <li>Prepared statements (<code>?</code> placeholders)</li>
                            <li>INT / VARCHAR / DATETIME indexes</li>
                            <li><code>ON DUPLICATE KEY UPDATE</code> where compatible</li>
                            <li><code>utf8mb4_general_ci</code> collation</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ SCHEMA BROWSER TAB ══════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-schema" role="tabpanel">

        <!-- Search -->
        <div class="mb-3">
            <input type="text" class="form-control mw-db-search" id="schemaSearch"
                   placeholder="Search tables..." oninput="filterTables(this.value)">
        </div>

        <!-- Table List -->
        <div id="schemaLoading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm"></span>
            <p class="text-muted mt-2 mb-0 small">Loading schema...</p>
        </div>
        <div id="schemaContent" style="display:none;">
            <div id="schemaTableList"></div>
        </div>
        <div id="schemaNoSnapshot" style="display:none;" class="alert alert-warning">
            No schema snapshot found. Click "Generate Snapshot" to create one.
        </div>
    </div>

    <!-- ═══ MIGRATIONS TAB ══════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-migrations" role="tabpanel">

        <!-- Pending Migrations -->
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title mb-0">Pending Migrations (<span id="migPendingCount">0</span>)</h5>
            </div>
            <div class="card-body">
                <div id="migPendingLoading" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm"></span>
                    <p class="text-muted mt-2 mb-0 small">Loading migrations...</p>
                </div>
                <div id="migPendingContainer" style="display:none;">
                    <div id="migPendingList" class="row"></div>
                    <div id="migNoPending" class="alert alert-info" style="display:none;">
                        All migrations have been applied. Your database is up to date.
                    </div>
                </div>
            </div>
        </div>

        <!-- Migration History -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Migration History</h5>
                <select id="migHistoryFilter" class="form-control form-control-sm" style="max-width:180px;"
                        onchange="loadMigrationHistory(this.value)">
                    <option value="all">All</option>
                    <option value="success">Success</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <div class="card-body p-0">
                <div id="migHistoryLoading" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm"></span>
                    <p class="text-muted mt-2 mb-0 small">Loading history...</p>
                </div>
                <div id="migHistoryContainer" style="display:none;">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Migration</th>
                                    <th>Status</th>
                                    <th>Executed By</th>
                                    <th>Executed At</th>
                                </tr>
                            </thead>
                            <tbody id="migHistoryList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ DRIFT DETECTION TAB ═════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-drift" role="tabpanel">

        <!-- Controls -->
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title mb-0">Compare Snapshots</h5></div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Select a historical snapshot to compare against the current schema.
                    Differences in tables, columns, indexes, and foreign keys will be highlighted.
                </p>
                <div class="d-flex align-items-end">
                    <div class="mr-3" style="min-width:300px;">
                        <label class="small text-muted">Compare current schema against:</label>
                        <select id="driftHistorySelect" class="form-control form-control-sm">
                            <option value="">-- Select a snapshot --</option>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-primary" onclick="runDriftComparison()">
                        <i data-feather="git-pull-request" style="width:14px;height:14px;"></i> Compare
                    </button>
                </div>
            </div>
        </div>

        <!-- Drift Results -->
        <div id="driftLoading" style="display:none;" class="text-center py-4">
            <span class="spinner-border spinner-border-sm"></span>
            <p class="text-muted mt-2 mb-0 small">Comparing schemas...</p>
        </div>
        <div id="driftResults" style="display:none;"></div>
        <div id="driftNoHistory" style="display:none;" class="alert alert-info">
            No historical snapshots found. Generate a snapshot, wait for the next day, then generate another to enable drift detection.
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         CRON JOBS TAB
    ════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-crons" role="tabpanel">

        <!-- Summary bar -->
        <div class="mw-cron-summary-bar d-flex align-items-center justify-content-between mb-3">
            <div class="mw-cron-summary-stat">
                <i data-feather="clock" class="mw-cron-summary-icon"></i>
                <strong>7</strong>&nbsp;cron jobs configured
            </div>
            <div class="mw-cron-summary-stat">
                <i data-feather="sun" class="mw-cron-summary-icon"></i>
                Daily execution window
            </div>
            <div class="mw-cron-summary-stat">
                <i data-feather="server" class="mw-cron-summary-icon"></i>
                cPanel hosted
            </div>
        </div>

        <!-- 24-hour timeline card -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center">
                <i data-feather="bar-chart-2" style="width:16px;height:16px;" class="mr-2"></i>
                <h5 class="card-title mb-0">Daily Execution Timeline</h5>
            </div>
            <div class="card-body">
                <div class="mw-cron-timeline">
                    <div class="mw-cron-timeline-track">
                        <?php foreach ([0,2,4,6,8,10,12,14,16,18,20,22,24] as $h): ?>
                        <span class="mw-cron-timeline-label" style="left:<?= ($h/24)*100 ?>%"><?= $h ?>h</span>
                        <?php endforeach; ?>
                        <!-- Generate Visits: every 6h (0, 6, 12, 18) -->
                        <div class="mw-cron-timeline-tick mw-tick-jobs" style="left:0%"        title="Generate Visits — 00:00">GV</div>
                        <div class="mw-cron-timeline-tick mw-tick-jobs" style="left:25%"       title="Generate Visits — 06:00">GV</div>
                        <div class="mw-cron-timeline-tick mw-tick-jobs" style="left:50%"       title="Generate Visits — 12:00">GV</div>
                        <div class="mw-cron-timeline-tick mw-tick-jobs" style="left:75%"       title="Generate Visits — 18:00">GV</div>
                        <!-- Auto Rollover: 23:00 -->
                        <div class="mw-cron-timeline-tick mw-tick-jobs" style="left:<?= (23/24)*100 ?>%" title="Auto Rollover — 23:00">AR</div>
                        <!-- Weather Guard: 12:00 (offset slightly so it doesn't overlap GV) -->
                        <div class="mw-cron-timeline-tick mw-tick-jobs mw-tick-offset" style="left:50%" title="Weather Guard — 12:00">WG</div>
                        <!-- Schema Snapshot: 04:00 -->
                        <div class="mw-cron-timeline-tick mw-tick-database" style="left:<?= (4/24)*100 ?>%" title="Schema Snapshot — 04:00">SS</div>
                        <!-- Purchase History: 02:00 -->
                        <div class="mw-cron-timeline-tick mw-tick-core" style="left:<?= (2/24)*100 ?>%" title="Purchase History — 02:00">PH</div>
                        <!-- SEO Recommendations: 03:00 -->
                        <div class="mw-cron-timeline-tick mw-tick-marketing" style="left:<?= (3/24)*100 ?>%" title="SEO Recommendations — 03:00">SEO</div>
                        <!-- Expense Baselines: 02:00, 1st of month — offset below PH -->
                        <div class="mw-cron-timeline-tick mw-tick-core mw-tick-monthly" style="left:<?= (2/24)*100 ?>%" title="Expense Baselines — 02:00 on 1st of month">EB</div>
                    </div>
                    <div class="mw-cron-timeline-legend mt-2">
                        <span class="mw-cron-legend-item mw-tick-jobs">Jobs</span>
                        <span class="mw-cron-legend-item mw-tick-database">Database</span>
                        <span class="mw-cron-legend-item mw-tick-marketing">Marketing</span>
                        <span class="mw-cron-legend-item mw-tick-core">Core</span>
                        <span class="mw-cron-legend-item" style="background:#78909c;color:#fff;font-size:.65rem;padding:1px 6px;border-radius:3px;">EB = monthly (1st)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cron cards grid -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3">

            <!-- ─── 1. Generate Visits ─────────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">Generate Visits</h6>
                        <span class="badge mw-badge-jobs">Jobs</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Every 6 hours</span>
                            <code class="mw-cron-schedule-expr">0 */6 * * *</code>
                        </div>
                        <p class="mw-cron-desc">Generate future visits 42 days ahead for all active recurring plans. Removed from schedule.php page load in Phase 1.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-1">php /home/mowology/app/Modules/Jobs/Cron/generate_visits.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-1').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/generate_visits.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── 2. Auto Rollover ───────────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">Auto Rollover</h6>
                        <span class="badge mw-badge-jobs">Jobs</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Daily at 11 PM</span>
                            <code class="mw-cron-schedule-expr">0 23 * * *</code>
                        </div>
                        <p class="mw-cron-desc">Roll past-due recurring visits forward 1 day. Cleans up orphaned calendar stops afterward.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-2">php /home/mowology/app/Modules/Jobs/Cron/auto_rollover.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-2').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/auto_rollover.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── 3. Weather Guard ───────────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">Weather Guard</h6>
                        <span class="badge mw-badge-jobs">Jobs</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Daily at 12 PM</span>
                            <code class="mw-cron-schedule-expr">0 12 * * *</code>
                        </div>
                        <p class="mw-cron-desc">Evaluate upcoming visits against weather forecasts. Auto-reschedules NOT_OK visits and sends SMS salt alerts for freezing/snow conditions.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-3">php /home/mowology/app/Modules/Jobs/Cron/weather_schedule_guard.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-3').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/weather_schedule_guard.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── 4. Schema Snapshot ─────────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">Schema Snapshot</h6>
                        <span class="badge mw-badge-database">Database</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Daily at 4 AM</span>
                            <code class="mw-cron-schedule-expr">0 4 * * *</code>
                        </div>
                        <p class="mw-cron-desc">Capture live DB structure (tables, columns, indexes, FKs) to storage for drift detection and schema authority.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-4">php /home/mowology/crm/cron/schema_snapshot.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-4').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/schema_snapshot.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── 5. Purchase History ────────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">Purchase History</h6>
                        <span class="badge mw-badge-core">Core</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Daily at 2 AM</span>
                            <code class="mw-cron-schedule-expr">0 2 * * *</code>
                        </div>
                        <p class="mw-cron-desc">Refresh contact&rarr;product history table, lifecycle stages, last service date, and total lifetime value from quotes and plans.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-5">php /home/mowology/crm/cron/refresh_purchase_history.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-5').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/refresh_purchase_history.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── 6. SEO Recommendations ─────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">SEO Recommendations</h6>
                        <span class="badge mw-badge-marketing">Marketing</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Daily at 3 AM</span>
                            <code class="mw-cron-schedule-expr">0 3 * * *</code>
                        </div>
                        <p class="mw-cron-desc">Read Google Search Console query stats (last 28 days), score opportunities, generate and update SEO recommendations.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-6">php /home/mowology/crm/cron/seo_recommendations.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-6').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/seo_recommendations.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── 7. Expense Baselines ───────────────────────────── -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100">
                    <div class="card-header mw-cron-card-header">
                        <h6 class="mw-cron-card-title mb-1">Expense Baselines</h6>
                        <span class="badge mw-badge-core">Core</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human">Monthly &mdash; 1st at 2 AM</span>
                            <code class="mw-cron-schedule-expr">0 2 1 * *</code>
                        </div>
                        <p class="mw-cron-desc">Aggregate monthly expense averages per accounting category (last 2 years) into baselines used by the anomaly detector.</p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-7">php /home/mowology/crm/cron/expense_baselines.php</code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-7').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-meta text-muted small mb-3">
                            <i data-feather="info" style="width:12px;height:12px;"></i>
                            Last run: <em>Not yet tracked</em>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '/crm/cron/expense_baselines.php')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.row (cron cards) -->
    </div><!-- /#tab-crons -->

</div><!-- /.tab-content -->

<script src="js/database-manager.js"></script>
<script>
// ════════════════════════════════════════════════════════════════════
// CRON MANAGER — Tab activation (Feather icons don't render in hidden tabs)
// ════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    var cronsTab = document.getElementById('crons-tab');
    if (cronsTab) {
        cronsTab.addEventListener('click', function () {
            if (typeof feather !== 'undefined') {
                setTimeout(function () { feather.replace(); }, 50);
            }
        });
    }
});

// ════════════════════════════════════════════════════════════════════
// CRON MANAGER — Copy CLI command to clipboard
// ════════════════════════════════════════════════════════════════════
function copyCronCommand(el, text) {
    text = (text || '').trim();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            showCopyFeedback(el);
        }).catch(function () {
            fallbackCopy(text);
            showCopyFeedback(el);
        });
    } else {
        fallbackCopy(text);
        showCopyFeedback(el);
    }
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
}

function showCopyFeedback(el) {
    var orig = el.innerHTML;
    el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    el.classList.add('mw-cron-copy-btn--copied');
    setTimeout(function () {
        el.innerHTML = orig;
        el.classList.remove('mw-cron-copy-btn--copied');
        if (typeof feather !== 'undefined') feather.replace();
    }, 2000);
}

// ════════════════════════════════════════════════════════════════════
// CRON MANAGER — Run Now (POST to web shim, show result inline)
// ════════════════════════════════════════════════════════════════════
function runCron(el, url) {
    var card     = el.closest('.mw-cron-card');
    var resultEl = card ? card.querySelector('.mw-cron-result') : null;

    // Disable button + show spinner
    el.disabled = true;
    var origHtml = el.innerHTML;
    el.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Running\u2026';

    if (resultEl) { resultEl.style.display = 'none'; resultEl.innerHTML = ''; }

    // CSRF token from meta tag (set by database_appstack.php line 23)
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // AbortController — 35s timeout (longer than PHP default 30s to catch near-timeouts)
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var tid = controller ? setTimeout(function () { controller.abort(); }, 35000) : null;

    var fetchOpts = {
        method:  'POST',
        headers: {
            'Content-Type':  'application/json',
            'X-CSRF-Token':  csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ csrf_token: csrfToken })
    };
    if (controller) fetchOpts.signal = controller.signal;

    fetch(url, fetchOpts)
        .then(function (r) {
            if (tid) clearTimeout(tid);
            var status = r.status;
            return r.json()
                .then(function (d) { return { ok: r.ok, status: status, data: d }; })
                .catch(function ()  { return { ok: r.ok, status: status, data: null }; });
        })
        .then(function (res) {
            showCronResult(resultEl, res.ok, res.data, res.status, false);
        })
        .catch(function (err) {
            if (tid) clearTimeout(tid);
            var isTimeout = err && (err.name === 'AbortError' || err.message === 'Failed to fetch');
            showCronResult(resultEl, false, null, 0, isTimeout);
        })
        .finally(function () {
            setTimeout(function () {
                el.disabled = false;
                el.innerHTML = origHtml;
                if (typeof feather !== 'undefined') feather.replace();
            }, 3000);
        });
}

function showCronResult(el, ok, data, status, isTimeout) {
    if (!el) return;
    el.style.display = 'block';

    var TIMEOUT_HTML = '<i data-feather="alert-circle" style="width:13px;height:13px;"></i>'
        + ' Cron timeout \u2014 normal for CLI-only crons. Check cPanel logs for results.';

    if (isTimeout || status === 0 || status >= 500) {
        el.className = 'mw-cron-result mw-cron-result--timeout';
        el.innerHTML = TIMEOUT_HTML;
    } else if (ok && data && data.success !== false) {
        var msg = (data.message || data.msg) ? escapeHtml(String(data.message || data.msg)) : 'Completed successfully.';
        el.className = 'mw-cron-result mw-cron-result--success';
        el.innerHTML = '<i data-feather="check-circle" style="width:13px;height:13px;"></i> ' + msg;
    } else {
        var errStr = data ? (data.error || data.message || data.msg || '') : '';
        var errMsg = errStr ? escapeHtml(String(errStr)) : 'Unexpected error (HTTP\u00a0' + status + ').';
        el.className = 'mw-cron-result mw-cron-result--error';
        el.innerHTML = '<i data-feather="x-circle" style="width:13px;height:13px;"></i> ' + errMsg;
    }

    if (typeof feather !== 'undefined') feather.replace();
}
// Note: escapeHtml() is provided by database-manager.js (loaded above) and is in global scope.
</script>
<?php include 'includes/appstack_footer.php'; ?>
