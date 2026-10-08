<?php
/**
 * IcloudInboxRouter — Tim's iCloud mailbox (mowology@icloud.com) as a READ-ONLY source for
 * the department heads. One pass per run: each message is classified once and handed to the
 * storage the existing office@ readers already use. Nothing is ever marked, moved, flagged
 * or deleted (ImapReader: OP_READONLY + FT_PEEK).
 *
 * Routes, first match wins (classify() — pure, unit tested):
 *   interac  — Interac e-Transfer notice (same sender/subject rules as etransfer_inbox_poll)
 *              → EtransferInboxService::parseInteracEmail() + ingest()        (Penny)
 *   yardi    — Yardi EFT remittance (DoNotReply@yardi.com, as yardi_eft_inbox_poll)
 *              → YardiEftInboxService::parseRemittanceEmail() + ingest()      (Penny)
 *   contact  — to or from a CRM contact → sales_messages, mailbox 'icloud …'  (Sam / Yui)
 *   receipt  — a PDF/photo with invoice/receipt words, or a known vendor's e-receipt
 *              → ReceiptInboxService (a pending/draft expense, never approved) (Penny)
 *   vendor   — other mail to/from a known vendor → vendor_messages
 *   lead     — an enquiry from an unknown sender (EmailLeadService::score; robots excluded)
 *              → strong: a quote_requests lead; weak: "maybe a lead" on Sam's card
 *   ignore   — everything else: personal mail, NOT stored. Only counts are logged.
 *
 * Dedupe across mailboxes is by Message-ID in every store (sales_messages.message_key,
 * receipt_inbox_messages.dedup_key = Message-ID:sha256, etransfer reference numbers,
 * vendor_messages / email_lead_candidates.message_key), so an email that reached both
 * office@ and iCloud is stored once.
 *
 * Reading position: mailbox_poll_state (migration 1223) per folder — UIDVALIDITY + last UID.
 * The first run reads 90 days back; after that only new UIDs. Money routes (payments and
 * receipts) never backfill: mail dated before the folder's first run is left alone, so the
 * first run can't flood Penny with payments and receipts that were handled long ago.
 *
 * Inert until ICLOUD_IMAP_USER + ICLOUD_IMAP_PASS exist in secrets.php (MailboxConfig::icloud()).
 * No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/../../../Services/Mail/MailboxConfig.php';
require_once __DIR__ . '/../../../Services/Mail/ImapReader.php';
require_once __DIR__ . '/../../Sales/Services/SalesInboxService.php';
require_once __DIR__ . '/../../Sales/Services/EmailLeadService.php';
require_once __DIR__ . '/../../Expenses/Services/ReceiptInboxService.php';
require_once __DIR__ . '/../../Expenses/Services/VendorMessageService.php';
require_once __DIR__ . '/../../Accounting/Services/EtransferInboxService.php';
require_once __DIR__ . '/../../Accounting/Services/YardiEftInboxService.php';

class IcloudInboxRouter
{
    public const MAILBOX_KEY = 'icloud';
    public const BACKFILL_DAYS = 90;
    /** Normal runs re-scan a short window and skip UIDs already read. */
    public const WINDOW_DAYS = 3;
    public const INTERAC_SENDER = 'notify@payments.interac.ca';
    public const YARDI_SENDER = 'donotreply@yardi.com';
    public const ROUTES = ['contact', 'interac', 'yardi', 'receipt', 'vendor', 'lead', 'ignore'];

    private PDO $db;
    /** @var callable(string): void */
    private $log;

    public function __construct(PDO $db, ?callable $log = null)
    {
        $this->db = $db;
        $this->log = $log ?? static function (string $m): void {};
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** The first address in a header value, lowercase ('' if none). */
    public static function firstAddress(string $header): string
    {
        return SalesInboxService::addresses($header)[0] ?? '';
    }

    /** The display name in a From header ("The Home Depot" <x@y>) or ''. */
    public static function displayName(string $header): string
    {
        $n = trim((string)preg_replace('/<[^>]*>|[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '', $header));
        return trim($n, " \t\"'");
    }

    /**
     * Where one message goes.
     * @param array{folder: string, from: string, to: string, subject: string, headers?: string,
     *              attachments?: array<int, array{filename: string, mime: string}>, body?: string|callable} $m
     *        folder: 'inbox' | 'sent'. body may be a callable that fetches the text on demand
     *        (only unknown senders' mail is read for the lead check).
     * @param array{contacts: array<string,int>, vendors: array, ours: string[]} $ctx
     * @return array{route: string, reason: string, contact_id?: int, direction?: string, vendor_id?: int,
     *               receipt_kind?: string, lead?: array}
     */
    public static function classify(array $m, array $ctx): array
    {
        $from = self::firstAddress((string)$m['from']);
        $subject = (string)($m['subject'] ?? '');
        $sent = ($m['folder'] ?? 'inbox') === 'sent';
        $ours = array_map('strtolower', (array)($ctx['ours'] ?? []));
        $contacts = (array)($ctx['contacts'] ?? []);
        $vendors = (array)($ctx['vendors'] ?? []);
        $atts = (array)($m['attachments'] ?? []);
        $headers = (string)($m['headers'] ?? '');

        if ($from === '') return ['route' => 'ignore', 'reason' => 'no sender'];

        // 1. Payment notices (inbox only) — the same rules the office@ pollers search with.
        if (!$sent) {
            if ($from === self::INTERAC_SENDER || stripos($subject, 'Interac e-Transfer') !== false) {
                return ['route' => 'interac', 'reason' => 'Interac e-Transfer notice'];
            }
            if ($from === self::YARDI_SENDER) {
                return ['route' => 'yardi', 'reason' => 'Yardi EFT remittance'];
            }
        }

        // 2. A customer conversation, either direction.
        $c = SalesInboxService::classify((string)$m['from'], (string)$m['to'], $contacts, $ours);
        if ($c !== null) {
            return ['route' => 'contact', 'reason' => 'CRM contact', 'contact_id' => $c['contact_id'], 'direction' => $c['direction']];
        }

        $fromUs = SalesInboxService::isOurs($from, $ours);
        if ($sent || $fromUs) {
            // Our own mail: only correspondence with a vendor is kept.
            foreach (SalesInboxService::addresses((string)$m['to']) as $t) {
                if (SalesInboxService::isOurs($t, $ours)) continue;
                $vid = VendorMessageService::matchVendor($t, '', $vendors);
                if ($vid !== null) return ['route' => 'vendor', 'reason' => 'mail to a vendor', 'vendor_id' => $vid, 'direction' => 'outbound'];
            }
            // …or a receipt Tim forwarded to himself (attachment + receipt words).
            if (!$sent && $atts && self::receiptAttachment($from, $subject, $atts, array_keys($contacts), $ours)) {
                return ['route' => 'receipt', 'reason' => 'receipt forwarded by Tim', 'receipt_kind' => 'attachment'];
            }
            return ['route' => 'ignore', 'reason' => 'our own mail'];
        }

        $clients = array_keys($contacts);
        $vendorId = VendorMessageService::matchVendor($from, self::displayName((string)$m['from']), $vendors);

        // 3. Receipts: a PDF/photo that says invoice/receipt, or a known vendor's e-receipt.
        if ($atts && self::receiptAttachment($from, $subject, $atts, $clients, $ours)) {
            return ['route' => 'receipt', 'reason' => 'receipt attachment', 'receipt_kind' => 'attachment', 'vendor_id' => $vendorId];
        }
        if ($vendorId !== null && !$atts && ReceiptInboxService::isBodyReceipt($from, $subject, [], $clients)) {
            return ['route' => 'receipt', 'reason' => 'vendor e-receipt', 'receipt_kind' => 'body', 'vendor_id' => $vendorId];
        }

        // Newsletters and notifications are never a vendor conversation or a lead.
        if (EmailLeadService::isAutomated($from, $headers)) {
            return ['route' => 'ignore', 'reason' => 'automated / newsletter'];
        }

        // 4. Other vendor correspondence.
        if ($vendorId !== null) {
            return ['route' => 'vendor', 'reason' => 'vendor correspondence', 'vendor_id' => $vendorId, 'direction' => 'inbound'];
        }

        // 5. A work enquiry from someone new.
        $body = $m['body'] ?? '';
        $text = is_callable($body) ? (string)$body() : (string)$body;
        // Only the new text counts (quoted history stripped), so an old thread can't make a lead.
        $score = EmailLeadService::score($subject, SalesInboxService::snippet(self::plain($text)));
        if ($score['strength'] !== null) {
            return ['route' => 'lead', 'reason' => $score['strength'] . ' enquiry', 'lead' => $score];
        }

        return ['route' => 'ignore', 'reason' => 'personal / not business'];
    }

    /** Any attachment that reads as a receipt (ReceiptInboxService::isOfficeReceipt, personal mailbox rules). */
    private static function receiptAttachment(string $from, string $subject, array $atts, array $clients, array $ours): bool
    {
        // No owner list (personal mailbox): a mail from Tim himself is judged on its words alone.
        foreach ($atts as $a) {
            if (ReceiptInboxService::isOfficeReceipt($from, $subject, (string)($a['filename'] ?? ''), [], $clients)) return true;
        }
        return false;
    }

    /** HTML or plain body → plain text for the word checks. */
    public static function plain(string $body): string
    {
        return preg_match('/<[a-z][^>]*>/i', $body) ? ReceiptInboxService::htmlToText($body) : $body;
    }

    /** Message-ID, else a hash that is the same in every mailbox (no mailbox in it). */
    public static function messageKey(?string $messageId, string $from, string $to, string $subject, string $date): string
    {
        $mid = trim((string)$messageId);
        if ($mid !== '') return mb_substr($mid, 0, 191);
        return 'h-' . sha1(strtolower($from) . '|' . strtolower($to) . '|' . $subject . '|' . (strtotime($date) ?: $date));
    }

    /**
     * New UIDs to read this run: above last_uid, unless UIDVALIDITY changed (then all).
     * @param int[] $uids from a SINCE search
     */
    public static function newUids(array $uids, ?array $state, ?int $uidValidity): array
    {
        $uids = array_map('intval', $uids);
        sort($uids);
        if ($state === null || $uidValidity === null || (int)($state['uid_validity'] ?? 0) !== $uidValidity) return $uids;
        $last = (int)($state['last_uid'] ?? 0);
        return array_values(array_filter($uids, fn($u) => $u > $last));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Context + state
    // ─────────────────────────────────────────────────────────────────────────

    /** Contacts, vendors and our own addresses for classify(). */
    public function context(array $mb): array
    {
        $sales = new SalesInboxService($this->db);
        $ours = [strtolower($mb['user']), 'mowology@icloud.com'];
        return [
            'contacts' => $sales->contactMap(),
            'vendors'  => (new VendorMessageService($this->db))->vendors(),
            'ours'     => array_values(array_unique($ours)),
        ];
    }

    public function stateReady(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'mailbox_poll_state'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function state(string $folder): ?array
    {
        $s = $this->db->prepare("SELECT * FROM mailbox_poll_state WHERE mailbox_key = ? AND folder = ?");
        $s->execute([self::MAILBOX_KEY, $folder]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function saveState(string $folder, ?int $uidValidity, int $lastUid, string $summary): void
    {
        $this->db->prepare("
            INSERT INTO mailbox_poll_state (mailbox_key, folder, uid_validity, last_uid, first_run_at, last_run_at, last_summary)
            VALUES (?, ?, ?, ?, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE uid_validity = VALUES(uid_validity), last_uid = VALUES(last_uid),
                                    last_run_at = NOW(), last_summary = VALUES(last_summary)
        ")->execute([self::MAILBOX_KEY, mb_substr($folder, 0, 120), $uidValidity, $lastUid, mb_substr($summary, 0, 255)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAP
    // ─────────────────────────────────────────────────────────────────────────

    /** "Not configured" reason, or '' when the iCloud constants exist. */
    public static function notConfigured(?array $consts = null): string
    {
        if (MailboxConfig::icloud($consts) !== null) return '';
        $m = MailboxConfig::get('icloud', $consts);
        return 'iCloud mailbox not configured — ' . ($m['missing'] ?? 'ICLOUD_IMAP_USER / ICLOUD_IMAP_PASS not set in secrets.php');
    }

    /** Log in, list folders and message counts. Stores nothing. */
    public function test(): array
    {
        $mb = MailboxConfig::icloud();
        if ($mb === null) return ['ok' => false, 'configured' => false, 'message' => self::notConfigured()];
        if (!function_exists('imap_open')) return ['ok' => false, 'configured' => true, 'message' => 'PHP imap extension not available.'];
        ImapReader::timeouts();
        $conn = ImapReader::open($mb, 'INBOX');
        if ($conn === false) {
            return ['ok' => false, 'configured' => true, 'message' => 'Could not log into iCloud: ' . implode('; ', imap_errors() ?: ['unknown'])
                . ' — check the app-specific password.'];
        }
        $names = ImapReader::folders($conn, $mb);
        $sent = ImapReader::pickSentFolder($names);
        $folders = [];
        foreach ($names as $n) {
            $st = @imap_status($conn, ImapReader::serverRef($mb) . $n, SA_MESSAGES);
            $folders[] = ['name' => $n, 'messages' => $st ? (int)$st->messages : null, 'read' => $n === 'INBOX' || $n === $sent];
        }
        @imap_close($conn);
        return ['ok' => true, 'configured' => true, 'user' => $mb['user'], 'host' => $mb['host'], 'sent_folder' => $sent,
                'folders' => $folders, 'message' => 'Logged in read-only. ' . count($folders) . ' folder(s); reading INBOX' . ($sent ? ' + ' . $sent : '') . '.'];
    }

    /**
     * One pass over INBOX + the sent folder.
     * @param bool $dryRun classify and count only — nothing stored, no state saved
     * @param int|null $days dry-run window (default 30); live runs use the saved state
     * @return array{ok: bool, configured: bool, message: string, counts: array, samples?: array, errors: int}
     */
    public function poll(bool $dryRun = false, ?int $days = null): array
    {
        $counts = array_fill_keys(self::ROUTES, 0) + ['stored' => 0, 'dupe' => 0, 'held' => 0, 'leads_made' => 0, 'maybe_leads' => 0];
        $mb = MailboxConfig::icloud();
        if ($mb === null) return ['ok' => false, 'configured' => false, 'message' => self::notConfigured(), 'counts' => $counts, 'errors' => 0];
        if (!function_exists('imap_open')) return ['ok' => false, 'configured' => true, 'message' => 'PHP imap extension not available.', 'counts' => $counts, 'errors' => 0];
        if (!$dryRun && !$this->stateReady()) {
            return ['ok' => false, 'configured' => true, 'message' => 'Migration 1223 not run yet — nothing read.', 'counts' => $counts, 'errors' => 0];
        }

        ImapReader::timeouts();
        $inbox = ImapReader::open($mb, 'INBOX');
        if ($inbox === false) {
            return ['ok' => false, 'configured' => true, 'message' => 'Could not log into iCloud: ' . implode('; ', imap_errors() ?: ['unknown']),
                    'counts' => $counts, 'errors' => 1];
        }
        $sentName = ImapReader::pickSentFolder(ImapReader::folders($inbox, $mb));
        @imap_close($inbox);

        $ctx = $this->context($mb);
        $sys = $this->systemUserId();
        $samples = ['lead' => [], 'vendor' => []];
        $errors = 0;
        $folders = ['INBOX' => 'inbox'];
        if ($sentName !== null) $folders[$sentName] = 'sent';

        foreach ($folders as $folder => $kind) {
            $conn = ImapReader::open($mb, $folder);
            if ($conn === false) { ($this->log)("ERROR: could not open {$folder}"); $errors++; continue; }
            $status = @imap_status($conn, ImapReader::serverRef($mb) . $folder, SA_UIDVALIDITY);
            $uidValidity = $status ? (int)$status->uidvalidity : null;
            $state = $dryRun ? null : $this->state($folder);
            $first = $state === null || (int)$state['last_uid'] === 0 || (int)($state['uid_validity'] ?? 0) !== (int)$uidValidity;
            $window = $dryRun ? max(1, min(self::BACKFILL_DAYS, (int)($days ?: 30))) : ($first ? self::BACKFILL_DAYS : self::WINDOW_DAYS);
            $floor = (!$dryRun && $state && !empty($state['first_run_at'])) ? strtotime((string)$state['first_run_at']) : time();
            $found = @imap_search($conn, 'SINCE "' . date('d-M-Y', strtotime("-{$window} days")) . '"', SE_UID);
            $uids = self::newUids(is_array($found) ? $found : [], $first ? null : $state, $uidValidity);
            ($this->log)("{$folder}: " . count($uids) . " new message(s) in the last {$window} day(s)");

            $lastOk = $first ? 0 : (int)($state['last_uid'] ?? 0);
            $frozen = false;
            foreach ($uids as $uid) {
                try {
                    $no = (int)@imap_msgno($conn, $uid);
                    if ($no <= 0) { if (!$frozen) $lastOk = $uid; continue; }
                    $res = $this->handle($conn, $no, $kind, $folder, $ctx, $sys, $floor, $dryRun);
                    $counts[$res['route']]++;
                    foreach (['stored', 'dupe', 'held', 'leads_made', 'maybe_leads'] as $k) $counts[$k] += (int)($res[$k] ?? 0);
                    if ($dryRun && isset($samples[$res['route']]) && count($samples[$res['route']]) < 25) {
                        $samples[$res['route']][] = ['subject' => $res['subject'], 'from' => $res['from'], 'why' => $res['reason']];
                    }
                    if (!$frozen) $lastOk = $uid;
                } catch (Throwable $e) {
                    // Never content in the log — just where it failed.
                    ($this->log)("ERROR on UID {$uid} in {$folder}: " . $e->getMessage());
                    $errors++;
                    $frozen = true;   // re-read from here next run (every store dedupes)
                }
            }
            @imap_close($conn);
            if (!$dryRun) $this->saveState($folder, $uidValidity, max($lastOk, $first ? 0 : (int)$state['last_uid']), self::summary($counts));
        }

        $out = ['ok' => $errors === 0, 'configured' => true, 'dry_run' => $dryRun, 'message' => self::summary($counts), 'counts' => $counts, 'errors' => $errors];
        if ($dryRun) $out['samples'] = $samples;
        return $out;
    }

    /** Counts only — never names, subjects or text. */
    public static function summary(array $c): string
    {
        return sprintf('%d customer, %d e-Transfer, %d Yardi, %d receipt, %d vendor, %d enquiry (%d lead(s), %d maybe), %d ignored; %d stored, %d already kept, %d held (older than the first run)',
            $c['contact'], $c['interac'], $c['yardi'], $c['receipt'], $c['vendor'], $c['lead'], $c['leads_made'], $c['maybe_leads'],
            $c['ignore'], $c['stored'], $c['dupe'], $c['held']);
    }

    private function systemUserId(): int
    {
        try {
            $id = (int)($this->db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            return $id > 0 ? $id : (int)($this->db->query("SELECT MIN(id) FROM users")->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Classify one message and (unless dry-run) hand it to its store. */
    private function handle($conn, int $no, string $kind, string $folder, array $ctx, int $sys, int $floor, bool $dryRun): array
    {
        $h = @imap_headerinfo($conn, $no);
        if (!$h) return ['route' => 'ignore', 'reason' => 'no headers', 'subject' => '', 'from' => ''];
        $from = isset($h->fromaddress) ? imap_utf8($h->fromaddress) : '';
        $to = trim((isset($h->toaddress) ? imap_utf8($h->toaddress) : '') . ', ' . (isset($h->ccaddress) ? imap_utf8($h->ccaddress) : ''), ', ');
        $subject = isset($h->subject) ? imap_utf8($h->subject) : '';
        $date = (string)($h->date ?? 'now');
        $msgId = isset($h->message_id) ? (string)$h->message_id : null;
        $struct = @imap_fetchstructure($conn, $no);
        $atts = $struct ? ImapReader::attachments($struct) : [];
        $text = null;
        $body = function () use (&$text, $conn, $no, $struct): string {
            if ($text === null) $text = ImapReader::textBody($conn, $no, $struct);
            return $text;
        };
        $r = self::classify([
            'folder' => $kind, 'from' => $from, 'to' => $to, 'subject' => $subject,
            'headers' => ImapReader::rawHeaders($conn, $no), 'attachments' => $atts, 'body' => $body,
        ], $ctx);
        $fromAddr = self::firstAddress($from);
        $out = $r + ['subject' => $subject, 'from' => $fromAddr];
        if ($dryRun || $r['route'] === 'ignore') return $out;

        $mailbox = 'icloud ' . ($kind === 'sent' ? 'Sent' : 'INBOX');
        $key = self::messageKey($msgId, $fromAddr, $to, $subject, $date);
        $sentTs = strtotime($date) ?: time();
        $money = in_array($r['route'], ['interac', 'yardi', 'receipt'], true);
        if ($money && $sentTs < $floor) return $out + ['held' => 1];

        switch ($r['route']) {
            case 'contact': {
                $svc = new SalesInboxService($this->db);
                if (!$svc->ready()) return $out;
                $res = $svc->ingest(['mailbox' => $mailbox, 'message_id' => $msgId, 'from' => $from, 'to' => $to,
                                     'subject' => $subject, 'body' => '', 'date' => $date, 'ours' => $ctx['ours']]);
                if ($res === 'stored') {
                    $raw = $body();
                    $snip = SalesInboxService::snippet($raw);
                    if ($snip !== '') $svc->setSnippet($svc->lastKey, $snip);
                    if ($svc->lastDirection === 'inbound') {
                        $sig = SalesInboxService::signature($raw);
                        if ($sig !== '') $svc->setSignature($svc->lastKey, $sig);
                    }
                }
                return $out + [$res === 'stored' ? 'stored' : 'dupe' => 1];
            }
            case 'interac': {
                $parsed = EtransferInboxService::parseInteracEmail($subject, self::plain($body()));
                $res = (new EtransferInboxService($this->db))->ingest($parsed, self::MAILBOX_KEY, $msgId, $subject, $date);
                return $out + [$res['inserted'] ? 'stored' : 'dupe' => 1];
            }
            case 'yardi': {
                $parsed = YardiEftInboxService::parseRemittanceEmail($subject, $body());
                if (empty($parsed['lines'])) return $out;
                $res = (new YardiEftInboxService($this->db))->ingest($parsed, self::MAILBOX_KEY, $msgId, $subject, $date, $sys);
                return $out + [($res['auto_recorded'] + $res['pending']) > 0 ? 'stored' : 'dupe' => 1];
            }
            case 'receipt': {
                if ($sys <= 0) return $out;
                $svc = new ReceiptInboxService($this->db);
                $meta = ['message_id' => $msgId, 'sender_email' => $fromAddr, 'subject' => $subject, 'email_date' => $date];
                $stored = 0; $dupe = 0;
                if (($r['receipt_kind'] ?? '') === 'body') {
                    $bp = $struct ? ImapReader::bodyPart($struct) : null;
                    if ($bp) {
                        $html = ImapReader::fetchPart($conn, $no, $bp['pn'], $bp['encoding']);
                        if ($bp['charset'] !== 'UTF-8' && $bp['charset'] !== 'US-ASCII') $html = (string)@mb_convert_encoding($html, 'UTF-8', $bp['charset']);
                        if (!$bp['html']) $html = nl2br(htmlspecialchars($html));
                        $res = $svc->ingestEmailBody($meta, $html, $sys);
                        $res['status'] === 'duplicate' ? $dupe++ : $stored++;
                    }
                } else {
                    foreach ($atts as $a) {
                        $bytes = ImapReader::fetchPart($conn, $no, $a['pn'], $a['encoding']);
                        if ($bytes === '' || strlen($bytes) > 15 * 1024 * 1024) continue;
                        $res = $svc->ingestAttachment($meta, $bytes, $a['filename'], $a['mime'], $sys);
                        $res['status'] === 'duplicate' ? $dupe++ : $stored++;
                    }
                }
                return $out + ['stored' => $stored, 'dupe' => $dupe];
            }
            case 'vendor': {
                $svc = new VendorMessageService($this->db);
                if (!$svc->ready()) return $out;
                $res = $svc->store(['message_key' => $key, 'mailbox' => $mailbox, 'vendor_id' => (int)$r['vendor_id'],
                                    'direction' => $r['direction'] ?? 'inbound', 'from' => $fromAddr,
                                    'to' => implode(', ', SalesInboxService::addresses($to)), 'subject' => $subject,
                                    'snippet' => SalesInboxService::snippet(self::plain($body())), 'sent_at' => $date]);
                return $out + [$res === 'stored' ? 'stored' : 'dupe' => 1];
            }
            case 'lead': {
                $svc = new EmailLeadService($this->db);
                if (!$svc->ready()) return $out;
                $res = $svc->record(['message_key' => $key, 'mailbox' => $mailbox, 'from_name' => self::displayName($from),
                                     'from_addr' => $fromAddr, 'subject' => $subject,
                                     'snippet' => SalesInboxService::snippet(self::plain($body())), 'received_at' => date('Y-m-d H:i:s', $sentTs)], $r['lead']);
                return $out + ['stored' => in_array($res, ['lead', 'maybe'], true) ? 1 : 0, 'dupe' => $res === 'dupe' ? 1 : 0,
                               'leads_made' => $res === 'lead' ? 1 : 0, 'maybe_leads' => $res === 'maybe' ? 1 : 0];
            }
        }
        return $out;
    }
}
