<?php
/**
 * ReceiptInboxService — turn a vendor receipt/invoice that arrived by email into an
 * expense record.
 *
 * The IMAP fetch lives in the cron poller
 * (app/Modules/Expenses/Cron/receipt_inbox_poll.php); this service holds the
 * storage + OCR + auto-post logic so the decision rules can be unit-tested without
 * a mailbox.
 *
 * Nothing is approved here (2026-10-07). A "clean match" (isCleanMatch: the vendor is
 * recognised in the vendor directory AND a total parsed AND a date parsed AND that
 * vendor has a default accounting category) used to be created 'approved' and posted
 * straight to the books. Now it is created 'pending_approval' and flagged high-confidence
 * — Penny prepares those first (BookkeeperDeskService::prepare orders pending_approval
 * first) and the owner approves. Anything short of a clean match is a 'draft'. Both show
 * in the Expenses review panel. PDFs that can't be rasterised for OCR are never clean.
 *
 * Reuses the existing receipt pipeline: ReceiptOCR / ReceiptParser / ReceiptSmartMatch
 * (the same code the camera "Snap Receipt" flow runs), media_assets for storage, and
 * AccountingService::syncFromExpenses() (run by the sync-ledger cron) for posting.
 */
class ReceiptInboxService
{
    /** Attachment MIME types we will ingest. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    private const PDF_MIME     = 'application/pdf';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Pure, unit-testable decision helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Stable dedup key for one attachment. Same email re-polled, or the same file
     * seen twice, collapses to one key; two different attachments on one email get
     * two keys (both ingested). Pure + static so it's trivially testable.
     */
    /**
     * office@ is a shared business inbox (clients, quotes, payment notices), not a
     * receipts drop — so only take an attachment from it when it looks like a receipt
     * (2026-10-06: "she should watch all emails"). receipts@ takes everything.
     *   yes — sent or forwarded by one of us (staff / owner addresses)
     *   no  — our own domain otherwise (sent copies), a client, payment / remittance notices
     *   yes — the subject or file name says receipt / invoice / bill / order / statement
     *   no  — anything else (client photos, documents)
     * @param string[] $ownerEmails  staff + owner addresses (lower case)
     * @param string[] $clientEmails client contact addresses (lower case)
     */
    public static function isOfficeReceipt(?string $from, string $subject, string $filename, array $ownerEmails, array $clientEmails): bool
    {
        $from = strtolower(trim((string)$from));
        if ($from !== '' && in_array($from, $ownerEmails, true)) return true;
        if ($from === '' || str_ends_with($from, '@mowology.ca')) return false;
        if (in_array($from, $clientEmails, true)) return false;
        $text = $subject . ' ' . $filename;
        if (preg_match('/yardi|\beft\b|remittance|e-?transfer|interac|payment (advice|notification|received)|quote request/i', $text)) return false;
        return (bool)preg_match('/receipt|invoice|\bbill\b|your order|order (confirmation|#)|purchase|statement|\binv[\s#-]*\d/i', $text);
    }

    /**
     * A receipt that IS the email (RONA, Amazon, Uber… no attachment): only when the
     * subject says so, and never a client, our own sent mail or a payment notice.
     */
    public static function isBodyReceipt(?string $from, string $subject, array $ownerEmails, array $clientEmails): bool
    {
        $from = strtolower(trim((string)$from));
        if ($from === '') return false;
        $ours = in_array($from, $ownerEmails, true);
        if (!$ours && (str_ends_with($from, '@mowology.ca') || in_array($from, $clientEmails, true))) return false;
        if (preg_match('/yardi|\beft\b|remittance|e-?transfer|interac|payment (advice|notification|received)|quote/i', $subject)) return false;
        return (bool)preg_match('/receipt|re[çc]u|your order|order (confirmation|#|receipt)|purchase|invoice|\bbill\b/i', $subject);
    }

    /** An HTML (or plain) email body as readable receipt text, one row per line. */
    public static function htmlToText(string $html): string
    {
        $t = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $t = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6]|table)>#i', "\n", (string)$t);
        $t = preg_replace('#</t[dh]>#i', "  ", (string)$t);
        $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $lines = array_filter(array_map(fn($l) => trim((string)preg_replace('/[ \t]+/', ' ', $l)), explode("\n", $t)), 'strlen');
        return implode("\n", $lines);
    }

    /**
     * Ingest a receipt that is the email body. The email is saved as a file (for the
     * record), its text is read like OCR text, and it always becomes a draft for Penny —
     * never auto-posted (no photo to check against).
     */
    public function ingestEmailBody(array $msg, string $html, int $systemUserId): array
    {
        $sha256 = hash('sha256', $html);
        $dedup  = self::deriveDedupKey($msg['message_id'] ?? null, $sha256, 'email-body.html');
        if (!$this->claim($msg, $dedup, 'email-body.html')) {
            return ['status' => 'duplicate', 'expense_id' => null, 'note' => null];
        }
        try {
            $text = self::htmlToText($html);
            if (mb_strlen($text) < 20) {
                $this->finalizeClaim($dedup, null, null, 'skipped', null, 'email body has no receipt text');
                return ['status' => 'skipped', 'expense_id' => null, 'note' => 'no text'];
            }
            $dir = PUBLIC_ROOT . '/uploads/receipts/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $file = 'receipt-email-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.html';
            file_put_contents($dir . $file, $html);

            require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
            $parsed = parseReceiptText($text, null);
            $payee = trim((string)($msg['payee'] ?? ''));
            if ($payee !== '') {
                // A payment platform's receipt (PayPal "You sent a payment"): the vendor is who was
                // paid, not the platform whose name fills the email — match on the payee alone.
                $parsed['vendor_hint'] = $payee;
                $suggestions = suggestReceiptMeta($payee, null, null, null, $parsed);
                if (empty($suggestions['vendor_id'])) $suggestions['vendor_name'] = $payee;
            } else {
                $suggestions = suggestReceiptMeta($text, null, null, null, $parsed);
            }
            $ocr = ['readable' => true, 'source' => 'email_body', 'ocr_text' => $text, 'parsed' => $parsed,
                    'suggestions' => $suggestions];
            $msg['subject'] = trim(($msg['subject'] ?? '') . ' (saved: /uploads/receipts/' . $file . ')');
            return $this->createExpenseFromRead($msg, $ocr, null, $dedup, false, 'no text extracted', $systemUserId);
        } catch (\Throwable $e) {
            $this->releaseClaim($dedup);
            throw $e;
        }
    }

    public static function deriveDedupKey(?string $messageId, string $sha256, string $attachmentName): string
    {
        $mid = $messageId !== null ? trim($messageId) : '';
        if ($mid !== '') {
            return $mid . ':' . $sha256;
        }
        return 'sha:' . $sha256;
    }

    /**
     * The auto-post gate. Returns true only for a "clean 100% match":
     *   - vendor matched in the directory (vendor_id > 0)
     *   - a positive total parsed
     *   - a valid YYYY-MM-DD date parsed
     *   - the matched vendor has a non-empty default accounting category
     *
     * Pure + static — no DB. The caller supplies the resolved fields.
     *
     * @param array{vendor_id:?int,total:mixed,expense_date:?string,vendor_default_category:?string} $d
     */
    public static function isCleanMatch(array $d): bool
    {
        $vendorId = (int) ($d['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            return false;
        }
        if ((float) ($d['total'] ?? 0) <= 0) {
            return false;
        }
        if (!self::isValidDate($d['expense_date'] ?? null)) {
            return false;
        }
        $cat = trim((string) ($d['vendor_default_category'] ?? ''));
        return $cat !== '';
    }

    /** Note on the inbox log for a clean match, so it reads as high-confidence. */
    public const HIGH_CONFIDENCE_NOTE = 'clean match — high confidence, waiting for approval';

    /**
     * The status an emailed receipt is created with. Never 'approved': a clean match
     * waits for approval flagged high-confidence (Penny prepares it first), anything
     * else is a draft. Pure.
     *
     * @return array{status:string, high_confidence:bool}
     */
    public static function inboxStatus(bool $cleanMatch): array
    {
        return $cleanMatch
            ? ['status' => 'pending_approval', 'high_confidence' => true]
            : ['status' => 'draft', 'high_confidence' => false];
    }

    /** True for a real calendar date in YYYY-MM-DD form. */
    public static function isValidDate(?string $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Ingest
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Atomically claim a dedup key by inserting a 'processing' placeholder row.
     * The UNIQUE constraint on dedup_key is the concurrency guard: if two
     * overlapping polls race the same attachment, the loser gets a duplicate-key
     * error here and bails before any OCR work or an `expenses` write — instead
     * of both racing to insert the financial record itself.
     */
    private function claim(array $msg, string $dedup, ?string $filename): bool
    {
        $dt = !empty($msg['email_date']) ? date('Y-m-d H:i:s', strtotime($msg['email_date'])) : null;
        try {
            $this->db->prepare("
                INSERT INTO receipt_inbox_messages
                    (dedup_key, sender_email, subject, email_date, attachment_name, outcome)
                VALUES (?,?,?,?,?, 'processing')
            ")->execute([$dedup, $msg['sender_email'] ?? null, $msg['subject'] ?? null, $dt, $filename]);
            return true;
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate') !== false || $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /** Finalize a claimed row with the outcome of processing. */
    private function finalizeClaim(string $dedup, ?int $mediaId, ?int $expenseId, string $outcome, ?int $confidence, ?string $note): void
    {
        $this->db->prepare("
            UPDATE receipt_inbox_messages
               SET media_id = ?, expense_id = ?, outcome = ?, match_confidence = ?, note = ?
             WHERE dedup_key = ?
        ")->execute([$mediaId, $expenseId, $outcome, $confidence, $note, $dedup]);
    }

    /**
     * Release a claim after a failure so the attachment is retried on the next
     * poll instead of being silently dropped forever (dedup_key would otherwise
     * stay permanently occupied by an unfinished 'processing' row).
     */
    private function releaseClaim(string $dedup): void
    {
        try {
            $this->db->prepare(
                "DELETE FROM receipt_inbox_messages WHERE dedup_key = ? AND outcome = 'processing'"
            )->execute([$dedup]);
        } catch (\Throwable $e) {
            error_log('[ReceiptInboxService] releaseClaim failed: ' . $e->getMessage());
        }
    }

    /**
     * Ingest one email attachment.
     *
     * @param array  $msg          ['message_id'=>?, 'sender_email'=>?, 'subject'=>?, 'email_date'=>?]
     * @param string $bytes        Raw decoded attachment bytes
     * @param string $filename     Original attachment filename
     * @param string $mime         MIME type
     * @param int    $systemUserId users.id to attribute the expense to (an admin)
     * @return array{status:string,expense_id:?int,note:?string}
     *         status ∈ duplicate | unsupported | pending | error (never auto_posted since 2026-10-07; high_confidence flags a clean match)
     */
    public function ingestAttachment(array $msg, string $bytes, string $filename, string $mime, int $systemUserId): array
    {
        $mime   = strtolower(trim($mime));
        $sha256 = hash('sha256', $bytes);
        $dedup  = self::deriveDedupKey($msg['message_id'] ?? null, $sha256, $filename);

        if (!$this->claim($msg, $dedup, $filename)) {
            return ['status' => 'duplicate', 'expense_id' => null, 'note' => null];
        }
        // The same file under another Message-ID (forwarded from office@ to iCloud, a vendor's
        // resend): already read once — never a second expense (2026-10-07, iCloud router).
        if ($this->shaSeenElsewhere($sha256, $dedup)) {
            $this->finalizeClaim($dedup, null, null, 'skipped', null, 'same file as an earlier email');
            return ['status' => 'duplicate', 'expense_id' => null, 'note' => 'same file as an earlier email'];
        }

        try {
            return $this->processClaimedAttachment($msg, $bytes, $filename, $mime, $systemUserId, $dedup, $sha256);
        } catch (\Throwable $e) {
            $this->releaseClaim($dedup);
            throw $e;
        }
    }

    /** An earlier email (any mailbox) already carried exactly these bytes. */
    private function shaSeenElsewhere(string $sha256, string $dedup): bool
    {
        $s = $this->db->prepare("
            SELECT 1 FROM receipt_inbox_messages
            WHERE dedup_key <> ? AND (dedup_key = ? OR dedup_key LIKE ?) AND outcome <> 'processing'
            LIMIT 1
        ");
        $s->execute([$dedup, 'sha:' . $sha256, '%:' . $sha256]);
        return (bool)$s->fetchColumn();
    }

    /** Does the actual store/OCR/expense-creation work for an already-claimed dedup key. */
    private function processClaimedAttachment(array $msg, string $bytes, string $filename, string $mime, int $systemUserId, string $dedup, string $sha256): array
    {
        $isImage = in_array($mime, self::IMAGE_MIMES, true);
        $isPdf   = ($mime === self::PDF_MIME);
        if (!$isImage && !$isPdf) {
            $this->finalizeClaim($dedup, null, null, 'skipped', null, 'unsupported attachment (' . $mime . ')');
            return ['status' => 'unsupported', 'expense_id' => null, 'note' => 'unsupported attachment'];
        }

        // 1) Store the attachment to disk + media_assets.
        $stored = $this->storeAttachment($bytes, $filename, $mime, $isPdf, $sha256, $systemUserId);
        $mediaId  = $stored['media_id'];
        $diskPath = $stored['disk_path'];

        // 2) OCR (rasterise PDFs first). Unreadable PDFs return readable=false.
        $ocr = $this->runOcr($diskPath, $isPdf);

        return $this->createExpenseFromRead($msg, $ocr, $mediaId, $dedup, true, $isPdf ? 'pdf not OCR-able' : 'no text extracted', $systemUserId);
    }

    /**
     * Steps 3–7 for any read receipt (an OCR'd attachment or an email body): build the
     * expense, gate auto-posting, score anomalies, insert, finalize the claim.
     */
    private function createExpenseFromRead(array $msg, array $ocr, ?int $mediaId, string $dedup, bool $mayAutoPost, string $unreadableNote, int $systemUserId): array
    {
        // 3) Build expense fields from parsed text + smart match.
        $parsed      = $ocr['parsed'];
        $suggestions = $ocr['suggestions'];

        $vendorId   = !empty($suggestions['vendor_id']) ? (int) $suggestions['vendor_id'] : null;
        $vendorCat  = $vendorId ? $this->vendorDefaultCategory($vendorId) : null;
        $total      = isset($parsed['total']) && $parsed['total'] !== null ? (float) $parsed['total'] : 0.0;
        $gst        = isset($parsed['gst'])   && $parsed['gst']   !== null ? (float) $parsed['gst']   : 0.0;
        $subtotal   = isset($parsed['subtotal']) && $parsed['subtotal'] !== null ? (float) $parsed['subtotal'] : null;
        $amount     = $subtotal !== null ? $subtotal : max(0.0, $total - $gst);
        $expDate    = (!empty($parsed['date']) && self::isValidDate($parsed['date'])) ? $parsed['date'] : date('Y-m-d');
        $category   = $suggestions['accounting_category'] ?? $vendorCat;
        $confidence = (int) ($suggestions['vendor_confidence'] ?? 0);

        // 4) Confidence gate. A PDF we couldn't OCR can never be a clean match. A clean
        //    match is NOT approved — it waits for approval, flagged high-confidence.
        $clean = $mayAutoPost && $ocr['readable'] && self::isCleanMatch([
            'vendor_id'               => $vendorId,
            'total'                   => $total,
            'expense_date'            => $parsed['date'] ?? null,   // gate on the *parsed* date, not the fallback
            'vendor_default_category' => $vendorCat,
        ]);
        $status = self::inboxStatus($clean)['status'];

        // 5) Anomaly scoring (best-effort, mirrors expense-save.php).
        $anomalyFlags = null;
        $anomalyScore = 0;
        try {
            require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
            $an = detectAnomalies([
                'total' => $total, 'amount' => $amount, 'vendor_id' => $vendorId,
                'expense_date' => $expDate, 'created_by' => $systemUserId,
            ], $this->db);
            $anomalyFlags = ($an['flags'] ?? '') ?: null;
            $anomalyScore = (int) ($an['score'] ?? 0);
        } catch (\Throwable $e) {
            error_log('[ReceiptInboxService] anomaly: ' . $e->getMessage());
        }

        // 6) Create the expense — through ExpenseCreateGuard like every create path: one
        //    receipt file, one expense.
        require_once __DIR__ . '/ExpenseCreateGuard.php';
        $guard = new ExpenseCreateGuard($this->db);
        if ($mediaId && ($existing = $guard->claim($mediaId)) !== null) {
            $this->finalizeClaim($dedup, $mediaId, $existing, 'skipped', null, 'already expense #' . $existing);
            return ['status' => 'duplicate', 'expense_id' => $existing, 'note' => 'already expense #' . $existing, 'high_confidence' => false];
        }
        $note = $msg['subject'] ? ('Emailed receipt: ' . $msg['subject']) : 'Emailed receipt';
        $ins = $this->db->prepare("
            INSERT INTO expenses
                (expense_date, vendor_id, vendor_name_raw, description, amount, gst_amount, pst_amount, total,
                 accounting_category, gbp_category, payment_method, receipt_media_id,
                 match_confidence, anomaly_flags, anomaly_score, raw_ocr_json,
                 notes, status, source, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $expDate,
            $vendorId,
            $suggestions['vendor_name'] ?? ($parsed['vendor_hint'] ?? null),
            $parsed['vendor_hint'] ?? null,
            $amount,
            $gst,
            0.0,
            $total,
            $category,
            $suggestions['gbp_category'] ?? null,
            $parsed['payment_method'] ?? null,
            $mediaId,
            $confidence,
            $anomalyFlags,
            $anomalyScore,
            $ocr['ocr_text'] !== '' ? json_encode(['text' => $ocr['ocr_text'], 'parsed' => $parsed, 'source' => $ocr['source']]) : null,
            $note,
            $status,
            'email_inbox',
            $systemUserId,
        ]);
        $expenseId = (int) $this->db->lastInsertId();
        $guard->release($mediaId);

        // 7) Finalize the claimed audit row with the outcome.
        //    'auto_posted' is now written only when the owner approves (approve() /
        //    BookkeeperDeskService::afterInboxApproval).
        $outcome = 'pending';
        $logNote = $ocr['readable'] ? ($clean ? self::HIGH_CONFIDENCE_NOTE : null) : $unreadableNote;
        $this->finalizeClaim($dedup, $mediaId, $expenseId, $outcome, $confidence, $logNote);

        return ['status' => $outcome, 'expense_id' => $expenseId, 'note' => $logNote, 'high_confidence' => $clean];
    }

    /** Persist the raw bytes to /uploads/receipts/ and register in media_assets. */
    private function storeAttachment(string $bytes, string $filename, string $mime, bool $isPdf, string $sha256, int $userId): array
    {
        $uploadDir = PUBLIC_ROOT . '/uploads/receipts/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5) {
            $ext = $isPdf ? 'pdf' : 'jpg';
        }
        $storedName = 'receipt-email-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $diskPath   = $uploadDir . $storedName;
        $webPath    = '/uploads/receipts/' . $storedName;
        file_put_contents($diskPath, $bytes);

        $w = null; $h = null;
        if (!$isPdf) {
            $info = @getimagesize($diskPath);
            if ($info) { $w = $info[0]; $h = $info[1]; }
        }

        $stmt = $this->db->prepare("
            INSERT INTO media_assets
                (original_filename, stored_filename, file_path, file_type, mime_type, file_size,
                 image_width, image_height, sha256, alt_text, context_type, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Emailed receipt', 'expense', ?)
        ");
        $stmt->execute([
            $filename,
            $storedName,
            $webPath,
            $isPdf ? 'document' : 'image',
            $mime,
            strlen($bytes),
            $w,
            $h,
            $sha256,
            $userId,
        ]);

        return ['media_id' => (int) $this->db->lastInsertId(), 'disk_path' => $diskPath, 'web_path' => $webPath];
    }

    /**
     * Run the existing OCR pipeline on a stored file. PDFs are rasterised (page 1)
     * via Imagick first; if that's unavailable the receipt is stored but returns
     * readable=false so it routes to manual review.
     *
     * @return array{readable:bool,source:string,ocr_text:string,parsed:array,suggestions:array}
     */
    private function runOcr(string $diskPath, bool $isPdf): array
    {
        $empty = ['readable' => false, 'source' => 'none', 'ocr_text' => '', 'parsed' => [], 'suggestions' => []];

        $imagePath = $diskPath;
        $tmpPng    = null;
        if ($isPdf) {
            if (!extension_loaded('imagick')) {
                return $empty + ['note' => 'imagick missing'];
            }
            try {
                $im = new \Imagick();
                $im->setResolution(200, 200);
                $im->readImage($diskPath . '[0]');     // first page only
                $im->setImageFormat('png');
                $im->setImageBackgroundColor('white');
                $im = $im->flattenImages();
                $tmpPng = $diskPath . '.page1.png';
                $im->writeImage($tmpPng);
                $im->destroy();
                $imagePath = $tmpPng;
            } catch (\Throwable $e) {
                error_log('[ReceiptInboxService] PDF rasterise failed: ' . $e->getMessage());
                return $empty;
            }
        }

        try {
            require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
            require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
            require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

            // Tesseract pre-screen → Vision fallback, mirroring receipt-intake.php.
            $pre = tesseractPreScreen($imagePath);
            if (empty($pre['error']) && ($pre['score'] ?? 0) > 0) {
                $decision = ($pre['score'] >= 70) ? 'use_tesseract' : (($pre['score'] >= 30) ? 'use_vision' : 'skip');
            } else {
                $decision = $pre['decision'] ?? 'use_vision';
            }

            $ocr = ['success' => false, 'text' => '', 'raw_response' => null];
            $source = 'none';
            if ($decision === 'use_tesseract') {
                $ocr = ['success' => true, 'text' => $pre['text'], 'raw_response' => null];
                $source = 'tesseract';
            } elseif ($decision === 'use_vision') {
                $ocr = extractTextFromImage($imagePath);
                $source = 'vision';
            }

            $text = $ocr['text'] ?? '';
            if (!($ocr['success'] ?? false) || $text === '') {
                return $empty;
            }

            $parsed      = parseReceiptText($text, $ocr['raw_response'] ?? null);
            $suggestions = suggestReceiptMeta($text, null, null, null, $parsed);

            return [
                'readable'    => true,
                'source'      => $source,
                'ocr_text'    => $text,
                'parsed'      => $parsed,
                'suggestions' => $suggestions,
            ];
        } catch (\Throwable $e) {
            error_log('[ReceiptInboxService] OCR error: ' . $e->getMessage());
            return $empty;
        } finally {
            if ($tmpPng && is_file($tmpPng)) {
                @unlink($tmpPng);
            }
        }
    }

    private function vendorDefaultCategory(int $vendorId): ?string
    {
        $stmt = $this->db->prepare("SELECT default_accounting_category FROM vendors WHERE id = ? LIMIT 1");
        $stmt->execute([$vendorId]);
        $cat = $stmt->fetchColumn();
        return ($cat !== false && $cat !== null && trim((string) $cat) !== '') ? (string) $cat : null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Review panel
    // ──────────────────────────────────────────────────────────────────────

    /** Draft expenses created from email, newest first, with receipt + vendor info. */
    public function listPending(): array
    {
        $sql = "SELECT e.id, e.expense_date, e.vendor_id, e.vendor_name_raw, e.description,
                       e.amount, e.gst_amount, e.pst_amount, e.total, e.accounting_category,
                       e.match_confidence, e.notes, e.created_at, e.receipt_media_id,
                       v.name AS vendor_name,
                       m.file_path AS receipt_path, m.mime_type AS receipt_mime,
                        rim.note AS inbox_note, rim.sender_email
                  FROM expenses e
             LEFT JOIN vendors v        ON e.vendor_id = v.id
             LEFT JOIN media_assets m   ON e.receipt_media_id = m.id
             LEFT JOIN receipt_inbox_messages rim ON rim.expense_id = e.id
                 WHERE e.source = 'email_inbox' AND e.status IN ('draft', 'pending_approval')
              ORDER BY e.created_at DESC, e.id DESC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countPending(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM expenses WHERE source = 'email_inbox' AND status IN ('draft', 'pending_approval')"
        )->fetchColumn();
    }

    /**
     * Approve a pending emailed expense — apply any edits and flip it to 'approved'
     * so the sync-ledger cron posts it. Returns ['ok'=>bool,'message'=>string].
     */
    public function approve(int $expenseId, array $edits, int $userId): array
    {
        $exp = $this->loadPendingExpense($expenseId);
        if (!$exp) {
            return ['ok' => false, 'message' => 'Receipt not found or already handled'];
        }

        $vendorId = array_key_exists('vendor_id', $edits) && $edits['vendor_id'] !== '' ? (int) $edits['vendor_id'] : ($exp['vendor_id'] !== null ? (int) $exp['vendor_id'] : null);
        $date     = !empty($edits['expense_date']) && self::isValidDate($edits['expense_date']) ? $edits['expense_date'] : $exp['expense_date'];
        $total    = isset($edits['total']) && $edits['total'] !== '' ? (float) $edits['total'] : (float) $exp['total'];
        $gst      = isset($edits['gst_amount']) && $edits['gst_amount'] !== '' ? (float) $edits['gst_amount'] : (float) $exp['gst_amount'];
        $pst      = isset($edits['pst_amount']) && $edits['pst_amount'] !== '' ? (float) $edits['pst_amount'] : (float) $exp['pst_amount'];
        $amount   = max(0.0, $total - $gst - $pst);
        $category = array_key_exists('accounting_category', $edits) && $edits['accounting_category'] !== '' ? $edits['accounting_category'] : $exp['accounting_category'];

        if ($total <= 0) {
            return ['ok' => false, 'message' => 'Total must be greater than zero'];
        }

        $this->db->prepare("
            UPDATE expenses
               SET vendor_id = ?, expense_date = ?, amount = ?, gst_amount = ?, pst_amount = ?,
                   total = ?, accounting_category = ?, status = 'approved', updated_at = NOW()
             WHERE id = ? AND source = 'email_inbox' AND status IN ('draft', 'pending_approval')
        ")->execute([$vendorId, $date, $amount, $gst, $pst, $total, $category, $expenseId]);

        $this->db->prepare(
            "UPDATE receipt_inbox_messages SET outcome = 'auto_posted' WHERE expense_id = ? AND outcome = 'pending'"
        )->execute([$expenseId]);

        // An emailed receipt's extraction was written straight into the row, so the row as
        // loaded (before these edits) is the baseline the approver's corrections are learned from.
        require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        learnFromConfirmedExpense($this->db, $expenseId, baselineFromExpenseRow($exp));

        return ['ok' => true, 'message' => 'Approved — will post to the books on next sync'];
    }

    public function dismiss(int $expenseId, int $userId): array
    {
        $upd = $this->db->prepare(
            "UPDATE expenses SET status = 'cancelled', updated_at = NOW()
              WHERE id = ? AND source = 'email_inbox' AND status IN ('draft', 'pending_approval')"
        );
        $upd->execute([$expenseId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'message' => 'Nothing to dismiss'];
        }
        $this->db->prepare(
            "UPDATE receipt_inbox_messages SET outcome = 'dismissed' WHERE expense_id = ?"
        )->execute([$expenseId]);
        return ['ok' => true, 'message' => 'Dismissed'];
    }

    private function loadPendingExpense(int $expenseId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM expenses WHERE id = ? AND source = 'email_inbox' AND status IN ('draft', 'pending_approval') LIMIT 1"
        );
        $stmt->execute([$expenseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
