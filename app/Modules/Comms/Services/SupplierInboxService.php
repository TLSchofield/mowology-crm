<?php
/**
 * SupplierInboxService — Yui's supplier mail rule (owner, 2026-10-10).
 *
 * For each supplier in the directory (SupplierDirectoryService), searches the mailbox for mail
 * FROM that supplier in the last few days — one IMAP SEARCH each, no full scan — and:
 *   - notes that we heard from them (last_heard_at: stops Otto's "hasn't answered" chase);
 *   - hands every PDF to SupplierFormService::capture(), which files account applications
 *     for Otto to fill and offer to send.
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

class SupplierInboxService
{
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
     * @return array{heard: int, forms: int, licences: int, errors: int, note: string}
     */
    public function poll(array $mb, string $label, ?float $deadline = null): array
    {
        $out = ['heard' => 0, 'forms' => 0, 'licences' => 0, 'errors' => 0, 'note' => ''];
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

    private function one($conn, int $no, int $sid, string $label, SupplierDirectoryService $dir, SupplierFormService $forms, array &$out): void
    {
        $raw = ImapReader::rawHeaders($conn, $no);
        $dec = fn(string $v): string => function_exists('imap_utf8') ? imap_utf8($v) : $v;
        $from = $dec(ImapReader::header($raw, 'From'));
        $subject = $dec(ImapReader::header($raw, 'Subject'));
        $date = ImapReader::header($raw, 'Date');
        $toCc = trim($dec(ImapReader::header($raw, 'To')) . ', ' . $dec(ImapReader::header($raw, 'Cc')), ', ');
        $key = self::messageKey(ImapReader::header($raw, 'Message-ID'), $from, $subject, $date);
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
        if (!$pdfs) return;
        $body = ImapReader::textBody($conn, $no, $st);
        foreach ($pdfs as $a) {
            $bytes = ImapReader::fetchPart($conn, $no, $a['pn'], (int)$a['encoding']);
            $id = $forms->capture($supplier, ['message_key' => $key, 'mailbox' => $label, 'from' => $from, 'to_cc' => $toCc,
                                              'subject' => $subject, 'body' => $body, 'received_at' => date('Y-m-d H:i:s', $ts)],
                                  (string)$a['filename'], $bytes);
            if ($id) $out['forms']++;
        }
    }
}
