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
            // Whatever pass produced them (Vision/LLM tier, learned patterns): a tax line is never
            // an item, and impossible amounts fall back (Chevron #355, 2026-10-13).
            $parsed['line_items'] = dropTaxLineItems($parsed['line_items'] ?? []);
            $parsed = sanitizeReceiptAmounts($parsed);
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

        // Replace only unlinked lines — linked ones were checked by a person. Never on a receipt
        // already sent to accounting, or one split across jobs/categories: replacing its lines
        // dropped the owner's split choices and re-posted the books (review 2026-10-12).
        $fresh = $parsed['line_items'] ?? [];
        $st = $this->db->prepare("SELECT status, COALESCE(forwarded_to_accounting, 0) AS fwd,
                                         (SELECT COUNT(*) FROM expense_line_allocations a WHERE a.expense_id = e.id) AS splits
                                  FROM expenses e WHERE e.id = ?");
        try { $st->execute([$expenseId]); $state = $st->fetch(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { $state = ['splits' => 0]; }   // before the split table existed
        $frozen = ($state['status'] ?? '') === 'forwarded' || (int)($state['fwd'] ?? 0) === 1 || (int)($state['splits'] ?? 0) > 0;
        if (!empty($fresh) && !$frozen) {
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

        // Stored amounts that cannot be true (a negative subtotal, tax at or over the total) on an
        // open receipt are replaced by this read when it agrees on the total. Before, a rescan only
        // filled EMPTY form fields, so a bad first read (Chevron #355: GST 5.00, subtotal −2.39)
        // survived every rescan.
        $amountsRepaired = $frozen ? null : $this->repairAmounts($expenseId, $parsed, $actor, $source);

        // Penny's read, when she has one open, is read again against the rescanned receipt.
        $pennyRefreshed = $this->refreshPenny($expenseId);

        return [
            'success' => true, 'ocr_available' => $ocrAvailable, 'ocr_source' => $ocrSource, 'ocr_text' => $ocrText,
            'parsed' => $parsed, 'suggestions' => $suggestions, 'pre_screen' => $preScreen,
            'line_items_stored' => !empty($fresh), 'baseline_set' => $baselineSet,
            'amounts_repaired' => $amountsRepaired, 'penny_refreshed' => $pennyRefreshed,
        ];
    }

    /**
     * Stored subtotal / GST / PST that cannot be true. Pure.
     * @param array $e ['amount', 'gst_amount', 'pst_amount', 'total']
     */
    public static function amountsImpossible(array $e): bool
    {
        $total = (float)($e['total'] ?? 0);
        if ($total <= 0) return false;
        $sub = (float)($e['amount'] ?? 0);
        $gst = (float)($e['gst_amount'] ?? 0);
        $pst = (float)($e['pst_amount'] ?? 0);
        return $sub <= 0 || $gst < 0 || $pst < 0 || $gst + $pst >= $total || $gst > $total * 0.14 + 0.02;
    }

    /**
     * The fix for impossible stored amounts, from a read that agrees on the total. Pure.
     * @return array|null gate changes, or null when the read can't be trusted for it
     */
    public static function amountRepair(array $stored, array $parsed): ?array
    {
        if (!self::amountsImpossible($stored)) return null;
        $total = (float)($stored['total'] ?? 0);
        if (!isset($parsed['total']) || abs((float)$parsed['total'] - $total) >= 0.005) return null;
        $sub = isset($parsed['subtotal']) && is_numeric($parsed['subtotal']) ? (float)$parsed['subtotal'] : 0.0;
        $gst = isset($parsed['gst']) && is_numeric($parsed['gst']) ? (float)$parsed['gst'] : 0.0;
        $pst = isset($parsed['pst']) && is_numeric($parsed['pst']) ? (float)$parsed['pst'] : 0.0;
        $fix = ['amount' => round($sub, 2), 'gst_amount' => round($gst, 2), 'pst_amount' => round($pst, 2)];
        return self::amountsImpossible($fix + ['total' => $total]) ? null : $fix;
    }

    private function repairAmounts(int $expenseId, array $parsed, array $actor, string $source): ?array
    {
        try {
            $s = $this->db->prepare("SELECT amount, gst_amount, pst_amount, total, status FROM expenses WHERE id = ?");
            $s->execute([$expenseId]);
            $e = $s->fetch(PDO::FETCH_ASSOC);
            if (!$e || !in_array($e['status'], ExpenseGate::OPEN, true)) return null;
            $fix = self::amountRepair($e, $parsed);
            if (!$fix) return null;
            $this->gate->apply($expenseId, $fix, $actor, $source);
            return ['before' => ['amount' => $e['amount'], 'gst_amount' => $e['gst_amount'], 'pst_amount' => $e['pst_amount']],
                    'after' => $fix];
        } catch (Throwable $ex) {
            error_log('Rescan amount repair #' . $expenseId . ': ' . $ex->getMessage());
            return null;
        }
    }

    /** Re-read by Penny when she has a pending read on this receipt (owner-asked, ~5¢, LlmDailyCap). */
    private function refreshPenny(int $expenseId): bool
    {
        try {
            $s = $this->db->prepare("SELECT id FROM expense_suggestions WHERE expense_id = ? AND source = 'live' AND status = 'pending'
                                     ORDER BY id DESC LIMIT 1");
            $s->execute([$expenseId]);
            $sid = (int)$s->fetchColumn();
            if (!$sid) return false;
            require_once __DIR__ . '/ReceiptBookkeeperService.php';
            require_once __DIR__ . '/BookkeeperDeskService.php';
            $desk = new BookkeeperDeskService($this->db, new ReceiptBookkeeperService($this->db), $this->gate);
            if (!$desk->canAsk()) return false;
            return !empty($desk->recheck($sid)['ok']);
        } catch (Throwable $ex) {
            error_log('Rescan Penny refresh #' . $expenseId . ': ' . $ex->getMessage());
            return false;
        }
    }
}
