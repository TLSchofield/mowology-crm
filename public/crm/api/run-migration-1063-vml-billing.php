<?php
/**
 * Migration 1063 — repair VML PM-managed billing routing data.
 *
 * Two targeted, idempotent fixes (admin-only, safe to re-run):
 *   1. Brandon Lodge (property 75) was linked to client 2088 (Ron Harvie's
 *      individual account) instead of 2064 (VANCOUVER MANAGEMENT LTD), unlike
 *      its sibling buildings Oakridge/Mohawk. Re-point it to 2064.
 *   2. VML's account (client 2064) was missing its accounts contact in
 *      client_contacts — it only had Ron Harvie (receives_invoices=0). Add Jodi
 *      Peacock (contact 573, invoices@vml.bc.ca) as the invoice recipient.
 *
 * Generic by id so it is data-correct regardless of environment quirks; each
 * step checks current state and only writes when needed.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();
$results = [];

// ── Fix 1: re-point Brandon Lodge (property 75) → client 2064 (VML) ──────────
try {
    $cur = $db->prepare("SELECT client_id FROM properties WHERE id = 75");
    $cur->execute();
    $curClient = $cur->fetchColumn();
    if ($curClient === false) {
        $results[] = ['step' => 'brandon property_id 75', 'status' => 'not_found'];
    } elseif ((int)$curClient === 2064) {
        $results[] = ['step' => 'brandon client_id', 'status' => 'already_2064'];
    } else {
        $db->prepare("UPDATE properties SET client_id = 2064 WHERE id = 75")->execute();
        $results[] = ['step' => 'brandon client_id', 'status' => 'updated', 'from' => (int)$curClient, 'to' => 2064];
    }
} catch (Throwable $e) {
    $results[] = ['step' => 'brandon client_id', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── Fix 2: ensure Jodi Peacock (573) is an invoice recipient on client 2064 ──
try {
    $exists = $db->prepare("SELECT id, receives_invoices FROM client_contacts WHERE client_id = 2064 AND contact_id = 573 LIMIT 1");
    $exists->execute();
    $row = $exists->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if ((int)$row['receives_invoices'] !== 1) {
            $db->prepare("UPDATE client_contacts SET receives_invoices = 1 WHERE id = ?")->execute([$row['id']]);
            $results[] = ['step' => 'jodi on client 2064', 'status' => 'flag_set', 'cc_id' => (int)$row['id']];
        } else {
            $results[] = ['step' => 'jodi on client 2064', 'status' => 'already_present'];
        }
    } else {
        // Build an INSERT from whatever columns this environment's client_contacts has.
        $cols = array_column($db->query("SHOW COLUMNS FROM client_contacts")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $vals = [
            'client_id'         => 2064,
            'contact_id'        => 573,
            'role'              => 'billing',
            'is_primary'        => 0,
            'receives_invoices' => 1,
        ];
        $insCols = []; $insPh = []; $insArgs = [];
        foreach ($vals as $c => $v) {
            if (in_array($c, $cols, true)) { $insCols[] = $c; $insPh[] = '?'; $insArgs[] = $v; }
        }
        $db->prepare("INSERT INTO client_contacts (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insPh) . ")")
           ->execute($insArgs);
        $results[] = ['step' => 'jodi on client 2064', 'status' => 'inserted', 'columns' => $insCols];
    }
} catch (Throwable $e) {
    $results[] = ['step' => 'jodi on client 2064', 'status' => 'error', 'msg' => $e->getMessage()];
}

$ok = !in_array('error', array_column($results, 'status'), true);
echo json_encode(['migration' => 1063, 'ok' => $ok, 'results' => $results], JSON_PRETTY_PRINT);
