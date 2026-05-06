<?php
/**
 * ONE-TIME migration: add FULLTEXT indexes for global search.
 * DELETE this file after running it once.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only'); }

$db = getDB();
header('Content-Type: text/plain');

$ops = [
    'contacts FT'   => "ALTER TABLE contacts ADD FULLTEXT INDEX ft_contacts_search (first_name, last_name, email)",
    'properties FT' => "ALTER TABLE properties ADD FULLTEXT INDEX ft_properties_search (address, city)",
    'quotes FT'     => "ALTER TABLE quotes ADD FULLTEXT INDEX ft_quotes_search (quote_number, title)",
    'job_plans FT'  => "ALTER TABLE job_plans ADD FULLTEXT INDEX ft_plans_search (plan_number, title)",
];

foreach ($ops as $label => $sql) {
    try {
        $db->exec($sql);
        echo "OK: $label\n";
    } catch (PDOException $e) {
        // 1061 = Duplicate key name (index already exists) — safe to ignore
        if (str_contains($e->getMessage(), '1061') || str_contains($e->getMessage(), 'Duplicate key')) {
            echo "SKIP (already exists): $label\n";
        } else {
            echo "ERROR: $label — " . $e->getMessage() . "\n";
        }
    }
}
echo "\nDone. Delete this file.\n";
