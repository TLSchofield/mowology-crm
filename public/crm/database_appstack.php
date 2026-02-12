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

// Admin only
if (($user['role'] ?? '') !== 'admin') {
    header('Location: dashboard_appstack.php');
    exit;
}

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

</div><!-- /.tab-content -->

<script src="js/database-manager.js"></script>
<?php include 'includes/appstack_footer.php'; ?>
