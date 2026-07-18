<?php
/**
 * API: Receipt Intake
 *
 * One-shot endpoint: upload receipt photo → OCR → parse → smart match → return suggestions.
 *
 * POST multipart/form-data:
 *   receipt_photo  — image file (required)
 *   csrf_token     — CSRF token (required)
 *   lat            — GPS latitude (optional)
 *   lng            — GPS longitude (optional)
 *   job_id         — linked job ID (optional)
 *
 * Returns JSON:
 *   { success, media_id, file_path, ocr_text, parsed: {...}, suggestions: {...}, ocr_available }
 *
 * If no Vision API key: uploads photo, returns ocr_available: false, user fills manually.
 */

declare(strict_types=1);

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

header('Content-Type: application/json');

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptIntakeService.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
    require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
    require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.edit');
    session_write_close(); // Release session lock — no session writes needed beyond this point

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    // CSRF check
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    // ── Rescan mode: re-OCR an already-stored media asset ───────────
    // When rescan_media_id is provided, skip file upload and re-run the
    // OCR pipeline on the existing image. Used by the review panel's
    // "Rescan" button before the expense has been saved.
    if (!empty($_POST['rescan_media_id'])) {
        $rescanMediaId = (int)$_POST['rescan_media_id'];
        $db = getDB();
        $mStmt = $db->prepare("SELECT id, file_path, stored_filename FROM media_assets WHERE id = ?");
        $mStmt->execute([$rescanMediaId]);
        $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mRow) {
            echo json_encode(['success' => false, 'error' => 'Media asset not found']);
            exit;
        }
        $filePath = PUBLIC_ROOT . '/' . ltrim($mRow['file_path'], '/');
        if (!file_exists($filePath)) $filePath = $mRow['file_path'];
        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'error' => 'Receipt image file not found on disk']);
            exit;
        }
        // Run the full OCR + parse + match pipeline and return results
        require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
        require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

        $preScreen = tesseractPreScreen($filePath);

        // Vendor-aware threshold: quick name-only match on Tesseract text
        $rescanEarlyVendorId = null;
        if (!empty($preScreen['text'])) {
            try {
                $rescanEarlyMatch = suggestReceiptMeta($preScreen['text'], null, null, null);
                $rescanEarlyVendorId = !empty($rescanEarlyMatch['vendor_id']) ? (int)$rescanEarlyMatch['vendor_id'] : null;
            } catch (Throwable $e) { /* non-fatal */ }
        }
        $rescanTessThreshold = getVendorTesseractThreshold($rescanEarlyVendorId);
        $rescanScore = $preScreen['score'];
        // Only apply vendor threshold when Tesseract ran cleanly — preserve original
        // fallback decision (use_vision) when Tesseract errored or scored 0.
        if (empty($preScreen['error']) && $rescanScore > 0) {
            $rescanDecision = $rescanScore >= $rescanTessThreshold ? 'use_tesseract'
                            : ($rescanScore >= 30 ? 'use_vision' : 'skip');
        } else {
            $rescanDecision = $preScreen['decision']; // use_vision (Tesseract failed/missing)
        }

        $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
        $ocrSource = 'none';
        if ($rescanDecision === 'use_tesseract') {
            $ocrResult = ['success' => true, 'text' => $preScreen['text'], 'raw_response' => null, 'error' => null];
            $ocrSource = 'tesseract';
        } elseif ($rescanDecision === 'use_vision') {
            $ocrResult = extractTextFromImage($filePath);
            $ocrSource = 'vision';
        }
        $ocrText = $ocrResult['text'] ?? '';
        $parsed = [];
        $suggestions = [];
        if ($ocrResult['success'] && !empty($ocrText)) {
            $parsed      = parseReceiptText($ocrText, $ocrResult['raw_response'] ?? null);
            $suggestions = suggestReceiptMeta($ocrText, null, null, null, $parsed);
            if (!empty($suggestions['vendor_id'])) {
                $parsed = applyLearnedPatterns((int)$suggestions['vendor_id'], $parsed, $ocrText);
                $parsed = matchVendorProducts((int)$suggestions['vendor_id'], $ocrText, $parsed);

                // Back-fill location + phone/website if vendor is missing them
                try {
                    $locCheckDb = getDB();
                    $locData = extractVendorLocationFromOcr($ocrText);
                    // Update vendors.phone / vendors.website if blank
                    if ($locData['phone'] || $locData['website']) {
                        $vRow = $locCheckDb->prepare("SELECT phone, website FROM vendors WHERE id = ?");
                        $vRow->execute([(int)$suggestions['vendor_id']]);
                        $vCurrent = $vRow->fetch(PDO::FETCH_ASSOC);
                        if ($vCurrent) {
                            if ($locData['phone'] && empty($vCurrent['phone'])) {
                                $locCheckDb->prepare("UPDATE vendors SET phone = ? WHERE id = ?")->execute([$locData['phone'], (int)$suggestions['vendor_id']]);
                            }
                            if ($locData['website'] && empty($vCurrent['website'])) {
                                $locCheckDb->prepare("UPDATE vendors SET website = ? WHERE id = ?")->execute([$locData['website'], (int)$suggestions['vendor_id']]);
                            }
                        }
                    }
                    // Insert preferred location if none exists yet
                    $locCount = $locCheckDb->prepare("SELECT COUNT(*) FROM vendor_locations WHERE vendor_id = ?");
                    $locCount->execute([(int)$suggestions['vendor_id']]);
                    if ((int)$locCount->fetchColumn() === 0 && ($locData['address'] || $locData['city'] || $locData['phone'])) {
                        $locIns = $locCheckDb->prepare("INSERT INTO vendor_locations (vendor_id, label, address, city, phone, is_preferred) VALUES (?, 'Main', ?, ?, ?, 1)");
                        $locIns->execute([(int)$suggestions['vendor_id'], $locData['address'], $locData['city'], $locData['phone']]);
                        $suggestions['vendor_location_added'] = true;
                    }
                } catch (Throwable $le) {
                    error_log('Vendor location back-fill failed: ' . $le->getMessage());
                }
            }
            // Gated vendor auto-creation (same logic as main upload path)
            if (empty($suggestions['vendor_id']) && !empty($parsed['vendor_hint'])) {
                $rescanVendorName = strtoupper(trim($parsed['vendor_hint']));
                if (strlen($rescanVendorName) >= 3) {
                    try {
                        $rescanDb = getDB();
                        $rescanGateResult = gateVendorAutoCreation($rescanVendorName, $rescanDb);
                        if ($rescanGateResult['action'] === 'match' && $rescanGateResult['vendor']) {
                            $suggestions['vendor_id']        = (int)$rescanGateResult['vendor']['id'];
                            $suggestions['vendor_name']      = $rescanGateResult['vendor']['name'];
                            $suggestions['vendor_confidence'] = 65;
                        } elseif ($rescanGateResult['action'] === 'review' && $rescanGateResult['vendor']) {
                            $suggestions['vendor_id']        = (int)$rescanGateResult['vendor']['id'];
                            $suggestions['vendor_name']      = $rescanGateResult['vendor']['name'];
                            $suggestions['vendor_confidence'] = 40;
                            $suggestions['vendor_needs_review'] = true;
                        } elseif ($rescanGateResult['action'] === 'create') {
                            $chk = $rescanDb->prepare("SELECT id FROM vendors WHERE UPPER(name) = ? LIMIT 1");
                            $chk->execute([$rescanVendorName]);
                            $existingVendorId = $chk->fetchColumn();
                            if ($existingVendorId) {
                                $suggestions['vendor_id']        = (int)$existingVendorId;
                                $suggestions['vendor_name']      = $rescanVendorName;
                                $suggestions['vendor_confidence'] = 80;
                            } else {
                                $ins = $rescanDb->prepare("INSERT INTO vendors (name, aliases, is_active) VALUES (?, ?, 1)");
                                $ins->execute([$rescanVendorName, strtolower($rescanVendorName)]);
                                $newRescanVendorId = (int)$rescanDb->lastInsertId();
                                $suggestions['vendor_id']         = $newRescanVendorId;
                                $suggestions['vendor_name']       = $rescanVendorName;
                                $suggestions['vendor_confidence']  = 70;
                                $suggestions['vendor_auto_created'] = true;

                                // Extract address/phone from receipt and save as vendor location
                                $rescanLocData = extractVendorLocationFromOcr($ocrText ?? '');
                                if ($rescanLocData['address'] || $rescanLocData['city'] || $rescanLocData['phone']) {
                                    try {
                                        $rescanLocIns = $rescanDb->prepare("INSERT INTO vendor_locations (vendor_id, label, address, city, phone, is_preferred) VALUES (?, 'Main', ?, ?, ?, 1)");
                                        $rescanLocIns->execute([$newRescanVendorId, $rescanLocData['address'], $rescanLocData['city'], $rescanLocData['phone']]);
                                    } catch (Throwable $le) {
                                        error_log('Vendor location from rescan receipt failed: ' . $le->getMessage());
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Rescan vendor auto-creation error: ' . $e->getMessage());
                    }
                }
            }
        }
        echo json_encode([
            'success'       => true,
            'media_id'      => $rescanMediaId,
            'ocr_available' => $ocrResult['success'],
            'ocr_source'    => $ocrSource,
            'ocr_text'      => $ocrText,
            'parsed'        => $parsed,
            'suggestions'   => $suggestions,
        ]);
        exit;
    }

    // Get GPS/job context from POST (also consumed inside the service, but read
    // here too since nothing else in this file needs them independently)
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    $jobId = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id'] : null;

    $intakeService = new ReceiptIntakeService(getDB());
    $result = $intakeService->intake((int)$user['id'], $_FILES['receipt_photo'] ?? [], $lat, $lng, $jobId);

    if (!empty($result['http_code']) && $result['http_code'] !== 200) {
        http_response_code($result['http_code']);
    }
    unset($result['http_code']);
    echo json_encode($result);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt upload failed: ' . $e->getMessage()]);
}
