<?php
/**
 * /crm/gsc/sync-history.php
 * Returns GSC sync history for display on insights tab
 *
 * Expects $db and $user to be available from parent scope (included by index.php)
 */

declare(strict_types=1);

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
            ghd.id,
            ghd.property_id,
            gp.site_url,
            ghd.sync_type,
            ghd.status,
            ghd.rows_processed,
            ghd.rows_inserted,
            ghd.rows_updated,
            ghd.error_message,
            ghd.started_at,
            ghd.completed_at,
            ghd.duration_seconds,
            ghd.initiated_by_user_id,
            ghd.notes,
            u.full_name as initiated_by_name
        FROM gsc_sync_history_with_duration ghd
        LEFT JOIN gsc_properties gp ON ghd.property_id = gp.id
        LEFT JOIN users u ON ghd.initiated_by_user_id = u.id
        WHERE ghd.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY ghd.started_at DESC
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
        FROM gsc_sync_history_with_duration
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
