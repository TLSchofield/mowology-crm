<?php
/**
 * DEBUG SCRIPT - Diagnose quotes query issue
 * Access at: http://localhost/crm/DEBUG_QUOTES.php
 * This helps identify why quotes aren't loading in the kanban board
 */

require_once __DIR__ . '/../loginAuth/auth.php';

try {
    $db = getDB();

    echo "<h2>Database Query Diagnostics</h2>";
    echo "<hr>";

    // Test 1: Basic table exists
    echo "<h3>Test 1: Table Existence</h3>";
    try {
        $result = $db->query("SELECT COUNT(*) as count FROM quotes");
        $row = $result->fetch(PDO::FETCH_ASSOC);
        echo "✅ Quotes table exists. Total rows: " . $row['count'] . "<br>";
    } catch (Exception $e) {
        echo "❌ Error accessing quotes table: " . $e->getMessage() . "<br>";
    }

    // Test 2: Status distribution
    echo "<h3>Test 2: Status Distribution</h3>";
    try {
        $result = $db->query("SELECT status, COUNT(*) as count FROM quotes GROUP BY status ORDER BY count DESC");
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Status</th><th>Count</th></tr>";
        foreach ($rows as $row) {
            echo "<tr><td>" . htmlspecialchars($row['status']) . "</td><td>" . $row['count'] . "</td></tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "❌ Error querying status distribution: " . $e->getMessage() . "<br>";
    }

    // Test 3: Simple quotes select
    echo "<h3>Test 3: Simple Quotes Query (No JOINs)</h3>";
    try {
        $result = $db->query("SELECT id, quote_number, status, created_at FROM quotes ORDER BY created_at DESC LIMIT 5");
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Got " . count($rows) . " rows<br>";
        echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    } catch (Exception $e) {
        echo "❌ Error with simple query: " . $e->getMessage() . "<br>";
    }

    // Test 4: Query with companies JOIN
    echo "<h3>Test 4: Query with Companies JOIN</h3>";
    try {
        $result = $db->query("
            SELECT q.id, q.quote_number, q.status, c.company_name
            FROM quotes q
            LEFT JOIN companies c ON q.company_id = c.id
            LIMIT 5
        ");
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Got " . count($rows) . " rows<br>";
        echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    } catch (Exception $e) {
        echo "❌ Error with companies join: " . $e->getMessage() . "<br>";
    }

    // Test 5: Query with jobs JOIN
    echo "<h3>Test 5: Query with Jobs LEFT JOIN</h3>";
    try {
        $result = $db->query("
            SELECT q.id, q.quote_number, q.status, j.id as job_id
            FROM quotes q
            LEFT JOIN jobs j ON q.id = j.quote_id
            LIMIT 10
        ");
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Got " . count($rows) . " rows<br>";
        echo "<pre>" . json_encode(array_slice($rows, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    } catch (Exception $e) {
        echo "❌ Error with jobs join: " . $e->getMessage() . "<br>";
    }

    // Test 6: Full complex query from kanban
    echo "<h3>Test 6: Full Kanban Query</h3>";
    try {
        $sql = "
            SELECT
                q.id, q.quote_number, q.status, q.total_amount, q.created_at, q.expiry_date,
                q.property_id, q.company_id, q.created_by,
                c.company_name,
                c.primary_contact_id as company_primary_contact_id,
                p.address as property_address,
                p.city as property_city,
                p.property_type,
                p.contact_id as property_contact_id,
                ct.first_name as contact_first,
                ct.last_name as contact_last,
                ct.email as contact_email,
                pct.first_name as property_contact_first,
                pct.last_name as property_contact_last,
                pct.email as property_contact_email,
                u.full_name as created_by_name,
                COALESCE(j.id, 0) as job_id
            FROM quotes q
            LEFT JOIN properties p ON q.property_id = p.id
            LEFT JOIN companies c ON q.company_id = c.id
            LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
            LEFT JOIN contacts pct ON p.contact_id = pct.id
            LEFT JOIN users u ON q.created_by = u.id
            LEFT JOIN jobs j ON q.id = j.quote_id AND j.id IS NOT NULL
            ORDER BY q.created_at DESC
            LIMIT 10
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Got " . count($rows) . " rows from full kanban query<br>";

        if (!empty($rows)) {
            echo "<h4>First Row (all columns):</h4>";
            echo "<pre>";
            print_r($rows[0]);
            echo "</pre>";
        }
    } catch (Exception $e) {
        echo "❌ Error with full kanban query: " . $e->getMessage() . "<br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage();
}
?>
