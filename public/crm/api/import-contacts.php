<?php
/**
 * Contact Import API
 *
 * POST ?action=upload   — upload CSV, return headers + preview rows
 * POST ?action=preview  — with field mapping, return preview with duplicate detection
 * POST ?action=execute  — perform the actual import
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
$user = getCurrentUser();
session_write_close();

if (!function_exists('userHasPermission') || !userHasPermission('settings.edit')) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

// Available CRM fields for mapping
$crmFields = [
    'first_name' => 'First Name',
    'last_name'  => 'Last Name',
    'email'      => 'Email',
    'phone'      => 'Phone',
    'mobile'     => 'Mobile',
    'preferred_contact_method' => 'Preferred Contact Method',
    'notes'      => 'Notes',
    '_skip'      => '(Skip this column)',
];

// Auto-detect mapping from header names
function autoDetect(string $header): string {
    $h = strtolower(trim($header));
    $map = [
        'first name' => 'first_name', 'first' => 'first_name', 'given name' => 'first_name',
        'last name' => 'last_name', 'last' => 'last_name', 'surname' => 'last_name', 'family name' => 'last_name',
        'email' => 'email', 'e-mail' => 'email', 'email address' => 'email',
        'phone' => 'phone', 'telephone' => 'phone', 'phone number' => 'phone',
        'mobile' => 'mobile', 'cell' => 'mobile', 'cell phone' => 'mobile',
        'notes' => 'notes', 'note' => 'notes', 'comments' => 'notes',
    ];
    return $map[$h] ?? '_skip';
}

try {
    switch ($action) {
        case 'upload':
            if (empty($_FILES['csv_file'])) {
                echo json_encode(['error' => 'No file uploaded']);
                exit;
            }

            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'Upload failed (error code ' . $file['error'] . ')']);
                exit;
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(['error' => 'File too large (max 2MB)']);
                exit;
            }

            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                echo json_encode(['error' => 'Cannot read file']);
                exit;
            }

            // Read headers
            $headers = fgetcsv($handle);
            if (!$headers) {
                echo json_encode(['error' => 'Empty CSV or invalid format']);
                exit;
            }
            // Strip BOM
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

            // Read preview rows (first 5)
            $previewRows = [];
            $allRows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $allRows[] = $row;
                if (count($previewRows) < 5) {
                    $previewRows[] = $row;
                }
            }
            fclose($handle);

            // Store in session for execute step
            $_SESSION['import_csv_headers'] = $headers;
            $_SESSION['import_csv_data']    = $allRows;
            $_SESSION['import_csv_name']    = $file['name'];

            // Auto-detect mapping
            $mapping = [];
            foreach ($headers as $h) {
                $mapping[] = autoDetect($h);
            }

            echo json_encode([
                'success'     => true,
                'headers'     => $headers,
                'preview'     => $previewRows,
                'total_rows'  => count($allRows),
                'mapping'     => $mapping,
                'crm_fields'  => $crmFields,
            ]);
            break;

        case 'execute':
            session_start();
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                exit;
            }

            $headers = $_SESSION['import_csv_headers'] ?? [];
            $allRows = $_SESSION['import_csv_data'] ?? [];
            $mapping = $input['mapping'] ?? [];
            $filename = $_SESSION['import_csv_name'] ?? 'unknown.csv';

            if (!$headers || !$allRows) {
                echo json_encode(['error' => 'No CSV data in session. Please re-upload.']);
                exit;
            }

            $imported = 0;
            $updated  = 0;
            $skipped  = 0;
            $errors   = [];

            foreach ($allRows as $rowIndex => $row) {
                // Map CSV columns to CRM fields
                $record = [];
                foreach ($mapping as $colIndex => $fieldKey) {
                    if ($fieldKey === '_skip' || !isset($row[$colIndex])) continue;
                    $record[$fieldKey] = trim($row[$colIndex]);
                }

                // Validate minimum data
                if (empty($record['first_name']) && empty($record['last_name']) && empty($record['email'])) {
                    $skipped++;
                    continue;
                }

                // Duplicate detection by email or phone
                $existingId = null;
                if (!empty($record['email'])) {
                    $dup = $db->prepare("SELECT id FROM contacts WHERE email = ? LIMIT 1");
                    $dup->execute([$record['email']]);
                    $existingId = $dup->fetchColumn();
                }
                if (!$existingId && !empty($record['phone'])) {
                    $dup = $db->prepare("SELECT id FROM contacts WHERE phone = ? OR mobile = ? LIMIT 1");
                    $dup->execute([$record['phone'], $record['phone']]);
                    $existingId = $dup->fetchColumn();
                }

                try {
                    if ($existingId) {
                        // Update existing
                        $sets = [];
                        $vals = [];
                        foreach (['first_name','last_name','email','phone','mobile','notes'] as $f) {
                            if (!empty($record[$f])) {
                                $sets[] = "{$f} = ?";
                                $vals[] = $record[$f];
                            }
                        }
                        if ($sets) {
                            $vals[] = $existingId;
                            $db->prepare("UPDATE contacts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                        }
                        $updated++;
                    } else {
                        // Insert new
                        $fullName = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
                        $db->prepare("
                            INSERT INTO contacts (first_name, last_name, full_name, email, phone, mobile, preferred_contact_method, notes, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ")->execute([
                            $record['first_name'] ?? '', $record['last_name'] ?? '', $fullName,
                            $record['email'] ?? null, $record['phone'] ?? null, $record['mobile'] ?? null,
                            $record['preferred_contact_method'] ?? 'phone', $record['notes'] ?? null,
                        ]);
                        $imported++;
                    }
                } catch (Throwable $e) {
                    $errors[] = ['row' => $rowIndex + 2, 'error' => $e->getMessage()];
                }
            }

            // Log import
            $db->prepare("
                INSERT INTO import_logs (import_type, filename, total_rows, imported_count, updated_count, skipped_count, error_count, errors, field_mapping, imported_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                'contacts', $filename, count($allRows), $imported, $updated, $skipped, count($errors),
                $errors ? json_encode($errors) : null,
                json_encode($mapping),
                $user['id'],
            ]);

            // Clean session
            unset($_SESSION['import_csv_headers'], $_SESSION['import_csv_data'], $_SESSION['import_csv_name']);

            echo json_encode([
                'success'  => true,
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'errors'   => $errors,
                'total'    => count($allRows),
            ]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log("Import contacts error: " . $e->getMessage());
}
