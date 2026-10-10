<?php
/**
 * CompanyDocumentService — the company's papers (owner, 2026-10-10).
 *
 * Business licence first (suppliers ask for a copy with every account application); insurance
 * and WorkSafeBC clearance letters use the same table. Files live in STORAGE_ROOT/company-docs
 * (app/ is closed to the web). One current document per type; a newer one replaces it.
 *
 * Yui files the city's licence email herself (supplier_inbox_poll: sender = ops_settings
 * licence_sender, a PDF attached) — number and expiry are read from the PDF (parseLicence).
 * Otto reminds 30 days before a current document expires (briefItems).
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class CompanyDocumentService
{
    public const TYPES = ['business_licence' => 'Business licence', 'insurance' => 'Liability insurance',
                          'wcb_clearance' => 'WorkSafeBC clearance letter', 'other' => 'Document'];
    public const REMIND_DAYS = 30;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A City of Vancouver-style licence's text → number, issued and expiry dates.
     * "2026 LICENCE OFFICE … Licence # 26-105147 May 30, 2026 December 31, 2026 …"
     * @return array{number: ?string, issued: ?string, expires: ?string}
     */
    public static function parseLicence(string $text): array
    {
        $t = (string)preg_replace('/\s+/', ' ', $text);
        $num = preg_match('/Licen[cs]e\s*(?:#|No\.?|Number)\s*:?\s*([A-Z0-9][A-Z0-9-]{3,})/i', $t, $m) ? $m[1] : null;
        $dates = [];
        if (preg_match_all('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2}),\s*(\d{4})\b/', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $d) {
                $ts = strtotime("{$d[1]} {$d[2]} {$d[3]}");
                if ($ts) $dates[] = date('Y-m-d', $ts);
            }
        }
        if (!$dates && preg_match_all('/\b(20\d\d)-(\d\d)-(\d\d)\b/', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $d) $dates[] = "{$d[1]}-{$d[2]}-{$d[3]}";
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
        return ['number' => $num, 'issued' => $dates[0] ?? null, 'expires' => count($dates) > 1 ? end($dates) : null];
    }

    /** 'ok' | 'soon' | 'expired' | 'unknown' */
    public static function expiryState(?string $expires, string $today): string
    {
        if ($expires === null || $expires === '') return 'unknown';
        if ($expires < $today) return 'expired';
        return (strtotime($expires) - strtotime($today)) <= self::REMIND_DAYS * 86400 ? 'soon' : 'ok';
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM company_documents LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function current(string $type): ?array
    {
        if (!$this->ready()) return null;
        $s = $this->db->prepare("SELECT * FROM company_documents WHERE doc_type = ? AND is_current = 1 ORDER BY COALESCE(expires_on, '9999-12-31') DESC, id DESC LIMIT 1");
        $s->execute([$type]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function absolutePath(array $doc): string
    {
        return rtrim(STORAGE_ROOT, '/') . '/' . ltrim((string)$doc['file_path'], '/');
    }

    /**
     * Keep a document. For a licence, number + dates come from the PDF when not given, and the
     * number is copied to business_settings.business_license (what invoices and forms read).
     * Returns the new id, or null when this email was already filed (message_key).
     */
    public function store(string $type, string $bytes, string $fileName, array $meta = []): ?int
    {
        if (!$this->ready() || $bytes === '') return null;
        if (!isset(self::TYPES[$type])) $type = 'other';
        $key = $meta['message_key'] ?? null;
        if ($key !== null) {
            $s = $this->db->prepare("SELECT id FROM company_documents WHERE message_key = ?");
            $s->execute([$key]);
            if ($s->fetchColumn()) return null;
        }
        if ($type === 'business_licence' && (empty($meta['number']) || empty($meta['expires'])) && stripos($fileName, '.pdf') !== false) {
            $parsed = self::parseLicence(self::pdfText($bytes));
            $meta += array_filter(['number' => $parsed['number'], 'issued' => $parsed['issued'], 'expires' => $parsed['expires']]);
        }
        $dir = rtrim(STORAGE_ROOT, '/') . '/company-docs';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $safe = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName);
        $rel = 'company-docs/' . $type . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '-' . $safe;
        if (file_put_contents(rtrim(STORAGE_ROOT, '/') . '/' . $rel, $bytes) === false) {
            throw new RuntimeException('Could not save the document');
        }
        $this->db->prepare("UPDATE company_documents SET is_current = 0 WHERE doc_type = ? AND is_current = 1 AND (expires_on IS NULL OR ? IS NULL OR expires_on <= ?)")
            ->execute([$type, $meta['expires'] ?? null, $meta['expires'] ?? null]);
        $this->db->prepare("
            INSERT INTO company_documents (doc_type, title, doc_number, issued_on, expires_on, file_path, file_name, mime, source, message_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$type, $meta['title'] ?? self::TYPES[$type], $meta['number'] ?? null, $meta['issued'] ?? null, $meta['expires'] ?? null,
                     $rel, mb_substr($fileName, 0, 160), $meta['mime'] ?? 'application/pdf', $meta['source'] ?? 'upload', $key]);
        $id = (int)$this->db->lastInsertId();
        if ($type === 'business_licence' && !empty($meta['number'])) {
            try {
                $this->db->prepare("UPDATE business_settings SET business_license = ?")->execute([$meta['number']]);
            } catch (Throwable $e) { /* column optional */ }
        }
        return $id;
    }

    /** Otto's reminders: a current document expiring within 30 days, or expired. */
    public function briefItems(string $today): array
    {
        if (!$this->ready()) return [];
        $out = [];
        foreach (array_keys(self::TYPES) as $type) {
            $d = $this->current($type);
            if (!$d) continue;
            $state = self::expiryState($d['expires_on'], $today);
            if ($state !== 'soon' && $state !== 'expired') continue;
            $when = date('M j', strtotime((string)$d['expires_on']));
            $out[] = ['key' => 'otto:doc:' . $type . ':' . $d['expires_on'], 'kind' => 'document', 'priority' => $state === 'expired' ? 1 : 2,
                      'text' => self::TYPES[$type] . ($state === 'expired' ? " expired {$when}" : " expires {$when}")
                              . ' — renew it; suppliers ask for a copy with every account application.',
                      'url' => null, 'value' => null, 'since' => $today];
        }
        return $out;
    }

    /** The PDF's text (smalot/pdfparser, already in public/vendor). '' when unreadable. */
    public static function pdfText(string $bytes): string
    {
        try {
            if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
                $auto = defined('PUBLIC_ROOT') ? PUBLIC_ROOT . '/vendor/autoload.php' : '';
                if ($auto !== '' && is_file($auto)) require_once $auto;
            }
            if (!class_exists('\\Smalot\\PdfParser\\Parser')) return '';
            return (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        } catch (Throwable $e) {
            return '';
        }
    }
}
