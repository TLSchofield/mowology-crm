<?php
/**
 * Popular Upsells Auto-Tagger (Cron)
 *
 * Computes which upsell has the highest adoption rate per base product
 * over the last 90 days, sets is_popular=1 on that one, clears it on others.
 *
 * Runs weekly: Sundays at 3 AM
 *   0 3 * * 0 /usr/local/bin/php /home/mowology/public_html/crm/cron/popular_upsells.php
 *
 * @package Mowology\Products
 */

declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    requirePermission('admin');
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
}

$db = getDB();
$updates = 0;
$cleared = 0;

try {
    // Step 1: Find base products that have multiple upsells configured
    $stmt = $db->query("
        SELECT base_product_id, COUNT(*) as upsell_count
        FROM product_upsells
        WHERE is_active = 1
        GROUP BY base_product_id
        HAVING upsell_count >= 1
    ");
    $bases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bases as $base) {
        $baseId = (int)$base['base_product_id'];

        // Step 2: For each base, count how many times each upsell was adopted
        // in the last 90 days (sent or accepted quotes that contain both the
        // base product AND the upsell as line items)
        $adoptionStmt = $db->prepare("
            SELECT
                pu.id AS upsell_row_id,
                pu.upsell_product_id,
                COUNT(DISTINCT q.id) AS adoption_count
            FROM product_upsells pu
            LEFT JOIN quote_line_items upsell_li
                ON upsell_li.product_id = pu.upsell_product_id
               AND upsell_li.is_upsell = 1
            LEFT JOIN quotes q
                ON q.id = upsell_li.quote_id
               AND q.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND q.status IN ('sent', 'accepted')
               AND EXISTS (
                   SELECT 1 FROM quote_line_items base_li
                   WHERE base_li.quote_id = q.id
                     AND base_li.product_id = pu.base_product_id
                     AND base_li.is_upsell = 0
               )
            WHERE pu.base_product_id = ?
              AND pu.is_active = 1
            GROUP BY pu.id, pu.upsell_product_id
            ORDER BY adoption_count DESC, pu.sort_order ASC
        ");
        $adoptionStmt->execute([$baseId]);
        $rows = $adoptionStmt->fetchAll(PDO::FETCH_ASSOC);

        // Clear is_popular for this base first
        $db->prepare("UPDATE product_upsells SET is_popular = 0 WHERE base_product_id = ?")
           ->execute([$baseId]);
        $cleared++;

        // Set is_popular=1 on the top row only if it has actual adoptions
        if (!empty($rows) && (int)$rows[0]['adoption_count'] > 0) {
            $db->prepare("UPDATE product_upsells SET is_popular = 1 WHERE id = ?")
               ->execute([(int)$rows[0]['upsell_row_id']]);
            $updates++;
        }
    }

    $summary = sprintf('Popular upsells cron: processed %d base products, tagged %d as popular, cleared %d', count($bases), $updates, $cleared);
    error_log($summary);

    if ($isCli) {
        echo $summary . "\n";
    } else {
        echo json_encode([
            'success'  => true,
            'bases'    => count($bases),
            'tagged'   => $updates,
            'cleared'  => $cleared,
        ]);
    }

} catch (Throwable $e) {
    $msg = 'popular_upsells cron error: ' . $e->getMessage();
    error_log($msg);
    if ($isCli) { echo $msg . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $msg]);
}
