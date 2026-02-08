<?php
/**
 * Migration: Add PDF Attachment Preference to Companies
 *
 * Adds a column to track whether companies prefer PDF attachments in emails.
 * Default: 1 (yes, attach PDF)
 *
 * Usage: Visit this URL in your browser after backing up your database
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Only admins can run migrations
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required.');
}

$db = getDB();
$status = '';
$error = false;

try {
    // Check if column already exists
    $result = $db->query("SHOW COLUMNS FROM companies LIKE 'pref_attach_pdf'");

    if ($result->rowCount() > 0) {
        $status = 'Column pref_attach_pdf already exists. No changes needed.';
    } else {
        // Add the column
        $db->exec("
            ALTER TABLE companies
            ADD COLUMN pref_attach_pdf TINYINT(1) DEFAULT 1
            AFTER account_status
        ");

        $status = 'Successfully added pref_attach_pdf column to companies table!';
    }

} catch (PDOException $e) {
    $error = true;
    $status = 'Error: ' . htmlspecialchars($e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            padding: 2rem;
            background: #f5f7fa;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1a202c;
            border-bottom: 2px solid #2D8659;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .details {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
            font-size: 14px;
            line-height: 1.6;
        }
        code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-family: monospace;
        }
        a {
            color: #2D8659;
            text-decoration: none;
            font-weight: 500;
        }
        a:hover {
            text-decoration: underline;
        }
        .info {
            margin-top: 2rem;
            padding: 1rem;
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>PDF Attachment Preference Migration</h1>

        <div class="alert <?php echo $error ? 'alert-error' : 'alert-success'; ?>">
            <strong><?php echo $error ? 'Error' : 'Success'; ?></strong><br>
            <?php echo htmlspecialchars($status); ?>
        </div>

        <div class="details">
            <strong>What this migration does:</strong>
            <ul style="margin: 0.5rem 0; padding-left: 1.5rem;">
                <li>Adds <code>pref_attach_pdf</code> column to companies table</li>
                <li>Default value: <code>1</code> (attach PDF by default)</li>
                <li>Placed after <code>account_status</code> column</li>
                <li>Type: TINYINT(1) (0 = no, 1 = yes)</li>
            </ul>
        </div>

        <div class="info">
            <strong>Next Steps:</strong>
            <ol style="margin: 0.5rem 0; padding-left: 1.5rem;">
                <li>Go to <a href="clients_appstack.php">Client Management</a></li>
                <li>Edit a client to see the PDF attachment preference checkbox</li>
                <li>Check/uncheck to control whether PDFs attach to quote emails</li>
            </ol>
        </div>

        <div style="margin-top: 2rem; text-align: center;">
            <a href="dashboard_appstack.php" style="display: inline-block; padding: 0.75rem 1.5rem; background: #2D8659; color: white; border-radius: 6px; text-decoration: none;">
                ← Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
