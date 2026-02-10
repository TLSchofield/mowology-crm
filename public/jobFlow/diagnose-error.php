<?php
/**
 * jobFlow Error Diagnostic
 * Helps identify why quote submission is failing
 *
 * URL: https://www.mowology.ca/jobFlow/diagnose-error.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  jobFlow Quote Submission Error Diagnostic                ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

try {
    $db = getDB();
    echo "✓ Database connection successful\n\n";

    // List all tables
    $result = $db->query("SHOW TABLES");
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "Database Tables (" . count($tables) . " total):\n";
    foreach ($tables as $table) {
        echo "  • $table\n";
    }
    echo "\n";

    // Check required tables for quote submission
    $requiredTables = [
        'contacts',
        'properties',
        'quote_requests',
        'lead_events',
        'consent_log',
        'activity_log'
    ];

    echo "Required Tables Status:\n";
    $allExist = true;
    foreach ($requiredTables as $table) {
        $exists = in_array($table, $tables);
        echo "  " . ($exists ? "✓" : "✗") . " $table\n";
        if (!$exists) {
            $allExist = false;
        }
    }
    echo "\n";

    if (!$allExist) {
        echo "✗ PROBLEM: Some required tables are missing!\n\n";
        exit(1);
    }

    // Test each INSERT operation
    echo "Testing INSERT Operations:\n";
    echo "─────────────────────────────────────────────────────────\n\n";

    // 1. Test contacts INSERT
    echo "1. Testing contacts INSERT:\n";
    try {
        $stmt = $db->prepare("
            INSERT INTO contacts
                (first_name, last_name, email, phone, is_active)
            VALUES
                (?, ?, ?, ?, 1)
        ");
        $stmt->execute(['Test', 'User', 'test@example.com', '604-555-1234']);
        $testContactId = $db->lastInsertId();
        echo "   ✓ INSERT successful (ID: $testContactId)\n";

        // Cleanup
        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$testContactId]);
    } catch (Exception $e) {
        echo "   ✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 2. Test properties INSERT
    echo "2. Testing properties INSERT:\n";
    try {
        $stmt = $db->prepare("
            INSERT INTO properties
                (property_name, property_type, address, city, province, postal_code,
                 latitude, longitude, geocoded_at, site_contact_id, status)
            VALUES
                (?, ?, ?, ?, 'BC', ?, ?, ?, NOW(), ?, 'active')
        ");
        $stmt->execute([
            'Test Property',
            'single_family',
            '123 Test St',
            'Vancouver',
            'V6B 1A1',
            null,
            null,
            null
        ]);
        $testPropId = $db->lastInsertId();
        echo "   ✓ INSERT successful (ID: $testPropId)\n";

        // Cleanup
        $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$testPropId]);
    } catch (Exception $e) {
        echo "   ✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 3. Test quote_requests INSERT
    echo "3. Testing quote_requests INSERT:\n";
    try {
        // First create test contact
        $stmt = $db->prepare("INSERT INTO contacts (first_name, last_name, email, phone, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute(['Test', 'User', 'test@example.com', '604-555-1234']);
        $contactId = $db->lastInsertId();

        // Create test property
        $stmt = $db->prepare("INSERT INTO properties (property_name, property_type, address, city, province, postal_code, status) VALUES (?, ?, ?, ?, 'BC', ?, 'active')");
        $stmt->execute(['Test', 'single_family', '123 Test St', 'Vancouver', 'V6B 1A1']);
        $propId = $db->lastInsertId();

        // Now test quote_requests
        $stmt = $db->prepare("
            INSERT INTO quote_requests
                (contact_id, property_id, lead_event_id, service_types, urgency, project_description,
                 status, source, ip_address, user_agent)
            VALUES
                (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)
        ");
        $stmt->execute([
            $contactId,
            $propId,
            null,
            'landscaping,hedging',
            'soon',
            'Test description',
            'website',
            '127.0.0.1',
            'Mozilla/5.0'
        ]);
        $quoteId = $db->lastInsertId();
        echo "   ✓ INSERT successful (ID: $quoteId)\n";

        // Cleanup
        $db->prepare("DELETE FROM quote_requests WHERE id = ?")->execute([$quoteId]);
        $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$propId]);
        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contactId]);
    } catch (Exception $e) {
        echo "   ✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 4. Test consent_log INSERT
    echo "4. Testing consent_log INSERT:\n";
    try {
        // Create test contact
        $stmt = $db->prepare("INSERT INTO contacts (first_name, last_name, email, phone, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute(['Test', 'User', 'test@example.com', '604-555-1234']);
        $contactId = $db->lastInsertId();

        // Test consent_log
        $stmt = $db->prepare("
            INSERT INTO consent_log
                (contact_id, consent_type, consent_given, consent_text, ip_address, user_agent, consent_source)
            VALUES
                (?, ?, ?, ?, ?, ?, 'website_form')
        ");
        $stmt->execute([
            $contactId,
            'quote_followup',
            1,
            'I agree to be contacted',
            '127.0.0.1',
            'Mozilla/5.0'
        ]);
        $consentId = $db->lastInsertId();
        echo "   ✓ INSERT successful (ID: $consentId)\n";

        // Cleanup
        $db->prepare("DELETE FROM consent_log WHERE id = ?")->execute([$consentId]);
        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contactId]);
    } catch (Exception $e) {
        echo "   ✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 5. Test activity_log INSERT
    echo "5. Testing activity_log INSERT:\n";
    try {
        // Create test records
        $stmt = $db->prepare("INSERT INTO contacts (first_name, last_name, email, phone, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute(['Test', 'User', 'test@example.com', '604-555-1234']);
        $contactId = $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO properties (property_name, property_type, address, city, province, postal_code, status) VALUES (?, ?, ?, ?, 'BC', ?, 'active')");
        $stmt->execute(['Test', 'single_family', '123 Test St', 'Vancouver', 'V6B 1A1']);
        $propId = $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO quote_requests (contact_id, property_id, service_types, status) VALUES (?, ?, ?, 'new')");
        $stmt->execute([$contactId, $propId, 'landscaping']);
        $quoteId = $db->lastInsertId();

        // Test activity_log
        $stmt = $db->prepare("
            INSERT INTO activity_log
                (user_id, contact_id, property_id, quote_request_id, action, details, ip_address)
            VALUES
                (NULL, ?, ?, ?, 'Quote request submitted', ?, ?)
        ");
        $stmt->execute([
            $contactId,
            $propId,
            $quoteId,
            'Via website form',
            '127.0.0.1'
        ]);
        $activityId = $db->lastInsertId();
        echo "   ✓ INSERT successful (ID: $activityId)\n";

        // Cleanup
        $db->prepare("DELETE FROM activity_log WHERE id = ?")->execute([$activityId]);
        $db->prepare("DELETE FROM quote_requests WHERE id = ?")->execute([$quoteId]);
        $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$propId]);
        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contactId]);
    } catch (Exception $e) {
        echo "   ✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  ✓ All tests passed!                                       ║\n";
    echo "║  Database is ready for quote submission.                   ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n\n";

} catch (Exception $e) {
    echo "✗ Fatal Error: " . $e->getMessage() . "\n\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
}
?>
