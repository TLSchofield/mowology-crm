<?php
/**
 * Seed Schedule Test Data
 *
 * Creates sample job plans, calendar stops, and job visits
 * so the mobile card execution view can be tested.
 *
 * Run once via authenticated browser request:
 *   GET /crm/api/seed-schedule-data.php
 *
 * Safe to re-run: checks for existing data first.
 */
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();
$results = [];

try {
    // ─── Check existing data ────────────────────────────────────────
    $planCount = (int)$db->query("SELECT COUNT(*) FROM job_plans")->fetchColumn();
    $results['existing_plans'] = $planCount;

    if ($planCount >= 3) {
        // Already have plans — just make sure we have today's stops
        $today = date('Y-m-d');
        $todayStops = (int)$db->query("SELECT COUNT(*) FROM calendar_stops WHERE stop_date = '{$today}'")->fetchColumn();
        $results['existing_today_stops'] = $todayStops;

        if ($todayStops > 0) {
            $results['message'] = 'Test data already exists. Skipping seed.';
            echo json_encode($results, JSON_PRETTY_PRINT);
            exit;
        }
    }

    // ─── Get existing properties ────────────────────────────────────
    $properties = $db->query("
        SELECT p.id, p.address, p.city, p.site_contact_id
        FROM properties p
        WHERE p.status = 'active'
        ORDER BY p.id
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($properties)) {
        // No properties — create some with contacts
        $results['message'] = 'No active properties found. Creating test properties and contacts.';

        // Check for contacts
        $contacts = $db->query("SELECT id, first_name, last_name FROM contacts LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($contacts)) {
            // Create test contacts
            $testContacts = [
                ['John', 'Smith', 'john@example.com', '604-555-0101'],
                ['Sarah', 'Johnson', 'sarah@example.com', '604-555-0102'],
                ['Mike', 'Williams', 'mike@example.com', '604-555-0103'],
                ['Lisa', 'Chen', 'lisa@example.com', '604-555-0104'],
            ];

            $insContact = $db->prepare("
                INSERT INTO contacts (first_name, last_name, email, phone, status, created_at)
                VALUES (?, ?, ?, ?, 'active', NOW())
            ");

            $contacts = [];
            foreach ($testContacts as $tc) {
                $insContact->execute($tc);
                $contacts[] = [
                    'id' => (int)$db->lastInsertId(),
                    'first_name' => $tc[0],
                    'last_name' => $tc[1],
                ];
            }
            $results['contacts_created'] = count($contacts);
        }

        // Create test properties
        $testProperties = [
            ['123 Main St', 'Vancouver', 49.2827, -123.1207],
            ['456 Oak Ave', 'Burnaby', 49.2488, -122.9805],
            ['789 Cedar Blvd', 'Richmond', 49.1666, -123.1336],
            ['321 Maple Dr', 'Vancouver', 49.2612, -123.1139],
            ['654 Pine Cres', 'North Vancouver', 49.3200, -123.0724],
            ['987 Birch Lane', 'Surrey', 49.1913, -122.8490],
        ];

        $insProperty = $db->prepare("
            INSERT INTO properties (address, city, site_contact_id, latitude, longitude, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");

        $properties = [];
        foreach ($testProperties as $idx => $tp) {
            $contactId = $contacts[$idx % count($contacts)]['id'];
            $insProperty->execute([$tp[0], $tp[1], $contactId, $tp[2], $tp[3]]);
            $properties[] = [
                'id' => (int)$db->lastInsertId(),
                'address' => $tp[0],
                'city' => $tp[1],
                'site_contact_id' => $contactId,
            ];
        }
        $results['properties_created'] = count($properties);
    }

    // ─── Get crew member (use current user) ─────────────────────────
    $crewId = (int)$user['id'];

    // ─── Create job plans ───────────────────────────────────────────
    $serviceTypes = ['lawn_care', 'hedge_trimming', 'garden_maintenance', 'snow_removal', 'landscaping', 'seasonal_cleanup'];
    $planTitles = [
        'lawn_care'          => 'Weekly Lawn Maintenance',
        'hedge_trimming'     => 'Hedge Trimming Service',
        'garden_maintenance' => 'Garden Bed Cleanup',
        'snow_removal'       => 'Snow Removal',
        'landscaping'        => 'Full Landscape Renovation',
        'seasonal_cleanup'   => 'Spring Cleanup',
    ];

    $defaultTimes = [
        ['08:00:00', '09:00:00', 60],
        ['09:30:00', '10:30:00', 45],
        ['11:00:00', '12:00:00', 60],
        ['13:00:00', '14:00:00', 90],
        ['14:30:00', '15:30:00', 45],
        ['16:00:00', '17:00:00', 60],
    ];

    $plansCreated = 0;
    $today = date('Y-m-d');

    foreach ($properties as $idx => $prop) {
        $serviceType = $serviceTypes[$idx % count($serviceTypes)];
        $title = $planTitles[$serviceType];
        $times = $defaultTimes[$idx % count($defaultTimes)];

        // Check if plan already exists for this property + service
        $existing = $db->prepare("
            SELECT id FROM job_plans
            WHERE property_id = ? AND service_type = ? AND status = 'active'
            LIMIT 1
        ");
        $existing->execute([$prop['id'], $serviceType]);
        if ($existing->fetchColumn()) {
            continue; // Skip — already has a plan
        }

        $planNumber = generatePlanNumber();

        $stmt = $db->prepare("
            INSERT INTO job_plans (
                plan_number, property_id, company_id, title, description,
                service_type, is_recurring, recurrence_pattern, recurrence_interval,
                recurrence_day_of_week, plan_start_date, default_crew_id,
                default_time_start, default_time_end, estimated_duration_minutes,
                horizon_days, status, created_by, created_at
            ) VALUES (
                ?, ?, 0, ?, ?,
                ?, 1, 'weekly', 1,
                ?, ?, ?,
                ?, ?, ?,
                28, 'active', ?, NOW()
            )
        ");

        // Set recurrence day to today's day of week (0=Sun..6=Sat)
        $todayDow = (int)date('w');

        $stmt->execute([
            $planNumber,
            $prop['id'],
            $title,
            'Test plan for mobile schedule view',
            $serviceType,
            $todayDow,
            $today,
            $crewId,
            $times[0],
            $times[1],
            $times[2],
            $crewId,
        ]);

        $plansCreated++;
    }

    $results['plans_created'] = $plansCreated;

    // ─── Generate visits (creates calendar_stops + job_visits) ──────
    $genResult = generateVisits(null, 28);
    $results['visit_generation'] = $genResult;

    // ─── Verify today's data ────────────────────────────────────────
    $todayStops = (int)$db->query("SELECT COUNT(*) FROM calendar_stops WHERE stop_date = '{$today}'")->fetchColumn();
    $todayVisits = (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE scheduled_date = '{$today}'")->fetchColumn();

    $results['today_stops'] = $todayStops;
    $results['today_visits'] = $todayVisits;

    // Mark one stop as in_progress for demo
    if ($todayStops > 0) {
        $firstStop = $db->query("
            SELECT cs.id, jv.id AS visit_id
            FROM calendar_stops cs
            JOIN job_visits jv ON jv.stop_id = cs.id
            WHERE cs.stop_date = '{$today}' AND jv.status = 'scheduled'
            ORDER BY cs.route_order
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        // Don't mark any as in_progress — leave all as scheduled for clean testing
        $results['first_stop'] = $firstStop;
    }

    $results['success'] = true;
    $results['message'] = "Seed complete. {$todayStops} stops and {$todayVisits} visits for today.";

} catch (Exception $e) {
    $results['success'] = false;
    $results['error'] = $e->getMessage();
    error_log('seed-schedule-data error: ' . $e->getMessage());
}

echo json_encode($results, JSON_PRETTY_PRINT);
