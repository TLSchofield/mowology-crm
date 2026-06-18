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
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Database Manager</h1>
        <p class="mw-page-subtitle">Schema authority, migrations, and drift detection.</p>
    </div>
    <div class="mw-page-actions">
        <button class="btn btn-primary" id="btnGenerateSnapshot" onclick="generateSnapshot()">
            <i data-feather="refresh-cw"></i> Generate Snapshot
        </button>
    </div>
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
<?php
// ── Cron registry ─────────────────────────────────────────────────────────
// Single source of truth for the cron manager. To add/edit a cron, change one
// entry here — the summary count, the timeline ticks, and the cards grid all
// render from this array, so the page can no longer drift out of sync with the
// actual scheduled jobs. `run` may be null to render a card with no "Run Now".
// `tl` is the timeline position (% of 24h, or an array for multi-run jobs);
// `freq` flags high-frequency jobs; `monthly` flags monthly/weekly cadence.
$CRON_BASE = '/usr/local/bin/php /home/mowology/public_html';
$crons = [
    ['key'=>'generate_visits','title'=>'Generate Visits','cat'=>'jobs','human'=>'Every 6 hours','expr'=>'0 */6 * * *','app'=>'/app/Modules/Jobs/Cron/generate_visits.php','run'=>'/crm/cron/generate_visits.php','abbr'=>'GV','tl'=>[0,25,50,75],'desc'=>'Generate future visits 42 days ahead for all active recurring plans. Removed from schedule.php page load in Phase 1.'],
    ['key'=>'auto_rollover','title'=>'Auto Rollover','cat'=>'jobs','human'=>'Daily at 11 PM','expr'=>'0 23 * * *','app'=>'/app/Modules/Jobs/Cron/auto_rollover.php','run'=>'/crm/cron/auto_rollover.php','abbr'=>'AR','tl'=>95.8,'desc'=>'Roll past-due recurring visits forward 1 day. Cleans up orphaned calendar stops afterward.'],
    ['key'=>'weather_guard','title'=>'Weather Guard','cat'=>'jobs','human'=>'Daily at 12 PM','expr'=>'0 12 * * *','app'=>'/app/Modules/Jobs/Cron/weather_schedule_guard.php','run'=>'/crm/cron/weather_schedule_guard.php','abbr'=>'WG','tl'=>50,'offset'=>true,'desc'=>'Evaluate upcoming visits against weather forecasts. Auto-reschedules NOT_OK visits and sends SMS salt alerts for freezing/snow conditions.'],
    ['key'=>'schema_snapshot','title'=>'Schema Snapshot','cat'=>'database','human'=>'Daily at 4 AM','expr'=>'0 4 * * *','app'=>'/crm/cron/schema_snapshot.php','run'=>'/crm/cron/schema_snapshot.php','abbr'=>'SS','tl'=>16.7,'desc'=>'Capture live DB structure (tables, columns, indexes, FKs) to storage for drift detection and schema authority.'],
    ['key'=>'purchase_history','title'=>'Purchase History','cat'=>'core','human'=>'Daily at 2 AM','expr'=>'0 2 * * *','app'=>'/crm/cron/refresh_purchase_history.php','run'=>'/crm/cron/refresh_purchase_history.php','abbr'=>'PH','tl'=>8.3,'desc'=>'Refresh contact&rarr;product history table, lifecycle stages, last service date, and total lifetime value from quotes and plans.'],
    ['key'=>'seo_recommendations','title'=>'SEO Recommendations','cat'=>'marketing','human'=>'Daily at 3 AM','expr'=>'0 3 * * *','app'=>'/crm/cron/seo_recommendations.php','run'=>'/crm/cron/seo_recommendations.php','abbr'=>'SEO','tl'=>12.5,'desc'=>'Read Google Search Console query stats (last 28 days), score opportunities, generate and update SEO recommendations.'],
    ['key'=>'expense_baselines','title'=>'Expense Baselines','cat'=>'core','human'=>'Monthly &mdash; 1st at 2 AM','expr'=>'0 2 1 * *','app'=>'/crm/cron/expense_baselines.php','run'=>'/crm/cron/expense_baselines.php','abbr'=>'EB','tl'=>8.3,'monthly'=>true,'desc'=>'Aggregate monthly expense averages per accounting category (last 2 years) into baselines used by the anomaly detector.'],
    ['key'=>'campaign_sender','title'=>'Campaign Email Sender','cat'=>'marketing','human'=>'Every 15 minutes','expr'=>'*/15 * * * *','app'=>'/app/Modules/Marketing/Cron/campaign_sender.php','run'=>'/crm/cron/campaign_sender.php','abbr'=>'CS','tl'=>60,'freq'=>true,'desc'=>'Process pending campaign sends (max 20/run for SMTP throttle). Renders templates, sends email, updates tracking.'],
    ['key'=>'automation_runner','title'=>'Automation Runner','cat'=>'marketing','human'=>'Every 5 minutes','expr'=>'*/5 * * * *','app'=>'/app/Modules/Marketing/Cron/automation_runner.php','run'=>'/crm/cron/automation_runner.php','abbr'=>'AR5','tl'=>20,'freq'=>true,'desc'=>'Process queued_actions and evaluate automation rules against recent CRM events. Max 50 queue items per run.'],
    ['key'=>'invoice_overdue','title'=>'Invoice Overdue','cat'=>'marketing','human'=>'Daily at 8 AM','expr'=>'0 8 * * *','app'=>'/app/Modules/Marketing/Cron/invoice_overdue.php','run'=>'/crm/cron/invoice_overdue.php','abbr'=>'IO','tl'=>33.3,'desc'=>'Mark invoices as overdue when due_date has passed and they remain unpaid. Feeds the invoice_overdue automation trigger.'],
    ['key'=>'invoice_reminders','title'=>'Invoice Reminders','cat'=>'marketing','human'=>'Daily at 9 AM','expr'=>'0 9 * * *','app'=>'/app/Modules/Marketing/Cron/invoice_reminders.php','run'=>'/crm/cron/invoice_reminders.php','abbr'=>'IR','tl'=>37.5,'offset'=>true,'desc'=>'Send email + SMS reminders for unpaid invoices at 3 stages: 3 days before due, due today, 7+ days overdue. Max 3 reminders per invoice.'],
    ['key'=>'seasonal_triggers','title'=>'Seasonal Triggers','cat'=>'marketing','human'=>'Monthly &mdash; 1st at 8 AM','expr'=>'0 8 1 * *','app'=>'/app/Modules/Marketing/Cron/seasonal_triggers.php','run'=>'/crm/cron/seasonal_triggers.php','abbr'=>'ST','tl'=>33.3,'monthly'=>true,'offset'=>true,'desc'=>'On 1st of month, create queued campaigns for products with trigger_month matching current month, targeting contacts who haven&rsquo;t purchased that product.'],
    ['key'=>'reconsent_sender','title'=>'Reconsent Sender','cat'=>'marketing','human'=>'Daily at 9 AM','expr'=>'0 9 * * *','app'=>'/crm/cron/reconsent_sender.php','run'=>'/crm/cron/reconsent_sender.php','abbr'=>'RC','tl'=>37.5,'desc'=>'Send up to 10 opt-in/reconsent emails per run from approved queue entries. Used for Jobber contact re-consent campaign.'],
    ['key'=>'social_publisher','title'=>'Social Post Publisher','cat'=>'social','human'=>'Every 5 minutes','expr'=>'*/5 * * * *','app'=>'/app/Modules/Social/Cron/social_publisher.php','run'=>'/crm/cron/social_publisher.php','abbr'=>'SP5','tl'=>80,'freq'=>true,'desc'=>'Publish due items from social_queue to Google Business and Meta. Max 10 posts per run with exponential backoff retry. Process lock prevents overlaps.'],
    ['key'=>'cms_schedule_publish','title'=>'CMS Schedule Publish','cat'=>'cms','human'=>'Every minute','expr'=>'* * * * *','app'=>'/app/Modules/CMS/Cron/cms_schedule_publish.php','run'=>'/crm/cron/cms-schedule-publish.php','abbr'=>'CP1','tl'=>40,'freq'=>true,'desc'=>'Auto-publish draft pages where publish_at &le; NOW(). Auto-archive published pages where unpublish_at &le; NOW(). Invalidates HTML cache.'],
    ['key'=>'cms_seo_recalc','title'=>'CMS SEO Recalc','cat'=>'cms','human'=>'Daily at 3 AM','expr'=>'0 3 * * *','app'=>'/app/Modules/CMS/Cron/cms_seo_recalc.php','run'=>'/crm/cron/cms-seo-recalc.php','abbr'=>'CR','tl'=>12.5,'offset'=>true,'desc'=>'Recalculate seo_score for all CMS pages via cms_getPageCompletionScore(). Useful after bulk edits or imports.'],
    ['key'=>'sync_ledger','title'=>'Accounting Ledger Sync','cat'=>'accounting','human'=>'Daily at 2 AM','expr'=>'0 2 * * *','app'=>'/app/Modules/Accounting/Cron/sync-ledger.php','run'=>'/crm/cron/sync-ledger.php','abbr'=>'LS','tl'=>8.3,'offset'=>true,'desc'=>'Pull new paid invoices and expenses into accounting_transactions. Then run AlertEngine to refresh financial intelligence alerts.'],
    ['key'=>'contract_renewal','title'=>'Contract Auto-Renewal','cat'=>'contracts','human'=>'Daily at 1 AM','expr'=>'0 1 * * *','app'=>'/app/Modules/Contracts/Cron/contract_renewal.php','run'=>'/crm/cron/contract_renewal.php','abbr'=>'CA','tl'=>4.2,'desc'=>'Auto-renew active contracts with auto_renew=1: apply increase %, push dates forward, recalculate plan pricing. Expire non-renewing contracts past end_date.'],
    ['key'=>'contract_billing','title'=>'Contract Monthly Billing','cat'=>'contracts','human'=>'1st of month at 6 AM','expr'=>'0 6 1 * *','app'=>'/app/Modules/Contracts/Cron/contract_billing.php','run'=>'/crm/cron/contract_billing.php','abbr'=>'CB','tl'=>25,'monthly'=>true,'desc'=>'Generate and email invoices for all active monthly contracts. Idempotent &mdash; skips any contract already invoiced this month. 5% GST applied, due end of month.'],
    ['key'=>'estimating_feedback','title'=>'Estimating Feedback','cat'=>'core','human'=>'Weekly &mdash; Mon at 3 AM','expr'=>'0 3 * * 1','app'=>'/crm/cron/estimating_feedback.php','run'=>'/crm/cron/estimating_feedback.php','abbr'=>'EF','tl'=>12.5,'monthly'=>true,'desc'=>'Compare quoted material costs vs actual expenses for active job plans. Writes to quote_accuracy_log and vendor_price_index for profitability dashboard.'],
    // ── Previously missing from the manager (added) ──────────────────────
    ['key'=>'autopay_charge','title'=>'Autopay Charge','cat'=>'accounting','human'=>'Daily at 9 AM','expr'=>'0 17 * * *','app'=>'/app/Modules/Invoices/Cron/autopay_charge.php','run'=>'/crm/cron/autopay_charge.php','abbr'=>'AP','tl'=>37.5,'offset'=>true,'desc'=>'Charge saved cards for due autopay invoices &mdash; gated by business_settings.autopay_live_mode. Defers Stripe calls past DB commit; idempotent per invoice.'],
    ['key'=>'etransfer_inbox_poll','title'=>'e-Transfer Inbox Poll','cat'=>'accounting','human'=>'Every 10 minutes','expr'=>'*/10 * * * *','app'=>'/app/Modules/Accounting/Cron/etransfer_inbox_poll.php','run'=>'/crm/cron/etransfer_inbox_poll.php','abbr'=>'ET','tl'=>30,'freq'=>true,'desc'=>'Poll info@ + office@ mailboxes for Interac e-Transfer notifications, parse memo/amount, and surface pending matches on the Invoices page. Never auto-records.'],
    ['key'=>'data_retention','title'=>'Data Retention','cat'=>'core','human'=>'Daily at 3 AM','expr'=>'0 3 * * *','app'=>'/app/Modules/Privacy/Cron/data_retention.php','run'=>'/crm/cron/data_retention.php','abbr'=>'DR','tl'=>12.5,'desc'=>'Apply privacy data-retention policies &mdash; purge or anonymize expired records on schedule.'],
    ['key'=>'quote_request_reminder','title'=>'Quote Request Reminder','cat'=>'core','human'=>'Every 30 minutes','expr'=>'*/30 * * * *','app'=>'/app/Modules/Quotes/Cron/quote_request_reminder.php','run'=>'/crm/cron/quote_request_reminder.php','abbr'=>'QR','tl'=>50,'freq'=>true,'desc'=>'SMS the admin for every quote_request left unactioned past the threshold, so no inbound lead goes cold.'],
    ['key'=>'auto_clockout','title'=>'Auto Clock-Out','cat'=>'team','human'=>'Every 30 minutes','expr'=>'0,30 * * * *','app'=>'/app/Modules/Team/Cron/auto_clockout.php','run'=>'/crm/cron/auto_clockout.php','abbr'=>'AC','tl'=>70,'freq'=>true,'desc'=>'Auto clock-out crew left on the clock past a max shift length, preventing runaway timer hours on payroll.'],
    ['key'=>'forgot_clockout_sms','title'=>'Forgot Clock-Out SMS','cat'=>'team','human'=>'Every 30 minutes','expr'=>'0,30 * * * *','app'=>'/app/Modules/Team/Cron/forgot_clockout_sms.php','run'=>'/crm/cron/forgot_clockout_sms.php','abbr'=>'FC','tl'=>72,'freq'=>true,'desc'=>'SMS crew who forgot to clock out, prompting them to close their timer before payroll is calculated.'],
    ['key'=>'trackimo_poll','title'=>'Trackimo GPS Poll','cat'=>'tracking','human'=>'Every minute (business hrs)','expr'=>'* * * * *','app'=>'/app/Modules/Tracking/Cron/trackimo_poll.php','run'=>'/crm/cron/trackimo_poll.php','abbr'=>'TK','tl'=>90,'freq'=>true,'desc'=>'Poll the Trackimo GPS API for truck positions (every minute during business hours, every 5 min otherwise) for live map tracking.'],
];
// Defensive: drop any malformed entry (keeps the page rendering if one is half-edited).
$crons = array_values(array_filter($crons, static fn($c) => is_array($c) && !empty($c['key']) && !empty($c['title'])));

$CRON_CAT_LABELS = [
    'jobs'=>'Jobs','database'=>'Database','marketing'=>'Marketing','core'=>'Core',
    'accounting'=>'Accounting','contracts'=>'Contracts','cms'=>'CMS','social'=>'Social',
    'team'=>'Team','tracking'=>'Tracking',
];
?>

        <!-- Summary bar -->
        <div class="mw-cron-summary-bar d-flex align-items-center justify-content-between mb-3">
            <div class="mw-cron-summary-stat">
                <i data-feather="clock" class="mw-cron-summary-icon"></i>
                <strong><?= count($crons) ?></strong>&nbsp;cron jobs configured
            </div>
            <div class="mw-cron-summary-stat" id="cronHealthStat">
                <i data-feather="loader" class="mw-cron-summary-icon"></i>
                Loading status&hellip;
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
                        <?php
                        // Render one tick per scheduled run, straight from the registry.
                        foreach ($crons as $c):
                            $positions = is_array($c['tl'] ?? null) ? $c['tl'] : [$c['tl'] ?? 0];
                            $tickClass = 'mw-tick-' . $c['cat'];
                            if (!empty($c['offset']))  { $tickClass .= ' mw-tick-offset'; }
                            if (!empty($c['monthly'])) { $tickClass .= ' mw-tick-monthly'; }
                            $extraStyle = !empty($c['freq']) ? 'font-size:.5rem;' : '';
                            $titleAttr  = htmlspecialchars($c['title'] . ' — ' . strip_tags(html_entity_decode($c['human'])), ENT_QUOTES);
                            foreach ($positions as $pos):
                        ?>
                        <div class="mw-cron-timeline-tick <?= $tickClass ?>" style="left:<?= $pos ?>%;<?= $extraStyle ?>" title="<?= $titleAttr ?>"><?= htmlspecialchars($c['abbr'] ?? '') ?></div>
                        <?php endforeach; endforeach; ?>
                    </div>
                    <div class="mw-cron-timeline-legend mt-2">
                        <?php foreach ($CRON_CAT_LABELS as $catKey => $catLabel): ?>
                        <span class="mw-cron-legend-item mw-tick-<?= $catKey ?>"><?= htmlspecialchars($catLabel) ?></span>
                        <?php endforeach; ?>
                        <span class="mw-cron-legend-item" style="background:#78909c;color:#fff;font-size:.65rem;padding:1px 6px;border-radius:3px;">monthly/weekly &amp; frequent jobs shown smaller</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cron cards grid -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3">
<?php foreach ($crons as $c):
            $catLabel = $CRON_CAT_LABELS[$c['cat']] ?? ucfirst($c['cat']);
            $cmd = htmlspecialchars($CRON_BASE . $c['app'], ENT_QUOTES);
            $titleAttr = htmlspecialchars($c['title'], ENT_QUOTES);
        ?>
            <!-- <?= htmlspecialchars($c['title']) ?> -->
            <div class="col mb-4">
                <div class="card mw-cron-card h-100" data-cron-key="<?= htmlspecialchars($c['key']) ?>">
                    <div class="card-header mw-cron-card-header">
                        <div class="d-flex align-items-center justify-content-between w-100">
                            <h6 class="mw-cron-card-title mb-0"><?= htmlspecialchars($c['title']) ?></h6>
                            <div class="d-flex align-items-center">
                                <span class="mw-cron-status-dot mr-2" title="Loading…"></span>
                                <span class="badge mw-badge-<?= htmlspecialchars($c['cat']) ?>"><?= htmlspecialchars($catLabel) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mw-cron-schedule mb-2">
                            <i data-feather="clock" class="mw-cron-schedule-icon"></i>
                            <span class="mw-cron-schedule-human"><?= $c['human'] ?></span>
                            <code class="mw-cron-schedule-expr"><?= htmlspecialchars($c['expr']) ?></code>
                        </div>
                        <p class="mw-cron-desc"><?= $c['desc'] ?></p>
                        <div class="mw-cron-command-wrap mb-3">
                            <code class="mw-cron-command" id="cmd-<?= htmlspecialchars($c['key']) ?>"><?= $cmd ?></code>
                            <button class="mw-cron-copy-btn" onclick="copyCronCommand(this, document.getElementById('cmd-<?= htmlspecialchars($c['key']) ?>').textContent)" title="Copy to clipboard">
                                <i data-feather="clipboard" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                        <div class="mw-cron-last-run small mb-1">
                            <i data-feather="clock" style="width:12px;height:12px;"></i>
                            Last run: <span class="mw-cron-ran-at text-muted"><em>Loading&hellip;</em></span>
                        </div>
                        <div class="mw-cron-last-summary small text-muted mb-3" style="display:none;"></div>
                        <div class="mt-auto">
                            <?php if (!empty($c['run'])): ?>
                            <button class="btn btn-sm mw-cron-run-btn" onclick="runCron(this, '<?= htmlspecialchars($c['run'], ENT_QUOTES) ?>')">
                                <i data-feather="play" style="width:13px;height:13px;"></i> Run Now
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-link mw-cron-history-btn p-0 ml-2" onclick="showCronHistory('<?= htmlspecialchars($c['key'], ENT_QUOTES) ?>', '<?= $titleAttr ?>')">History</button>
                            <div class="mw-cron-result mt-2" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div><!-- /.row (cron cards) -->
    </div><!-- /#tab-crons -->

    <!-- Cron History Modal -->
    <div class="modal fade" id="cronHistoryModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cronHistoryModalLabel">Cron History</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-0">
                    <div id="cronHistoryLoading" class="text-center py-4">
                        <span class="spinner-border spinner-border-sm"></span>
                        <p class="text-muted mt-2 mb-0 small">Loading history&hellip;</p>
                    </div>
                    <div id="cronHistoryContent" style="display:none;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Status</th>
                                    <th>Triggered by</th>
                                    <th>Summary</th>
                                    <th>Duration</th>
                                    <th>Ran at</th>
                                </tr>
                            </thead>
                            <tbody id="cronHistoryRows"></tbody>
                        </table>
                    </div>
                    <div id="cronHistoryEmpty" style="display:none;" class="p-4 text-center text-muted">
                        No runs recorded yet. Run the cron manually or wait for the next scheduled execution.
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.tab-content -->

<script src="js/database-manager.js"></script>
<script>
// ════════════════════════════════════════════════════════════════════
// CRON MANAGER — Tab activation + status loading
// ════════════════════════════════════════════════════════════════════
var cronStatusLoaded = false;

document.addEventListener('DOMContentLoaded', function () {
    var cronsTab = document.getElementById('crons-tab');
    if (cronsTab) {
        cronsTab.addEventListener('click', function () {
            if (typeof feather !== 'undefined') {
                setTimeout(function () { feather.replace(); }, 50);
            }
            if (!cronStatusLoaded) {
                loadCronStatus();
            }
        });
    }
});

// ════════════════════════════════════════════════════════════════════
// CRON STATUS — load latest run per cron from API, paint cards
// ════════════════════════════════════════════════════════════════════
function loadCronStatus() {
    fetch('/crm/api/cron-log.php?action=latest', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
        if (!resp.success) return;
        cronStatusLoaded = true;

        // Build lookup: cron_key → row
        var byKey = {};
        (resp.data || []).forEach(function (row) { byKey[row.cron_key] = row; });

        // Summarise overall health for the summary bar
        var total = 0, errors = 0, warnings = 0, neverRun = 0;
        document.querySelectorAll('.mw-cron-card[data-cron-key]').forEach(function (card) {
            total++;
            var key = card.dataset.cronKey;
            var row = byKey[key] || null;
            paintCronCard(card, row);
            if (!row) { neverRun++; }
            else if (row.status === 'error')   { errors++; }
            else if (row.status === 'warning') { warnings++; }
        });

        // Update summary bar health stat
        var healthEl = document.getElementById('cronHealthStat');
        if (healthEl) {
            var icon, txt, cls;
            if (errors > 0) {
                icon = 'alert-triangle'; txt = errors + ' error' + (errors > 1 ? 's' : ''); cls = 'text-danger';
            } else if (warnings > 0) {
                icon = 'alert-circle'; txt = warnings + ' warning' + (warnings > 1 ? 's' : ''); cls = 'text-warning';
            } else if (neverRun > 0) {
                icon = 'clock'; txt = neverRun + ' never run'; cls = 'text-muted';
            } else {
                icon = 'check-circle'; txt = 'All crons healthy'; cls = 'text-success';
            }
            healthEl.innerHTML = '<i data-feather="' + icon + '" class="mw-cron-summary-icon ' + cls + '"></i>'
                + '<span class="' + cls + '">' + escapeHtml(txt) + '</span>';
            if (typeof feather !== 'undefined') feather.replace();
        }
    })
    .catch(function () {
        // Silently fail — table may not exist yet on first deploy
        document.querySelectorAll('.mw-cron-card[data-cron-key]').forEach(function (card) {
            paintCronCard(card, null, true);
        });
    });
}

function paintCronCard(card, row, loadFailed) {
    var dot     = card.querySelector('.mw-cron-status-dot');
    var ranAtEl = card.querySelector('.mw-cron-ran-at');
    var summEl  = card.querySelector('.mw-cron-last-summary');

    if (loadFailed) {
        if (dot) { dot.className = 'mw-cron-status-dot mw-cron-dot-unknown'; dot.title = 'Status unavailable'; }
        if (ranAtEl) ranAtEl.innerHTML = '<em class="text-muted">Not yet tracked</em>';
        return;
    }

    if (!row) {
        // Never run
        if (dot) { dot.className = 'mw-cron-status-dot mw-cron-dot-unknown'; dot.title = 'Never run'; }
        if (ranAtEl) ranAtEl.innerHTML = '<em class="text-muted">Never run</em>';
        return;
    }

    // Status dot
    var dotClass = { success: 'mw-cron-dot-ok', warning: 'mw-cron-dot-warn', error: 'mw-cron-dot-err' }[row.status] || 'mw-cron-dot-unknown';
    var dotTitle = { success: 'Last run OK', warning: 'Last run had warnings', error: 'Last run failed' }[row.status] || row.status;
    if (dot) { dot.className = 'mw-cron-status-dot ' + dotClass; dot.title = dotTitle; }

    // Ran-at timestamp
    if (ranAtEl) {
        var ago = timeAgo(row.ran_at);
        var fullDate = formatDateTime(row.ran_at);
        var byLabel  = row.triggered_by === 'web' ? ' (manual)' : '';
        ranAtEl.innerHTML = '<span title="' + escapeHtml(fullDate) + '">' + escapeHtml(ago) + escapeHtml(byLabel) + '</span>';
    }

    // Summary line
    if (summEl && row.summary) {
        summEl.textContent = row.summary;
        summEl.style.display = '';
    }
    if (summEl && row.status === 'error' && row.error_message) {
        summEl.textContent = row.error_message;
        summEl.classList.add('text-danger');
        summEl.style.display = '';
    }
}

function timeAgo(dateStr) {
    if (!dateStr) return '—';
    var d = new Date(dateStr.replace(' ', 'T') + 'Z'); // treat stored UTC
    var now = new Date();
    var diff = Math.floor((now - d) / 1000);
    if (diff < 0)   return 'just now';
    if (diff < 60)  return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

// ════════════════════════════════════════════════════════════════════
// CRON HISTORY MODAL
// ════════════════════════════════════════════════════════════════════
function showCronHistory(key, label) {
    var modal = document.getElementById('cronHistoryModal');
    var titleEl = document.getElementById('cronHistoryModalLabel');
    var loadingEl = document.getElementById('cronHistoryLoading');
    var contentEl = document.getElementById('cronHistoryContent');
    var emptyEl   = document.getElementById('cronHistoryEmpty');
    var tbody     = document.getElementById('cronHistoryRows');

    if (!modal) return;
    if (titleEl) titleEl.textContent = label + ' — Run History';
    loadingEl.style.display = '';
    contentEl.style.display = 'none';
    emptyEl.style.display   = 'none';
    if (tbody) tbody.innerHTML = '';

    // Show modal
    if (typeof $ !== 'undefined') {
        $(modal).modal('show');
    } else {
        modal.style.display = 'block';
    }

    fetch('/crm/api/cron-log.php?action=history&key=' + encodeURIComponent(key) + '&limit=20', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
        loadingEl.style.display = 'none';
        if (!resp.success || !resp.data || resp.data.length === 0) {
            emptyEl.style.display = '';
            return;
        }
        contentEl.style.display = '';
        resp.data.forEach(function (row) {
            var statusBadge = {
                success: '<span class="badge badge-success">OK</span>',
                warning: '<span class="badge badge-warning">Warning</span>',
                error:   '<span class="badge badge-danger">Error</span>',
            }[row.status] || '<span class="badge badge-secondary">' + escapeHtml(row.status) + '</span>';

            var durText = row.duration_ms ? (row.duration_ms < 1000 ? row.duration_ms + 'ms' : (row.duration_ms / 1000).toFixed(1) + 's') : '—';
            var summary = row.status === 'error' && row.error_message
                ? '<span class="text-danger">' + escapeHtml(row.error_message.substring(0, 80)) + '</span>'
                : escapeHtml(row.summary || '—');

            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + statusBadge + '</td>'
                + '<td>' + escapeHtml(row.triggered_by || 'cron') + '</td>'
                + '<td class="small">' + summary + '</td>'
                + '<td class="text-nowrap">' + escapeHtml(durText) + '</td>'
                + '<td class="text-nowrap small">' + escapeHtml(formatDateTime(row.ran_at)) + '</td>';
            tbody.appendChild(tr);
        });
    })
    .catch(function () {
        loadingEl.style.display = 'none';
        emptyEl.style.display   = '';
    });
}

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

    // CSRF token from meta tag
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // AbortController — 35s timeout
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
            // Refresh status dots after a short delay (cron script writes log before responding)
            setTimeout(function () {
                cronStatusLoaded = false;
                loadCronStatus();
            }, 1500);
        })
        .catch(function (err) {
            if (tid) clearTimeout(tid);
            var isTimeout = err && (err.name === 'AbortError' || err.message === 'Failed to fetch');
            showCronResult(resultEl, false, null, 0, isTimeout);
            // Still refresh — cron may have logged before timing out
            if (isTimeout) {
                setTimeout(function () { cronStatusLoaded = false; loadCronStatus(); }, 2000);
            }
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

    if (isTimeout || status === 0) {
        el.className = 'mw-cron-result mw-cron-result--timeout';
        el.innerHTML = TIMEOUT_HTML;
    } else if (status >= 500 && data && (data.error || data.message || data.msg)) {
        // Server error with a real message — show it instead of the generic timeout banner
        var srvErr = escapeHtml(String(data.error || data.message || data.msg));
        el.className = 'mw-cron-result mw-cron-result--error';
        el.innerHTML = '<i data-feather="x-circle" style="width:13px;height:13px;"></i> Server error: ' + srvErr;
    } else if (status >= 500) {
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
// Note: escapeHtml() and formatDateTime() are provided by database-manager.js (loaded above).
</script>
<?php include 'includes/appstack_footer.php'; ?>
