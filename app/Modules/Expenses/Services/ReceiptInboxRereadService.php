<?php
declare(strict_types=1);

/**
 * ReceiptInboxRereadService — read an emailed receipt again, after it was left unread (Tim, 2026-10-20).
 *
 * Why: ReceiptInboxService reads an emailed receipt once, when the email arrives. If that read failed
 * ("pdf not OCR-able" before the 15 Oct text-layer fix, "no text extracted") the draft sat in "Receipts from
 * email" with no vendor and a 0.00 total, and nothing ever looked at it again. This reads it again through the
 * SAME reader (ReceiptInboxService::runOcr: PDF text layer, then Imagick/Tesseract/Vision), so it inherits every
 * improvement made to the first read.
 *
 * Safe by construction:
 *   - only a receipt still waiting for review (draft / pending_approval, source email_inbox);
 *   - a field is filled only when it is blank; an amount only while the row is still unread (total <= 0), so
 *     nothing a person saved is ever overwritten (mergeBlankOnly, pure);
 *   - every write goes through ExpenseGate (audited, guarded to still-waiting), never a raw UPDATE of the expense;
 *   - stores the first-read baseline (storeCaptureBaseline) so approving it from ANY screen teaches the reader;
 *   - dry run reports what would be filled and writes nothing;
 *   - never approves, dismisses or posts anything.
 *
 * Deliberately a new file: ReceiptInboxService / expenses_appstack.php are being changed by another session, so
 * this adds beside them instead of editing them. runOcr is private there, hence the ReflectionMethod call.
 *
 * Global-namespace service: require_once the file (no autoloader in production).
 */
require_once __DIR__ . '/ExpenseGate.php';
require_once __DIR__ . '/ReceiptInboxService.php';

class ReceiptInboxRereadService
{
    /** Inbox notes that mean "the first read failed". */
    public const UNREAD_NOTES = ['pdf not OCR-able', 'no text extracted'];
    /** Receipts re-read in one "re-read all" call (OCR can take a while each). */
    public const BATCH_MAX = 30;

    private PDO $db;
    private ExpenseGate $gate;

    public function __construct(PDO $db, ?ExpenseGate $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate ?? new ExpenseGate($db);
    }

    // ═══ Pure (unit-tested) ═══════════════════════════════════════════════════════════════════

    /**
     * The fields a read says the expense should have, shaped like ReceiptInboxService::createExpenseFromRead
     * builds them (same tax rules: no ITC on GST that was only estimated or that an exempt vendor never charges).
     *
     * @param array       $parsed      parseReceiptText() output
     * @param array       $suggestions suggestReceiptMeta() output
     * @param string|null $vendorCat   the matched vendor's default accounting category
     */
    public static function fieldsFromRead(array $parsed, array $suggestions, ?string $vendorCat = null): array
    {
        $total = isset($parsed['total']) && $parsed['total'] !== null ? (float)$parsed['total'] : 0.0;
        $gst   = isset($parsed['gst'])   && $parsed['gst']   !== null ? (float)$parsed['gst']   : 0.0;
        $pst   = isset($parsed['pst'])   && $parsed['pst']   !== null ? (float)$parsed['pst']   : 0.0;
        $gstNote = null;
        if (!empty($suggestions['vendor_gst_exempt'])) {
            $gst = 0.0;
        } elseif (!empty($parsed['gst_estimated'])) {
            $gst = 0.0;
            $gstNote = 'GST not printed on the receipt, so no ITC claimed; add it if the receipt shows GST.';
        }
        $subtotal = isset($parsed['subtotal']) && $parsed['subtotal'] !== null ? (float)$parsed['subtotal'] : null;
        $amount   = $subtotal !== null ? $subtotal : max(0.0, $total - $gst - $pst);
        $date     = (string)($parsed['date'] ?? '');
        $validDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
        return [
            'vendor_id'           => !empty($suggestions['vendor_id']) ? (int)$suggestions['vendor_id'] : null,
            'vendor_name_raw'     => (string)($suggestions['vendor_name'] ?? ($parsed['vendor_hint'] ?? '')),
            'description'         => (string)($parsed['vendor_hint'] ?? ''),
            'expense_date'        => $validDate ? $date : '',
            'amount'              => round($amount, 2),
            'gst_amount'          => round($gst, 2),
            'pst_amount'          => round($pst, 2),
            'total'               => round($total, 2),
            'accounting_category' => (string)($suggestions['accounting_category'] ?? $vendorCat ?? ''),
            'gbp_category'        => (string)($suggestions['gbp_category'] ?? ''),
            'payment_method'      => (string)($parsed['payment_method'] ?? ''),
            'gst_note'            => $gstNote,
        ];
    }

    /**
     * Why a read should NOT be filled in, or null when it looks like a normal purchase. On production (2026-10-20) a
     * dry run read nine PDFs as vendor "MOWOLOGY" / "PREPARED FOR" with totals like $1,024.28: our own invoices and
     * quotes, not bills we paid. Filling those would hand the owner confident wrong figures, so they are flagged for a
     * look instead. Only the VENDOR the reader picked is judged (the body of a real bill to us also says Mowology).
     */
    public static function suspectReason(array $read): ?string
    {
        $vendor = strtoupper(trim((string)($read['vendor_name_raw'] ?? '')));
        $hint   = strtoupper(trim((string)($read['description'] ?? '')));
        if (preg_match('/\bMOWOLOGY\b/', $vendor) || preg_match('/\bMOWOLOGY\b/', $hint)) {
            return 'The read took our own name as the vendor, so this may be one of our own invoices or quotes, not a purchase.';
        }
        $label = '/^(PREPARED FOR|BILL TO|BILLED TO|INVOICE TO|SHIP TO|SOLD TO|CUSTOMER|CLIENT|INVOICE|RECEIPT|QUOTE|ESTIMATE|STATEMENT)\b/';
        if (($vendor !== '' && preg_match($label, $vendor)) || ($vendor === '' && $hint !== '' && preg_match($label, $hint))) {
            return 'The read took a form label as the vendor, so it may not have understood this document.';
        }
        return null;
    }

    /**
     * What to write: the read's values for blank fields only. Amounts and the date move together and only while
     * the row is still unread (total <= 0); a row with a saved total keeps every amount it has.
     *
     * @param array $row  the expense as stored (vendor_id, vendor_name_raw, description, total, accounting_category, ...)
     * @param array $read fieldsFromRead() output
     * @return array<string,mixed> column => value (empty = nothing to fill)
     */
    public static function mergeBlankOnly(array $row, array $read): array
    {
        if ((float)($read['total'] ?? 0) <= 0) return [];   // the read found no total: it is still unread, say so rather than half-fill
        $changes = [];
        if ((float)($row['total'] ?? 0) <= 0) {
            foreach (['amount', 'gst_amount', 'pst_amount', 'total'] as $k) $changes[$k] = $read[$k];
            if ($read['expense_date'] !== '') $changes['expense_date'] = $read['expense_date'];
        }
        if (empty($row['vendor_id']) && !empty($read['vendor_id'])) $changes['vendor_id'] = (int)$read['vendor_id'];
        foreach (['vendor_name_raw', 'description', 'accounting_category', 'gbp_category', 'payment_method'] as $k) {
            if (trim((string)($row[$k] ?? '')) === '' && trim((string)($read[$k] ?? '')) !== '') $changes[$k] = $read[$k];
        }
        return $changes;
    }

    // ═══ Loaders / writers ═══════════════════════════════════════════════════════════════════

    /**
     * Re-read one waiting emailed receipt.
     * @return array{id:int,status:string,note:string,fills?:array,flag_cleared?:bool}
     *         status: filled | would_fill | nothing_to_fill | check_first | unreadable | skipped | error
     */
    public function reread(int $expenseId, int $userId, bool $dryRun = false): array
    {
        $s = $this->db->prepare("
            SELECT e.id, e.vendor_id, e.vendor_name_raw, e.description, e.total, e.accounting_category, e.gbp_category,
                   e.payment_method, e.source, e.status, ma.file_path, ma.mime_type
              FROM expenses e LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
             WHERE e.id = ?");
        $s->execute([$expenseId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['source'] ?? '') !== 'email_inbox' || !in_array($row['status'], ExpenseGate::OPEN, true)) {
            return ['id' => $expenseId, 'status' => 'skipped', 'note' => 'Not an emailed receipt that is still waiting.'];
        }
        $path = $this->resolvePath((string)($row['file_path'] ?? ''));
        if ($path === null) {
            return ['id' => $expenseId, 'status' => 'unreadable', 'note' => 'The receipt file is missing on the server.'];
        }
        $isPdf = strtolower((string)($row['mime_type'] ?? '')) === 'application/pdf' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';

        try {
            $ocr = $this->read($path, $isPdf);
        } catch (Throwable $e) {
            error_log('[ReceiptInboxRereadService] read #' . $expenseId . ': ' . $e->getMessage());
            return ['id' => $expenseId, 'status' => 'error', 'note' => 'The reader failed; open the receipt and use Penny\'s read.'];
        }
        if (empty($ocr['readable'])) {
            return ['id' => $expenseId, 'status' => 'unreadable', 'note' => 'Still unreadable by the server reader; open the full editor and use Penny\'s read.'];
        }

        $parsed = (array)($ocr['parsed'] ?? []);
        $sugg   = (array)($ocr['suggestions'] ?? []);
        $vendorId = !empty($sugg['vendor_id']) ? (int)$sugg['vendor_id'] : (int)($row['vendor_id'] ?? 0);
        $read = self::fieldsFromRead($parsed, $sugg, $vendorId ? $this->vendorDefaultCategory($vendorId) : null);
        if (($why = self::suspectReason($read)) !== null) {
            return ['id' => $expenseId, 'status' => 'check_first', 'note' => $why . ' Nothing was filled; open the full editor to check it.'];
        }
        $changes = self::mergeBlankOnly($row, $read);
        if (!$changes) {
            return ['id' => $expenseId, 'status' => 'nothing_to_fill', 'note' => ((float)($read['total'] ?? 0) > 0)
                ? 'Already filled in, so nothing to change.'
                : 'Read, but it found no total to fill in.'];
        }
        if ($dryRun) {
            return ['id' => $expenseId, 'status' => 'would_fill', 'note' => 'Dry run: nothing written.', 'fills' => $changes];
        }

        $changes['raw_ocr_json'] = json_encode(['text' => (string)($ocr['ocr_text'] ?? ''), 'parsed' => $parsed, 'source' => (string)($ocr['source'] ?? '')],
                                               JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) ?: null;
        try {
            $this->gate->apply($expenseId, $changes, ['id' => $userId ?: null, 'kind' => 'user'], 'email_inbox_reread', [
                'only_if' => function (array $e) { return ($e['source'] ?? '') === 'email_inbox' && in_array($e['status'], ExpenseGate::OPEN, true); },
            ]);
            if (defined('APP_ROOT')) {
                require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
                storeCaptureBaseline($this->db, $expenseId, withSuggestedCategory($parsed, $sugg));   // so any approval path teaches the reader
            }
            $cleared = $this->db->prepare("UPDATE receipt_inbox_messages SET note = NULL WHERE expense_id = ? AND note IN ('" . implode("','", self::UNREAD_NOTES) . "')");
            $cleared->execute([$expenseId]);
        } catch (Throwable $e) {
            error_log('[ReceiptInboxRereadService] write #' . $expenseId . ': ' . $e->getMessage());
            return ['id' => $expenseId, 'status' => 'error', 'note' => 'Read it, but could not save: ' . $e->getMessage()];
        }
        unset($changes['raw_ocr_json']);
        return ['id' => $expenseId, 'status' => 'filled', 'note' => 'Filled from the receipt.', 'fills' => $changes, 'flag_cleared' => $cleared->rowCount() > 0];
    }

    /**
     * Re-read every waiting emailed receipt that is still unread (0.00 total, or flagged by the first read).
     * @return array{results: array, counts: array, remaining: int}
     */
    public function rereadUnread(int $userId, bool $dryRun = false): array
    {
        $notes = "'" . implode("','", self::UNREAD_NOTES) . "'";
        $ids = $this->db->query("
            SELECT e.id FROM expenses e
              LEFT JOIN receipt_inbox_messages rim ON rim.expense_id = e.id
             WHERE e.source = 'email_inbox' AND e.status IN ('draft', 'pending_approval')
               AND (e.total <= 0 OR rim.note IN ({$notes}))
          GROUP BY e.id ORDER BY e.id")->fetchAll(PDO::FETCH_COLUMN);
        $todo = array_slice(array_map('intval', $ids), 0, self::BATCH_MAX);
        $results = [];
        $counts = [];
        foreach ($todo as $id) {
            $r = $this->reread($id, $userId, $dryRun);
            $results[] = $r;
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        }
        return ['results' => $results, 'counts' => $counts, 'remaining' => max(0, count($ids) - count($todo))];
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /** The first-read pipeline, through ReceiptInboxService::runOcr (private there; see the class note). */
    private function read(string $path, bool $isPdf): array
    {
        $m = new ReflectionMethod('ReceiptInboxService', 'runOcr');
        return (array)$m->invoke(new ReceiptInboxService($this->db), $path, $isPdf);
    }

    private function resolvePath(string $filePath): ?string
    {
        if ($filePath === '') return null;
        $candidates = [];
        if (defined('PUBLIC_ROOT')) $candidates[] = PUBLIC_ROOT . '/' . ltrim($filePath, '/');
        $candidates[] = $filePath;   // some are stored absolute
        foreach ($candidates as $c) if (is_file($c)) return $c;
        return null;
    }

    private function vendorDefaultCategory(int $vendorId): ?string
    {
        $s = $this->db->prepare("SELECT default_accounting_category FROM vendors WHERE id = ? LIMIT 1");
        $s->execute([$vendorId]);
        $c = $s->fetchColumn();
        return ($c !== false && $c !== null && trim((string)$c) !== '') ? (string)$c : null;
    }
}
