<?php
/**
 * InvoicePdfGate — every invoice email MUST carry the invoice PDF (owner rule, 2026-10-06).
 *
 * Property managers' accounts systems reject invoices without a PDF. sendEmail() drops a
 * missing attachment silently and sends anyway, so every invoice email path asks this gate
 * first and does NOT send (and does NOT mark the invoice sent) when it returns null.
 *
 *   $gate = new InvoicePdfGate($db);
 *   $pdf  = $gate->ensurePdf($invoiceId);
 *   if ($pdf === null) {
 *       $gate->recordBlocked($invoiceId, 'view_send');      // Charlie alerts on this
 *       return InvoicePdfGate::NOT_SENT_MESSAGE;
 *   }
 *   ... just before each send: if (!$gate->isUsable($pdf)) abort the same way.
 *
 * ensurePdf(): 1. PdfGenerator::generateInvoicePdf()  2. the cached getPdfPath()
 *              3. the file exists, is non-empty and starts with "%PDF".
 *
 * A failure is recorded as an activity_log row (action 'invoice_pdf_blocked', details JSON
 * {context, reason}). CharlieUrgentService emails the owner once per invoice; the contract
 * billing cron uses the 'contract_billing' rows to retry a held draft on its next run.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class InvoicePdfGate
{
    public const NOT_SENT_MESSAGE = 'Not sent: the invoice PDF could not be made';
    public const BLOCKED_ACTION   = 'invoice_pdf_blocked';

    private ?PDO $db;
    /** @var object|null anything with generateInvoicePdf(int): array and getPdfPath(string, int): ?string */
    private $generator;
    private ?string $lastError = null;

    public function __construct(?PDO $db = null, $generator = null)
    {
        $this->db = $db;
        $this->generator = $generator;
    }

    /** A readable, non-empty PDF for this invoice, or null (reason in lastError() and the error log). */
    public function ensurePdf(int $invoiceId): ?string
    {
        $this->lastError = null;
        $reasons = [];

        $gen = $this->generator();
        if ($gen === null) {
            $reasons[] = 'PDF generator unavailable';
        } else {
            // 1. Fresh render.
            try {
                $r = $gen->generateInvoicePdf($invoiceId);
                $path = is_array($r) && !empty($r['success']) ? (string)($r['path'] ?? '') : '';
                if ($path !== '') {
                    $bad = self::checkPdfFile($path);
                    if ($bad === null) return $path;
                    $reasons[] = 'generated file ' . $bad;
                } else {
                    $reasons[] = 'generate failed: ' . (is_array($r) ? (string)($r['error'] ?? 'unknown error') : 'no result');
                }
            } catch (Throwable $e) {
                $reasons[] = 'generate threw: ' . $e->getMessage();
            }

            // 2. Cached copy.
            try {
                $cached = $gen->getPdfPath('invoice', $invoiceId);
                if ($cached) {
                    $bad = self::checkPdfFile((string)$cached);
                    if ($bad === null) {
                        error_log("[InvoicePdfGate] invoice {$invoiceId}: " . implode('; ', $reasons) . ' — using cached PDF ' . $cached);
                        return (string)$cached;
                    }
                    $reasons[] = 'cached file ' . $bad;
                } else {
                    $reasons[] = 'no cached PDF';
                }
            } catch (Throwable $e) {
                $reasons[] = 'cache lookup threw: ' . $e->getMessage();
            }
        }

        $this->lastError = implode('; ', $reasons);
        error_log("[InvoicePdfGate] invoice {$invoiceId}: NO PDF — {$this->lastError}");
        return null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** True when the path is still a real PDF (call again just before sending). */
    public function isUsable(?string $path): bool
    {
        return $path !== null && $path !== '' && self::checkPdfFile($path) === null;
    }

    /** Pure: null when the file is a usable PDF, otherwise why not. */
    public static function checkPdfFile(string $path): ?string
    {
        // Never throws — some crons install an error handler that turns warnings into exceptions.
        try {
            clearstatcache(true, $path);
            if (!is_file($path))       return "missing ({$path})";
            if (!is_readable($path))   return "unreadable ({$path})";
            if ((int)filesize($path) <= 0) return "empty ({$path})";
            $fh = fopen($path, 'rb');
            if (!$fh) return "unreadable ({$path})";
            $head = (string)fread($fh, 4);
            fclose($fh);
            if ($head !== '%PDF') return "not a PDF ({$path})";
            return null;
        } catch (Throwable $e) {
            return "unreadable ({$path}): " . $e->getMessage();
        }
    }

    /**
     * Record that an invoice email was NOT sent (or a reminder held) because there was no PDF.
     * Charlie reads these rows; contract billing retries its own. Never throws.
     */
    public function recordBlocked(int $invoiceId, string $context, ?string $reason = null): void
    {
        $reason = $reason ?? $this->lastError ?? 'PDF unavailable';
        error_log("[InvoicePdfGate] invoice {$invoiceId} NOT SENT ({$context}): {$reason}");
        try {
            $db = $this->db ?? (function_exists('getDB') ? getDB() : null);
            if (!$db) return;
            $db->prepare("INSERT INTO activity_log (user_id, invoice_id, action, details) VALUES (NULL, ?, ?, ?)")
               ->execute([$invoiceId, self::BLOCKED_ACTION, json_encode([
                   'context' => $context,
                   'reason'  => mb_substr($reason, 0, 400),
               ], JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $e) {
            error_log("[InvoicePdfGate] could not record block for invoice {$invoiceId}: " . $e->getMessage());
        }
    }

    private function generator()
    {
        if ($this->generator !== null) return $this->generator;
        try {
            require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';   // mPDF autoloader; throws if vendor/ is missing
            if (!class_exists('PdfGenerator')) {
                require_once APP_ROOT . '/Services/Pdf/PdfGenerator.php';
            }
            $this->generator = new PdfGenerator();
        } catch (Throwable $e) {
            error_log('[InvoicePdfGate] PdfGenerator unavailable: ' . $e->getMessage());
            return null;
        }
        return $this->generator;
    }
}
