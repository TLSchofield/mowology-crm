<?php
/**
 * InboundRouteService — inbound customer mail goes to the department head who owns it, and
 * Tim's "Move to…" teaches it where the next one goes.
 *
 * Why (2026-10-08): Alena at Vancouver Management Ltd (a property manager; VML pays our
 * contract invoices by cheque) emailed "EFT Direct deposit form". It showed up under SAM on the
 * iOS Team tab: SalesDeskService counted every inbound email from a contact with an open quote
 * as "they replied, you haven't". It is Penny's.
 *
 *   stamp()      at ingest (SalesInboxService, office@ and iCloud): InboundTopicClassifier +
 *                the learned rules → sales_messages.head / topic / head_source / head_reason.
 *                Never re-stamps a row Tim moved or the re-route moved.
 *   move()       "Move to… Penny / Sam / Otto / Mia / Yui" on a message (web Action Board, iOS
 *                cards): re-routes it and teaches sender + topic → head (inbound_route_rules;
 *                the address, and the business domain), logged in inbound_route_moves.
 *   done()       Tim dealt with it: the message leaves every list (handled_at).
 *   reroute()    one-off: inbound mail of the last REROUTE_DAYS still on Sam (or never stamped)
 *                that is billing → Penny. Dry run first (lists what would move), then apply.
 *   briefItems() Penny's / Otto's / Mia's messages for Charlie's brief (UnclaimedReplyService
 *                lanes billing / ops / marketing). Penny's become tasks: "Vancouver Management
 *                wants your direct-deposit details — the form is attached; fill it in and email
 *                vidhya@vml.ca". Penny NEVER fills in or sends banking details — she reminds Tim.
 *
 * Migrations 1280 (columns), 1281 (rules), 1282 (moves log), 1283 (attachments). Every DB path
 * is guarded: before 1280 nothing is stamped and lanes are classified on the fly.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/InboundTopicClassifier.php';
require_once __DIR__ . '/InboundAttachmentService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';

class InboundRouteService
{
    public const REROUTE_DAYS = 60;
    /** How far back a card's message is looked up by its item key. */
    public const RESOLVE_DAYS = 120;
    public const NEVER_SEND_NOTE = 'Penny never fills in or sends banking details — this one is yours.';
    /** Sources a stamp may overwrite (Tim's moves and the re-route are kept). */
    public const RESTAMPABLE = ['', 'rule', 'learned'];

    private PDO $db;
    private ?bool $ready = null;
    private ?bool $rulesReady = null;
    private ?bool $movesReady = null;
    private ?array $learned = null;
    private ?array $unclaimed = null;
    private InboundAttachmentService $attachments;

    public function __construct(PDO $db, ?InboundAttachmentService $attachments = null)
    {
        $this->db = $db;
        $this->attachments = $attachments ?? new InboundAttachmentService($db);
    }

    /** Migration 1280: the routing columns exist. */
    public function ready(): bool
    {
        return $this->ready ??= $this->probe('SELECT head, topic, head_source, head_reason, handled_at FROM sales_messages LIMIT 0');
    }

    public function rulesReady(): bool
    {
        return $this->rulesReady ??= $this->probe('SELECT 1 FROM inbound_route_rules LIMIT 0');
    }

    private function movesReady(): bool
    {
        return $this->movesReady ??= $this->probe('SELECT 1 FROM inbound_route_moves LIMIT 0');
    }

    private function probe(string $sql): bool
    {
        try {
            $this->db->query($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function attachments(): InboundAttachmentService
    {
        return $this->attachments;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Deciding
    // ─────────────────────────────────────────────────────────────────────────

    /** sender_key|topic => head */
    public function learnedRules(): array
    {
        if ($this->learned !== null) return $this->learned;
        $this->learned = [];
        if (!$this->rulesReady()) return $this->learned;
        foreach ($this->db->query('SELECT sender_key, topic, head FROM inbound_route_rules')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->learned[strtolower((string)$r['sender_key']) . '|' . $r['topic']] = (string)$r['head'];
        }
        return $this->learned;
    }

    /** @param array $row from_addr, subject, snippet */
    public function decide(array $row): array
    {
        return InboundTopicClassifier::classify([
            'from' => (string)($row['from_addr'] ?? ''), 'subject' => (string)($row['subject'] ?? ''),
            'snippet' => (string)($row['snippet'] ?? ''),
        ], $this->learnedRules());
    }

    /** The head a row shows under now: its stamp, else the rules (no learned rules — as the lanes). */
    public static function effectiveHead(array $row): string
    {
        $h = strtolower((string)($row['head'] ?? ''));
        if (InboundTopicClassifier::isHead($h)) return $h;
        return InboundTopicClassifier::classify(['from' => (string)($row['from_addr'] ?? ''), 'subject' => (string)($row['subject'] ?? ''),
                                                 'snippet' => (string)($row['snippet'] ?? '')])['head'];
    }

    /**
     * Stamp one stored message with its head (inbound only). Called at ingest, and again once
     * the text is in (the readers store the row first, then the snippet). Returns the decision,
     * or null when nothing was stamped.
     */
    public function stamp(string $messageKey): ?array
    {
        if ($messageKey === '' || !$this->ready()) return null;
        $s = $this->db->prepare('SELECT id, direction, from_addr, subject, snippet, head_source FROM sales_messages WHERE message_key = ?');
        $s->execute([$messageKey]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['direction'] !== 'inbound') return null;
        if (!in_array((string)($row['head_source'] ?? ''), self::RESTAMPABLE, true)) return null;
        $d = $this->decide($row);
        $this->db->prepare('UPDATE sales_messages SET head = ?, topic = ?, head_source = ?, head_reason = ? WHERE id = ?')
           ->execute([$d['head'], $d['topic'], $d['source'], mb_substr($d['reason'], 0, 160), (int)$row['id']]);
        return $d;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding the message behind a card
    // ─────────────────────────────────────────────────────────────────────────

    private function cols(): string
    {
        return 'm.id, m.message_key, m.contact_id, m.from_addr, m.subject, m.snippet, m.sent_at'
            . ($this->ready() ? ', m.head, m.topic, m.head_source' : '');
    }

    /**
     * The inbound message a card stands for.
     * @param array{message_key?: string, key?: string, contact_id?: int} $ref
     *   key: an unclaimed-reply item key (sam|yui|penny|otto|mia:reply:<contact>:<hash>) or a
     *   Sam queue card (sam:contact:c<contact> — the reply that made it "replied").
     */
    public function find(array $ref, ?string $now = null): ?array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $since = date('Y-m-d H:i:s', strtotime($now) - self::RESOLVE_DAYS * 86400);
        $cols = $this->cols();
        $mk = trim((string)($ref['message_key'] ?? ''));
        if ($mk !== '') {
            $s = $this->db->prepare("SELECT {$cols} FROM sales_messages m WHERE m.message_key = ? AND m.direction = 'inbound'");
            $s->execute([$mk]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $key = trim((string)($ref['key'] ?? ''));
        $cid = (int)($ref['contact_id'] ?? 0);
        $hash = null;
        if (preg_match('/^(?:sam|yui|penny|otto|mia):reply:(\d+):([0-9a-f]{12})$/', $key, $m)) {
            [$cid, $hash] = [(int)$m[1], $m[2]];
        } elseif (preg_match('/^(?:sam:contact:)?c(\d+)$/', $key, $m)) {
            $cid = (int)$m[1];
        }
        if ($cid <= 0) return null;
        $s = $this->db->prepare("SELECT {$cols} FROM sales_messages m
                                 WHERE m.contact_id = ? AND m.direction = 'inbound' AND m.sent_at >= ?
                                 ORDER BY m.sent_at DESC, m.id DESC LIMIT 200");
        $s->execute([$cid, $since]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($hash !== null) {
            foreach ($rows as $r) if (substr(sha1((string)$r['message_key']), 0, 12) === $hash) return $r;
            return null;
        }
        // A Sam card: the newest reply that counts for Sam, else the newest.
        if (!class_exists('SalesDeskService')) require_once dirname(__DIR__, 2) . '/Sales/Services/SalesDeskService.php';
        foreach ($rows as $r) if (SalesDeskService::countsAsSamReply($r)) return $r;
        return $rows[0] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Move / done / re-route
    // ─────────────────────────────────────────────────────────────────────────

    /** "Move to…": re-route the message and learn sender + topic → head. */
    public function move(array $ref, string $to, int $userId, ?string $now = null): array
    {
        $to = strtolower(trim($to));
        if (!InboundTopicClassifier::isHead($to)) return ['ok' => false, 'message' => 'Move it to Penny, Sam, Otto, Mia or Yui.'];
        if (!$this->ready()) return ['ok' => false, 'message' => 'Migration 1280 has not run yet.'];
        $now = $now ?? date('Y-m-d H:i:s');
        $row = $this->find($ref, $now);
        if (!$row) return ['ok' => false, 'message' => 'That message is no longer here — refresh.'];

        $from = self::effectiveHead($row);
        $topic = (string)($row['topic'] ?? '') ?: $this->decide($row)['topic'];
        $name = InboundTopicClassifier::HEAD_NAMES[$to];
        $this->db->prepare('UPDATE sales_messages SET head = ?, topic = ?, head_source = ?, head_reason = ?, handled_at = NULL WHERE id = ?')
           ->execute([$to, $topic, 'moved', 'Moved to ' . $name . ' by Tim', (int)$row['id']]);
        $this->log((string)$row['message_key'], $from, $to, $topic, 'move', $userId, $now);
        $taught = $this->teach((string)$row['from_addr'], $topic, $to, $userId, $now);

        $addr = InboundTopicClassifier::address((string)$row['from_addr']);
        return [
            'ok'      => true,
            'from'    => $from,
            'head'    => $to,
            'topic'   => $topic,
            'taught'  => $taught,
            'message' => 'Moved to ' . $name . '.' . ($taught && $addr !== ''
                ? ' Next time ' . self::topicWords($topic) . ' from ' . $addr . ' goes straight to ' . $name . '.' : ''),
        ];
    }

    /** Tim dealt with it: the message leaves every head's list. */
    public function done(array $ref, int $userId, ?string $now = null): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Migration 1280 has not run yet.'];
        $now = $now ?? date('Y-m-d H:i:s');
        $row = $this->find($ref, $now);
        if (!$row) return ['ok' => false, 'message' => 'That message is no longer here — refresh.'];
        $this->db->prepare('UPDATE sales_messages SET handled_at = ? WHERE id = ?')->execute([$now, (int)$row['id']]);
        $head = self::effectiveHead($row);
        $this->log((string)$row['message_key'], $head, $head, (string)($row['topic'] ?? ''), 'done', $userId, $now);
        return ['ok' => true, 'message' => 'Done — it\'s off the list.'];
    }

    /**
     * The one-off re-route: inbound mail of the last $days days that is on Sam (stamped Sam, or
     * never stamped — Sam's queue counted every unstamped reply) and that the classifier says
     * is billing → Penny. Tim's own moves are never touched.
     * @return array{ok: bool, dry_run: bool, days: int, checked: int, moving: array, others: array<string, int>, moved: int}
     */
    public function reroute(bool $apply, int $userId = 0, int $days = self::REROUTE_DAYS, ?string $now = null): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Migration 1280 has not run yet.'];
        $now = $now ?? date('Y-m-d H:i:s');
        $days = max(1, min(365, $days));
        $s = $this->db->prepare("
            SELECT m.id, m.message_key, m.contact_id, m.from_addr, m.subject, m.snippet, m.sent_at, m.head, m.topic, m.head_source,
                   c.first_name, c.last_name
            FROM sales_messages m
            LEFT JOIN contacts c ON c.id = m.contact_id
            WHERE m.direction = 'inbound' AND m.sent_at >= ?
              AND (m.head IS NULL OR m.head = 'sam')
              AND (m.head_source IS NULL OR m.head_source IN ('rule', 'learned'))
              AND m.handled_at IS NULL
            ORDER BY m.sent_at DESC, m.id DESC
        ");
        $s->execute([date('Y-m-d H:i:s', strtotime($now) - $days * 86400)]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        $plan = self::planReroute($rows, $this->learnedRules());
        $moved = 0;
        if ($apply) {
            $u = $this->db->prepare("UPDATE sales_messages SET head = 'penny', topic = ?, head_source = 'reroute', head_reason = ?
                                     WHERE id = ? AND (head IS NULL OR head = 'sam') AND (head_source IS NULL OR head_source IN ('rule', 'learned'))");
            foreach ($plan['moving'] as $m) {
                $u->execute([$m['topic'], mb_substr('Re-routed: ' . $m['reason'], 0, 160), $m['id']]);
                if ($u->rowCount() > 0) {
                    $moved++;
                    $this->log($m['message_key'], $m['from_head'], 'penny', $m['topic'], 'reroute', $userId, $now);
                }
            }
        }
        foreach ($plan['moving'] as &$m) unset($m['message_key']);   // the list is for people; keys stay server-side
        unset($m);
        return ['ok' => true, 'dry_run' => !$apply, 'days' => $days, 'checked' => count($rows),
                'moving' => $plan['moving'], 'others' => $plan['others'], 'moved' => $moved];
    }

    /**
     * Pure: which of these rows go to Penny, and what the rest would be.
     * @return array{moving: array, others: array<string, int>}
     */
    public static function planReroute(array $rows, array $learned = []): array
    {
        $moving = [];
        $others = [];
        foreach ($rows as $r) {
            $d = InboundTopicClassifier::classify(['from' => (string)($r['from_addr'] ?? ''), 'subject' => (string)($r['subject'] ?? ''),
                                                   'snippet' => (string)($r['snippet'] ?? '')], $learned);
            if ($d['head'] !== 'penny') {
                $others[$d['head']] = ($others[$d['head']] ?? 0) + 1;
                continue;
            }
            $moving[] = [
                'id'          => (int)$r['id'],
                'message_key' => (string)$r['message_key'],
                'sent_at'     => (string)$r['sent_at'],
                'from'        => InboundTopicClassifier::address((string)($r['from_addr'] ?? '')),
                'contact'     => trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
                'subject'     => (string)($r['subject'] ?? ''),
                'from_head'   => ($r['head'] ?? '') !== '' && $r['head'] !== null ? (string)$r['head'] : 'unstamped',
                'topic'       => $d['topic'],
                'reason'      => $d['reason'],
            ];
        }
        ksort($others);
        return ['moving' => $moving, 'others' => $others];
    }

    private function teach(string $fromAddr, string $topic, string $head, int $userId, string $now): bool
    {
        if (!$this->rulesReady()) return false;
        $keys = InboundTopicClassifier::senderKeys(InboundTopicClassifier::address($fromAddr));
        if (!$keys) return false;
        $get = $this->db->prepare('SELECT hits FROM inbound_route_rules WHERE sender_key = ? AND topic = ?');
        $del = $this->db->prepare('DELETE FROM inbound_route_rules WHERE sender_key = ? AND topic = ?');
        $ins = $this->db->prepare('INSERT INTO inbound_route_rules (sender_key, topic, head, hits, created_by, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($keys as $k) {
            $get->execute([$k, $topic]);
            $hits = (int)($get->fetchColumn() ?: 0);
            $del->execute([$k, $topic]);
            $ins->execute([$k, $topic, $head, $hits + 1, $userId ?: null, $now, $now]);
        }
        $this->learned = null;
        return true;
    }

    private function log(string $messageKey, ?string $from, string $to, ?string $topic, string $source, int $userId, string $now): void
    {
        if (!$this->movesReady()) return;
        try {
            $this->db->prepare('INSERT INTO inbound_route_moves (message_key, from_head, to_head, topic, source, moved_by, moved_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$messageKey, $from, $to, $topic ?: null, $source, $userId ?: null, $now]);
        } catch (Throwable $e) {
            error_log('Inbound route log: ' . $e->getMessage());
        }
    }

    public static function topicWords(string $topic): string
    {
        return ['billing' => 'billing mail', 'sales' => 'quote mail', 'schedule' => 'scheduling mail',
                'marketing' => 'review / social mail', 'general' => 'mail like this'][$topic] ?? 'mail like this';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penny / Otto / Mia: their messages
    // ─────────────────────────────────────────────────────────────────────────

    /** Unclaimed replies, all lanes, once per request. */
    private function unclaimed(?string $now): array
    {
        if ($this->unclaimed === null) {
            try {
                $this->unclaimed = (new UnclaimedReplyService($this->db))->items([], $now);
            } catch (Throwable $e) {
                error_log('Inbound route (unclaimed): ' . $e->getMessage());
                $this->unclaimed = [];
            }
        }
        return $this->unclaimed;
    }

    /**
     * A head's routed messages, as card items (Penny's decorated as tasks with attachments).
     * Only penny / otto / mia — Sam's and Yui's replies already have their own sections.
     */
    public function messages(string $head, ?string $now = null): array
    {
        $lane = UnclaimedReplyService::HEAD_LANE[$head] ?? null;
        if (!in_array($head, ['penny', 'otto', 'mia'], true) || $lane === null) return [];
        $items = array_values(array_filter($this->unclaimed($now), fn($u) => ($u['lane'] ?? '') === $lane));
        if ($head !== 'penny' || !$items) return $items;

        $keys = array_column($items, 'message_key');
        $in = implode(',', array_fill(0, count($keys), '?'));
        $s = $this->db->prepare("
            SELECT m.message_key, m.from_addr, m.subject, m.snippet, c.first_name, c.last_name, co.company_name
            FROM sales_messages m
            LEFT JOIN contacts c ON c.id = m.contact_id
            LEFT JOIN companies co ON co.id = c.company_id
            WHERE m.message_key IN ({$in})
        ");
        try {
            $s->execute($keys);
            $msgs = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $msgs[$r['message_key']] = $r;
        } catch (Throwable $e) {
            $msgs = [];
        }
        $atts = $this->attachments->forKeys($keys);
        return array_map(fn($it) => self::pennyItem($it, $msgs[$it['message_key']] ?? [], $atts[$it['message_key']] ?? []), $items);
    }

    /** The brief items Charlie collects for this head (Action Board, iOS Team tab). */
    public function briefItems(string $head, ?string $now = null): array
    {
        $out = [];
        foreach ($this->messages($head, $now) as $it) {
            $out[] = array_intersect_key($it, array_flip(['key', 'kind', 'value', 'since', 'text', 'url', 'priority', 'yes']));
        }
        return $out;
    }

    /**
     * Pure: messages as the iOS app shows them — attachment links re-signed for the app
     * (Safari / Quick Look can't send a Bearer header), the CRM link kept as a web path.
     */
    public static function forApp(array $items, int $expiry, string $secret): array
    {
        $out = [];
        foreach ($items as $it) {
            $out[] = [
                'key'         => (string)$it['key'],
                'kind'        => (string)($it['kind'] ?? ''),
                'penny_kind'  => $it['penny_kind'] ?? null,
                'text'        => (string)($it['text'] ?? ''),
                'name'        => (string)($it['name'] ?? ''),
                'subject'     => (string)($it['subject'] ?? ''),
                'quote'       => (string)($it['quote'] ?? ''),
                'at'          => (string)($it['at'] ?? ''),
                'email_to'    => $it['email_to'] ?? null,
                'note'        => $it['note'] ?? null,
                'contact_url' => !empty($it['contact_id']) ? '/crm/clients_appstack.php?action=view_contact&id=' . (int)$it['contact_id'] : null,
                'attachments' => array_map(fn($a) => [
                    'id' => (int)$a['id'], 'filename' => (string)$a['filename'], 'mime' => (string)$a['mime'],
                    'url' => InboundAttachmentService::appUrl((int)$a['id'], $expiry, $secret),
                ], (array)($it['attachments'] ?? [])),
            ];
        }
        return $out;
    }

    /** Pure: add routed items to a head's brief (count follows). */
    public static function mergeBrief(array $brief, array $items): array
    {
        if (!$items) return $brief;
        $brief['items'] = array_merge((array)($brief['items'] ?? []), $items);
        $brief['count'] = max((int)($brief['count'] ?? 0), 0) + count($items);
        return $brief;
    }

    /**
     * Pure: Penny's version of a billing reply — a task for Tim, never a reply she sends.
     * @param array $it   UnclaimedReplyService item (billing lane)
     * @param array $msg  from_addr, subject, snippet, first_name, last_name, company_name
     * @param array $atts [{id, filename, mime, size}]
     */
    public static function pennyItem(array $it, array $msg, array $atts): array
    {
        $task = self::pennyTask($msg, (bool)$atts);
        $first = $atts[0] ?? null;
        $it['text'] = $task['text'];
        $it['penny_kind'] = $task['kind'];
        $it['email_to'] = $task['email_to'];
        $it['never_send'] = true;
        $it['note'] = self::NEVER_SEND_NOTE;
        $it['priority'] = $task['kind'] === 'message' ? 2 : 1;
        $it['yes'] = false;
        $it['attachments'] = array_map(fn($a) => $a + ['url' => InboundAttachmentService::webUrl((int)$a['id'])], $atts);
        if ($first) $it['url'] = InboundAttachmentService::webUrl((int)$first['id']);
        return $it;
    }

    /**
     * Pure: what Penny tells Tim.
     * @return array{kind: string, text: string, email_to: ?string}
     *   kind: banking_form | vendor_form | remittance | receipt | message
     */
    public static function pennyTask(array $msg, bool $hasAttachment = false): array
    {
        $subject = (string)($msg['subject'] ?? '');
        $snippet = (string)($msg['snippet'] ?? '');
        $all = $subject . "\n" . $snippet;
        $who = self::who($msg);
        $to = self::replyTo($snippet, (string)($msg['from_addr'] ?? ''));
        $attached = $hasAttachment || (bool)preg_match('/\battach(ed|ment)/i', $all);
        $send = $to !== null ? 'email ' . $to : 'send it back to them';
        $formWhere = $attached ? 'the form is attached' : 'the form is in their email';

        if (preg_match('/direct[\s-]+deposit|\bEFTs?\b|electronic\s+funds?\s+transfer|\bbank(ing)?\s+(form|info|information|details)\b|void(ed)?\s+che(que|ck)|\bPAD\b|pre-?authori[sz]ed\s+(debit|payment)/i', $all)) {
            return ['kind' => 'banking_form', 'email_to' => $to,
                    'text' => $who . ' wants your direct-deposit details — ' . $formWhere . '; fill it in and ' . $send . '.'];
        }
        if (preg_match('/\bW-?9\b|\bT4A\b|\b(vendor|supplier)\s+(set\s*-?up|form|registration|onboarding|application)|\b(GST|HST|business)\s*(number|#|no\.?)\b/i', $all)) {
            return ['kind' => 'vendor_form', 'email_to' => $to,
                    'text' => $who . ' needs your vendor details (GST number / forms) — ' . ($attached ? 'the form is attached' : 'see their email') . '; fill it in and ' . $send . '.'];
        }
        $quote = UnclaimedReplyService::quote($snippet);
        if (preg_match('/\bremit(tance)?s?\b/i', $all)) {
            return ['kind' => 'remittance', 'email_to' => $to,
                    'text' => $who . ' sent a payment remittance' . ($quote !== '' ? ': "' . $quote . '"' : '') . ' — Penny will match it when the money lands.'];
        }
        if (preg_match('/\breceipts?\b/i', $all) && preg_match('/\b(send|need|request|copy|could|can)\b/i', $all)) {
            return ['kind' => 'receipt', 'email_to' => $to,
                    'text' => $who . ' is asking for a receipt' . ($quote !== '' ? ': "' . $quote . '"' : '') . ' — send it from their invoice.'];
        }
        return ['kind' => 'message', 'email_to' => $to,
                'text' => $who . ' wrote about billing' . ($quote !== '' ? ': "' . $quote . '"' : '')
                        . (self::subjectLine($subject) !== '' ? ' (' . self::subjectLine($subject) . ')' : '') . ' — answer them.'];
    }

    /** The subject without Re:/Fwd: or email addresses — invoice numbers stay (Penny needs them). */
    public static function subjectLine(string $s): string
    {
        $s = (string)preg_replace('/^\s*((re|fwd?|fw)\s*:\s*)+/i', '', $s);
        $s = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '…', $s)));
        return mb_strlen($s) > 80 ? rtrim(mb_substr($s, 0, 79)) . '…' : $s;
    }

    /** The company without its "Ltd." (Vancouver Management Ltd. → Vancouver Management), else the person. */
    public static function who(array $msg): string
    {
        $co = trim((string)($msg['company_name'] ?? ''));
        if ($co !== '') {
            $co = trim((string)preg_replace('/[\s,]+(ltd|limited|inc|incorporated|corp|corporation|llc|co)\.?$/i', '', $co));
            if ($co !== '') return $co;
        }
        $name = UnclaimedReplyService::firstName((string)($msg['first_name'] ?? ''));
        return $name !== '' ? $name : 'A customer';
    }

    /**
     * Where the form goes: "email it to vidhya@vml.ca" / "send … to …" / "return … to …"; else
     * the first other address in the text; else the sender. Never one of ours.
     */
    public static function replyTo(string $snippet, string $from): ?string
    {
        $re = '[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}';
        $ours = fn(string $a) => (bool)preg_match('/@mowology\.ca$|^mowology@icloud\.com$/i', $a);
        if (preg_match_all('/\b(?:e-?mail|send|return|submit|forward|reply)\b[^.\n]{0,60}?\bto\s*:?\s*(' . $re . ')/i', $snippet, $m)) {
            foreach ($m[1] as $a) if (!$ours($a)) return strtolower(rtrim($a, '.'));
        }
        $fromAddr = InboundTopicClassifier::address($from);
        if (preg_match_all('/' . $re . '/i', $snippet, $m)) {
            foreach ($m[0] as $a) {
                $a = strtolower(rtrim($a, '.'));
                if (!$ours($a) && $a !== $fromAddr) return $a;
            }
        }
        return $fromAddr !== '' ? $fromAddr : null;
    }
}
