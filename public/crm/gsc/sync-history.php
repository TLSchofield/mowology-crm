<?php
/**
 * /crm/gsc/sync-history.php
 * Returns GSC sync history for display on insights tab
 *
 * Expects $db and $user to be available from parent scope (included by portfolio/index.php)
 */

if (empty($user) || ($user['role'] ?? '') !== 'admin') {
    return [
        'history' => [],
        'summary' => [
            'total_syncs' => 0,
            'successful' => 0,
            'failed' => 0,
            'partial' => 0,
            'last_sync' => null
        ]
    ];
}

try {
    $stmt = $db->prepare("
        SELECT
            gsh.id,
            gsh.property_id,
            gp.site_url,
            gsh.sync_type,
            gsh.status,
            gsh.rows_processed,
            gsh.rows_inserted,
            gsh.rows_updated,
            gsh.error_message,
            gsh.started_at,
            gsh.completed_at,
            TIMESTAMPDIFF(SECOND, gsh.started_at, COALESCE(gsh.completed_at, NOW())) as duration_seconds,
            gsh.initiated_by_user_id,
            gsh.notes,
            u.full_name as initiated_by_name
        FROM gsc_sync_history gsh
        LEFT JOIN gsc_properties gp ON gsh.property_id = gp.id
        LEFT JOIN users u ON gsh.initiated_by_user_id = u.id
        WHERE gsh.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY gsh.started_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('GSC Sync History Error: ' . $e->getMessage());
    $history = [];
}

$summary = [
    'total_syncs' => 0,
    'successful' => 0,
    'failed' => 0,
    'partial' => 0,
    'last_sync' => null
];

try {
    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
            MAX(started_at) as last_sync
        FROM gsc_sync_history
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    if ($stats) {
        $summary = [
            'total_syncs' => (int)$stats['total'],
            'successful' => (int)$stats['successful'],
            'failed' => (int)$stats['failed'],
            'partial' => (int)$stats['partial'],
            'last_sync' => $stats['last_sync']
        ];
    }
} catch (Exception $e) {
    error_log('GSC Summary Stats Error: ' . $e->getMessage());
}

return [
    'history' => $history,
    'summary' => $summary
];
