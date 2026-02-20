<?php
/**
 * Cron: Refresh Contact Purchase History
 *
 * Scans quote_line_items (accepted quotes) and plan_line_items (active plans)
 * to build a materialized contact↔product history table. Also refreshes
 * contacts.lifecycle_stage, last_service_date, and total_lifetime_value.
 *
 * Run daily: 0 2 * * * php /home/mowology/crm/cron/refresh_purchase_history.php
 * Also callable via web POST: POST /crm/cron/refresh_purchase_history.php
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

// Auth: require admin for web access, skip for CLI
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}

try {
    $db = getDB();

    // ── Check if required tables/columns exist ─────────────────────────
    $hasHistory = false;
    try {
        $db->query("SELECT 1 FROM contact_product_history LIMIT 0");
        $hasHistory = true;
    } catch (\Exception $e) {
        // Table doesn't exist yet — migration 601 hasn't run
    }

    if (!$hasHistory) {
        $msg = 'contact_product_history table does not exist. Run migration 601 first.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // Check if quote_line_items has product_id
    $hasQliProduct = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM quote_line_items LIKE 'product_id'");
        $hasQliProduct = ($check->rowCount() > 0);
    } catch (\Exception $e) {}

    // Check if plan_line_items has product_id
    $hasPliProduct = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM plan_line_items LIKE 'product_id'");
        $hasPliProduct = ($check->rowCount() > 0);
    } catch (\Exception $e) {}

    if (!$hasQliProduct && !$hasPliProduct) {
        $msg = 'Neither quote_line_items nor plan_line_items has a product_id column.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // ── Step 1: Collect purchase data ──────────────────────────────────
    // We gather contact_id → product_id → stats from accepted quotes and active plans

    $purchases = []; // keyed by "contact_id:product_id"

    // 1a. From accepted quotes: quote_line_items → quotes → properties → contacts
    if ($hasQliProduct) {
        $stmt = $db->query("
            SELECT p.site_contact_id AS contact_id,
                   qli.product_id,
                   q.created_at AS purchase_date,
                   qli.quantity,
                   qli.line_total
            FROM quote_line_items qli
            JOIN quotes q ON q.id = qli.quote_id
            JOIN properties p ON p.id = q.property_id
            WHERE q.status = 'accepted'
              AND qli.product_id IS NOT NULL
              AND p.site_contact_id IS NOT NULL
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['contact_id'] . ':' . $row['product_id'];
            if (!isset($purchases[$key])) {
                $purchases[$key] = [
                    'contact_id'     => (int)$row['contact_id'],
                    'product_id'     => (int)$row['product_id'],
                    'first_purchased' => $row['purchase_date'],
                    'last_purchased'  => $row['purchase_date'],
                    'total_quantity'  => 0,
                    'total_revenue'   => 0,
                    'sources'         => [],
                ];
            }
            $p = &$purchases[$key];
            if ($row['purchase_date'] < $p['first_purchased']) $p['first_purchased'] = $row['purchase_date'];
            if ($row['purchase_date'] > $p['last_purchased'])  $p['last_purchased']  = $row['purchase_date'];
            $p['total_quantity'] += (float)($row['quantity'] ?? 0);
            $p['total_revenue']  += (float)($row['line_total'] ?? 0);
            $p['sources']['quote'] = true;
            unset($p);
        }
    }

    // 1b. From active plans: plan_line_items → job_plans → properties → contacts
    if ($hasPliProduct) {
        $stmt = $db->query("
            SELECT p.site_contact_id AS contact_id,
                   pli.product_id,
                   jp.created_at AS purchase_date,
                   pli.quantity,
                   pli.line_total
            FROM plan_line_items pli
            JOIN job_plans jp ON jp.id = pli.plan_id
            JOIN properties p ON p.id = jp.property_id
            WHERE jp.status IN ('active', 'completed')
              AND pli.product_id IS NOT NULL
              AND p.site_contact_id IS NOT NULL
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['contact_id'] . ':' . $row['product_id'];
            if (!isset($purchases[$key])) {
                $purchases[$key] = [
                    'contact_id'     => (int)$row['contact_id'],
                    'product_id'     => (int)$row['product_id'],
                    'first_purchased' => $row['purchase_date'],
                    'last_purchased'  => $row['purchase_date'],
                    'total_quantity'  => 0,
                    'total_revenue'   => 0,
                    'sources'         => [],
                ];
            }
            $p = &$purchases[$key];
            if ($row['purchase_date'] < $p['first_purchased']) $p['first_purchased'] = $row['purchase_date'];
            if ($row['purchase_date'] > $p['last_purchased'])  $p['last_purchased']  = $row['purchase_date'];
            $p['total_quantity'] += (float)($row['quantity'] ?? 0);
            $p['total_revenue']  += (float)($row['line_total'] ?? 0);
            $p['sources']['plan'] = true;
            unset($p);
        }
    }

    // ── Step 2: Truncate and re-insert ─────────────────────────────────
    $db->exec("TRUNCATE TABLE contact_product_history");

    $insertStmt = $db->prepare("
        INSERT INTO contact_product_history
            (contact_id, product_id, first_purchased, last_purchased,
             total_quantity, total_revenue, source, refreshed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $historyCount = 0;
    foreach ($purchases as $p) {
        $sourceList = array_keys($p['sources']);
        $source = count($sourceList) > 1 ? 'both' : ($sourceList[0] ?? 'unknown');

        $insertStmt->execute([
            $p['contact_id'],
            $p['product_id'],
            $p['first_purchased'] ? date('Y-m-d', strtotime($p['first_purchased'])) : null,
            $p['last_purchased']  ? date('Y-m-d', strtotime($p['last_purchased']))  : null,
            $p['total_quantity'],
            $p['total_revenue'],
            $source,
        ]);
        $historyCount++;
    }

    // ── Step 3: Update contact lifecycle fields ────────────────────────
    // Check which lifecycle columns exist
    $hasLifecycle = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM contacts LIKE 'lifecycle_stage'");
        $hasLifecycle = ($check->rowCount() > 0);
    } catch (\Exception $e) {}

    $hasLastService = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM contacts LIKE 'last_service_date'");
        $hasLastService = ($check->rowCount() > 0);
    } catch (\Exception $e) {}

    $hasLtv = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM contacts LIKE 'total_lifetime_value'");
        $hasLtv = ($check->rowCount() > 0);
    } catch (\Exception $e) {}

    $contactsUpdated = 0;

    if ($hasLifecycle || $hasLastService || $hasLtv) {
        // Get all active contacts
        $contacts = $db->query("SELECT id FROM contacts WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($contacts as $contactId) {
            // Get aggregate purchase data for this contact
            $stats = $db->prepare("
                SELECT COUNT(DISTINCT product_id) AS product_count,
                       SUM(total_revenue) AS total_revenue,
                       MIN(first_purchased) AS first_purchase,
                       MAX(last_purchased) AS last_purchase
                FROM contact_product_history
                WHERE contact_id = ?
            ");
            $stats->execute([$contactId]);
            $s = $stats->fetch(PDO::FETCH_ASSOC);

            $productCount = (int)($s['product_count'] ?? 0);
            $totalRevenue = (float)($s['total_revenue'] ?? 0);
            $lastPurchase = $s['last_purchase'] ?? null;

            // Determine lifecycle stage
            $stage = 'lead';
            if ($productCount > 0) {
                if ($productCount >= 3 || $totalRevenue >= 1000) {
                    $stage = 'repeat';
                } else {
                    $stage = 'customer';
                }
                // Check if at-risk (no purchase in 6+ months)
                if ($lastPurchase && strtotime($lastPurchase) < strtotime('-6 months')) {
                    $stage = 'at_risk';
                }
            } else {
                // Check if they have any quotes (prospect)
                $quoteCheck = $db->prepare("
                    SELECT COUNT(*) FROM quotes q
                    JOIN properties p ON p.id = q.property_id
                    WHERE p.site_contact_id = ?
                ");
                $quoteCheck->execute([$contactId]);
                if ((int)$quoteCheck->fetchColumn() > 0) {
                    $stage = 'prospect';
                }
            }

            // Build UPDATE dynamically
            $setClauses = [];
            $params = [];

            if ($hasLifecycle) {
                $setClauses[] = "lifecycle_stage = ?";
                $params[] = $stage;
            }
            if ($hasLastService && $lastPurchase) {
                $setClauses[] = "last_service_date = ?";
                $params[] = $lastPurchase;
            }
            if ($hasLtv) {
                $setClauses[] = "total_lifetime_value = ?";
                $params[] = $totalRevenue;
            }

            if (!empty($setClauses)) {
                $params[] = $contactId;
                $db->prepare("UPDATE contacts SET " . implode(', ', $setClauses) . " WHERE id = ?")
                   ->execute($params);
                $contactsUpdated++;
            }
        }
    }

    // ── Result ─────────────────────────────────────────────────────────
    $result = [
        'success'          => true,
        'message'          => "Purchase history refreshed: {$historyCount} product-contact records, {$contactsUpdated} contacts updated",
        'history_records'   => $historyCount,
        'contacts_updated' => $contactsUpdated,
    ];

    if ($isCli) {
        echo $result['message'] . "\n";
    } else {
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    $error = 'refresh_purchase_history error: ' . $e->getMessage();
    error_log($error);

    if ($isCli) {
        echo "ERROR: " . $error . "\n";
        exit(1);
    } else {
        echo json_encode(['success' => false, 'error' => $error]);
    }
}
