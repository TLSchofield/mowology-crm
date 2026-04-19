<?php
/**
 * Jobber Re-Consent Campaign API
 *
 * Actions:
 *   preview     — parse CSVs and show importable/duplicate counts
 *   import      — import Jobber contacts into contacts + properties + queue
 *   fsa-review  — list FSA blocks with contact counts for approval
 *   approve-fsa — approve or skip an FSA block
 *   approve-all — approve all FSA blocks at once
 *   stats       — campaign progress stats
 *   send-test   — send one opt-in email for testing
 *   trigger-send — manually trigger a batch send (same as cron)
 *
 * @package Mowology\Marketing
 */

declare(strict_types=1);

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
require_once CRM_INCLUDES . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

session_write_close();
requireLogin();
$user = getCurrentUser();
requirePermission('admin');

$action = $_GET['action'] ?? '';
$db = getDB();

try {
    switch ($action) {

        // ── Preview CSV files ───────────────────────────────────────────
        case 'preview':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $file1 = $input['file1'] ?? '';
            $file2 = $input['file2'] ?? '';

            if (empty($file1)) {
                echo json_encode(['success' => false, 'error' => 'At least one CSV file path required']); break;
            }

            $allRows = [];
            foreach ([$file1, $file2] as $filePath) {
                if (empty($filePath) || !is_file($filePath)) continue;
                $parsed = parseJobberCsv($filePath);
                $allRows = array_merge($allRows, $parsed);
            }

            if (empty($allRows)) {
                echo json_encode(['success' => false, 'error' => 'No valid rows found in CSV files']); break;
            }

            // Deduplicate by email within CSV data
            $seen = [];
            $unique = [];
            $noEmail = [];
            $archived = [];
            foreach ($allRows as $row) {
                if ($row['archived']) { $archived[] = $row; continue; }
                if (empty($row['email'])) { $noEmail[] = $row; continue; }
                $key = strtolower(trim($row['email']));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $unique[] = $row;
            }

            // Check which emails already exist in contacts
            $existingEmails = [];
            if (!empty($unique)) {
                $placeholders = implode(',', array_fill(0, count($unique), '?'));
                $emails = array_map(fn($r) => strtolower(trim($r['email'])), $unique);
                $stmt = $db->prepare("SELECT LOWER(email) FROM contacts WHERE LOWER(email) IN ($placeholders)");
                $stmt->execute($emails);
                $existingEmails = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            $importable = [];
            $duplicates = [];
            foreach ($unique as $row) {
                $key = strtolower(trim($row['email']));
                if (isset($existingEmails[$key])) {
                    $duplicates[] = $row;
                } else {
                    $importable[] = $row;
                }
            }

            // Group importable by FSA for preview — sort by proximity priority
            $fsaPreview = [];
            foreach ($importable as $row) {
                $fsa = extractFsa($row['postal_code']) ?? 'No postal code';
                if (!isset($fsaPreview[$fsa])) $fsaPreview[$fsa] = 0;
                $fsaPreview[$fsa]++;
            }
            // Sort: proximity priority first, then count descending
            uksort($fsaPreview, function($a, $b) use ($fsaPreview) {
                $pa = computeSortPriority(false, $a === 'No postal code' ? null : $a);
                $pb = computeSortPriority(false, $b === 'No postal code' ? null : $b);
                if ($pa !== $pb) return $pa <=> $pb;
                return $fsaPreview[$b] <=> $fsaPreview[$a];
            });

            // Sort importable rows by priority for sample (so service-area shows first)
            usort($importable, function($a, $b) {
                $pa = computeSortPriority(false, extractFsa($a['postal_code']));
                $pb = computeSortPriority(false, extractFsa($b['postal_code']));
                return $pa <=> $pb;
            });

            // Sample rows for preview (first 20) — now ordered by proximity
            $sampleRows = array_slice($importable, 0, 20);
            $sampleData = array_map(fn($r) => [
                'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                'email' => $r['email'],
                'phone' => $r['phone'],
                'address' => $r['service_address'] ?: $r['billing_address'],
                'city' => $r['service_city'] ?: $r['billing_city'],
                'fsa' => extractFsa($r['postal_code']) ?? '—',
                'is_company' => $r['is_company'],
            ], $sampleRows);

            echo json_encode([
                'success' => true,
                'total_csv_rows' => count($allRows),
                'importable' => count($importable),
                'duplicates' => count($duplicates),
                'archived' => count($archived),
                'no_email' => count($noEmail),
                'fsa_preview' => $fsaPreview,
                'sample_rows' => $sampleData,
            ]);
            break;

        // ── Import contacts ─────────────────────────────────────────────
        case 'import':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $file1 = $input['file1'] ?? '';
            $file2 = $input['file2'] ?? '';

            if (empty($file1)) {
                echo json_encode(['success' => false, 'error' => 'At least one CSV file path required']); break;
            }

            $allRows = [];
            foreach ([$file1, $file2] as $filePath) {
                if (empty($filePath) || !is_file($filePath)) continue;
                $parsed = parseJobberCsv($filePath);
                $allRows = array_merge($allRows, $parsed);
            }

            // Deduplicate by email, skip archived + no-email
            $seen = [];
            $importable = [];
            foreach ($allRows as $row) {
                if ($row['archived'] || empty($row['email'])) continue;
                $key = strtolower(trim($row['email']));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $importable[] = $row;
            }

            // Get existing emails to skip
            $existingEmails = [];
            if (!empty($importable)) {
                $emails = array_map(fn($r) => strtolower(trim($r['email'])), $importable);
                $chunks = array_chunk($emails, 500);
                foreach ($chunks as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $stmt = $db->prepare("SELECT LOWER(email) FROM contacts WHERE LOWER(email) IN ($placeholders)");
                    $stmt->execute($chunk);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $e) {
                        $existingEmails[$e] = true;
                    }
                }
            }

            $batchId = 'jobber-' . date('Ymd-His');
            $imported = 0;
            $skipped = 0;

            $contactStmt = $db->prepare("
                INSERT INTO contacts (first_name, last_name, email, phone, mobile, is_active, prospect_status, import_source, notes, created_at)
                VALUES (?, ?, ?, ?, ?, 1, 'client', 'jobber', ?, NOW())
            ");

            $propertyStmt = $db->prepare("
                INSERT INTO properties (address, city, province, postal_code, site_contact_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");

            $queueStmt = $db->prepare("
                INSERT INTO jobber_reconsent_queue (contact_id, fsa, is_current_client, sort_priority, status, import_batch, created_at)
                VALUES (?, ?, ?, ?, 'pending_approval', ?, NOW())
            ");

            $db->beginTransaction();
            try {
                foreach ($importable as $row) {
                    $emailKey = strtolower(trim($row['email']));
                    if (isset($existingEmails[$emailKey])) {
                        $skipped++;
                        continue;
                    }

                    // Build notes from company name + tags
                    $notes = '';
                    if ($row['is_company'] && !empty($row['company_name'])) {
                        $notes .= '[Company: ' . $row['company_name'] . '] ';
                    }
                    if (!empty($row['tags'])) {
                        $notes .= '[Tags: ' . $row['tags'] . '] ';
                    }
                    if (!empty($row['lead_source'])) {
                        $notes .= '[Source: ' . $row['lead_source'] . ']';
                    }
                    $notes = trim($notes) ?: null;

                    // Insert contact
                    $contactStmt->execute([
                        $row['first_name'] ?: 'Valued',
                        $row['last_name'] ?: 'Customer',
                        $row['email'],
                        $row['phone'] ?: null,
                        $row['mobile'] ?: null,
                        $notes,
                    ]);
                    $contactId = (int)$db->lastInsertId();

                    // Insert property (prefer service address, fallback to billing)
                    $address  = $row['service_address'] ?: $row['billing_address'];
                    $city     = $row['service_city'] ?: $row['billing_city'];
                    $province = mapProvinceCode($row['service_province'] ?: $row['billing_province']);
                    $postal   = normalizePostalCode($row['postal_code']);

                    if (!empty($address)) {
                        $propertyStmt->execute([
                            $address,
                            $city ?: 'Vancouver',
                            $province ?: 'BC',
                            $postal ?: null,
                            $contactId,
                        ]);
                    }

                    // Queue for reconsent — compute priority
                    $fsa = extractFsa($postal);
                    $isCurrent = isCurrentClient($db, $contactId);
                    $sortPriority = computeSortPriority($isCurrent, $fsa);
                    $queueStmt->execute([$contactId, $fsa, $isCurrent ? 1 : 0, $sortPriority, $batchId]);

                    $imported++;
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
                break;
            }

            echo json_encode([
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'batch_id' => $batchId,
            ]);
            break;

        // ── FSA Review ──────────────────────────────────────────────────
        case 'fsa-review':
            $batchId = $_GET['batch_id'] ?? '';

            $batchWhere = '';
            $params = [];
            if ($batchId) {
                $batchWhere = 'AND jrq.import_batch = ?';
                $params[] = $batchId;
            }

            $stmt = $db->prepare("
                SELECT
                    COALESCE(jrq.fsa, '---') AS fsa,
                    COUNT(*) AS contact_count,
                    SUM(CASE WHEN jrq.fsa_approved = 1 THEN 1 ELSE 0 END) AS approved_count,
                    SUM(CASE WHEN jrq.status = 'skipped' THEN 1 ELSE 0 END) AS skipped_count,
                    SUM(CASE WHEN jrq.status = 'queued' THEN 1 ELSE 0 END) AS queued_count,
                    SUM(CASE WHEN jrq.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN jrq.is_current_client = 1 THEN 1 ELSE 0 END) AS current_client_count,
                    MIN(jrq.sort_priority) AS best_priority,
                    GROUP_CONCAT(DISTINCT CONCAT(c.first_name, ' ', c.last_name) ORDER BY c.last_name SEPARATOR ', ') AS all_names,
                    MIN(p.city) AS city
                FROM jobber_reconsent_queue jrq
                JOIN contacts c ON jrq.contact_id = c.id
                LEFT JOIN properties p ON p.site_contact_id = c.id
                WHERE 1=1 $batchWhere
                GROUP BY COALESCE(jrq.fsa, '---')
                ORDER BY best_priority ASC, contact_count DESC
            ");
            $stmt->execute($params);
            $fsaBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Trim sample names to first 5
            foreach ($fsaBlocks as &$block) {
                $names = explode(', ', $block['all_names']);
                $block['sample_names'] = implode(', ', array_slice($names, 0, 5));
                if (count($names) > 5) $block['sample_names'] .= ' ...';
                unset($block['all_names']);
            }

            echo json_encode(['success' => true, 'fsa_blocks' => $fsaBlocks]);
            break;

        // ── Approve / Skip an FSA block ─────────────────────────────────
        case 'approve-fsa':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $fsa = $input['fsa'] ?? '';
            $approved = (bool)($input['approved'] ?? false);
            $batchId = $input['batch_id'] ?? '';

            $newStatus = $approved ? 'queued' : 'skipped';
            $fsaApproved = $approved ? 1 : 0;

            $params = [$fsaApproved, $newStatus];

            if ($fsa === '---' || $fsa === '' || $fsa === null) {
                $fsaCondition = 'jrq.fsa IS NULL';
            } else {
                $fsaCondition = 'jrq.fsa = ?';
                $params[] = $fsa;
            }

            if ($batchId) {
                $fsaCondition .= ' AND jrq.import_batch = ?';
                $params[] = $batchId;
            }

            $stmt = $db->prepare("
                UPDATE jobber_reconsent_queue jrq
                SET fsa_approved = ?, status = ?
                WHERE $fsaCondition AND status IN ('pending_approval','skipped','queued')
            ");
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            echo json_encode(['success' => true, 'updated' => $affected]);
            break;

        // ── Approve all FSA blocks ──────────────────────────────────────
        case 'approve-all':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $batchId = $input['batch_id'] ?? '';

            $params = [];
            $batchWhere = '';
            if ($batchId) {
                $batchWhere = 'AND import_batch = ?';
                $params[] = $batchId;
            }

            $stmt = $db->prepare("
                UPDATE jobber_reconsent_queue
                SET fsa_approved = 1, status = 'queued'
                WHERE status IN ('pending_approval','skipped') $batchWhere
            ");
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            echo json_encode(['success' => true, 'updated' => $affected]);
            break;

        // ── Campaign stats ──────────────────────────────────────────────
        case 'stats':
            $batchId = $_GET['batch_id'] ?? '';

            $params = [];
            $batchWhere = '';
            if ($batchId) {
                $batchWhere = 'WHERE jrq.import_batch = ?';
                $params[] = $batchId;
            }

            $stmt = $db->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(jrq.status = 'pending_approval') AS pending_approval,
                    SUM(jrq.status = 'queued') AS queued,
                    SUM(jrq.status = 'sent') AS sent,
                    SUM(jrq.status = 'failed') AS failed,
                    SUM(jrq.status = 'skipped') AS skipped
                FROM jobber_reconsent_queue jrq
                $batchWhere
            ");
            $stmt->execute($params);
            $queueStats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get confirmation stats for sent contacts
            $confirmParams = [];
            $confirmBatch = '';
            if ($batchId) {
                $confirmBatch = 'AND jrq.import_batch = ?';
                $confirmParams[] = $batchId;
            }

            $stmt = $db->prepare("
                SELECT
                    COUNT(CASE WHEN mot.status = 'confirmed' THEN 1 END) AS confirmed,
                    COUNT(CASE WHEN mot.status = 'unsubscribed' THEN 1 END) AS unsubscribed,
                    COUNT(CASE WHEN mot.status = 'pending' THEN 1 END) AS awaiting
                FROM jobber_reconsent_queue jrq
                JOIN marketing_optin_tokens mot ON mot.contact_id = jrq.contact_id
                WHERE jrq.status = 'sent' $confirmBatch
            ");
            $stmt->execute($confirmParams);
            $responseStats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get batches list
            $batches = $db->query("
                SELECT import_batch, COUNT(*) AS cnt, MIN(created_at) AS created
                FROM jobber_reconsent_queue
                GROUP BY import_batch
                ORDER BY created DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'queue' => $queueStats,
                'responses' => $responseStats,
                'batches' => $batches,
            ]);
            break;

        // ── Send test email to one contact ──────────────────────────────
        case 'send-test':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $contactId = (int)($input['contact_id'] ?? 0);
            if (!$contactId) {
                echo json_encode(['success' => false, 'error' => 'contact_id required']); break;
            }

            $result = sendReconsentEmail($db, $contactId);
            if ($result['success']) {
                // Mark queue entry as sent
                $db->prepare("UPDATE jobber_reconsent_queue SET status='sent', sent_at=NOW() WHERE contact_id=? AND status='queued'")->execute([$contactId]);
            }
            echo json_encode($result);
            break;

        // ── Trigger manual batch send (same logic as cron) ──────────────
        case 'trigger-send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $limit = min(20, max(1, (int)($input['limit'] ?? 10)));

            $stmt = $db->prepare("
                SELECT jrq.id, jrq.contact_id, jrq.attempts, c.email, c.first_name
                FROM jobber_reconsent_queue jrq
                JOIN contacts c ON jrq.contact_id = c.id
                WHERE jrq.status = 'queued'
                  AND c.email IS NOT NULL AND c.email != ''
                  AND c.is_active = 1
                  AND jrq.attempts < 3
                ORDER BY jrq.sort_priority ASC, jrq.created_at ASC
                LIMIT $limit
            ");
            $stmt->execute();
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sent = 0;
            $failed = 0;
            foreach ($contacts as $contact) {
                $result = sendReconsentEmail($db, (int)$contact['contact_id']);
                if ($result['success']) {
                    $db->prepare("UPDATE jobber_reconsent_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$contact['id']]);
                    $sent++;
                } else {
                    $db->prepare("UPDATE jobber_reconsent_queue SET attempts=attempts+1, error_message=? WHERE id=?")->execute([$result['error'] ?? 'Unknown', $contact['id']]);
                    if ($contact['attempts'] ?? 0 >= 2) {
                        $db->prepare("UPDATE jobber_reconsent_queue SET status='failed' WHERE id=?")->execute([$contact['id']]);
                    }
                    $failed++;
                }
                usleep(200000); // 200ms between sends
            }

            echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'total' => count($contacts)]);
            break;

        // ── Skip a single queue entry (dashboard review) ────────────────
        case 'skip':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $queueId = (int)($input['queue_id'] ?? 0);
            if (!$queueId) {
                echo json_encode(['success' => false, 'error' => 'queue_id required']); break;
            }

            $stmt = $db->prepare("UPDATE jobber_reconsent_queue SET status='skipped' WHERE id=? AND status='queued'");
            $stmt->execute([$queueId]);
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
            break;

        // ── Get next review batch for dashboard ────────────────────────
        case 'review-batch':
            $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
            $stmt = $db->prepare("
                SELECT
                    jrq.id AS queue_id,
                    jrq.contact_id,
                    jrq.fsa,
                    jrq.is_current_client,
                    jrq.sort_priority,
                    c.first_name,
                    c.last_name,
                    c.email,
                    p.address AS service_address,
                    p.city AS service_city
                FROM jobber_reconsent_queue jrq
                JOIN contacts c ON jrq.contact_id = c.id
                LEFT JOIN properties p ON p.site_contact_id = c.id
                WHERE jrq.status = 'queued'
                  AND c.email IS NOT NULL AND c.email != ''
                  AND c.is_active = 1
                  AND jrq.attempts < 3
                ORDER BY jrq.sort_priority ASC, jrq.created_at ASC
                LIMIT $limit
            ");
            $stmt->execute();
            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Queue totals for progress bar
            $totals = $db->query("
                SELECT
                    COUNT(*) AS total,
                    SUM(status = 'queued') AS queued,
                    SUM(status = 'sent') AS sent,
                    SUM(status = 'skipped') AS skipped,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'expired') AS expired,
                    SUM(status = 'pending_approval') AS pending_approval
                FROM jobber_reconsent_queue
            ")->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'batch' => $batch,
                'totals' => $totals,
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (Throwable $e) {
    error_log('jobber-reconsent API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


// ═══════════════════════════════════════════════════════════════════════
// Helper Functions
// ═══════════════════════════════════════════════════════════════════════

/**
 * Parse a Jobber CSV export into normalized associative arrays.
 */
function parseJobberCsv(string $filePath): array
{
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return [];

    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); return []; }

    // Build header index map
    $headerMap = [];
    foreach ($headers as $i => $h) {
        $headerMap[trim($h)] = $i;
    }

    while (($data = fgetcsv($handle)) !== false) {
        $get = function(string $key) use ($data, $headerMap) {
            $idx = $headerMap[$key] ?? null;
            return ($idx !== null && isset($data[$idx])) ? trim($data[$idx]) : '';
        };

        // Extract primary email (take first if comma-separated)
        $emailRaw = $get('E-mails');
        $email = '';
        if ($emailRaw) {
            $parts = preg_split('/[,;]/', $emailRaw);
            foreach ($parts as $p) {
                $p = trim($p);
                if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower($p);
                    break;
                }
            }
        }

        // Determine postal code (prefer service, fallback billing)
        $servicePostal = $get('Service Zip code');
        $billingPostal = $get('Billing Zip code');
        $postalCode = normalizePostalCode($servicePostal ?: $billingPostal);

        $rows[] = [
            'jobber_id'        => $get('J-ID'),
            'first_name'       => $get('First Name'),
            'last_name'        => $get('Last Name'),
            'email'            => $email,
            'phone'            => $get('Main Phone #s'),
            'mobile'           => $get('Mobile Phone #s') ?: $get('Text Message Enabled Phone #'),
            'billing_address'  => $get('Billing Street 1'),
            'billing_city'     => $get('Billing City'),
            'billing_province' => $get('Billing State'),
            'billing_postal'   => normalizePostalCode($billingPostal),
            'service_address'  => $get('Service Street 1'),
            'service_city'     => $get('Service City'),
            'service_province' => $get('Service State'),
            'service_postal'   => normalizePostalCode($servicePostal),
            'postal_code'      => $postalCode,
            'is_company'       => strtolower($get('Is Company?')) === 'true',
            'company_name'     => $get('Company Name'),
            'archived'         => strtolower($get('Archived')) === 'true',
            'tags'             => $get('Tags'),
            'lead_source'      => $get('Lead Source'),
            'sms_phone'        => $get('Text Message Enabled Phone #'),
        ];
    }

    fclose($handle);
    return $rows;
}

/**
 * Normalize a Canadian postal code: strip spaces, uppercase.
 */
function normalizePostalCode(string $postalCode): string
{
    $postalCode = strtoupper(preg_replace('/\s+/', '', trim($postalCode)));
    // Basic validation: Canadian postal codes are A1A1A1 format
    if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postalCode)) {
        return $postalCode;
    }
    // Try with space format
    $postalCode = strtoupper(preg_replace('/\s+/', '', trim($postalCode)));
    return $postalCode;
}

/**
 * Extract FSA (Forward Sortation Area) — first 3 chars of postal code.
 */
function extractFsa(string $postalCode): ?string
{
    $postalCode = strtoupper(preg_replace('/\s+/', '', trim($postalCode)));
    if (strlen($postalCode) >= 3 && preg_match('/^[A-Z]\d[A-Z]/', $postalCode)) {
        return substr($postalCode, 0, 3);
    }
    return null;
}

/**
 * Map province name variants to 2-letter code.
 */
function mapProvinceCode(string $state): string
{
    $state = trim($state);
    $map = [
        'British Columbia' => 'BC', 'Alberta' => 'AB', 'Saskatchewan' => 'SK',
        'Manitoba' => 'MB', 'Ontario' => 'ON', 'Quebec' => 'QC',
        'New Brunswick' => 'NB', 'Nova Scotia' => 'NS',
        'Prince Edward Island' => 'PE', 'Newfoundland and Labrador' => 'NL',
        'Northwest Territories' => 'NT', 'Yukon' => 'YT', 'Nunavut' => 'NU',
    ];
    return $map[$state] ?? (strlen($state) === 2 ? strtoupper($state) : $state);
}

/**
 * Send a re-consent opt-in email to a contact.
 * Uses two email variants: current client (warm) vs inactive (re-engagement).
 */
function sendReconsentEmail(PDO $db, int $contactId): array
{
    $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE id=? AND is_active=1");
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact || empty($contact['email'])) {
        return ['success' => false, 'error' => 'Contact not found or no email'];
    }

    // Check for existing confirmed/unsubscribed token — skip
    $existing = $db->prepare("SELECT id, status FROM marketing_optin_tokens WHERE contact_id=? ORDER BY created_at DESC LIMIT 1");
    $existing->execute([$contactId]);
    $existingToken = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingToken && in_array($existingToken['status'], ['confirmed', 'unsubscribed'])) {
        return ['success' => false, 'error' => 'Already ' . $existingToken['status']];
    }

    // Generate a secure token
    $rawToken  = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    if ($existingToken && $existingToken['status'] === 'pending') {
        $db->prepare("UPDATE marketing_optin_tokens SET token=?, expires_at=?, resent_count=resent_count+1, last_resent_at=NOW() WHERE id=?")
           ->execute([$rawToken, $expiresAt, $existingToken['id']]);
    } else {
        $db->prepare("INSERT INTO marketing_optin_tokens (contact_id, email, token, status, sent_at, expires_at) VALUES (?,?,?,'pending',NOW(),?)")
           ->execute([$contactId, $contact['email'], $rawToken, $expiresAt]);
    }

    // Determine if this is a current client (visited in last 12 months)
    $isCurrent = isCurrentClient($db, $contactId);

    $baseUrl    = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
    $confirmUrl = $baseUrl . '/optin-confirm.php?token=' . urlencode($rawToken);
    $firstName  = $contact['first_name'] ?: 'Valued Customer';

    // Build variant-specific email
    if ($isCurrent) {
        $subject = 'Stay in the loop — Mowology Landscaping';
        $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>As a valued Mowology client, we want to make sure you\'re getting the most out of our services.</p>
<p>We occasionally send updates about seasonal care tips, scheduling reminders, and exclusive offers for existing clients.</p>
<p>To keep receiving these updates, just confirm below:</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($confirmUrl) . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you prefer not to receive these updates, simply ignore this email. Your service schedule is not affected either way.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days.</p>';
    } else {
        $subject = 'We\'d love to hear from you — Mowology Landscaping';
        $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>It\'s been a while since we last worked together, and we wanted to reach out.</p>
<p>Spring is here and our crews are gearing up for the season. Whether you need lawn care, garden maintenance, hedge trimming, or a fresh landscape design, we\'d love to help again.</p>
<p>If you\'d like to stay on our list for seasonal offers and updates, just confirm below:</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($confirmUrl) . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you\'re no longer interested, simply ignore this email and you won\'t hear from us again.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days.</p>';
    }

    require_once CRM_INCLUDES . '/messaging.php';
    $displayName = trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''));
    $sent = sendCrmEmail($contact['email'], $subject, $body, $displayName);

    if (!$sent) {
        return ['success' => false, 'error' => 'Failed to send email'];
    }

    $db->prepare("UPDATE marketing_optin_tokens SET sent_at=NOW() WHERE token=?")->execute([$rawToken]);

    return ['success' => true, 'contact_id' => $contactId, 'variant' => $isCurrent ? 'current' : 'inactive'];
}

/**
 * Check if a contact is a current client (has visits in the last 12 months).
 */
function isCurrentClient(PDO $db, int $contactId): bool
{
    try {
        // Check via properties → job_plans → job_visits
        $stmt = $db->prepare("
            SELECT 1
            FROM properties p
            JOIN job_plans jp ON jp.property_id = p.id
            JOIN job_visits jv ON jv.plan_id = jp.id
            WHERE p.site_contact_id = ?
              AND jv.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            LIMIT 1
        ");
        $stmt->execute([$contactId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Compute sort_priority for a queue entry based on current-client status and FSA.
 * Lower number = higher priority. North Van (V7G-R) and West Van (V7S-W) are
 * no longer serviced — deprioritized.
 *   Current client + Vancouver east (core) = 1
 *   Current client + Vancouver west        = 2
 *   Current client + Burnaby/Richmond      = 3
 *   Current client + elsewhere (still BC)  = 4
 *   Inactive + same tiers                  = 11..14
 *   Any North/West Vancouver (not serviced)= 90..91
 */
function computeSortPriority(bool $isCurrent, ?string $fsa): int
{
    // Vancouver east (core service hub, Main/Knight/Fraser corridors)
    $coreFsas = ['V5K','V5L','V5M','V5N','V5P','V5R','V5S','V5T','V5V','V5W','V5X'];
    // Vancouver west (Kitsilano, Point Grey, Kerrisdale, Marpole)
    $nearFsas = ['V6H','V6J','V6K','V6L','V6M','V6N','V6P','V6R','V6S','V6T'];
    // Inner Burnaby + Richmond (still serviceable)
    $extendedFsas = ['V5A','V5B','V5C','V5E','V5G','V5H','V5J','V6A','V6B','V6C','V6E','V6G','V6Z','V7C','V7E','V7Y'];
    // No longer serviced — North Van (V7G-V7R) + West Van (V7S-V7W)
    $noService = ['V7G','V7H','V7J','V7K','V7L','V7M','V7N','V7P','V7R','V7S','V7T','V7V','V7W'];

    $fsaUp = $fsa ? strtoupper($fsa) : null;

    // Deprioritize non-serviced areas heavily (even if current-ish)
    if ($fsaUp && in_array($fsaUp, $noService)) {
        return $isCurrent ? 90 : 91;
    }

    $base = $isCurrent ? 0 : 10;

    if ($fsaUp && in_array($fsaUp, $coreFsas))     return $base + 1;
    if ($fsaUp && in_array($fsaUp, $nearFsas))     return $base + 2;
    if ($fsaUp && in_array($fsaUp, $extendedFsas)) return $base + 3;
    return $base + 4;
}
