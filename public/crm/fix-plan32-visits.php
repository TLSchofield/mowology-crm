<?php
/**
 * One-time fix: Reset plan 32 visits to regenerate with correct biweekly recurrence.
 * Safe to run multiple times — just cancels future scheduled visits and resets watermark.
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();

// Only admins can run this
if (($user['role'] ?? '') !== 'admin') {
    die('Admin access required.');
}

$db = getDB();
$planId = 32;

// 1. Cancel future scheduled visits for this plan
$stmt = $db->prepare("
    UPDATE job_visits
    SET status = 'cancelled', status_changed_at = NOW()
    WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= CURDATE()
");
$stmt->execute([$planId]);
$cancelledCount = $stmt->rowCount();

// 2. Reset generation watermark so visits regenerate from today
$stmt = $db->prepare("UPDATE job_plans SET visits_generated_through = NULL WHERE id = ?");
$stmt->execute([$planId]);

// 3. Regenerate visits with the fixed logic
$result = generateVisits($planId);

echo "<h2>Plan 32 Visit Fix</h2>";
echo "<p>Cancelled {$cancelledCount} future visits.</p>";
echo "<p>Regenerated: {$result['visits_created']} visits for {$result['plans_processed']} plan(s).</p>";
if (!empty($result['errors'])) {
    echo "<p style='color:red;'>Errors: " . implode(', ', $result['errors']) . "</p>";
}
echo "<p><a href='/crm/jobs/view.php?id=32'>View Plan 32</a> | <a href='/crm/jobs/schedule.php'>View Schedule</a></p>";
