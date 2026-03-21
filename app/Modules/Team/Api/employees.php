<?php
/**
 * Employees API — CRUD for employee management + HR fields
 *
 * GET:  ?action=get&id=X        — single employee (operational fields)
 * GET:  ?action=get_sin&id=X    — decrypt + return SIN (hr.edit only)
 * POST: {action: 'create', ...} — create new employee
 * POST: {action: 'update', ...} — update operational fields
 * POST: {action: 'update_hr', ...}      — update personal/address/SIN (hr.edit)
 * POST: {action: 'update_payroll', ...} — update banking/direct deposit (hr.edit)
 * POST: {action: 'update_td1', ...}     — update TD1 credits + notes (hr.edit)
 * POST: {action: 'update_roles', ...}  — update RBAC role assignments (users.manage)
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('team.view');

    $canEdit    = userHasPermission('team.edit');
    $canHrView  = userHasPermission('hr.view');
    $canHrEdit  = userHasPermission('hr.edit');

    $db = getDB();

    // ── Encryption helpers ─────────────────────────────────────────────────
    function encryptField(string $plain): string {
        $key = defined('APP_ENCRYPTION_KEY') ? APP_ENCRYPTION_KEY : '';
        if (!$key) throw new RuntimeException('Encryption key not configured');
        $iv         = random_bytes(16);
        $ciphertext = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) throw new RuntimeException('Encryption failed');
        return base64_encode($iv . $ciphertext);
    }

    function decryptField(?string $encrypted): string {
        if (!$encrypted) return '';
        $key = defined('APP_ENCRYPTION_KEY') ? APP_ENCRYPTION_KEY : '';
        if (!$key) return '';
        try {
            $data = base64_decode($encrypted);
            if (strlen($data) < 16) return '';
            $iv         = substr($data, 0, 16);
            $ciphertext = substr($data, 16);
            $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return $plain === false ? '' : $plain;
        } catch (Throwable $e) {
            return '';
        }
    }

    // ── Action routing ─────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
    }

    switch ($action) {

        // ── GET: single employee (operational fields) ──────────────────────
        case 'get':
            if (!$canEdit) throw new Exception('Access denied');

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $stmt = $db->prepare("
                SELECT id, email, full_name, first_name, last_name, phone, role, is_active,
                       hourly_rate, hire_date, emergency_contact, notes,
                       location_tracking_enabled, last_login, created_at,
                       IFNULL(receive_weather_sms, 1)     AS receive_weather_sms,
                       IFNULL(device_type, 'personal')    AS device_type,
                       IFNULL(location_ping_rate, 'high') AS location_ping_rate
                FROM users WHERE id = ?
            ");
            $stmt->execute([$id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emp) throw new Exception('Employee not found');

            echo json_encode(['success' => true, 'employee' => $emp]);
            break;

        // ── GET: reveal driver's licence number (hr.edit only, logged) ────
        case 'get_dl':
            if (!$canHrEdit) throw new Exception('Access denied — requires hr.edit permission');

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $stmt = $db->prepare("SELECT dl_number_encrypted, full_name FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Employee not found');

            $dl = decryptField($row['dl_number_encrypted'] ?? '');
            if (!$dl) {
                echo json_encode(['success' => false, 'error' => 'No licence number on file']);
                break;
            }

            logActivity($user['id'], null, "Revealed driver's licence for employee #{$id} ({$row['full_name']})", null);
            echo json_encode(['success' => true, 'dl_number' => $dl]);
            break;

        // ── GET: reveal SIN (admin/hr.edit only, logged) ───────────────────
        case 'get_sin':
            if (!$canHrEdit) throw new Exception('Access denied — requires hr.edit permission');

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $stmt = $db->prepare("SELECT sin_encrypted, full_name FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Employee not found');

            $sin = decryptField($row['sin_encrypted'] ?? '');
            if (!$sin) {
                echo json_encode(['success' => false, 'error' => 'No SIN on file']);
                break;
            }

            // Format as ### ### ###
            $digits = preg_replace('/\D/', '', $sin);
            $formatted = strlen($digits) === 9
                ? substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3)
                : $sin;

            // Audit log
            logActivity($user['id'], null, 'Revealed SIN for employee #' . $id . ' (' . ($row['full_name'] ?? '') . ')', null);

            echo json_encode(['success' => true, 'sin' => $formatted]);
            break;

        // ── POST: create employee ──────────────────────────────────────────
        case 'create':
            if (!$canEdit) throw new Exception('Admin access required');
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

            $firstName = trim($input['first_name'] ?? '');
            $lastName  = trim($input['last_name'] ?? '');
            // Fall back to full_name if first/last not provided (backward compat)
            if (!$firstName && !$lastName && !empty($input['full_name'])) {
                $parts = explode(' ', trim($input['full_name']), 2);
                $firstName = $parts[0];
                $lastName  = $parts[1] ?? '';
            }
            $fullName = trim($firstName . ' ' . $lastName);
            $email    = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $role     = $input['role'] ?? 'user';

            if (!$firstName) throw new Exception('First name is required');
            if (!$lastName) throw new Exception('Last name is required');
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email is required');
            if (strlen($password) < 8) throw new Exception('Password must be at least 8 characters');
            if (!in_array($role, ['admin', 'manager', 'user'])) throw new Exception('Invalid role');

            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) throw new Exception('Email already exists');

            $phone          = trim($input['phone'] ?? '') ?: null;
            $hourlyRate     = isset($input['hourly_rate']) && $input['hourly_rate'] !== '' ? (float)$input['hourly_rate'] : null;
            $hireDate       = !empty($input['hire_date']) ? $input['hire_date'] : null;
            $emergencyContact = trim($input['emergency_contact'] ?? '') ?: null;
            $notes          = trim($input['notes'] ?? '') ?: null;
            $receiveWeatherSms = isset($input['receive_weather_sms']) ? ($input['receive_weather_sms'] ? 1 : 0) : 1;

            $stmt = $db->prepare("
                INSERT INTO users (email, password_hash, full_name, first_name, last_name, phone, role,
                                   hourly_rate, hire_date, emergency_contact, notes, is_active, receive_weather_sms)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->execute([
                $email,
                hashPassword($password),
                $fullName,
                $firstName,
                $lastName,
                $phone,
                $role,
                $hourlyRate,
                $hireDate,
                $emergencyContact,
                $notes,
                $receiveWeatherSms,
            ]);

            $newId = (int)$db->lastInsertId();
            echo json_encode(['success' => true, 'message' => 'Employee created successfully', 'employee_id' => $newId]);
            break;

        // ── POST: update operational fields ───────────────────────────────
        case 'update':
            if (!$canEdit) throw new Exception('Admin access required');
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) throw new Exception('Employee not found');

            $updates = [];
            $params  = [];

            // Handle first_name / last_name → auto-sync full_name
            if (isset($input['first_name']) || isset($input['last_name'])) {
                // Fetch current values for any missing field
                $curStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $curStmt->execute([$id]);
                $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
                $fn = isset($input['first_name']) ? trim($input['first_name']) : ($cur['first_name'] ?? '');
                $ln = isset($input['last_name'])  ? trim($input['last_name'])  : ($cur['last_name'] ?? '');
                if ($fn) { $updates[] = 'first_name = ?'; $params[] = $fn; }
                if ($ln !== '') { $updates[] = 'last_name = ?'; $params[] = $ln; }
                $composedName = trim($fn . ' ' . $ln);
                if ($composedName) { $updates[] = 'full_name = ?'; $params[] = $composedName; }
            } elseif (isset($input['full_name']) && trim($input['full_name'])) {
                // Backward compat: if only full_name sent, split into first/last
                $parts = explode(' ', trim($input['full_name']), 2);
                $updates[] = 'full_name = ?'; $params[] = trim($input['full_name']);
                $updates[] = 'first_name = ?'; $params[] = $parts[0];
                $updates[] = 'last_name = ?'; $params[] = $parts[1] ?? '';
            }
            if (isset($input['email']) && filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $emailCheck->execute([trim($input['email']), $id]);
                if ($emailCheck->fetch()) throw new Exception('Email already in use by another employee');
                $updates[] = 'email = ?'; $params[] = trim($input['email']);
            }
            if (isset($input['phone'])) {
                $updates[] = 'phone = ?'; $params[] = trim($input['phone']) ?: null;
            }
            if (isset($input['role']) && in_array($input['role'], ['admin', 'manager', 'user'])) {
                $updates[] = 'role = ?'; $params[] = $input['role'];
            }
            if (isset($input['hourly_rate'])) {
                $updates[] = 'hourly_rate = ?';
                $params[]  = $input['hourly_rate'] !== '' ? (float)$input['hourly_rate'] : null;
            }
            if (isset($input['hire_date'])) {
                $updates[] = 'hire_date = ?'; $params[] = !empty($input['hire_date']) ? $input['hire_date'] : null;
            }
            if (isset($input['emergency_contact'])) {
                $updates[] = 'emergency_contact = ?'; $params[] = trim($input['emergency_contact']) ?: null;
            }
            if (isset($input['notes'])) {
                $updates[] = 'notes = ?'; $params[] = trim($input['notes']) ?: null;
            }
            if (isset($input['is_active'])) {
                $updates[] = 'is_active = ?'; $params[] = $input['is_active'] ? 1 : 0;
            }
            if (isset($input['location_tracking_enabled'])) {
                $updates[] = 'location_tracking_enabled = ?'; $params[] = $input['location_tracking_enabled'] ? 1 : 0;
            }
            if (isset($input['receive_weather_sms'])) {
                $updates[] = 'receive_weather_sms = ?'; $params[] = $input['receive_weather_sms'] ? 1 : 0;
            }
            if (isset($input['is_driver'])) {
                $updates[] = 'is_driver = ?'; $params[] = $input['is_driver'] ? 1 : 0;
            }
            if (isset($input['device_type']) && in_array($input['device_type'], ['personal', 'truck'])) {
                $updates[] = 'device_type = ?'; $params[] = $input['device_type'];
            }
            if (isset($input['location_ping_rate']) && in_array($input['location_ping_rate'], ['low', 'medium', 'high'])) {
                $updates[] = 'location_ping_rate = ?'; $params[] = $input['location_ping_rate'];
            }
            if (!empty($input['password']) && strlen($input['password']) >= 8) {
                $updates[] = 'password_hash = ?'; $params[] = hashPassword($input['password']);
            }

            if (empty($updates)) throw new Exception('No changes provided');

            $params[] = $id;
            $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

            echo json_encode(['success' => true, 'message' => 'Employee updated successfully']);
            break;

        // ── POST: update HR details (address, DOB, SIN) ───────────────────
        case 'update_hr':
            if (!$canHrEdit) throw new Exception('Access denied — requires hr.edit permission');
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $updates = [];
            $params  = [];

            if (array_key_exists('date_of_birth', $input)) {
                $updates[] = 'date_of_birth = ?';
                $params[]  = !empty($input['date_of_birth']) ? $input['date_of_birth'] : null;
            }
            if (array_key_exists('address', $input)) {
                $updates[] = 'address = ?'; $params[] = trim($input['address']) ?: null;
            }
            if (array_key_exists('city', $input)) {
                $updates[] = 'city = ?'; $params[] = trim($input['city']) ?: null;
            }
            if (array_key_exists('province', $input)) {
                $updates[] = 'province = ?'; $params[] = trim($input['province']) ?: null;
            }
            if (array_key_exists('postal_code', $input)) {
                $updates[] = 'postal_code = ?'; $params[] = trim($input['postal_code']) ?: null;
            }

            // SIN — only update if a new value was provided (non-empty)
            if (!empty($input['sin'])) {
                $sinDigits = preg_replace('/\D/', '', $input['sin']);
                if (strlen($sinDigits) !== 9) throw new Exception('SIN must be exactly 9 digits');
                $updates[] = 'sin_encrypted = ?';
                $params[]  = encryptField($sinDigits);
            }

            // Driver's licence — number stored encrypted, others plaintext
            if (!empty($input['dl_number'])) {
                $updates[] = 'dl_number_encrypted = ?';
                $params[]  = encryptField(trim($input['dl_number']));
            }
            if (array_key_exists('dl_class', $input)) {
                $updates[] = 'dl_class = ?';    $params[] = trim($input['dl_class']) ?: null;
            }
            if (array_key_exists('dl_province', $input)) {
                $updates[] = 'dl_province = ?'; $params[] = trim($input['dl_province']) ?: null;
            }
            if (array_key_exists('dl_expiry', $input)) {
                $updates[] = 'dl_expiry = ?';   $params[] = !empty($input['dl_expiry']) ? $input['dl_expiry'] : null;
            }
            if (array_key_exists('is_driver', $input)) {
                $updates[] = 'is_driver = ?';   $params[] = $input['is_driver'] ? 1 : 0;
            }

            if (empty($updates)) throw new Exception('No changes provided');

            $params[] = $id;
            $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

            logActivity($user['id'], null, 'Updated HR details for employee #' . $id, null);

            echo json_encode(['success' => true, 'message' => 'HR details updated']);
            break;

        // ── POST: update banking / direct deposit ──────────────────────────
        case 'update_payroll':
            if (!$canHrEdit) throw new Exception('Access denied — requires hr.edit permission');
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $updates = [];
            $params  = [];

            if (array_key_exists('bank_transit', $input)) {
                $transit = preg_replace('/\D/', '', trim($input['bank_transit'] ?? ''));
                if ($transit !== '' && strlen($transit) !== 5) throw new Exception('Transit number must be 5 digits');
                $updates[] = 'bank_transit = ?'; $params[] = $transit ?: null;
            }
            if (array_key_exists('bank_institution', $input)) {
                $inst = preg_replace('/\D/', '', trim($input['bank_institution'] ?? ''));
                if ($inst !== '' && strlen($inst) !== 3) throw new Exception('Institution number must be 3 digits');
                $updates[] = 'bank_institution = ?'; $params[] = $inst ?: null;
            }
            if (!empty($input['bank_account'])) {
                $acct = preg_replace('/\D/', '', trim($input['bank_account']));
                if (strlen($acct) < 5 || strlen($acct) > 12) throw new Exception('Account number must be 5–12 digits');
                $updates[] = 'bank_account_encrypted = ?';
                $params[]  = encryptField($acct);
            }

            if (empty($updates)) throw new Exception('No changes provided');

            $params[] = $id;
            $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

            logActivity($user['id'], null, 'Updated banking details for employee #' . $id, null);

            echo json_encode(['success' => true, 'message' => 'Banking details updated']);
            break;

        // ── POST: update TD1 credits ───────────────────────────────────────
        case 'update_td1':
            if (!$canHrEdit) throw new Exception('Access denied — requires hr.edit permission');
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $updates = [];
            $params  = [];

            if (array_key_exists('td1_federal', $input)) {
                $updates[] = 'td1_federal = ?';
                $params[]  = $input['td1_federal'] !== '' ? (float)$input['td1_federal'] : null;
            }
            if (array_key_exists('td1_provincial', $input)) {
                $updates[] = 'td1_provincial = ?';
                $params[]  = $input['td1_provincial'] !== '' ? (float)$input['td1_provincial'] : null;
            }
            if (array_key_exists('td1_notes', $input)) {
                $updates[] = 'td1_notes = ?'; $params[] = trim($input['td1_notes']) ?: null;
            }

            if (empty($updates)) throw new Exception('No changes provided');

            $params[] = $id;
            $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

            echo json_encode(['success' => true, 'message' => 'TD1 updated']);
            break;

        // ── POST: update RBAC role assignments ────────────────────────────
        case 'update_roles':
            if (!userHasPermission('users.manage')) throw new Exception('Access denied — requires users.manage permission');
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

            $targetId = (int)($input['id'] ?? 0);
            if (!$targetId) throw new Exception('Employee ID required');

            // Prevent removing your own admin role
            $roleIds = array_filter(array_map('intval', $input['roles'] ?? []));
            if ($targetId === $user['id']) {
                $adminRoleId = (int)$db->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetchColumn();
                if ($adminRoleId && !in_array($adminRoleId, $roleIds)) {
                    throw new Exception('You cannot remove your own admin role.');
                }
            }

            $db->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$targetId]);
            $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roleIds as $rid) {
                $stmt->execute([$targetId, $rid]);
            }

            logActivity($user['id'], null, 'Updated RBAC roles for user #' . $targetId, implode(',', $roleIds));
            echo json_encode(['success' => true, 'message' => 'Roles updated']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: get, get_sin, get_dl, create, update, update_hr, update_payroll, update_td1, update_roles']);
    }

} catch (PDOException $e) {
    error_log('Employees API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A database error occurred. Please try again.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
