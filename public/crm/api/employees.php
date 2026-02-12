<?php
/**
 * Employees API — CRUD for employee management
 * GET:  ?action=get&id=X — single employee data
 * POST: {action: 'create', ...fields} — create new employee
 * POST: {action: 'update', id, ...fields} — update existing employee
 */
declare(strict_types=1);
header('Content-Type: application/json');

try {
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';

    requireLogin();
    $user = getCurrentUser();

    // Admin only for create/update; admin/manager for read
    $isAdmin = ($user['role'] === 'admin');
    $isManager = in_array($user['role'], ['admin', 'manager']);

    $db = getDB();

    // Determine action
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'get':
            if (!$isManager) {
                throw new Exception('Access denied');
            }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            $stmt = $db->prepare("
                SELECT id, email, full_name, phone, role, is_active,
                       hourly_rate, hire_date, emergency_contact, notes,
                       last_login, created_at
                FROM users WHERE id = ?
            ");
            $stmt->execute([$id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emp) throw new Exception('Employee not found');

            echo json_encode(['success' => true, 'employee' => $emp]);
            break;

        case 'create':
            if (!$isAdmin) throw new Exception('Admin access required');

            // Validate CSRF
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            // Required fields
            $fullName = trim($input['full_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $role = $input['role'] ?? 'user';

            if (!$fullName) throw new Exception('Full name is required');
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email is required');
            if (strlen($password) < 8) throw new Exception('Password must be at least 8 characters');
            if (!in_array($role, ['admin', 'manager', 'user'])) throw new Exception('Invalid role');

            // Check email uniqueness
            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) throw new Exception('Email already exists');

            // Optional fields
            $phone = trim($input['phone'] ?? '') ?: null;
            $hourlyRate = isset($input['hourly_rate']) && $input['hourly_rate'] !== '' ? (float)$input['hourly_rate'] : null;
            $hireDate = !empty($input['hire_date']) ? $input['hire_date'] : null;
            $emergencyContact = trim($input['emergency_contact'] ?? '') ?: null;
            $notes = trim($input['notes'] ?? '') ?: null;

            $stmt = $db->prepare("
                INSERT INTO users (email, password_hash, full_name, phone, role,
                                   hourly_rate, hire_date, emergency_contact, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $email,
                hashPassword($password),
                $fullName,
                $phone,
                $role,
                $hourlyRate,
                $hireDate,
                $emergencyContact,
                $notes,
            ]);

            $newId = (int)$db->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Employee created successfully',
                'employee_id' => $newId,
            ]);
            break;

        case 'update':
            if (!$isAdmin) throw new Exception('Admin access required');

            // Validate CSRF
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Employee ID required');

            // Verify employee exists
            $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) throw new Exception('Employee not found');

            // Build update fields
            $updates = [];
            $params = [];

            if (isset($input['full_name']) && trim($input['full_name'])) {
                $updates[] = 'full_name = ?';
                $params[] = trim($input['full_name']);
            }

            if (isset($input['email']) && filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                // Check uniqueness
                $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $emailCheck->execute([trim($input['email']), $id]);
                if ($emailCheck->fetch()) throw new Exception('Email already in use by another employee');
                $updates[] = 'email = ?';
                $params[] = trim($input['email']);
            }

            if (isset($input['phone'])) {
                $updates[] = 'phone = ?';
                $params[] = trim($input['phone']) ?: null;
            }

            if (isset($input['role']) && in_array($input['role'], ['admin', 'manager', 'user'])) {
                $updates[] = 'role = ?';
                $params[] = $input['role'];
            }

            if (isset($input['hourly_rate'])) {
                $updates[] = 'hourly_rate = ?';
                $params[] = $input['hourly_rate'] !== '' ? (float)$input['hourly_rate'] : null;
            }

            if (isset($input['hire_date'])) {
                $updates[] = 'hire_date = ?';
                $params[] = !empty($input['hire_date']) ? $input['hire_date'] : null;
            }

            if (isset($input['emergency_contact'])) {
                $updates[] = 'emergency_contact = ?';
                $params[] = trim($input['emergency_contact']) ?: null;
            }

            if (isset($input['notes'])) {
                $updates[] = 'notes = ?';
                $params[] = trim($input['notes']) ?: null;
            }

            if (isset($input['is_active'])) {
                $updates[] = 'is_active = ?';
                $params[] = $input['is_active'] ? 1 : 0;
            }

            // Optional password reset
            if (!empty($input['password']) && strlen($input['password']) >= 8) {
                $updates[] = 'password_hash = ?';
                $params[] = hashPassword($input['password']);
            }

            if (empty($updates)) throw new Exception('No changes provided');

            $params[] = $id;
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Employee updated successfully',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: get, create, update']);
    }

} catch (PDOException $e) {
    error_log('Employees API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A database error occurred. Please try again.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
