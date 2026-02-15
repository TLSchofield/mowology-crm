<?php
/**
 * API: Ops Settings Management
 * Handles read/write for ops_settings table (key-value operational config).
 * Returns JSON.
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();

// Admin only
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db = getDB();

try {
    if ($action === 'get') {
        // Get a single setting by key
        $key = $_GET['key'] ?? null;
        if (!$key) {
            throw new Exception('Setting key is required');
        }

        $stmt = $db->prepare("SELECT setting_value, description, updated_at FROM ops_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => true, 'value' => null, 'exists' => false]);
        } else {
            $value = $row['setting_value'];
            // Try to decode JSON
            $decoded = json_decode($value, true);
            echo json_encode([
                'success'     => true,
                'exists'      => true,
                'value'       => $decoded !== null ? $decoded : $value,
                'raw'         => $value,
                'description' => $row['description'],
                'updated_at'  => $row['updated_at'],
            ]);
        }

    } elseif ($action === 'save') {
        // Save/upsert a setting
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;
        $description = $data['description'] ?? null;

        if (!$key) {
            throw new Exception('Setting key is required');
        }

        // Encode value as JSON if it's an array/object
        if (is_array($value)) {
            $value = json_encode($value);
        }

        $stmt = $db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_by = VALUES(updated_by)
        ");
        $stmt->execute([$key, $value, $description, $user['id']]);

        echo json_encode(['success' => true, 'message' => 'Setting saved']);

    } elseif ($action === 'get-cron-status') {
        // Get the latest weather cron run info from weather_action_log
        // Check if the table exists first (migration 202 may not have run)
        $tableCheck = $db->query("SHOW TABLES LIKE 'weather_action_log'");
        if ($tableCheck->rowCount() === 0) {
            echo json_encode(['success' => true, 'has_table' => false, 'last_run' => null]);
            exit;
        }

        // Last WEATHER_EVAL entry = last time the cron ran
        $stmt = $db->prepare("
            SELECT created_at, action_date, details
            FROM weather_action_log
            WHERE action_type = 'WEATHER_EVAL'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute();
        $lastEval = $stmt->fetch(PDO::FETCH_ASSOC);

        // Count today's evaluations
        $todayStmt = $db->prepare("
            SELECT created_at, details
            FROM weather_action_log
            WHERE action_type = 'WEATHER_EVAL'
              AND action_date = CURDATE()
            ORDER BY created_at ASC
        ");
        $todayStmt->execute();
        $todayRows = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

        $todaySummary = ['total' => count($todayRows), 'ok_count' => 0, 'not_ok_count' => 0, 'borderline_count' => 0, 'first_eval' => null, 'last_eval' => null];
        foreach ($todayRows as $i => $row) {
            $d = json_decode($row['details'] ?? '', true);
            $status = $d['status'] ?? '';
            if ($status === 'OK') $todaySummary['ok_count']++;
            elseif ($status === 'NOT_OK') $todaySummary['not_ok_count']++;
            elseif ($status === 'BORDERLINE') $todaySummary['borderline_count']++;
            if ($i === 0) $todaySummary['first_eval'] = $row['created_at'];
            $todaySummary['last_eval'] = $row['created_at'];
        }

        // Last salt alert
        $saltStmt = $db->prepare("
            SELECT created_at, details
            FROM weather_action_log
            WHERE action_type = 'SALT_ALERT'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $saltStmt->execute();
        $lastSalt = $saltStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'has_table'  => true,
            'last_run'   => $lastEval ? $lastEval['created_at'] : null,
            'today'      => [
                'total'      => (int)($todaySummary['total'] ?? 0),
                'ok'         => (int)($todaySummary['ok_count'] ?? 0),
                'not_ok'     => (int)($todaySummary['not_ok_count'] ?? 0),
                'borderline' => (int)($todaySummary['borderline_count'] ?? 0),
                'first_eval' => $todaySummary['first_eval'] ?? null,
                'last_eval'  => $todaySummary['last_eval'] ?? null,
            ],
            'last_salt_alert' => $lastSalt ? $lastSalt['created_at'] : null,
        ]);

    } elseif ($action === 'get-service-weather-rules') {
        // Get weather rules for all service packages
        $stmt = $db->prepare("
            SELECT id, package_name, slug, category, service_type, is_active,
                   weather_policy, max_precip_chance_pct, max_precip_mm_per_hr,
                   min_temp_c, max_temp_c, max_wind_kph,
                   move_window_hours, move_timeband_start, move_timeband_end,
                   auto_reschedule, require_manual_if_uncertain
            FROM service_packages
            ORDER BY category, package_name
        ");
        $stmt->execute();
        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'packages' => $packages]);

    } elseif ($action === 'save-service-weather-rules') {
        // Save weather rules for a service package
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);

        if (!$id) {
            throw new Exception('Service package ID is required');
        }

        $stmt = $db->prepare("
            UPDATE service_packages
            SET weather_policy = ?,
                max_precip_chance_pct = ?,
                max_precip_mm_per_hr = ?,
                min_temp_c = ?,
                max_temp_c = ?,
                max_wind_kph = ?,
                move_window_hours = ?,
                move_timeband_start = ?,
                move_timeband_end = ?,
                auto_reschedule = ?,
                require_manual_if_uncertain = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['weather_policy'] ?? 'ANY',
            !empty($data['max_precip_chance_pct']) ? (int)$data['max_precip_chance_pct'] : null,
            !empty($data['max_precip_mm_per_hr']) ? (float)$data['max_precip_mm_per_hr'] : null,
            isset($data['min_temp_c']) && $data['min_temp_c'] !== '' ? (float)$data['min_temp_c'] : null,
            isset($data['max_temp_c']) && $data['max_temp_c'] !== '' ? (float)$data['max_temp_c'] : null,
            !empty($data['max_wind_kph']) ? (float)$data['max_wind_kph'] : null,
            !empty($data['move_window_hours']) ? (int)$data['move_window_hours'] : null,
            !empty($data['move_timeband_start']) ? $data['move_timeband_start'] : null,
            !empty($data['move_timeband_end']) ? $data['move_timeband_end'] : null,
            !empty($data['auto_reschedule']) ? 1 : 0,
            isset($data['require_manual_if_uncertain']) ? ((int)$data['require_manual_if_uncertain']) : 1,
            $id,
        ]);

        echo json_encode(['success' => true, 'message' => 'Weather rules saved']);

    } elseif ($action === 'test-email') {
        // Send a test weather alert email to admin
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        require_once CRM_INCLUDES . '/messaging.php';

        // Get admin email
        $stmt = $db->prepare("SELECT company_email FROM business_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        $adminEmail = $settings['company_email'] ?? '';

        if (empty($adminEmail)) {
            throw new Exception('No admin email configured in Business Settings');
        }

        $subject = "🧪 Test Weather Alert — " . date('M j, g:i A');
        $htmlBody = "
            <div style='font-family:Arial,sans-serif;max-width:600px;'>
                <h2 style='color:#1565c0;'>🧪 Test Weather Alert</h2>
                <p>This is a <strong>test email</strong> from the Mowology Weather Operations system.</p>
                <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                    <tr style='background:#e3f2fd;'>
                        <td style='padding:10px;font-weight:600;'>Type</td>
                        <td style='padding:10px;'>Salt Alert (Test)</td>
                    </tr>
                    <tr>
                        <td style='padding:10px;font-weight:600;'>Date</td>
                        <td style='padding:10px;'>" . date('l, F j, Y') . "</td>
                    </tr>
                    <tr style='background:#e3f2fd;'>
                        <td style='padding:10px;font-weight:600;'>Sent By</td>
                        <td style='padding:10px;'>" . htmlspecialchars($user['full_name'] ?? 'Admin') . "</td>
                    </tr>
                    <tr>
                        <td style='padding:10px;font-weight:600;'>Sent To</td>
                        <td style='padding:10px;'>" . htmlspecialchars($adminEmail) . "</td>
                    </tr>
                </table>
                <p style='color:#2D8659;'>✅ If you received this, email alerts are working correctly.</p>
                <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
                <p style='color:#999;font-size:12px;'>Mowology Weather Guard — test message</p>
            </div>
        ";

        $result = sendEmail($adminEmail, $subject, $htmlBody);

        echo json_encode([
            'success' => $result['success'],
            'message' => $result['success'] ? "Test email sent to {$adminEmail}" : 'Email failed',
            'error'   => $result['error'] ?? null,
            'to'      => $adminEmail,
        ]);

    } elseif ($action === 'test-sms') {
        // Send a test weather SMS to the current admin user
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        require_once CRM_INCLUDES . '/messaging.php';

        // Get current user's phone number
        $stmt = $db->prepare("SELECT phone, full_name FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        $phone = $u['phone'] ?? '';
        if (empty($phone)) {
            throw new Exception('Your user account has no phone number set. Add one on the Team page.');
        }

        $msg = "Mowology test: Weather SMS alerts are working. " . date('M j, g:iA');

        $result = sendSms($phone, $msg);

        echo json_encode([
            'success' => $result['success'],
            'message' => $result['success'] ? "Test SMS sent to {$phone}" : 'SMS failed',
            'error'   => $result['error'] ?? null,
            'to'      => $phone,
        ]);

    } elseif ($action === 'get-holidays') {
        // Ensure table exists
        ensureHolidaysTable($db);

        $stmt = $db->prepare("SELECT id, holiday_date, name, is_annual, is_active FROM company_holidays ORDER BY holiday_date ASC");
        $stmt->execute();
        $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'holidays' => $holidays]);

    } elseif ($action === 'save-holiday') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }
        ensureHolidaysTable($db);

        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $holidayDate = $data['holiday_date'] ?? null;
        $name = trim($data['name'] ?? '');
        $isAnnual = !empty($data['is_annual']) ? 1 : 0;
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        if (!$holidayDate || !$name) {
            throw new Exception('Date and name are required');
        }

        if ($id > 0) {
            // Update existing
            $stmt = $db->prepare("
                UPDATE company_holidays SET holiday_date = ?, name = ?, is_annual = ?, is_active = ? WHERE id = ?
            ");
            $stmt->execute([$holidayDate, $name, $isAnnual, $isActive, $id]);
        } else {
            // Insert new (ON DUPLICATE KEY UPDATE handles same-date conflicts)
            $stmt = $db->prepare("
                INSERT INTO company_holidays (holiday_date, name, is_annual, is_active)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), is_annual = VALUES(is_annual), is_active = VALUES(is_active)
            ");
            $stmt->execute([$holidayDate, $name, $isAnnual, $isActive]);
        }

        echo json_encode(['success' => true, 'message' => 'Holiday saved']);

    } elseif ($action === 'delete-holiday') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }
        ensureHolidaysTable($db);

        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if (!$id) throw new Exception('Holiday ID is required');

        $stmt = $db->prepare("DELETE FROM company_holidays WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Holiday deleted']);

    } elseif ($action === 'seed-holidays') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }
        ensureHolidaysTable($db);

        $data = json_decode(file_get_contents('php://input'), true);
        $year = (int)($data['year'] ?? date('Y'));

        $bcHolidays = getBCStatutoryHolidays($year);
        $inserted = 0;

        $stmt = $db->prepare("
            INSERT INTO company_holidays (holiday_date, name, is_annual, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE name = VALUES(name), is_annual = VALUES(is_annual)
        ");

        foreach ($bcHolidays as $h) {
            $stmt->execute([$h['date'], $h['name'], $h['is_annual'] ? 1 : 0]);
            $inserted++;
        }

        echo json_encode(['success' => true, 'message' => "Seeded {$inserted} BC statutory holidays for {$year}", 'count' => $inserted]);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action ?? ''));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


// ── Helper Functions ─────────────────────────────────────

/**
 * Ensure the company_holidays table exists (auto-create if missing).
 */
function ensureHolidaysTable(PDO $db): void {
    $check = $db->query("SHOW TABLES LIKE 'company_holidays'");
    if ($check->rowCount() === 0) {
        $db->exec("
            CREATE TABLE company_holidays (
                id INT AUTO_INCREMENT PRIMARY KEY,
                holiday_date DATE NOT NULL,
                name VARCHAR(100) NOT NULL,
                is_annual TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_holiday_date (holiday_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

/**
 * Calculate BC/Canadian statutory holidays for a given year.
 * Returns array of ['date' => 'YYYY-MM-DD', 'name' => '...', 'is_annual' => bool].
 */
function getBCStatutoryHolidays(int $year): array {
    $holidays = [];

    // Fixed-date holidays (is_annual = true)
    $holidays[] = ['date' => "$year-01-01", 'name' => "New Year's Day", 'is_annual' => true];
    $holidays[] = ['date' => "$year-07-01", 'name' => 'Canada Day', 'is_annual' => true];
    $holidays[] = ['date' => "$year-11-11", 'name' => 'Remembrance Day', 'is_annual' => true];
    $holidays[] = ['date' => "$year-12-25", 'name' => 'Christmas Day', 'is_annual' => true];
    $holidays[] = ['date' => "$year-12-26", 'name' => 'Boxing Day', 'is_annual' => true];
    $holidays[] = ['date' => "$year-09-30", 'name' => 'Truth & Reconciliation Day', 'is_annual' => true];

    // Floating holidays (calculated, NOT annual — regenerate per year)

    // Family Day: 3rd Monday of February
    $holidays[] = ['date' => nthWeekdayOfMonth($year, 2, 1, 3), 'name' => 'Family Day', 'is_annual' => false];

    // Good Friday: Friday before Easter Sunday
    $easter = date('Y-m-d', easter_date($year));
    $goodFriday = date('Y-m-d', strtotime('-2 days', strtotime($easter)));
    $holidays[] = ['date' => $goodFriday, 'name' => 'Good Friday', 'is_annual' => false];

    // Victoria Day: Monday before May 25
    $may25 = new DateTime("$year-05-25");
    $dow = (int)$may25->format('w');
    $offset = ($dow === 1) ? 0 : (($dow === 0) ? 1 : $dow - 1);
    $may25->modify("-{$offset} days");
    $holidays[] = ['date' => $may25->format('Y-m-d'), 'name' => 'Victoria Day', 'is_annual' => false];

    // BC Day: 1st Monday of August
    $holidays[] = ['date' => nthWeekdayOfMonth($year, 8, 1, 1), 'name' => 'BC Day', 'is_annual' => false];

    // Labour Day: 1st Monday of September
    $holidays[] = ['date' => nthWeekdayOfMonth($year, 9, 1, 1), 'name' => 'Labour Day', 'is_annual' => false];

    // Thanksgiving: 2nd Monday of October
    $holidays[] = ['date' => nthWeekdayOfMonth($year, 10, 1, 2), 'name' => 'Thanksgiving', 'is_annual' => false];

    return $holidays;
}

/**
 * Get the Nth occurrence of a weekday in a month.
 * @param int $year
 * @param int $month 1-12
 * @param int $weekday 0=Sun, 1=Mon, ..., 6=Sat
 * @param int $nth 1=first, 2=second, 3=third, etc.
 * @return string YYYY-MM-DD
 */
function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): string {
    $first = new DateTime("$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01");
    $firstDow = (int)$first->format('w');
    $diff = ($weekday - $firstDow + 7) % 7;
    $first->modify("+{$diff} days");
    $first->modify("+" . ($nth - 1) . " weeks");
    return $first->format('Y-m-d');
}
