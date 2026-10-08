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
 * Folders: every folder imap_list returns (new ones are picked up automatically) except junk /
 * spam, trash / deleted, drafts and notes. The sent folder is read as our own mail. A folder's
 * name is a hint only (folderHint: RECEIPTS / a vendor's name, clients, payments): hinted
 * folders are read in full, but the same rules decide. Stored rows carry the folder
 * (mailbox 'icloud clients'); Message-ID dedupe keeps an Archive copy of an INBOX mail out.
 *
 * Speed (71,827 messages in INBOX): envelopes come from imap_fetch_overview in chunks of CHUNK
 * UIDs — one round trip per chunk, not ~4 per message. triage() decides most mail from the
 * envelope alone; only the rest has its header block, attachment list and (for routed mail)
 * body read. Every run has a time budget and stops cleanly between messages.
 *
 * Reading position: mailbox_poll_state (migration 1223) per folder — UIDVALIDITY + last UID.
 * The first run reads 90 days back; after that only new UIDs (a backfill cut short by the time
 * budget resumes from the last UID read — searchFrom()). Money routes (payments and receipts)
 * never backfill: mail dated before the folder's first run is left alone, so the first run
 * can't flood Penny with payments and receipts that were handled long ago.
 * Live runs hold MailboxPollLock, so a long backfill and the next 15-minute run never overlap.
 *
 * Inert until ICLOUD_IMAP_USER + ICLOUD_IMAP_PASS exist in secrets.php (MailboxConfig::icloud()).
 * No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/../../../Services/Mail/MailboxConfig.php';
require_once __DIR__ . '/../../../Services/Mail/ImapReader.php';
require_once __DIR__ . '/../../../Services/Mail/ImapClient.php';
require_once __DIR__ . '/MailboxPollLock.php';
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
    /** Admin dry run: default window. */
    public const DRY_RUN_DAYS = 7;
    /** Seconds before a run stops cleanly: web dry run (gateway gives up ~300 s), cron. */
    public const DRY_RUN_BUDGET = 240;
    public const LIVE_BUDGET = 600;
    /** UIDs per imap_fetch_overview round trip. */
    public const CHUNK = 200;
    public const INTERAC_SENDER = 'notify@payments.interac.ca';
    public const YARDI_SENDER = 'donotreply@yardi.com';
    public const ROUTES = ['contact', 'interac', 'yardi', 'receipt', 'vendor', 'lead', 'ignore'];

    private PDO $db;
    /** @var callable(string): void */
    private $log;
    /** @var ImapClient (or a test double with the same methods) */
    private $imap;
    /** @var callable(): float */
    private $clock;
    private ?array $mailbox;
    private ?array $context;
    /** @var MailboxPollLock|null (or a test double with acquire()/release()) */
    private $lock;

    /**
     * @param array{imap?: object, clock?: callable, mailbox?: array, context?: array, lock?: object} $opts
     *        test seams: a fake IMAP client, a fake clock, a mailbox config, a classify() context
     *        and a lock — production passes none of them.
     */
    public function __construct(PDO $db, ?callable $log = null, array $opts = [])
    {
        $this->db = $db;
        $this->log = $log ?? static function (string $m): void {};
        $this->imap = $opts['imap'] ?? new ImapClient();
        $this->clock = $opts['clock'] ?? static function (): float { return microtime(true); };
        $this->mailbox = $opts['mailbox'] ?? null;
        $this->context = $opts['context'] ?? null;
        $this->lock = $opts['lock'] ?? null;
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

    /**
     * A UID set for one FETCH: sorted, de-duplicated, consecutive runs as ranges ("3:5,9,11:12").
     * @param int[] $uids
     */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        $parts = [];
        $n = count($uids);
        for ($i = 0; $i < $n; $i++) {
            $start = $uids[$i];
            while ($i + 1 < $n && $uids[$i + 1] === $uids[$i] + 1) $i++;
            $parts[] = $start === $uids[$i] ? (string)$start : $start . ':' . $uids[$i];
        }
        return implode(',', $parts);
    }

    /**
     * Ascending UIDs in chunks of $size — one envelope round trip per chunk.
     * @param int[] $uids
     * @return int[][]
     */
    public static function uidChunks(array $uids, int $size = self::CHUNK): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        return $uids ? array_chunk($uids, max(1, $size)) : [];
    }

    /** Junk, spam, trash/deleted, drafts and notes folders are never read. */
    public static function isSkippedFolder(string $name): bool
    {
        return (bool)preg_match('/(^|[^a-z])(junk|spam|trash|deleted|drafts?|notes)([^a-z]|$)/i', $name);
    }

    /**
     * The folders a run reads, in listing order: every selectable folder except the skipped
     * kinds. The sent folder is read as 'sent' (our own mail), every other as 'inbox'.
     * @param array<int, array{name: string, noselect?: bool}|string> $folders
     * @return array<string, string> name => 'inbox' | 'sent'
     */
    public static function readableFolders(array $folders, ?string $sentName): array
    {
        $out = [];
        foreach ($folders as $f) {
            $name = is_array($f) ? (string)$f['name'] : (string)$f;
            if ($name === '' || (is_array($f) && !empty($f['noselect'])) || self::isSkippedFolder($name)) continue;
            $out[$name] = ($sentName !== null && $name === $sentName) ? 'sent' : 'inbox';
        }
        return $out;
    }

    /**
     * What a folder's name suggests (a hint only — the same rules still decide):
     * 'payments', 'receipts' (RECEIPTS, or a folder named after a vendor), 'clients', or null.
     * A hinted folder is read in full (headers + attachments) instead of pre-filtered.
     */
    public static function folderHint(string $folder, array $vendors = []): ?string
    {
        $leaf = (string)preg_replace('#^.*/#', '', $folder);
        if (preg_match('/payment|e-?transfer|interac|\beft\b|remittance/i', $folder)) return 'payments';
        if (preg_match('/receipt|invoice|expense|\bbills?\b/i', $folder)) return 'receipts';
        if (preg_match('/client|customer/i', $folder)) return 'clients';
        if ($folder !== 'INBOX' && $vendors && VendorMessageService::matchVendor('', $leaf, $vendors) !== null) return 'receipts';
        return null;
    }

    /** 'icloud INBOX', 'icloud Sent', 'icloud clients' … (<= 60 characters, the stores' width). */
    public static function mailboxLabel(string $folder, string $kind): string
    {
        // IMAP folder names are modified UTF-7 ("Apple &- Tech"); the label is for people.
        if ($kind !== 'sent' && function_exists('imap_mutf7_to_utf8')) $folder = (string)(imap_mutf7_to_utf8($folder) ?: $folder);
        return mb_substr('icloud ' . ($kind === 'sent' ? 'Sent' : $folder), 0, 60);
    }

    /**
     * Envelope-only first look (From / To / Subject — what imap_fetch_overview returns in bulk).
     *   stage 'final'  — decided here: payment notice, customer mail, or a robot sender (ignore);
     *   stage 'header' — an unknown person: read the header block; robots (List-Unsubscribe,
     *                    Precedence…) are ignored, the rest get the full read;
     *   stage 'full'   — headers (Cc) + attachment list, then classify() exactly as before.
     * The only mail that skips the attachment check is an automated sender's mail whose subject
     * has no receipt words — a receipt that only its PDF's filename gives away is missed there
     * (unless the folder is hinted: hinted folders always get the full read).
     * @param array{folder: string, from: string, to: string, subject: string} $m
     * @return array{stage: string, route?: string, reason?: string, contact_id?: int, direction?: string}
     */
    public static function triage(array $m, array $ctx, ?string $hint = null): array
    {
        $from = self::firstAddress((string)$m['from']);
        $subject = (string)($m['subject'] ?? '');
        $sent = ($m['folder'] ?? 'inbox') === 'sent';
        $ours = array_map('strtolower', (array)($ctx['ours'] ?? []));
        $contacts = (array)($ctx['contacts'] ?? []);

        if ($from === '') return ['stage' => 'final', 'route' => 'ignore', 'reason' => 'no sender'];
        if (!$sent) {
            if ($from === self::INTERAC_SENDER || stripos($subject, 'Interac e-Transfer') !== false) {
                return ['stage' => 'final', 'route' => 'interac', 'reason' => 'Interac e-Transfer notice'];
            }
            if ($from === self::YARDI_SENDER) return ['stage' => 'final', 'route' => 'yardi', 'reason' => 'Yardi EFT remittance'];
        }
        $c = SalesInboxService::classify((string)$m['from'], (string)$m['to'], $contacts, $ours);
        if ($c !== null) {
            return ['stage' => 'final', 'route' => 'contact', 'reason' => 'CRM contact', 'contact_id' => $c['contact_id'], 'direction' => $c['direction']];
        }
        // Our own mail (Cc may hold the customer or vendor), hinted folders, vendors, receipt words.
        if ($sent || SalesInboxService::isOurs($from, $ours) || $hint !== null) return ['stage' => 'full'];
        if (VendorMessageService::matchVendor($from, self::displayName((string)$m['from']), (array)($ctx['vendors'] ?? [])) !== null) {
            return ['stage' => 'full'];
        }
        if (ReceiptInboxService::isOfficeReceipt($from, $subject, '', [], array_keys($contacts))) return ['stage' => 'full'];
        if (EmailLeadService::isAutomated($from, '')) return ['stage' => 'final', 'route' => 'ignore', 'reason' => 'automated / newsletter'];
        return ['stage' => 'header'];
    }

    /**
     * Where a folder's SINCE search starts.
     *   dry run      — the last $days days (default DRY_RUN_DAYS);
     *   first read   — BACKFILL_DAYS;
     *   later reads  — the last WINDOW_DAYS, or from the arrival of the last UID read (minus a
     *                  day) when that is older: a backfill cut short by the time budget picks up
     *                  where it stopped instead of jumping to the last 3 days.
     */
    public static function searchFrom(bool $dryRun, ?int $days, bool $first, ?int $lastArrival, ?int $firstRunTs, int $now): int
    {
        if ($dryRun) return $now - max(1, min(self::BACKFILL_DAYS, (int)($days ?: self::DRY_RUN_DAYS))) * 86400;
        if ($first) return $now - self::BACKFILL_DAYS * 86400;
        $from = $now - self::WINDOW_DAYS * 86400;
        if ($lastArrival !== null && $lastArrival > 0) return min($from, $lastArrival - 86400);
        // The last message read is gone: fall back to the whole backfill window.
        return min($from, ($firstRunTs ?: $now) - self::BACKFILL_DAYS * 86400);
    }

    /**
     * Read order: fewest pending first, so small folders (RECEIPTS, clients, Sent…) are never
     * starved behind an INBOX backfill; each folder keeps its own resume point.
     * @param array<string, int> $pending folder => UIDs waiting
     * @return string[]
     */
    public static function folderOrder(array $pending): array
    {
        $names = array_map('strval', array_keys($pending));
        $pos = array_flip($names);
        usort($names, fn($a, $b) => [$pending[$a], $pos[$a]] <=> [$pending[$b], $pos[$b]]);
        return $names;
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
            $this->db->query("SELECT 1 FROM mailbox_poll_state LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function state(string $folder): ?array
    {
        $s = $this->db->prepare("SELECT * FROM mailbox_poll_state WHERE mailbox_key = ? AND folder = ?");
        $s->execute([self::MAILBOX_KEY, mb_substr($folder, 0, 120)]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Plain INSERT / UPDATE (portable; the poll lock means no one else writes this row). */
    private function saveState(string $folder, ?array $state, ?int $uidValidity, int $lastUid, string $summary): void
    {
        $now = date('Y-m-d H:i:s', (int)$this->now());
        $f = mb_substr($folder, 0, 120);
        if ($state === null) {
            $this->db->prepare("INSERT INTO mailbox_poll_state (mailbox_key, folder, uid_validity, last_uid, first_run_at, last_run_at, last_summary)
                                VALUES (?, ?, ?, ?, ?, ?, ?)")
                     ->execute([self::MAILBOX_KEY, $f, $uidValidity, $lastUid, $now, $now, mb_substr($summary, 0, 255)]);
            return;
        }
        $this->db->prepare("UPDATE mailbox_poll_state SET uid_validity = ?, last_uid = ?, last_run_at = ?, last_summary = ?
                            WHERE mailbox_key = ? AND folder = ?")
                 ->execute([$uidValidity, $lastUid, $now, mb_substr($summary, 0, 255), self::MAILBOX_KEY, $f]);
    }

    private function now(): float
    {
        return (float)($this->clock)();
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

    private function mailbox(): ?array
    {
        return $this->mailbox ?? MailboxConfig::icloud();
    }

    /** The real client needs ext-imap; a test double does not. */
    private function imapAvailable(): bool
    {
        return get_class($this->imap) !== ImapClient::class || function_exists('imap_open');
    }

    /** Log in, list folders and message counts. Stores nothing. */
    public function test(): array
    {
        $mb = $this->mailbox();
        if ($mb === null) return ['ok' => false, 'configured' => false, 'message' => self::notConfigured()];
        if (!$this->imapAvailable()) return ['ok' => false, 'configured' => true, 'message' => 'PHP imap extension not available.'];
        $conn = $this->imap->open($mb, 'INBOX');
        if ($conn === false) {
            return ['ok' => false, 'configured' => true, 'message' => 'Could not log into iCloud: ' . implode('; ', $this->imap->errors() ?: ['unknown'])
                . ' — check the app-specific password.'];
        }
        $list = $this->imap->folders($conn, $mb);
        $sent = ImapReader::pickSentFolder(array_column($list, 'name'));
        $read = self::readableFolders($list, $sent);
        $folders = [];
        foreach ($list as $f) {
            $st = @imap_status($conn, ImapReader::serverRef($mb) . $f['name'], SA_MESSAGES);
            $folders[] = ['name' => $f['name'], 'messages' => $st ? (int)$st->messages : null, 'read' => isset($read[$f['name']]),
                          'hint' => isset($read[$f['name']]) ? self::folderHint((string)$f['name']) : null];
        }
        $this->imap->close($conn);
        return ['ok' => true, 'configured' => true, 'user' => $mb['user'], 'host' => $mb['host'], 'sent_folder' => $sent,
                'folders' => $folders, 'message' => 'Logged in read-only. ' . count($folders) . ' folder(s); reading ' . count($read)
                    . ' (junk, trash, drafts and notes are skipped).'];
    }

    /**
     * One pass over every readable folder (all but junk / trash / drafts / notes), within a
     * time budget. Envelopes come in bulk (CHUNK UIDs per round trip) and are triaged first;
     * headers, attachment lists and bodies are fetched only for the mail that needs them.
     *
     * When the budget runs out the run stops cleanly between messages: a live run saves each
     * folder's last UID (mailbox_poll_state) and the next run carries on from there; a dry run
     * returns partial: true, how many remain, and a `resume` cursor to pass back.
     * Live runs hold MailboxPollLock — a second live run while one is going is skipped.
     *
     * @param bool $dryRun classify and count only — nothing stored, no state saved
     * @param int|null $days dry-run window (default DRY_RUN_DAYS); live runs use the saved state
     * @param int|null $budget seconds (default DRY_RUN_BUDGET for a dry run, LIVE_BUDGET live)
     * @param array<string,int> $resume dry run only: folder => last UID already scanned
     */
    public function poll(bool $dryRun = false, ?int $days = null, ?int $budget = null, array $resume = []): array
    {
        $t0 = $this->now();
        $deadline = $t0 + max(1, $budget ?? ($dryRun ? self::DRY_RUN_BUDGET : self::LIVE_BUDGET));
        $counts = array_fill_keys(self::ROUTES, 0) + ['stored' => 0, 'dupe' => 0, 'held' => 0, 'leads_made' => 0, 'maybe_leads' => 0];
        $base = ['configured' => true, 'dry_run' => $dryRun, 'counts' => $counts, 'errors' => 0, 'partial' => false,
                 'scanned' => 0, 'remaining' => 0, 'elapsed' => 0.0, 'folders' => []];
        $mb = $this->mailbox();
        if ($mb === null) return ['ok' => false, 'configured' => false, 'message' => self::notConfigured()] + $base;
        if (!$this->imapAvailable()) return ['ok' => false, 'message' => 'PHP imap extension not available.'] + $base;
        if (!$dryRun && !$this->stateReady()) return ['ok' => false, 'message' => 'Migration 1223 not run yet — nothing read.'] + $base;

        $lock = null;
        if (!$dryRun) {
            $lock = $this->lock ?? new MailboxPollLock($this->db, 'icloud_inbox_poll');
            if (!$lock->acquire()) {
                return ['ok' => true, 'skipped' => true, 'message' => 'Another iCloud read is still running — skipped this one.'] + $base;
            }
        }
        try {
            return $this->run($mb, $dryRun, $days, $deadline, $resume, $t0, $base);
        } finally {
            if ($lock) $lock->release();
        }
    }

    private function run(array $mb, bool $dryRun, ?int $days, float $deadline, array $resume, float $t0, array $base): array
    {
        $counts = $base['counts'];
        $conn = $this->imap->open($mb, 'INBOX');
        if ($conn === false) {
            return ['ok' => false, 'message' => 'Could not log into iCloud: ' . implode('; ', $this->imap->errors() ?: ['unknown']), 'errors' => 1] + $base;
        }
        $list = $this->imap->folders($conn, $mb);
        $sentName = ImapReader::pickSentFolder(array_column($list, 'name'));
        $folders = self::readableFolders($list, $sentName);

        $ctx = $this->context ?? $this->context($mb);
        $sys = $this->systemUserId();
        $samples = ['lead' => [], 'vendor' => []];
        $errors = 0;
        $now = (int)$this->now();

        // 1. Plan: per folder, which UIDs are new (one SEARCH each) — no message is read yet.
        $plans = [];
        $per = [];
        foreach ($folders as $folder => $kind) {
            $folder = (string)$folder;
            if (!$this->imap->reopen($conn, $mb, $folder)) { ($this->log)("ERROR: could not open {$folder}"); $errors++; continue; }
            $uidValidity = $this->imap->uidValidity($conn, $mb, $folder);
            $state = $dryRun ? null : $this->state($folder);
            $first = $state === null || (int)$state['last_uid'] === 0 || (int)($state['uid_validity'] ?? 0) !== (int)$uidValidity;
            $lastArrival = null;
            if (!$dryRun && !$first) {
                $o = $this->imap->overview($conn, (string)(int)$state['last_uid']);
                $lastArrival = isset($o[0]['udate']) ? (int)$o[0]['udate'] : null;
            }
            $firstRunTs = ($state && !empty($state['first_run_at'])) ? (strtotime((string)$state['first_run_at']) ?: null) : null;
            $found = $this->imap->searchSince($conn, self::searchFrom($dryRun, $days, $first, $lastArrival, $firstRunTs, $now));
            if ($dryRun) {
                $cursor = isset($resume[$folder]) ? ['uid_validity' => (int)$uidValidity, 'last_uid' => (int)$resume[$folder]] : null;
                $uids = self::newUids($found, $cursor, $uidValidity);
            } else {
                $uids = self::newUids($found, $first ? null : $state, $uidValidity);
            }
            $plans[$folder] = ['kind' => $kind, 'uid_validity' => $uidValidity, 'state' => $state, 'first' => $first, 'uids' => $uids,
                               // Money routes never backfill: nothing older than the folder's first read.
                               'floor' => (!$dryRun && $firstRunTs) ? $firstRunTs : $now,
                               'hint' => self::folderHint($folder, (array)($ctx['vendors'] ?? []))];
            $per[$folder] = ['scanned' => 0, 'remaining' => count($uids), 'hint' => $plans[$folder]['hint'], 'routes' => []];
        }

        // 2. Read, fewest pending first, until the budget runs out.
        $partial = false;
        $scanned = 0;
        $cursorOut = [];
        foreach (self::folderOrder(array_map(fn($p) => count($p['uids']), $plans)) as $folder) {
            $p = $plans[$folder];
            $kind = $p['kind'];
            $state = $p['state'];
            $prev = $dryRun ? (int)($resume[$folder] ?? 0) : ($p['first'] ? 0 : (int)($state['last_uid'] ?? 0));
            $lastOk = $prev;
            $frozen = false;
            $done = 0;
            $fc = array_fill_keys(self::ROUTES, 0) + ['stored' => 0, 'dupe' => 0, 'held' => 0, 'leads_made' => 0, 'maybe_leads' => 0];
            if ($p['uids'] && !$partial && $this->now() < $deadline) {
                if (!$this->imap->reopen($conn, $mb, $folder)) { ($this->log)("ERROR: could not open {$folder}"); $errors++; continue; }
                ($this->log)("{$folder}: " . count($p['uids']) . ' new message(s)' . ($p['hint'] ? " ({$p['hint']} folder)" : ''));
                foreach (self::uidChunks($p['uids']) as $chunk) {
                    if ($this->now() >= $deadline) { $partial = true; break; }
                    $rows = [];
                    foreach ($this->imap->overview($conn, self::uidSet($chunk)) as $row) $rows[(int)$row['uid']] = $row;
                    foreach ($chunk as $uid) {
                        if ($this->now() >= $deadline) { $partial = true; break 2; }
                        $done++;
                        if (!isset($rows[$uid])) { if (!$frozen) $lastOk = $uid; continue; }   // gone since the search
                        try {
                            $res = $this->handle($conn, $rows[$uid], $kind, $folder, $p['hint'], $ctx, $sys, $p['floor'], $dryRun);
                            $fc[$res['route']]++;
                            foreach (['stored', 'dupe', 'held', 'leads_made', 'maybe_leads'] as $k) $fc[$k] += (int)($res[$k] ?? 0);
                            if ($dryRun && isset($samples[$res['route']]) && count($samples[$res['route']]) < 25) {
                                $samples[$res['route']][] = ['subject' => $res['subject'], 'from' => $res['from'], 'why' => $res['reason'], 'folder' => $folder];
                            }
                            $scanned++;
                            $per[$folder]['scanned']++;
                            if (!$frozen) $lastOk = $uid;
                        } catch (Throwable $e) {
                            // Never content in the log — just where it failed.
                            ($this->log)("ERROR on UID {$uid} in {$folder}: " . $e->getMessage());
                            $errors++;
                            $frozen = true;   // re-read from here next run (every store dedupes)
                        }
                    }
                }
            } elseif ($p['uids']) {
                $partial = true;   // the budget ran out before this folder's turn
            }
            $per[$folder]['remaining'] = count($p['uids']) - $done;
            $per[$folder]['routes'] = array_filter($fc);
            foreach ($fc as $k => $v) $counts[$k] += $v;
            if ($dryRun) {
                if ($lastOk > 0) $cursorOut[$folder] = $lastOk;
            } else {
                $this->saveState($folder, $state, $p['uid_validity'], max($lastOk, $prev), self::summary($fc));
            }
        }
        $this->imap->close($conn);

        $remaining = (int)array_sum(array_column($per, 'remaining'));
        $elapsed = round($this->now() - $t0, 1);
        $partial = $partial || $remaining > 0;
        $msg = self::summary($counts) . sprintf('; %d scanned in %ss', $scanned, $elapsed)
             . ($partial ? "; stopped at the time limit, {$remaining} left for the next run" : '');
        $out = ['ok' => $errors === 0, 'message' => $msg, 'counts' => $counts, 'errors' => $errors, 'partial' => $partial,
                'scanned' => $scanned, 'remaining' => $remaining, 'elapsed' => $elapsed, 'folders' => $per] + $base;
        if ($dryRun) {
            $out['samples'] = $samples;
            if ($partial) $out['resume'] = $cursorOut;
        }
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

    /**
     * Classify one message from its envelope (a bulk overview row) and, unless dry-run, hand
     * it to its store. Header / structure / body are fetched only when the route needs them.
     * @param array{uid: int, msgno: int, from: string, to: string, subject: string, date: string, message_id: ?string} $row
     */
    private function handle($conn, array $row, string $kind, string $folder, ?string $hint, array $ctx, int $sys, int $floor, bool $dryRun): array
    {
        $imap = $this->imap;
        $no = (int)$row['msgno'];
        $from = (string)$row['from'];
        $to = (string)$row['to'];
        $subject = (string)$row['subject'];
        $date = (string)(($row['date'] ?? '') ?: 'now');
        $msgId = (isset($row['message_id']) && $row['message_id'] !== '') ? (string)$row['message_id'] : null;
        $fromAddr = self::firstAddress($from);

        $raw = null;
        $header = function () use (&$raw, $imap, $conn, $no): string {
            if ($raw === null) $raw = $imap->header($conn, $no);
            return $raw;
        };
        $structDone = false;
        $struct = null;
        $structure = function () use (&$structDone, &$struct, $imap, $conn, $no) {
            if (!$structDone) { $struct = $imap->structure($conn, $no); $structDone = true; }
            return $struct;
        };
        $text = null;
        $body = function () use (&$text, $imap, $conn, $no, $structure): string {
            if ($text === null) { $st = $structure(); $text = $st ? $imap->textBody($conn, $no, $st) : ''; }
            return $text;
        };
        $withCc = function () use ($header, $to): string {
            $cc = ImapReader::header($header(), 'Cc');
            if ($cc !== '' && function_exists('imap_utf8')) $cc = imap_utf8($cc);
            return trim($to . ', ' . $cc, ', ');
        };

        $t = self::triage(['folder' => $kind, 'from' => $from, 'to' => $to, 'subject' => $subject], $ctx, $hint);
        if ($t['stage'] === 'final') {
            unset($t['stage']);
            $r = $t;
        } elseif ($t['stage'] === 'header' && EmailLeadService::isAutomated($fromAddr, $header())) {
            $r = ['route' => 'ignore', 'reason' => 'automated / newsletter'];
        } else {
            $to = $withCc();
            $st = $kind === 'sent' ? null : $structure();   // our sent mail is never a receipt
            $r = self::classify([
                'folder' => $kind, 'from' => $from, 'to' => $to, 'subject' => $subject,
                'headers' => $header(), 'attachments' => $st ? ImapReader::attachments($st) : [], 'body' => $body,
            ], $ctx);
        }
        $out = $r + ['subject' => $subject, 'from' => $fromAddr];
        if ($dryRun || $r['route'] === 'ignore') return $out;

        $mailbox = self::mailboxLabel($folder, $kind);
        if ($r['route'] === 'contact' && $raw === null) $to = $withCc();
        $key = self::messageKey($msgId, $fromAddr, $to, $subject, $date);
        $sentTs = strtotime($date) ?: (int)$this->now();
        $money = in_array($r['route'], ['interac', 'yardi', 'receipt'], true);
        if ($money && $sentTs < $floor) return $out + ['held' => 1];

        switch ($r['route']) {
            case 'contact': {
                $svc = new SalesInboxService($this->db);
                if (!$svc->ready()) return $out;
                $res = $svc->ingest(['mailbox' => $mailbox, 'message_id' => $msgId, 'from' => $from, 'to' => $to,
                                     'subject' => $subject, 'body' => '', 'date' => $date, 'ours' => $ctx['ours']]);
                if ($res === 'stored') {
                    $plainText = $body();
                    $snip = SalesInboxService::snippet($plainText);
                    if ($snip !== '') $svc->setSnippet($svc->lastKey, $snip);
                    if ($svc->lastDirection === 'inbound') {
                        $sig = SalesInboxService::signature($plainText);
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
                $st = $structure();
                if (($r['receipt_kind'] ?? '') === 'body') {
                    $bp = $st ? ImapReader::bodyPart($st) : null;
                    if ($bp) {
                        $html = $imap->fetchPart($conn, $no, $bp['pn'], $bp['encoding']);
                        if ($bp['charset'] !== 'UTF-8' && $bp['charset'] !== 'US-ASCII') $html = (string)@mb_convert_encoding($html, 'UTF-8', $bp['charset']);
                        if (!$bp['html']) $html = nl2br(htmlspecialchars($html));
                        $res = $svc->ingestEmailBody($meta, $html, $sys);
                        $res['status'] === 'duplicate' ? $dupe++ : $stored++;
                    }
                } else {
                    foreach ($st ? ImapReader::attachments($st) : [] as $a) {
                        $bytes = $imap->fetchPart($conn, $no, $a['pn'], $a['encoding']);
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
