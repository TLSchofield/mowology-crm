<?php
/**
 * Test Script: Verify Schema Fixes
 *
 * This script tests that all the database queries work correctly
 * after fixing the company_id column references.
 *
 * Usage: Visit this URL in your browser (admin only)
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Only allow admins to run this test
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required.');
}

$db = getDB();
$tests = [];
$passed = 0;
$failed = 0;

// Helper function
function test_query($name, $query, $params = []) {
    global $db, $tests, $passed, $failed;

    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $tests[] = [
            'name' => $name,
            'status' => 'PASS',
            'query' => substr($query, 0, 100) . '...',
            'result' => ($result ? 'Found record' : 'No records (expected)')
        ];
        $passed++;
        return true;
    } catch (Exception $e) {
        $tests[] = [
            'name' => $name,
            'status' => 'FAIL',
            'query' => substr($query, 0, 100) . '...',
            'error' => $e->getMessage()
        ];
        $failed++;
        return false;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schema Fix Tests</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 2rem; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 2rem; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #2D8659; padding-bottom: 1rem; }
        .summary { margin: 2rem 0; padding: 1rem; background: #f9f9f9; border-left: 4px solid #2D8659; }
        .summary .stat { display: inline-block; margin-right: 2rem; font-size: 18px; }
        .summary .passed { color: #059669; font-weight: bold; }
        .summary .failed { color: #dc2626; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th { background: #2D8659; color: white; padding: 0.75rem; text-align: left; }
        td { padding: 0.75rem; border-bottom: 1px solid #e0e0e0; }
        tr:hover { background: #f9f9f9; }
        .pass { color: #059669; font-weight: bold; }
        .fail { color: #dc2626; font-weight: bold; }
        .error { background: #fef2f2; padding: 0.5rem; border-radius: 2px; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Schema Fix Tests</h1>

        <?php

        // Test 1: View Quote
        test_query(
            'View Quote - Get company from q.company_id',
            "
            SELECT q.id, q.quote_number, q.company_id, c.company_name
            FROM quotes q
            LEFT JOIN companies c ON q.company_id = c.id
            LIMIT 1
            "
        );

        // Test 2: Create Quote - List properties with companies
        test_query(
            'Create Quote - Properties with companies (junction)',
            "
            SELECT DISTINCT p.id, p.address, c.company_name
            FROM properties p
            LEFT JOIN company_properties cp ON p.id = cp.property_id
            LEFT JOIN companies c ON cp.company_id = c.id
            LIMIT 1
            "
        );

        // Test 3: PDF Generator - Quote data
        test_query(
            'PDF Generator - Quote with company',
            "
            SELECT q.id, q.quote_number, c.company_name
            FROM quotes q
            LEFT JOIN companies c ON q.company_id = c.id
            LIMIT 1
            "
        );

        // Test 4: Create Job from Quote
        test_query(
            'Create Job - Get quote with company',
            "
            SELECT q.id, q.quote_number, q.company_id, c.company_name
            FROM quotes q
            LEFT JOIN companies c ON q.company_id = c.id
            WHERE q.status = 'accepted'
            LIMIT 1
            "
        );

        // Test 5: Create Job - Properties list
        test_query(
            'Create Job - Properties list',
            "
            SELECT DISTINCT p.id, p.address, c.company_name
            FROM properties p
            LEFT JOIN company_properties cp ON p.id = cp.property_id
            LEFT JOIN companies c ON cp.company_id = c.id
            LIMIT 1
            "
        );

        // Test 6: Customer Quote View
        test_query(
            'Customer Quote - Token access',
            "
            SELECT q.id, q.access_token, c.company_name
            FROM quotes q
            LEFT JOIN companies c ON q.company_id = c.id
            WHERE q.access_token IS NOT NULL
            LIMIT 1
            "
        );

        // Test 7: Complex join - Quote view
        test_query(
            'Quote View - Full join',
            "
            SELECT q.id, q.quote_number, p.address, c.company_name, ct.first_name
            FROM quotes q
            LEFT JOIN properties p ON q.property_id = p.id
            LEFT JOIN companies c ON q.company_id = c.id
            LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
            LIMIT 1
            "
        );

        ?>

        <div class="summary">
            <h2>Test Results</h2>
            <div class="stat">
                Total: <strong><?php echo $passed + $failed; ?></strong>
            </div>
            <div class="stat passed">
                Passed: <?php echo $passed; ?>
            </div>
            <div class="stat failed">
                Failed: <?php echo $failed; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 30%;">Test</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 40%;">Query</th>
                    <th style="width: 20%;">Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $test): ?>
                <tr>
                    <td><?php echo htmlspecialchars($test['name']); ?></td>
                    <td>
                        <span class="<?php echo strtolower($test['status']); ?>">
                            <?php echo $test['status']; ?>
                        </span>
                    </td>
                    <td>
                        <code><?php echo htmlspecialchars($test['query']); ?></code>
                    </td>
                    <td>
                        <?php if ($test['status'] === 'PASS'): ?>
                            <span class="pass">✓</span> <?php echo htmlspecialchars($test['result']); ?>
                        <?php else: ?>
                            <div class="error"><?php echo htmlspecialchars($test['error']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 2rem; padding: 1rem; background: #f0fdf4; border-left: 4px solid #059669;">
            <h3>Summary</h3>
            <?php if ($failed === 0): ?>
                <p style="color: #059669; font-weight: bold;">✓ All tests passed! Schema fixes are working correctly.</p>
            <?php else: ?>
                <p style="color: #dc2626; font-weight: bold;">✗ Some tests failed. Check the errors above.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
