<?php
/**
 * Dev Context API — Claude Code session bootstrap
 * GET /crm/api/dev-context.php?token=CLAUDE_CONTEXT_TOKEN
 *
 * Returns a compact JSON snapshot of the live DB schema, quiz/cert IDs,
 * active employees, and recent errors — so Claude doesn't need to read
 * migration files or rediscover table structures each session.
 *
 * Auth: token-only (no CRM session required — called from shell hooks).
 * Token is defined as CLAUDE_CONTEXT_TOKEN in app_config/secrets.php.
 * NEVER expose this endpoint without a valid token check.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$appConfigDir = dirname(__DIR__, 2) . '/app_config';
require_once $appConfigDir . '/secrets.php';
require_once $appConfigDir . '/config.php';

// ── Token auth ────────────────────────────────────────────────────────────────
$expectedToken = defined('CLAUDE_CONTEXT_TOKEN') ? CLAUDE_CONTEXT_TOKEN : null;
$providedToken = $_GET['token'] ?? '';

if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db  = getDB();
$out = [];

// ── 1. Key table schemas (information_schema) ─────────────────────────────────
$keyTables = [
    'users', 'contacts', 'companies', 'properties',
    'invoices', 'invoice_line_items', 'quotes', 'quote_line_items',
    'jobs', 'job_visits', 'job_plans',
    'quiz_questions', 'quiz_question_options', 'quiz_categories',
    'cert_tiers', 'cert_courses', 'cert_course_questions',
    'cert_exam_sessions', 'cert_exam_answers', 'cert_records',
    'activity_log', 'system_log', 'stripe_payments',
    'service_types', 'time_clock_entries',
];

$placeholders = implode(',', array_fill(0, count($keyTables), '?'));
$colStmt = $db->prepare("
    SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ($placeholders)
    ORDER BY TABLE_NAME, ORDINAL_POSITION
");
$colStmt->execute($keyTables);
$colRows = $colStmt->fetchAll(PDO::FETCH_ASSOC);

$schemas = [];
foreach ($colRows as $row) {
    $t = $row['TABLE_NAME'];
    if (!isset($schemas[$t])) $schemas[$t] = [];
    // Compact: "col_name type [NOT NULL] [DEFAULT x] [AUTO_INCREMENT]"
    $def = $row['COLUMN_NAME'] . ' ' . $row['COLUMN_TYPE'];
    if ($row['IS_NULLABLE'] === 'NO') $def .= ' NOT NULL';
    if ($row['COLUMN_DEFAULT'] !== null) $def .= ' DEFAULT ' . $row['COLUMN_DEFAULT'];
    if ($row['EXTRA']) $def .= ' ' . strtoupper($row['EXTRA']);
    $schemas[$t][] = $def;
}
$out['schemas'] = $schemas;

// ── 2. All table names (for discovery) ───────────────────────────────────────
$allTables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$out['all_tables'] = $allTables;

// ── 3. Quiz categories ────────────────────────────────────────────────────────
try {
    $out['quiz_categories'] = $db->query("SELECT id, name, slug FROM quiz_categories ORDER BY sort_order")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $out['quiz_categories'] = [];
}

// ── 4. Cert tiers + courses ───────────────────────────────────────────────────
try {
    $out['cert_tiers'] = $db->query("SELECT id, tier_level, name, slug FROM cert_tiers ORDER BY tier_level")
        ->fetchAll(PDO::FETCH_ASSOC);
    $out['cert_courses'] = $db->query("
        SELECT cc.id, cc.slug, cc.name, cc.pass_score_pct, cc.min_questions, cc.max_questions,
               ct.slug AS tier_slug,
               (SELECT COUNT(*) FROM cert_course_questions ccq WHERE ccq.course_id = cc.id) AS question_count
        FROM cert_courses cc
        JOIN cert_tiers ct ON ct.id = cc.tier_id
        ORDER BY ct.tier_level, cc.sort_order
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $out['cert_tiers']   = [];
    $out['cert_courses'] = [];
}

// ── 5. Active employees ───────────────────────────────────────────────────────
$out['employees'] = $db->query("
    SELECT id, COALESCE(NULLIF(TRIM(CONCAT(first_name,' ',last_name)),''), full_name) AS name,
           role, email, is_active,
           pesticide_training_required
    FROM users
    WHERE is_active = 1
    ORDER BY role DESC, name
")->fetchAll(PDO::FETCH_ASSOC);

// ── 6. Recent system log errors ───────────────────────────────────────────────
try {
    $out['recent_errors'] = $db->query("
        SELECT level, source, message, created_at
        FROM system_log
        WHERE level IN ('error','warning')
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $out['recent_errors'] = [];
}

// ── 7. Pending migrations (files not yet tracked in migrations_log) ───────────
try {
    $ran = $db->query("SELECT migration_name FROM migrations_log")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $ran = [];
}
$out['pending_migrations'] = array_values(array_filter(
    array_map('basename', glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []),
    fn($f) => !in_array($f, $ran, true)
));

// ── 8. Meta ───────────────────────────────────────────────────────────────────
$out['meta'] = [
    'generated_at' => date('Y-m-d H:i:s T'),
    'db_version'   => $db->query("SELECT VERSION()")->fetchColumn(),
    'table_count'  => count($allTables),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
