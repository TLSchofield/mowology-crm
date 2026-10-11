<?php
/**
 * ExpenseRescanService — re-read a saved receipt's photo (owner, 2026-10-11).
 *
 * Moved out of expenses.php handleRescan (web only) so the iPhone can rescan too
 * (expense-line-items.php op 'rescan'). Same pipeline as intake: Tesseract pre-screen with the
 * vendor's learned threshold → Vision when needed → parseReceiptText with the vendor profile →
 * enhanceLineItemExtraction → learned patterns + product matching.
 *
 * Writes through the expense gate: the OCR text (allowed on a sent receipt — it isn't money) and
 * the line items, replacing only unlinked lines (linked ones were checked by a person).
 * New: when the receipt has no first-read baseline (ocr_parsed_json — never OCR'd, or read before
 * migration 1123), this read becomes it, so approving the receipt later can teach the parser.
 * Without it learnFromConfirmedExpense() returns early and the approval teaches nothing.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

require_once __DIR__ . '/ExpenseGate.php';

class ExpenseRescanService
{
    private PDO $db;
    private ExpenseGate $gate;

    public function __construct(PDO $db, ?ExpenseGate $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate ?? new ExpenseGate($db);
    }

    /**
     * @param array $actor ExpenseGate::actor(...)
     * @param string $source the gate's audit source ('desktop_rescan', 'ios_rescan')
     * @return array{success: bool, ocr_available: bool, ocr_source: string, ocr_text: string, parsed: array,
     *               suggestions: array, pre_screen: array, line_items_stored: bool, baseline_set: bool}
     */
    public function rescan(int $expenseId, array $actor, string $source): array
    {
        if ($expenseId <= 0) throw new InvalidArgumentException('Expense ID required');
        $stmt = $this->db->prepare("
            SELECT e.id, e.receipt_media_id, e.vendor_id, e.job_id, ma.file_path, ma.stored_filename
            FROM expenses e
            LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
            WHERE e.id = ?
        ");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$expense) throw new RuntimeException('Expense not found');
        if (empty($expense['file_path'])) throw new RuntimeException('No receipt image found for this expense. Upload a receipt first.');

        $filePath = PUBLIC_ROOT . '/' . ltrim($expense['file_path'], '/');
        if (!file_exists($filePath)) $filePath = $expense['file_path'];   // some are stored absolute
        if (!file_exists($filePath)) throw new RuntimeException('Receipt image file not found on disk. It may have been moved or deleted.');

        require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
        require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptLineItemIntelligence.php';

        // Tesseract first (cost-saving), with the vendor's learned threshold — as intake does.
        $preScreen = tesseractPreScreen($filePath);
        $vendorId = !empty($expense['vendor_id']) ? (int)$expense['vendor_id'] : null;
        $threshold = getVendorTesseractThreshold($vendorId);
        if (empty($preScreen['error']) && ($preScreen['score'] ?? 0) > 0) {
            $decision = $preScreen['score'] >= $threshold ? 'use_tesseract' : ($preScreen['score'] >= 30 ? 'use_vision' : 'skip');
        } else {
            $decision = $preScreen['decision'];
        }

        $ocr = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
        $ocrSource = 'none';
        if ($decision === 'use_tesseract') {
            $ocr = ['success' => true, 'text' => $preScreen['text'], 'raw_response' => null, 'error' => null];
            $ocrSource = 'tesseract';
        } elseif ($decision === 'use_vision') {
            $ocr = extractTextFromImage($filePath);
            $ocrSource = 'vision';
        }

        $ocrAvailable = (bool)$ocr['success'];
        $ocrText = (string)($ocr['text'] ?? '');
        $parsed = [];
        $suggestions = [];
        if ($ocrAvailable && $ocrText !== '') {
            $raw = $ocr['raw_response'] ?? null;
            $profile = getVendorLineItemProfile($vendorId);
            $parsed = parseReceiptText($ocrText, $raw, $profile);
            $enh = enhanceLineItemExtraction([
                'file_path' => $filePath, 'ocr_source' => $ocrSource, 'ocr_text' => $ocrText,
                'raw_response' => $raw, 'parsed' => $parsed, 'vendor_id' => $vendorId, 'vendor_profile' => $profile,
            ]);
            $parsed = $enh['parsed'];
            $ocrText = $enh['ocr_text'];
            $ocr['raw_response'] = $enh['raw_response'];
            $ocrSource = $enh['ocr_source'];
            $suggestions = suggestReceiptMeta($ocrText, null, null, $expense['job_id'] ?? null);
            $matchVendor = !empty($suggestions['vendor_id']) ? (int)$suggestions['vendor_id'] : (int)($vendorId ?? 0);
            if ($matchVendor) {
                $parsed = applyLearnedPatterns($matchVendor, $parsed, $ocrText);
                $parsed = matchVendorProducts($matchVendor, $ocrText, $parsed);
            }
        }

        $baselineSet = false;
        if ($ocrAvailable && $ocrText !== '') {
            $rawJson = $ocr['raw_response'] ? json_encode($ocr['raw_response']) : $ocrText;
            $this->gate->apply($expenseId, ['raw_ocr_json' => $rawJson], $actor, $source, ['allow_locked' => true]);
            // This read becomes the first-read baseline when there is none (fills NULL only).
            if ($parsed) {
                $before = $this->db->prepare("SELECT ocr_parsed_json IS NULL FROM expenses WHERE id = ?");
                $before->execute([$expenseId]);
                $wasEmpty = (bool)$before->fetchColumn();
                storeCaptureBaseline($this->db, $expenseId, withSuggestedCategory($parsed, $suggestions));
                $baselineSet = $wasEmpty;
            }
        }

        // Replace only unlinked lines — linked ones were checked by a person.
        $fresh = $parsed['line_items'] ?? [];
        if (!empty($fresh)) {
            $this->gate->apply($expenseId, ['line_items' => $fresh], $actor, $source, ['keep_linked' => true, 'allow_locked' => true]);
            $li = $this->db->prepare("
                SELECT eli.*, p.name AS product_name, p.sku AS product_sku
                FROM expense_line_items eli
                LEFT JOIN products p ON p.id = eli.product_id
                WHERE eli.expense_id = ?
                ORDER BY eli.sort_order, eli.id
            ");
            $li->execute([$expenseId]);
            $parsed['line_items'] = $li->fetchAll(PDO::FETCH_ASSOC);
            $parsed['line_items_stored'] = true;
        }

        return [
            'success' => true, 'ocr_available' => $ocrAvailable, 'ocr_source' => $ocrSource, 'ocr_text' => $ocrText,
            'parsed' => $parsed, 'suggestions' => $suggestions, 'pre_screen' => $preScreen,
            'line_items_stored' => !empty($fresh), 'baseline_set' => $baselineSet,
        ];
    }
}
