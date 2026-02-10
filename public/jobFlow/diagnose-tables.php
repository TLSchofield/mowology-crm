<?php
/**
 * jobFlow Database Diagnostics
 * Checks all tables needed for quote submission
 *
 * URL: https://www.mowology.ca/jobFlow/diagnose-tables.php
 */

header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

echo "=== jobFlow Database Table Diagnostics ===\n\n";

try {
    $db = getDB();
    echo "✓ Database connected\n\n";

    // Tables needed for quote submission
    $tables = [
        'contacts',
        'properties',
        'quote_requests',
        'consent_log',
        'activity_log',
        'lead_events',
        'conversion_events'
    ];

    foreach ($tables as $table) {
        echo "TABLE: $table\n";

        try {
            $result = $db->query("DESCRIBE `$table`");
            $columns = $result->fetchAll(PDO::FETCH_ASSOC);

            if (empty($columns)) {
                echo "  ✗ TABLE NOT FOUND\n";
            } else {
                echo "  ✓ EXISTS (" . count($columns) . " columns)\n";
                foreach ($columns as $col) {
                    echo "    - " . str_pad($col['Field'], 30) . " " . $col['Type'];
                    if ($col['Null'] === 'NO') echo " NOT NULL";
                    if ($col['Key'] === 'PRI') echo " PRIMARY";
                    if ($col['Extra']) echo " " . $col['Extra'];
                    echo "\n";
                }
            }
        } catch (PDOException $e) {
            echo "  ✗ ERROR: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }

    // Test INSERT on contacts
    echo "--- INSERT TEST: contacts ---\n";
    try {
        $stmt = $db->prepare("
            INSERT INTO contacts
                (first_name, last_name, email, phone, is_active)
            VALUES
                (?, ?, ?, ?, 1)
        ");
        echo "✓ Prepared statement created\n";

        $testData = ['John', 'Doe', 'test@example.com', '604-555-1234'];
        $stmt->execute($testData);
        echo "✓ Test INSERT successful (ID: " . $db->lastInsertId() . ")\n";

        // Rollback by deleting
        $db->prepare("DELETE FROM contacts WHERE email = ?")->execute(['test@example.com']);
        echo "✓ Test row cleaned up\n";
    } catch (Exception $e) {
        echo "✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // Test INSERT on properties
    echo "--- INSERT TEST: properties ---\n";
    try {
        $stmt = $db->prepare("
            INSERT INTO properties
                (property_name, property_type, address, city, province, postal_code,
                 latitude, longitude, geocoded_at, site_contact_id, status)
            VALUES
                (?, ?, ?, ?, 'BC', ?, ?, ?, NOW(), ?, 'active')
        ");
        echo "✓ Prepared statement created\n";

        $stmt->execute([
            'Test Property',
            'single_family',
            '123 Test St',
            'Vancouver',
            'V6B 1A1',
            null,
            null,
            1  // contact_id (dummy)
        ]);
        echo "✓ Test INSERT successful (ID: " . $db->lastInsertId() . ")\n";

        // Rollback by deleting
        $db->prepare("DELETE FROM properties WHERE address = ?")->execute(['123 Test St']);
        echo "✓ Test row cleaned up\n";
    } catch (Exception $e) {
        echo "✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // Test INSERT on quote_requests
    echo "--- INSERT TEST: quote_requests ---\n";
    try {
        $stmt = $db->prepare("
            INSERT INTO quote_requests
                (contact_id, property_id, lead_event_id, service_types, urgency, project_description,
                 status, source, ip_address, user_agent)
            VALUES
                (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)
        ");
        echo "✓ Prepared statement created\n";

        $stmt->execute([
            1,  // contact_id (dummy)
            1,  // property_id (dummy)
            null,  // lead_event_id
            'landscaping,hedging',
            'soon',
            'Test description',
            'website',
            '127.0.0.1',
            'Mozilla/5.0'
        ]);
        echo "✓ Test INSERT successful (ID: " . $db->lastInsertId() . ")\n";

        // Rollback by deleting
        $db->prepare("DELETE FROM quote_requests WHERE ip_address = ?")->execute(['127.0.0.1']);
        echo "✓ Test row cleaned up\n";
    } catch (Exception $e) {
        echo "✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // Test INSERT on consent_log
    echo "--- INSERT TEST: consent_log ---\n";
    try {
        $stmt = $db->prepare("
            INSERT INTO consent_log
                (contact_id, consent_type, consent_given, consent_text, ip_address, user_agent, consent_source)
            VALUES
                (?, ?, ?, ?, ?, ?, 'website_form')
        ");
        echo "✓ Prepared statement created\n";

        $stmt->execute([
            1,  // contact_id (dummy)
            'quote_followup',
            1,
            'I agree to be contacted about this quote request',
            '127.0.0.1',
            'Mozilla/5.0'
        ]);
        echo "✓ Test INSERT successful (ID: " . $db->lastInsertId() . ")\n";

        // Rollback by deleting
        $db->prepare("DELETE FROM consent_log WHERE ip_address = ?")->execute(['127.0.0.1']);
        echo "✓ Test row cleaned up\n";
    } catch (Exception $e) {
        echo "✗ INSERT failed: " . $e->getMessage() . "\n";
    }
    echo "\n";

    echo "=== Diagnostics Complete ===\n";

} catch (Exception $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
}
?>
