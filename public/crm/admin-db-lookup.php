<?php
/**
 * TEMPORARY ADMIN UTILITY — admin-db-lookup.php
 *
 * One-off GDPR/PIPEDA-style record locator + eraser for a single email.
 * Scans the LIVE production database (INFORMATION_SCHEMA) for every table that
 * stores the target email directly, plus every table that references the
 * matching contact row via a *_contact_id column.
 *
 * This file is intentionally self-contained (no AppStack chrome) because it is
 * a throwaway forensic tool that will be deleted from the codebase once Tim
 * confirms the deletion is complete. It is admin-gated and CSRF-protected.
 *
 * DELETE NOTHING UNTIL THE ADMIN CLICKS THE BUTTON ON PRODUCTION.
 */

require_once __DIR__ . '/../loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();
if (!isAdmin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden — admin role required.";
    exit;
}

$TARGET_EMAIL = 'Annie.louie@telus.net';
$TARGET_EMAIL_LC = strtolower($TARGET_EMAIL);

$db = getDB();
$csrfToken = generateCSRFToken();

function esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Return the single-column primary key name for a table, or null if the table
 * has no single-column PK (composite/none).
 */
function primaryKeyColumn(PDO $db, $table) {
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_KEY = 'PRI'"
    );
    $stmt->execute([$table]);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return (count($cols) === 1) ? $cols[0] : null;
}

/* ------------------------------------------------------------------ *
 * PHASE 1 — DISCOVERY
 * ------------------------------------------------------------------ */

// 1a. Every column in the live DB whose name looks like an email field.
$emailColsStmt = $db->query(
    "SELECT TABLE_NAME, COLUMN_NAME
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND (COLUMN_NAME LIKE '%email%' OR COLUMN_NAME = 'mail')
      ORDER BY TABLE_NAME, COLUMN_NAME"
);
$emailColumns = $emailColsStmt->fetchAll(PDO::FETCH_ASSOC);

// Scan each email column for the target address (case-insensitive).
$emailMatches = array(); // table => array of rows (assoc)
$emailMatchMeta = array(); // table => array('cols' => [matched email cols], 'pk' => pkCol|null)
foreach ($emailColumns as $ec) {
    $tbl = $ec['TABLE_NAME'];
    $col = $ec['COLUMN_NAME'];
    $sql = "SELECT * FROM `{$tbl}` WHERE LOWER(`{$col}`) = ?";
    try {
        $st = $db->prepare($sql);
        $st->execute([$TARGET_EMAIL_LC]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Skip views or columns that can't be queried that way.
        continue;
    }
    if ($rows) {
        if (!isset($emailMatches[$tbl])) {
            $emailMatches[$tbl] = array();
            $emailMatchMeta[$tbl] = array('cols' => array(), 'pk' => primaryKeyColumn($db, $tbl));
        }
        $emailMatchMeta[$tbl]['cols'][] = $col;
        // De-dupe rows by PK if available, else append.
        $pk = $emailMatchMeta[$tbl]['pk'];
        foreach ($rows as $r) {
            if ($pk !== null && isset($r[$pk])) {
                $emailMatches[$tbl][(string)$r[$pk]] = $r;
            } else {
                $emailMatches[$tbl][] = $r;
            }
        }
    }
}

// 1b. Resolve contact id(s) from the `contacts` table for this email.
$contactIds = array();
if (isset($emailMatches['contacts'])) {
    $pk = $emailMatchMeta['contacts']['pk'];
    if ($pk !== null) {
        foreach ($emailMatches['contacts'] as $r) {
            if (isset($r[$pk])) {
                $contactIds[(int)$r[$pk]] = (int)$r[$pk];
            }
        }
    }
}
$contactIds = array_values($contactIds);

// 1c. Every column that references a contact (contact_id, site_contact_id, etc.)
$contactRefMatches = array(); // table => array('col' => col, 'pk' => pk, 'rows' => rows)
if (!empty($contactIds)) {
    $refColsStmt = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND COLUMN_NAME LIKE '%contact_id'
          ORDER BY TABLE_NAME, COLUMN_NAME"
    );
    $refCols = $refColsStmt->fetchAll(PDO::FETCH_ASSOC);

    $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
    foreach ($refCols as $rc) {
        $tbl = $rc['TABLE_NAME'];
        $col = $rc['COLUMN_NAME'];
        if ($tbl === 'contacts') continue; // the contacts row itself handled separately
        $sql = "SELECT * FROM `{$tbl}` WHERE `{$col}` IN ({$placeholders})";
        try {
            $st = $db->prepare($sql);
            $st->execute($contactIds);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            continue;
        }
        if ($rows) {
            $contactRefMatches[] = array(
                'table' => $tbl,
                'col'   => $col,
                'pk'    => primaryKeyColumn($db, $tbl),
                'rows'  => $rows,
            );
        }
    }
}

$totalEmailRows = 0;
foreach ($emailMatches as $rows) { $totalEmailRows += count($rows); }
$totalRefRows = 0;
foreach ($contactRefMatches as $m) { $totalRefRows += count($m['rows']); }
$totalRows = $totalEmailRows + $totalRefRows;
$hasRecords = ($totalRows > 0);

/* ------------------------------------------------------------------ *
 * PHASE 2 — DELETION (only on confirmed POST)
 * ------------------------------------------------------------------ */
$deleteReport = null;
$deleteError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $deleteError = 'CSRF token invalid — refresh the page and try again.';
    } elseif (($_POST['confirm_email'] ?? '') !== $TARGET_EMAIL) {
        $deleteError = 'Confirmation email did not match. Nothing was deleted.';
    } elseif (!$hasRecords) {
        $deleteError = 'No records found — nothing to delete.';
    } else {
        $deleted = array();
        try {
            $db->beginTransaction();
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');

            // 2a. Delete contact-referencing rows first (children).
            foreach ($contactRefMatches as $m) {
                $tbl = $m['table'];
                $col = $m['col'];
                $ph  = implode(',', array_fill(0, count($contactIds), '?'));
                $st  = $db->prepare("DELETE FROM `{$tbl}` WHERE `{$col}` IN ({$ph})");
                $st->execute($contactIds);
                $deleted[] = array('table' => $tbl, 'where' => "`{$col}` IN (contact ids)", 'rows' => $st->rowCount());
            }

            // 2b. Delete every direct email match (non-contacts tables first, contacts last).
            $orderedTables = array();
            foreach (array_keys($emailMatches) as $t) {
                if ($t !== 'contacts') $orderedTables[] = $t;
            }
            if (isset($emailMatches['contacts'])) $orderedTables[] = 'contacts';

            foreach ($orderedTables as $tbl) {
                $cols = array_unique($emailMatchMeta[$tbl]['cols']);
                foreach ($cols as $col) {
                    $st = $db->prepare("DELETE FROM `{$tbl}` WHERE LOWER(`{$col}`) = ?");
                    $st->execute([$TARGET_EMAIL_LC]);
                    $deleted[] = array('table' => $tbl, 'where' => "LOWER(`{$col}`) = email", 'rows' => $st->rowCount());
                }
            }

            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            $db->commit();

            // Audit trail.
            try {
                logActivity((int)$user['id'], null, 'gdpr_erasure',
                    'Erased all records for ' . $TARGET_EMAIL . ' via admin-db-lookup.php');
            } catch (Throwable $e) { /* non-fatal */ }

            $deleteReport = $deleted;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e2) {}
            $deleteError = 'Deletion failed and was rolled back: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin DB Lookup — <?= esc($TARGET_EMAIL) ?></title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background:#f4f6f8; color:#1a2b3c; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  .sub { color:#5a6b7c; margin: 0 0 24px; font-size:.9rem; }
  .card { background:#fff; border:1px solid #dde3e9; border-radius:8px; margin-bottom:18px; overflow:hidden; }
  .card h2 { font-size:1rem; margin:0; padding:12px 16px; background:#0D3B2E; color:#fff; }
  .card .body { padding:0; overflow-x:auto; }
  table { border-collapse:collapse; width:100%; font-size:.8rem; }
  th, td { border-bottom:1px solid #eef2f5; padding:6px 10px; text-align:left; vertical-align:top; white-space:nowrap; }
  th { background:#f0f4f7; position:sticky; top:0; }
  td { max-width:280px; overflow:hidden; text-overflow:ellipsis; }
  .pill { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.72rem; font-weight:600; }
  .pill.green { background:#E8F3F0; color:#1A5F4A; }
  .pill.red { background:#fdecec; color:#b3261e; }
  .summary { background:#fff; border:1px solid #dde3e9; border-radius:8px; padding:16px; margin-bottom:18px; }
  .danger { background:#fdecec; border:1px solid #f5c6cb; color:#b3261e; padding:16px; border-radius:8px; margin-bottom:18px; }
  .ok { background:#E8F3F0; border:1px solid #b7dccd; color:#1A5F4A; padding:16px; border-radius:8px; margin-bottom:18px; }
  .delbtn { background:#b3261e; color:#fff; border:none; padding:12px 22px; border-radius:6px; font-size:1rem; font-weight:600; cursor:pointer; }
  .delbtn:hover { background:#8c1d17; }
  input[type=text] { padding:8px 10px; border:1px solid #c3ccd5; border-radius:5px; font-size:.95rem; width:280px; }
  code { background:#eef2f5; padding:1px 5px; border-radius:3px; font-size:.85em; }
  .muted { color:#7a8794; font-size:.8rem; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Admin DB Lookup &amp; Erasure</h1>
  <p class="sub">Target email: <code><?= esc($TARGET_EMAIL) ?></code> &nbsp;·&nbsp; live scan of <?= count($emailColumns) ?> email-bearing columns &nbsp;·&nbsp; logged in as <?= esc($user['email']) ?></p>

<?php if ($deleteError): ?>
  <div class="danger"><strong>Error:</strong> <?= esc($deleteError) ?></div>
<?php endif; ?>

<?php if ($deleteReport !== null): ?>
  <div class="ok">
    <strong>Deletion complete.</strong> The following rows were permanently removed:
    <table style="margin-top:10px;background:#fff;">
      <tr><th>Table</th><th>Condition</th><th>Rows deleted</th></tr>
      <?php foreach ($deleteReport as $d): ?>
        <tr><td><code><?= esc($d['table']) ?></code></td><td><?= esc($d['where']) ?></td><td><?= (int)$d['rows'] ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="margin-bottom:0;">Re-run the page to confirm zero records remain, then ask Claude to remove this script from the codebase.</p>
  </div>
<?php endif; ?>

  <div class="summary">
    <strong>Summary:</strong>
    <?php if ($hasRecords): ?>
      <span class="pill red"><?= $totalRows ?> total row(s) found</span>
      &nbsp; <?= $totalEmailRows ?> direct email match(es) across <?= count($emailMatches) ?> table(s),
      <?= $totalRefRows ?> contact-reference row(s) across <?= count($contactRefMatches) ?> table(s).
      <?php if (!empty($contactIds)): ?>
        &nbsp; Contact ID(s): <code><?= esc(implode(', ', $contactIds)) ?></code>.
      <?php endif; ?>
    <?php else: ?>
      <span class="pill green">No records found</span> — this email does not exist anywhere in the database.
    <?php endif; ?>
  </div>

<?php if ($hasRecords && $deleteReport === null): ?>

  <?php foreach ($emailMatches as $tbl => $rows): ?>
    <div class="card">
      <h2>Direct email match — <code style="color:#7FD858;"><?= esc($tbl) ?></code> (<?= count($rows) ?> row(s), column: <?= esc(implode(', ', array_unique($emailMatchMeta[$tbl]['cols']))) ?>)</h2>
      <div class="body">
        <?php $first = reset($rows); ?>
        <table>
          <tr><?php foreach (array_keys($first) as $c): ?><th><?= esc($c) ?></th><?php endforeach; ?></tr>
          <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($r as $v): ?><td title="<?= esc($v) ?>"><?= esc(mb_strimwidth((string)$v, 0, 120, '…')) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <?php foreach ($contactRefMatches as $m): ?>
    <div class="card">
      <h2>Contact reference — <code style="color:#7FD858;"><?= esc($m['table']) ?></code>.<?= esc($m['col']) ?> (<?= count($m['rows']) ?> row(s))</h2>
      <div class="body">
        <?php $first = reset($m['rows']); ?>
        <table>
          <tr><?php foreach (array_keys($first) as $c): ?><th><?= esc($c) ?></th><?php endforeach; ?></tr>
          <?php foreach ($m['rows'] as $r): ?>
            <tr><?php foreach ($r as $v): ?><td title="<?= esc($v) ?>"><?= esc(mb_strimwidth((string)$v, 0, 120, '…')) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="danger">
    <h2 style="margin-top:0;">⚠ Permanent deletion</h2>
    <p>Clicking the button below will <strong>permanently delete</strong> every row shown above:
       all <?= $totalRefRows ?> contact-reference row(s) first, then all <?= $totalEmailRows ?> direct
       email-match row(s) (the <code>contacts</code> row last). This runs inside a single transaction
       with <code>FOREIGN_KEY_CHECKS=0</code> and rolls back entirely on any error. <strong>This cannot be undone.</strong></p>
    <form method="post" onsubmit="return confirm('PERMANENTLY delete all records for <?= esc($TARGET_EMAIL) ?>? This cannot be undone.');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
      <p>Type the email <code><?= esc($TARGET_EMAIL) ?></code> to confirm:<br>
         <input type="text" name="confirm_email" autocomplete="off" placeholder="<?= esc($TARGET_EMAIL) ?>" required></p>
      <button type="submit" class="delbtn">Permanently delete all <?= $totalRows ?> record(s)</button>
    </form>
  </div>

<?php endif; ?>

  <p class="muted">Temporary forensic tool — delete this file (<code>public/crm/admin-db-lookup.php</code>) from the codebase once erasure is confirmed.</p>
</div>
</body>
</html>
