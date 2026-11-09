<?php
/**
 * SupplierInboxService — Yui's supplier mail rule (owner, 2026-10-10).
 *
 * For each supplier in the directory (SupplierDirectoryService), searches the mailbox for mail
 * FROM that supplier in the last few days — one IMAP SEARCH each, no full scan — and:
 *   - notes that we heard from them (last_heard_at: stops Otto's "hasn't answered" chase);
 *   - hands every PDF to SupplierFormService::capture(), which files account applications
 *     for Otto to fill and offer to send.
 *   - price lists (migration 1331): when the sender's domain has a list format
 *     (supplier_list_formats, e.g. Inline Nurseries' weekly Mailchimp email), the .xlsx/.csv links
 *     in the email are downloaded (format's hosts only, https, 5 MB) and imported by
 *     SupplierPriceListService — booking forms skipped; deduped by message + link and by
 *     (supplier, kind, list date). A failure is logged and never stops the poll.
 * Also files the city's business licence email (ops_settings licence_sender) as the current
 * business licence (CompanyDocumentService) — renewed every year without anyone forwarding it.
 *
 * READ-ONLY, always: ImapReader::open (OP_READONLY) and FT_PEEK fetches — nothing is marked,
 * moved or deleted. Runs inside sales_inbox_poll (office@, every 15 min) for office@ and iCloud.
 * Dedupe: supplier_forms (message_key + file name), company_documents.message_key.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Services/Mail/ImapReader.php';
require_once dirname(__DIR__, 2) . '/Operations/Services/SupplierFormService.php';
require_once dirname(__DIR__, 2) . '/Operations/Services/SupplierPriceListService.php';

class SupplierInboxService
{
    /** File names imported as price lists from the email being read (so they aren't filed as forms). */
    private array $listFiles = [];
    public const DAYS = 4;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Message key shared with the other readers' style: Message-ID, else a hash of the envelope. */
    public static function messageKey(string $messageId, string $from, string $subject, string $date): string
    {
        $id = trim($messageId, " <>\t");
        return $id !== '' ? mb_substr($id, 0, 191) : 'sha:' . hash('sha256', strtolower($from) . '|' . $subject . '|' . $date);
    }

    /**
     * The receiving server's verdict says the From was forged: DMARC failed, or SPF failed with
     * no passing DKIM signature. No verdict at all is not a failure (some hosts add none). PURE.
     */
    public static function authFailed(string $authResults): bool
    {
        $a = strtolower($authResults);
        if ($a === '') return false;
        if (preg_match('/\bdmarc=fail\b/', $a)) return true;
        return (bool)preg_match('/\bspf=(fail|softfail)\b/', $a) && !preg_match('/\bdkim=pass\b/', $a);
    }

    /**
     * Is this message really from $expected? The From ADDRESS (not a substring of the header)
     * must belong to the supplier / licence sender, and the server must not have flagged it as
     * forged. A forged sender would otherwise get our filled credit application and business
     * licence on Tim's "Send it" (2026-10-12). PURE.
     */
    public static function fromIs(string $fromHeader, string $authResults, array $expected): bool
    {
        if (self::authFailed($authResults)) return false;
        return SupplierDirectoryService::senderMatches(SupplierDirectoryService::addressOf($fromHeader), $expected);
    }

    /**
     * May a price list from this email be imported? (security review #20, 2026-10-12.) Stricter than
     * fromIs(): a list goes straight into prices, and Inline's mail comes through Mailchimp's shared
     * CDN, so a forged email could plant a poisoned file. The sender must be PROVEN — MailAuth's
     * exact address + a verified DKIM signature for its domain (or a trusted server's dmarc/dkim
     * pass). No verdict at all fails closed here (authFailed('') is "not failed" only for forms). PURE.
     */
    public static function listImportAllowed(array $mailAuth): bool
    {
        return !empty($mailAuth['ok']);
    }

    private function setting(string $key): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            return strtolower(trim((string)($s->fetchColumn() ?: '')));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Read one mailbox. $mb: a MailboxConfig entry. Returns counts.
     * @return array{heard: int, forms: int, licences: int, lists: int, errors: int, note: string}
     */
    public function poll(array $mb, string $label, ?float $deadline = null): array
    {
        $out = ['heard' => 0, 'forms' => 0, 'licences' => 0, 'lists' => 0, 'errors' => 0, 'note' => ''];
        $dir = new SupplierDirectoryService($this->db);
        $forms = new SupplierFormService($this->db);
        if (!$dir->ready() || !$forms->ready()) { $out['note'] = 'migration 1320 not run'; return $out; }
        $keys = $dir->mailKeys();
        $licenceFrom = $this->setting('licence_sender');
        if (!$keys && $licenceFrom === '') return $out;

        $conn = ImapReader::open($mb, 'INBOX');
        if ($conn === false) { $out['errors']++; $out['note'] = 'could not open ' . $label; return $out; }
        $since = date('d-M-Y', strtotime('-' . self::DAYS . ' days'));
        $searches = [];
        foreach ($keys as $k => $sid) $searches[] = [$k, $sid];
        if ($licenceFrom !== '') $searches[] = [$licenceFrom, 0];
        $seen = [];
        try {
            foreach ($searches as [$k, $sid]) {
                if ($deadline !== null && microtime(true) > $deadline) { $out['note'] = 'time budget reached'; break; }
                $hits = @imap_search($conn, 'FROM "' . str_replace('"', '', $k) . '" SINCE "' . $since . '"');
                foreach (is_array($hits) ? $hits : [] as $no) {
                    if (isset($seen[$no])) continue;
                    $seen[$no] = true;
                    try {
                        $this->one($conn, (int)$no, $sid, $label, $dir, $forms, $out);
                    } catch (Throwable $e) {
                        $out['errors']++;
                        error_log('[supplier inbox] ' . $label . ' #' . $no . ': ' . $e->getMessage());
                    }
                }
            }
        } finally {
            @imap_close($conn);
        }
        return $out;
    }

    /**
     * The supplier's emailed price lists → SupplierPriceListService. Only for a sender whose domain
     * has a list format. Never throws. Fills $body (plain + HTML text) for the caller to reuse.
     * @return array{imported: int, failed: int}
     */
    private function priceLists($conn, int $no, $st, array $supplier, string $from, string $subject, string $key, string $received, ?string &$body): array
    {
        $none = ['imported' => 0, 'failed' => 0];
        try {
            $svc = new SupplierPriceListService($this->db);
            if (!$svc->ready()) return $none;
            $domain = SupplierDirectoryService::domainOf(SupplierDirectoryService::addressOf($from));
            $format = $svc->formatForDomain($domain);
            $body = $this->fullBody($conn, $no, $st);
            // No format for this sender yet: read the first list by itself (migration 1390), hold it for one
            // "looks right" tap. Nothing here is a guess that reaches a price — until confirmed the list stays
            // out of quote pricing.
            if (!$format && $svc->reviewReady()) $format = $this->newFormat($conn, $no, $st, $svc, $supplier, $domain, $from, $subject, $key, $body);
            if (!$format) return $none;
            $r = $svc->importFromEmail($supplier, $format, $body, ['message_key' => $key, 'received_at' => $received]);
            // Lists attached to the email (Specimen Trees' .xls, SW Garden's PDF), when the format says so.
            foreach ($st ? ImapReader::files($st) : [] as $f) {
                if (!SupplierPriceListService::listAttachment((string)$f['filename'], $format)) continue;
                $bytes = ImapReader::fetchPart($conn, $no, $f['pn'], (int)$f['encoding']);
                $meta = ['message_key' => $key, 'received_at' => $received, 'body' => $body];
                $a = $svc->importAttachment($supplier, $format, (string)$f['filename'], $bytes, $meta);
                if ($a['status'] === 'failed' && !empty($format['auto_inferred'])) {
                    // A format read automatically can drift (the supplier renamed a column): read the file again,
                    // and if it reads, hold the new reading for another "looks right" tap and retry once.
                    $inf = $svc->inferFromFile((string)$f['filename'], $bytes);
                    if ($inf['ok'] && ($nf = $svc->respecAutoFormat((int)$format['id'], $inf))) {
                        $format = $nf;
                        $a = $svc->importAttachment($supplier, $format, (string)$f['filename'], $bytes, $meta);
                    } elseif (preg_match('/price|list|avail|special|stock|catalog|inventory/i', (string)$f['filename'] . ' ' . $subject)) {
                        $svc->recordSkip((int)($supplier['id'] ?? 0), SupplierDirectoryService::addressOf($from), $subject, (string)$f['filename'], (string)$inf['reason'], $key);
                    }
                    // A file that is not a price list (an account form PDF) is not an import failure for an auto format.
                    if ($a['status'] === 'failed') $a['status'] = 'ignored';
                }
                if ($a['status'] === 'imported') $r['imported']++;
                elseif ($a['status'] === 'failed') $r['failed']++;
                if ($a['status'] === 'imported' || $a['status'] === 'duplicate') $this->listFiles[(string)$f['filename']] = true;
            }
            if ($r['imported'] || $r['failed']) {
                error_log('[supplier inbox] price lists from ' . ($supplier['name'] ?? '?') . ": {$r['imported']} imported, {$r['duplicate']} already in, {$r['failed']} failed");
            }
            return ['imported' => $r['imported'], 'failed' => $r['failed']];
        } catch (Throwable $e) {
            error_log('[supplier inbox] price lists: ' . $e->getMessage());
            return ['imported' => 0, 'failed' => 1];
        }
    }

    /** Plain + HTML text of the email (Mailchimp puts the file links in the HTML part). */
    private function fullBody($conn, int $no, $st): string
    {
        $body = ImapReader::textBody($conn, $no, $st);
        $html = $st ? ImapReader::bodyPart($st) : null;
        if ($html && $html['html']) {
            $h = ImapReader::fetchPart($conn, $no, $html['pn'], (int)$html['encoding']);
            if ($html['charset'] !== '' && $html['charset'] !== 'UTF-8') $h = (string)@mb_convert_encoding($h, 'UTF-8', $html['charset']);
            if (strpos($body, $h) === false) $body .= "\n" . $h;
        }
        return $body;
    }

    /**
     * A sender with no list format: try each attached sheet/PDF and each sheet link on the sender's own
     * domain (or Mailchimp's file host) with SupplierFormatInferrer. The first that reads becomes the
     * sender's format (held for review). A file that was clearly a price list but did not read is recorded
     * as a skip, so it is visible instead of silent; a PDF that is not named like a list stays quiet
     * (usually an account form, handled elsewhere).
     */
    private function newFormat($conn, int $no, $st, SupplierPriceListService $svc, array $supplier, string $domain, string $from, string $subject, string $key, string $body): ?array
    {
        $sid = (int)($supplier['id'] ?? 0);
        $sender = SupplierDirectoryService::addressOf($from);
        $listy = '/price|list|avail|special|stock|catalog|inventory/i';
        $hosts = array_values(array_unique([$domain, 'mcusercontent.com']));
        $try = function (string $name, string $bytes) use ($svc, $supplier, $domain, $hosts, $sid, $sender, $subject, $key, $listy): ?array {
            $inf = $svc->inferFromFile($name, $bytes);
            if ($inf['ok']) {
                error_log('[supplier inbox] new list format for ' . ($supplier['name'] ?? $domain) . ' from ' . $name . ' (confidence ' . $inf['confidence'] . ', ' . $inf['rows'] . ' lines) — held for review');
                return $svc->createAutoFormat($supplier, $domain, $inf, $hosts);
            }
            $isPdf = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf';
            if (!$isPdf || preg_match($listy, $name . ' ' . $subject)) $svc->recordSkip($sid, $sender, $subject, $name, (string)$inf['reason'], $key);
            return null;
        };
        foreach ($st ? ImapReader::files($st) : [] as $f) {
            $name = (string)$f['filename'];
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'csv', 'pdf'], true)) continue;
            $format = $try($name, ImapReader::fetchPart($conn, $no, $f['pn'], (int)$f['encoding']));
            if ($format) return $format;
        }
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('#https?://[^\s"\'<>()\[\]]+#i', $body, $m);
        foreach (array_unique($m[0]) as $url) {
            $url = rtrim($url, '.,;:');
            $name = rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'csv'], true) || !SupplierPriceListService::urlAllowed($url, $hosts)) continue;
            try {
                $format = $try($name, SupplierPriceListService::fetch($url, $hosts));
            } catch (Throwable $e) {
                $svc->recordSkip($sid, $sender, $subject, $name, 'could not download the list (' . $e->getMessage() . ')', $key);
                continue;
            }
            if ($format) return $format;
        }
        return null;
    }

    private function one($conn, int $no, int $sid, string $label, SupplierDirectoryService $dir, SupplierFormService $forms, array &$out): void
    {
        $raw = ImapReader::rawHeaders($conn, $no);
        $dec = fn(string $v): string => function_exists('imap_utf8') ? imap_utf8($v) : $v;
        $from = $dec(ImapReader::header($raw, 'From'));
        $subject = $dec(ImapReader::header($raw, 'Subject'));
        $date = ImapReader::header($raw, 'Date');
        $toCc = trim($dec(ImapReader::header($raw, 'To')) . ', ' . $dec(ImapReader::header($raw, 'Cc')), ', ');
        $key = self::messageKey(ImapReader::header($raw, 'Message-ID'), $from, $subject, $date);
        $auth = ImapReader::header($raw, 'Authentication-Results');
        // The IMAP search matched a SUBSTRING of the From header — confirm the actual address.
        $expected = $sid === 0
            ? (strpos($this->setting('licence_sender'), '@') !== false ? ['email' => $this->setting('licence_sender')] : ['domain' => $this->setting('licence_sender')])
            : ($dir->get($sid) ?? []);
        if (!$expected || !self::fromIs($from, $auth, $expected)) {
            error_log('[supplier inbox] ' . $label . ' #' . $no . ': sender ' . SupplierDirectoryService::addressOf($from) . ' is not ' . ($sid === 0 ? 'the licence sender' : 'supplier ' . $sid) . ' — skipped');
            return;
        }
        $ts = strtotime($date) ?: time();
        $st = @imap_fetchstructure($conn, $no);
        $pdfs = array_values(array_filter($st ? ImapReader::attachments($st) : [], fn($a) => stripos((string)$a['mime'], 'pdf') !== false
                                                                                   || preg_match('/\.pdf$/i', (string)$a['filename'])));
        if ($sid === 0) {
            // The city's licence email: file each PDF that reads as a licence (not the receipt).
            if (!preg_match('/licen[cs]e/i', $subject) || stripos($subject, 'receipt') !== false || stripos($subject, 'renewal notice') !== false) return;
            $docs = new CompanyDocumentService($this->db);
            foreach ($pdfs as $a) {
                $bytes = ImapReader::fetchPart($conn, $no, $a['pn'], (int)$a['encoding']);
                $p = CompanyDocumentService::parseLicence(CompanyDocumentService::pdfText($bytes));
                if (!$p['number']) continue;
                $id = $docs->store('business_licence', $bytes, (string)$a['filename'],
                                   ['message_key' => $key . '#' . $a['pn'], 'source' => 'email', 'title' => 'Business licence ' . $p['number']]);
                if ($id) $out['licences']++;
            }
            return;
        }
        $supplier = $dir->get($sid);
        if (!$supplier) return;
        $dir->heardFrom($sid, date('Y-m-d H:i:s', $ts));
        $out['heard']++;
        $body = null;
        $listAuth = ImapReader::senderVerified($conn, $no, SupplierDirectoryService::addressOf($from));
        if (self::listImportAllowed($listAuth)) {
            $r = $this->priceLists($conn, $no, $st, $supplier, $from, $subject, $key, date('Y-m-d H:i:s', $ts), $body);
            $out['lists'] += $r['imported'];
            $out['errors'] += $r['failed'];
        } else {
            error_log('[supplier inbox] ' . $label . ' #' . $no . ': price lists not imported — sender not verified (' . $listAuth['reason'] . ')');
        }
        // A PDF that was this supplier's price list is not an account form for the owner to sign.
        $pdfs = array_values(array_filter($pdfs, fn($a) => !isset($this->listFiles[(string)$a['filename']])));
        $this->listFiles = [];
        if (!$pdfs) return;
        $body = $body ?? ImapReader::textBody($conn, $no, $st);
        foreach ($pdfs as $a) {
            $bytes = ImapReader::fetchPart($conn, $no, $a['pn'], (int)$a['encoding']);
            $id = $forms->capture($supplier, ['message_key' => $key, 'mailbox' => $label, 'from' => $from, 'to_cc' => $toCc,
                                              'subject' => $subject, 'body' => $body, 'received_at' => date('Y-m-d H:i:s', $ts)],
                                  (string)$a['filename'], $bytes);
            if ($id) $out['forms']++;
        }
    }
}
