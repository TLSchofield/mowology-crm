<?php
/**
 * Quick fix: Make job_plans.company_id nullable
 * Since companies table is empty, plans need to work without a company.
 * Run once then delete.
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { die('Admin only.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<h2>Fix: Make job_plans.company_id nullable</h2>';
    echo '<p>This will drop the FK constraint on company_id and make it nullable.</p>';
    echo '<form method="POST"><button type="submit">Run Fix</button></form>';
    exit;
}

$db = getDB();
$messages = [];

try {
    // Drop the foreign key constraint first
    $db->exec("ALTER TABLE job_plans DROP FOREIGN KEY job_plans_ibfk_3");
    $messages[] = "Dropped FK constraint job_plans_ibfk_3";
} catch (Exception $e) {
    $messages[] = "FK drop note: " . $e->getMessage();
}

try {
    // Make company_id nullable
    $db->exec("ALTER TABLE job_plans MODIFY company_id INT NULL DEFAULT NULL");
    $messages[] = "Made company_id nullable - SUCCESS";
} catch (Exception $e) {
    $messages[] = "Error modifying column: " . $e->getMessage();
}

try {
    // Re-add FK as nullable (ON DELETE SET NULL)
    $db->exec("ALTER TABLE job_plans ADD CONSTRAINT job_plans_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL");
    $messages[] = "Re-added FK with ON DELETE SET NULL";
} catch (Exception $e) {
    $messages[] = "FK re-add note: " . $e->getMessage();
}

echo '<h2>Results</h2><ul>';
foreach ($messages as $m) {
    echo '<li>' . htmlspecialchars($m) . '</li>';
}
echo '</ul>';
echo '<p><a href="jobs/create-from-quote.php?quote_id=29">Go to Create Plans</a></p>';
