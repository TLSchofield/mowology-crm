<?php
/**
 * MissingReceiptService — Penny chases the receipts that never came in (Penny backlog, migration 1245).
 *
 * Every 2026 card / bank SPENDING line (accounting_transactions: reference_type 'bank_import',
 * type 'expense') that
 *   - has no linked receipt (matched_expense_id NULL),
 *   - has no receipt that clearly fits it (BankImportService::candidateExpensesForTransaction — same
 *     amount ±2 %, with printed-facts boosts such as card ••last4 — within ±3 days, or scored ≥ 80), and
 *   - is not a kind of line that never has a receipt (penny_receipt_exempt_rules: bank fees, interest,
 *     loans, payroll, transfers, remittances, pre-authorised insurance / utilities — Tim edits the list)
 * becomes a "missing receipt" item, with who probably made the charge:
 *   card ••last4 → penny_card_holders → that person          (basis 'card')
 *   else Otto's truck runs that day at a place matching the vendor → the driver   ('truck')
 *   else the one person (not the owner) clocked in that day / at that time        ('clock')
 *   else the owner                                                                ('owner')
 *
 * Chase cadence (cron penny_chase, hourly): one push to the person the day after the charge (08:00 at
 * the earliest), a second 3 days later, then no more pushes — the item stays on their Penny card and in
 * Tim's weekly summary (Mondays: "Still missing: 6 receipts, $412"). Never between quiet hours
 * (ops_settings penny_chase_quiet_start / _end, default 21:00–07:00 Pacific). Charges older than
 * PUSH_MAX_AGE_DAYS when first found are listed, never pushed (no flood on the first run).
 *
 * Closing: a receipt saved through ExpenseGate (any intake path) that matches an open item — same
 * amount ±2 % / 50¢, ±3 days, one item or the one belonging to whoever saved it — closes it
 * (onReceipt). "Snap it" on the crew card closes its own item explicitly (attach). "No receipt"
 * records a reason (lost / not available) so Penny stops asking, and Tim sees it: without a receipt
 * the GST on it can't be claimed back.
 *
 * Nothing here touches the books: the bank line ↔ receipt link itself stays Penny's bank desk job.
 * Pushes go through PushDispatcher (APNs live; FCM no-ops cleanly when Firebase isn't configured —
 * the item records 'android: FCM not configured').
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MissingReceiptService
{
    public const FROM_DEFAULT = '2026-01-01';   // 2025 is filed and locked
    public const MATCH_DAYS = 3;
    public const STRONG = 80;                   // BankReceiptSweep::STRONG
    public const FIRST_NUDGE_HOUR = 8;
    public const SECOND_NUDGE_DAYS = 3;
    public const MAX_NUDGES = 2;
    public const PUSH_MAX_AGE_DAYS = 30;
    public const SCAN_LIMIT = 400;
    public const RECHECK_LIMIT = 300;
    public const QUIET_START = 21;
    public const QUIET_END = 7;
    public const SUMMARY_DOW = 1;               // Monday
    public const SUMMARY_HOUR = 8;
    public const REASONS = ['lost' => 'Lost it', 'not_available' => "Vendor didn't give one"];
    public const MATCH_ON = ['account_code', 'account_name', 'description'];
    public const TEAM_URL = '/crm/my-team.php';
    public const TZ = 'America/Vancouver';

    private PDO $db;
    /** @var callable|null fn(int $transactionId): list<array> receipt candidates (tests pass a stub) */
    private $candidates;
    /** @var callable|null fn(int $userId, string $title, string $body, array $data): string push note */
    private $push;
    private ?DateTimeImmutable $now;

    public function __construct(PDO $db, ?callable $candidates = null, ?callable $push = null, ?DateTimeImmutable $now = null)
    {
        $this->db = $db;
        $this->candidates = $candidates;
        $this->push = $push;
        $this->now = $now;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM penny_missing_receipts LIMIT 1");
            $this->db->query("SELECT 1 FROM penny_receipt_exempt_rules LIMIT 1");
            $this->db->query("SELECT 1 FROM penny_card_holders LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The rule that says this line never has a receipt, or null.
     * @param array $line  account_code, account_name, description
     * @param array $rules rows of penny_receipt_exempt_rules (match_on, pattern, label, active)
     */
    public static function exemptReason(array $line, array $rules): ?string
    {
        $code = trim((string)($line['account_code'] ?? ''));
        $name = mb_strtolower((string)($line['account_name'] ?? ''));
        $desc = mb_strtoupper(' ' . preg_replace('/\s+/', ' ', (string)($line['description'] ?? '')) . ' ');
        foreach ($rules as $r) {
            if (isset($r['active']) && !(int)$r['active']) continue;
            $p = trim((string)($r['pattern'] ?? ''));
            if ($p === '') continue;
            $on = (string)($r['match_on'] ?? '');
            $hit = false;
            if ($on === 'account_code' && $code !== '') {
                $hit = substr($p, -1) === '*' ? strpos($code, rtrim($p, '*')) === 0 : $code === $p;
            } elseif ($on === 'account_name' && $name !== '') {
                $hit = mb_strpos($name, mb_strtolower($p)) !== false;
            } elseif ($on === 'description') {
                // Raw substring on purpose: "CRA " with its space must not hit "CRAFT".
                $hit = mb_strpos($desc, mb_strtoupper((string)$r['pattern'])) !== false;
            }
            if ($hit) return (string)($r['label'] ?? $p);
        }
        return null;
    }

    /** "POS PURCHASE LAWN BOY #123 VANCOUVER BC" → "Lawn Boy". */
    public static function vendorLabel(string $description): string
    {
        $s = strtoupper(trim(preg_replace('/\s+/', ' ', $description)));
        $s = preg_replace('/^(POS PURCHASE|POINT OF SALE( - INTERAC)?|INTERAC PURCHASE|VISA DEBIT PURCHASE|VISA DEBIT|DEBIT PURCHASE|PURCHASE|CONTACTLESS( INTERAC)?( PURCHASE)?|INTERAC|OPOS|APOS|IDP PURCHASE|PREAUTHORIZED DEBIT|PRE-AUTHORIZED DEBIT|BILL PAYMENT|SQ \*|SQ\*|TST\*|TST-|PAYPAL \*|PP\*)\s*[-:]?\s*/', '', $s);
        $s = preg_replace('/^(SQ \*|SQ\*|TST\* ?|PAYPAL \*)/', '', $s);
        $s = preg_replace('/[#*]\s*\d+.*$/', '', $s);                       // store / reference numbers and everything after
        $s = preg_replace('/\b\d{3,}\b.*$/', '', $s);                        // long numbers and after
        $s = preg_replace('/\b(VANCOUVER|BURNABY|SURREY|RICHMOND|COQUITLAM|NORTH VAN(COUVER)?|WEST VAN(COUVER)?|NEW WESTMINSTER|DELTA|LANGLEY|PORT MOODY|MAPLE RIDGE)\b.*$/', '', $s);
        $s = preg_replace('/\s+\b(BC|AB|ON|CA|CAN)\b\s*$/', '', $s);
        $s = trim($s, " -:/.,");
        if ($s === '') return 'A card charge';
        $words = array_slice(explode(' ', $s), 0, 4);
        $out = [];
        foreach ($words as $w) {
            $vowels = preg_match_all('/[AEIOU]/', $w);
            $out[] = (strlen($w) <= 3 && $vowels === 0) || preg_match('/\d/', $w) ? $w : ucfirst(strtolower($w));
        }
        return mb_substr(implode(' ', $out), 0, 80);
    }

    /** "… 12:34 …" in the bank line → "12:34"; most lines carry no time. */
    public static function chargeTime(string $description): ?string
    {
        if (preg_match('/(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?![\d:])/', $description, $m)) {
            return sprintf('%02d:%s', (int)$m[1], $m[2]);
        }
        return null;
    }

    /**
     * The card's last 4: the bank account's own number (a card statement), else a masked
     * number in the line ("****1234", "XXXX1234", "CARD 1234").
     */
    public static function cardLast4(?string $accountNumber, string $description): ?string
    {
        if (preg_match('/(?:\*{2,}|X{2,}|•{2,}|CARD\s*#?\s*)(\d{4})(?!\d)/iu', $description, $m)) return $m[1];
        $digits = preg_replace('/\D/', '', (string)$accountNumber);
        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }

    /** Does an ops_places row (name, vendor_match "a|b") name this bank line? */
    public static function placeMatches(array $place, string $description): bool
    {
        $desc = mb_strtolower($description);
        $words = array_filter(array_map('trim', explode('|', (string)($place['vendor_match'] ?? ''))));
        if (!$words && !empty($place['name'])) $words = [(string)$place['name']];
        foreach ($words as $w) {
            $w = mb_strtolower($w);
            if (mb_strlen($w) >= 3 && mb_strpos($desc, $w) !== false) return true;
            $squashed = str_replace(' ', '', $w);   // "lawn boy" vs "LAWNBOY"
            if (mb_strlen($squashed) >= 4 && mb_strpos(str_replace(' ', '', $desc), $squashed) !== false) return true;
        }
        return false;
    }

    /**
     * Who to ask. Pure.
     * @param ?array $card    ['user_id' => int, 'name' => string, 'last4' => string] or null
     * @param array  $drivers [['user_id', 'name', 'place']] truck runs that day at a matching place
     * @param array  $clocked [['user_id', 'name']] people clocked in that day (or at the charge time)
     * @return array{user_id: int, basis: string, note: string}
     */
    public static function attribute(?array $card, array $drivers, array $clocked, int $ownerId, string $ownerName = 'Tim', ?string $time = null): array
    {
        if ($card && !empty($card['user_id'])) {
            return ['user_id' => (int)$card['user_id'], 'basis' => 'card',
                    'note' => 'Card ••' . $card['last4'] . ' is ' . self::first((string)$card['name']) . "'s"];
        }
        $byUser = [];
        foreach ($drivers as $d) {
            if (!empty($d['user_id'])) $byUser[(int)$d['user_id']] = $d;
        }
        if (count($byUser) === 1) {
            $d = reset($byUser);
            return ['user_id' => (int)$d['user_id'], 'basis' => 'truck',
                    'note' => 'The truck was at ' . $d['place'] . ' that day — ' . self::first((string)$d['name']) . ' was driving'];
        }
        $crew = [];
        foreach ($clocked as $c) {
            if (!empty($c['user_id']) && (int)$c['user_id'] !== $ownerId) $crew[(int)$c['user_id']] = $c;
        }
        $when = $time ? 'at ' . $time : 'that day';
        if (count($crew) === 1) {
            $c = reset($crew);
            return ['user_id' => (int)$c['user_id'], 'basis' => 'clock',
                    'note' => 'Only ' . self::first((string)$c['name']) . ' was clocked in ' . $when];
        }
        if (count($crew) > 1) {
            $names = array_map(fn($c) => self::first((string)$c['name']), array_values($crew));
            return ['user_id' => $ownerId, 'basis' => 'owner',
                    'note' => self::andList($names) . ' were all working ' . $when . ' — asking ' . self::first($ownerName)];
        }
        return ['user_id' => $ownerId, 'basis' => 'owner', 'note' => 'No card or crew match — asking ' . self::first($ownerName)];
    }

    /** Penny's question. "Hi Nigel — Lawn Boy charged $84.00 on Oct 7 at 12:34. Do you have the receipt?" */
    public static function ask(array $item, string $firstName = ''): string
    {
        $when = date('M j', strtotime((string)$item['charge_date']))
              . (!empty($item['charge_time']) ? ' at ' . $item['charge_time'] : '');
        return ($firstName !== '' ? 'Hi ' . $firstName . ' — ' : 'Hi — ')
             . ($item['vendor_label'] ?: 'A card charge') . ' charged ' . self::money((float)$item['amount'])
             . ' on ' . $when . '. Do you have the receipt?';
    }

    /**
     * One push per person per run. @param array $items that person's items being nudged now.
     * @return array{title: string, body: string, data: array}
     */
    public static function pushFor(array $items): array
    {
        if (count($items) === 1) {
            $i = $items[0];
            return [
                'title' => 'Penny: got the receipt?',
                'body'  => ($i['vendor_label'] ?: 'A card charge') . ' — ' . self::money((float)$i['amount']) . ' on '
                         . date('M j', strtotime((string)$i['charge_date'])) . '. Tap to snap it or tell me it\'s gone.',
                'data'  => ['type' => 'penny_missing', 'missing_id' => (int)$i['id'],
                            'url' => self::TEAM_URL . '?penny=missing&id=' . (int)$i['id']],
            ];
        }
        $total = array_sum(array_map(fn($i) => (float)$i['amount'], $items));
        return [
            'title' => 'Penny: ' . count($items) . ' receipts missing',
            'body'  => self::money($total) . ' in card charges with no receipt yet. Tap to see them.',
            'data'  => ['type' => 'penny_missing', 'url' => self::TEAM_URL . '?penny=missing'],
        ];
    }

    /** "Still missing: 6 receipts, $412" (+ the ones marked "no receipt"). */
    public static function summaryText(int $open, float $amount, int $noReceipt = 0, float $noReceiptAmount = 0.0): string
    {
        if ($open === 0 && $noReceipt === 0) return 'Every card charge has its receipt';
        $s = 'Still missing: ' . $open . ' receipt' . ($open === 1 ? '' : 's') . ', ' . self::money($amount, true);
        if ($noReceipt > 0) {
            $s .= ' · ' . $noReceipt . ' marked "no receipt" (' . self::money($noReceiptAmount, true) . ', no GST claim)';
        }
        return $s;
    }

    public static function isQuiet(int $hour, int $start = self::QUIET_START, int $end = self::QUIET_END): bool
    {
        if ($start === $end) return false;
        return $start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end);
    }

    /**
     * When the first push may go: the morning after the charge (FIRST_NUDGE_HOUR), or now if that
     * has passed; null for a charge too old to push about (it is still listed).
     */
    public static function firstNudgeAt(string $chargeDate, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $tz = $now->getTimezone();
        $charge = new DateTimeImmutable(substr($chargeDate, 0, 10) . ' 00:00:00', $tz);
        if ($charge < $now->setTime(0, 0)->modify('-' . self::PUSH_MAX_AGE_DAYS . ' days')) return null;
        $at = $charge->modify('+1 day')->setTime(self::FIRST_NUDGE_HOUR, 0);
        return $at > $now ? $at : $now;
    }

    /** After a push: the next one 3 days later, then none. */
    public static function nextAfter(int $nudgesSent, DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $nudgesSent >= self::MAX_NUDGES ? null : $now->modify('+' . self::SECOND_NUDGE_DAYS . ' days');
    }

    /**
     * The receipt that clearly fits a bank line, or null. Candidates as candidateExpensesForTransaction
     * returns them (date, confidence, expense_id), best first.
     */
    public static function clearMatch(array $candidates, string $chargeDate): ?array
    {
        $d0 = strtotime(substr($chargeDate, 0, 10));
        foreach ($candidates as $c) {
            $days = abs((int)round((strtotime((string)($c['date'] ?? $chargeDate)) - $d0) / 86400));
            if ($days <= self::MATCH_DAYS || (int)($c['confidence'] ?? 0) >= self::STRONG) return $c;
        }
        return null;
    }

    /** ISO week key for the weekly summary ("2026-W41"). */
    public static function weekKey(DateTimeImmutable $d): string
    {
        return $d->format('o-\WW');
    }

    public static function money(float $v, bool $round = false): string
    {
        return '$' . number_format($v, $round && $v >= 100 ? 0 : 2);
    }

    private static function first(string $name): string
    {
        $n = trim(strtok(trim($name), ' ') ?: '');
        return $n === '' ? 'someone' : ucfirst($n);
    }

    private static function andList(array $names): string
    {
        if (count($names) <= 1) return (string)($names[0] ?? '');
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Settings, owner
    // ─────────────────────────────────────────────────────────────────────────

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    private function setting(string $key, string $default): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false || $v === null || $v === '' ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function setSetting(string $key, string $value, string $description): void
    {
        $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value, $description]);
    }

    public function fromDate(): string
    {
        $d = $this->setting('penny_chase_from', self::FROM_DEFAULT);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d >= self::FROM_DEFAULT ? $d : self::FROM_DEFAULT;
    }

    /** @return array{0: int, 1: int} quiet start, end hours */
    public function quietHours(): array
    {
        return [(int)$this->setting('penny_chase_quiet_start', (string)self::QUIET_START) % 24,
                (int)$this->setting('penny_chase_quiet_end', (string)self::QUIET_END) % 24];
    }

    /** @return array{id: int, name: string} the owner (Freedom dashboard's choice, else the first admin) */
    public function owner(): array
    {
        $id = (int)$this->setting('freedom_owner_user_id', '0');
        try {
            if ($id > 0) {
                $s = $this->db->prepare("SELECT id, full_name FROM users WHERE id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) return ['id' => (int)$r['id'], 'name' => (string)$r['full_name']];
            }
            $r = $this->db->query("SELECT id, full_name FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($r) return ['id' => (int)$r['id'], 'name' => (string)$r['full_name']];
        } catch (Throwable $e) { /* fall through */ }
        return ['id' => 0, 'name' => 'Tim'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scan: find the missing receipts, close the ones that turned up
    // ─────────────────────────────────────────────────────────────────────────

    public function rules(bool $activeOnly = true): array
    {
        return $this->db->query("SELECT id, match_on, pattern, label, active FROM penny_receipt_exempt_rules"
                                . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY match_on, pattern")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{found: int, exempt: int, has_receipt: int, received: int, rechecked: int} */
    public function scan(): array
    {
        $out = ['found' => 0, 'exempt' => 0, 'has_receipt' => 0, 'received' => 0, 'rechecked' => 0];
        $rules = $this->rules();
        $owner = $this->owner();
        $now = $this->now();

        // 1. Open items first: a receipt may have turned up through a path the gate didn't see.
        $open = $this->db->query("
            SELECT m.id, m.transaction_id, m.charge_date, at.matched_expense_id, at.id AS tx_id,
                   coa.code AS account_code, coa.name AS account_name, at.description
            FROM penny_missing_receipts m
            LEFT JOIN accounting_transactions at ON at.id = m.transaction_id
            LEFT JOIN chart_of_accounts coa ON coa.id = at.account_id
            WHERE m.status = 'open'
            ORDER BY m.charge_date DESC
            LIMIT " . self::RECHECK_LIMIT)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($open as $m) {
            $out['rechecked']++;
            if (empty($m['tx_id'])) {   // the bank line was rolled back / deleted
                $this->close((int)$m['id'], 'exempt', null, null, 'Bank line removed');
                continue;
            }
            if (!empty($m['matched_expense_id'])) {
                $this->close((int)$m['id'], 'received', (int)$m['matched_expense_id'], null, 'Linked on the bank desk');
                $out['received']++;
                continue;
            }
            if ($why = self::exemptReason($m, $rules)) {
                $this->close((int)$m['id'], 'exempt', null, null, $why);
                continue;
            }
            $c = self::clearMatch($this->candidatesFor((int)$m['transaction_id']), (string)$m['charge_date']);
            if ($c && (int)$c['expense_id'] > 0) {
                $this->close((int)$m['id'], 'received', (int)$c['expense_id'], null, 'A matching receipt came in');
                $out['received']++;
            }
        }

        // 2. New lines.
        $s = $this->db->prepare("
            SELECT at.id, at.transaction_date, at.amount, at.description,
                   coa.code AS account_code, coa.name AS account_name, ba.account_number
            FROM accounting_transactions at
            LEFT JOIN chart_of_accounts coa ON coa.id = at.account_id
            LEFT JOIN chart_of_accounts ba ON ba.id = at.bank_account_id
            LEFT JOIN penny_missing_receipts m ON m.transaction_id = at.id
            WHERE at.reference_type = 'bank_import' AND at.type = 'expense'
              AND at.matched_expense_id IS NULL
              AND at.transaction_date >= ?
              AND at.transaction_date <= ?
              AND m.id IS NULL
            ORDER BY at.transaction_date DESC, at.id DESC
            LIMIT " . self::SCAN_LIMIT);
        $s->execute([$this->fromDate(), $now->format('Y-m-d')]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $desc = (string)$line['description'];
            if ($why = self::exemptReason($line, $rules)) {
                $this->insertItem($line, ['user_id' => null, 'basis' => 'owner', 'note' => null], 'exempt', $why, null);
                $out['exempt']++;
                continue;
            }
            if (self::clearMatch($this->candidatesFor((int)$line['id']), (string)$line['transaction_date']) !== null) {
                $out['has_receipt']++;   // Penny's bank desk links it; not chased
                continue;
            }
            $who = $this->whoFor($line, $owner);
            $next = self::firstNudgeAt((string)$line['transaction_date'], $now);
            $this->insertItem($line, $who, 'open', null, $next);
            $out['found']++;
        }
        return $out;
    }

    private function candidatesFor(int $transactionId): array
    {
        try {
            if ($this->candidates) return (array)call_user_func($this->candidates, $transactionId);
            if (!class_exists('BankImportService')) require_once dirname(__DIR__, 2) . '/Accounting/Services/BankImportService.php';
            return (new BankImportService($this->db))->candidateExpensesForTransaction($transactionId, 4);
        } catch (Throwable $e) {
            error_log('MissingReceipt candidates #' . $transactionId . ': ' . $e->getMessage());
            return [['expense_id' => 0, 'date' => '1970-01-01', 'confidence' => 100]];   // unsure → don't chase
        }
    }

    private function insertItem(array $line, array $who, string $status, ?string $note, ?DateTimeImmutable $next): void
    {
        $desc = (string)$line['description'];
        $this->db->prepare("
            INSERT IGNORE INTO penny_missing_receipts
              (transaction_id, charge_date, charge_time, amount, description, vendor_label, card_last4,
               user_id, basis, basis_note, status, reason_note, resolved_at, next_nudge_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            (int)$line['id'], substr((string)$line['transaction_date'], 0, 10), self::chargeTime($desc),
            round((float)$line['amount'], 2), mb_substr($desc, 0, 255), self::vendorLabel($desc),
            self::cardLast4($line['account_number'] ?? null, $desc),
            $who['user_id'], $who['basis'], $who['note'] !== null ? mb_substr((string)$who['note'], 0, 160) : null,
            $status, $note, $status === 'open' ? null : $this->now()->format('Y-m-d H:i:s'),
            $next ? $next->format('Y-m-d H:i:s') : null,
        ]);
    }

    /** Who probably made the charge (card → truck → clock → owner). */
    public function whoFor(array $line, ?array $owner = null): array
    {
        $owner = $owner ?? $this->owner();
        $desc = (string)$line['description'];
        $date = substr((string)$line['transaction_date'], 0, 10);
        $time = self::chargeTime($desc);

        $card = null;
        $last4 = self::cardLast4($line['account_number'] ?? null, $desc);
        if ($last4) {
            try {
                $s = $this->db->prepare("SELECT h.user_id, u.full_name FROM penny_card_holders h
                                         LEFT JOIN users u ON u.id = h.user_id WHERE h.card_last4 = ?");
                $s->execute([$last4]);
                if (($r = $s->fetch(PDO::FETCH_ASSOC)) && !empty($r['user_id'])) {
                    $card = ['user_id' => (int)$r['user_id'], 'name' => (string)$r['full_name'], 'last4' => $last4];
                }
            } catch (Throwable $e) { /* no mapping */ }
        }

        $drivers = [];
        try {
            $s = $this->db->prepare("SELECT r.user_id, u.full_name, p.name AS place, p.vendor_match
                                     FROM ops_trip_runs r JOIN ops_places p ON p.id = r.place_id
                                     LEFT JOIN users u ON u.id = r.user_id
                                     WHERE r.run_date = ? AND r.user_id IS NOT NULL");
            $s->execute([$date]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (self::placeMatches(['name' => $r['place'], 'vendor_match' => $r['vendor_match']], $desc)) {
                    $drivers[] = ['user_id' => (int)$r['user_id'], 'name' => (string)$r['full_name'], 'place' => (string)$r['place']];
                }
            }
        } catch (Throwable $e) { /* no trip data (migration 1216) */ }

        $clocked = [];
        try {
            if ($time) {
                $at = $date . ' ' . $time . ':00';
                $s = $this->db->prepare("SELECT DISTINCT t.user_id, u.full_name FROM time_clock_entries t JOIN users u ON u.id = t.user_id
                                         WHERE t.status <> 'void' AND t.clock_in <= ? AND (t.clock_out IS NULL OR t.clock_out >= ?)");
                $s->execute([$at, $at]);
            } else {
                $s = $this->db->prepare("SELECT DISTINCT t.user_id, u.full_name FROM time_clock_entries t JOIN users u ON u.id = t.user_id
                                         WHERE t.status <> 'void' AND t.clock_in >= ? AND t.clock_in < DATE_ADD(?, INTERVAL 1 DAY)");
                $s->execute([$date . ' 00:00:00', $date]);
            }
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $clocked[] = ['user_id' => (int)$r['user_id'], 'name' => (string)$r['full_name']];
            }
        } catch (Throwable $e) { /* no clock data */ }

        return self::attribute($card, $drivers, $clocked, (int)$owner['id'], (string)$owner['name'], $time);
    }

    private function close(int $id, string $status, ?int $expenseId, ?int $userId, ?string $note): bool
    {
        $s = $this->db->prepare("
            UPDATE penny_missing_receipts
               SET status = ?, expense_id = ?, resolved_by = ?, resolved_at = ?, reason_note = COALESCE(?, reason_note), next_nudge_at = NULL
             WHERE id = ? AND status = 'open'
        ");
        $s->execute([$status, $expenseId, $userId, $this->now()->format('Y-m-d H:i:s'), $note, $id]);
        return $s->rowCount() > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chase: pushes, weekly summary
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{sent: int, people: int, quiet: bool, notes: array} */
    public function nudge(): array
    {
        $now = $this->now();
        [$qs, $qe] = $this->quietHours();
        if (self::isQuiet((int)$now->format('G'), $qs, $qe)) return ['sent' => 0, 'people' => 0, 'quiet' => true, 'notes' => []];

        $s = $this->db->prepare("
            SELECT id, user_id, charge_date, amount, vendor_label, nudges
            FROM penny_missing_receipts
            WHERE status = 'open' AND user_id IS NOT NULL AND next_nudge_at IS NOT NULL AND next_nudge_at <= ? AND nudges < ?
            ORDER BY user_id, charge_date
        ");
        $s->execute([$now->format('Y-m-d H:i:s'), self::MAX_NUDGES]);
        $byUser = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $byUser[(int)$r['user_id']][] = $r;

        $sent = 0; $notes = [];
        foreach ($byUser as $userId => $items) {
            $p = self::pushFor($items);
            $note = $this->sendPush($userId, $p['title'], $p['body'], $p['data']);
            $notes[$userId] = $note;
            $upd = $this->db->prepare("UPDATE penny_missing_receipts SET nudges = nudges + 1, last_nudged_at = ?, next_nudge_at = ?, last_push = ? WHERE id = ?");
            foreach ($items as $i) {
                $next = self::nextAfter((int)$i['nudges'] + 1, $now);
                $upd->execute([$now->format('Y-m-d H:i:s'), $next ? $next->format('Y-m-d H:i:s') : null, mb_substr($note, 0, 60), (int)$i['id']]);
                $sent++;
            }
        }
        return ['sent' => $sent, 'people' => count($byUser), 'quiet' => false, 'notes' => $notes];
    }

    /** Monday morning, once a week: Tim's "Still missing: 6 receipts, $412". @return ?array what was sent */
    public function weeklySummary(bool $force = false): ?array
    {
        $now = $this->now();
        $week = self::weekKey($now);
        if (!$force) {
            if ((int)$now->format('N') !== self::SUMMARY_DOW || (int)$now->format('G') < self::SUMMARY_HOUR) return null;
            [$qs, $qe] = $this->quietHours();
            if (self::isQuiet((int)$now->format('G'), $qs, $qe)) return null;
            if ($this->setting('penny_chase_last_summary', '') === $week) return null;
        }
        $t = $this->totals();
        $owner = $this->owner();
        $text = self::summaryText($t['open'], $t['open_amount'], $t['no_receipt'], $t['no_receipt_amount']);
        $note = 'nothing to send';
        if ($owner['id'] && ($t['open'] > 0 || $t['no_receipt'] > 0)) {
            $note = $this->sendPush($owner['id'], 'Penny: weekly receipts', $text,
                                    ['type' => 'penny_missing_summary', 'url' => '/crm/dashboard_appstack.php#mw-penny']);
        }
        $this->setSetting('penny_chase_last_summary', $week, 'Penny receipt chaser: ISO week of the last weekly summary');
        return ['week' => $week, 'text' => $text, 'push' => $note] + $t;
    }

    /**
     * Queue a push through PushDispatcher and say what will happen to it: 'ios', 'android',
     * 'android: FCM not configured', 'no device'.
     */
    private function sendPush(int $userId, string $title, string $body, array $data): string
    {
        if ($this->push) return (string)call_user_func($this->push, $userId, $title, $body, $data);
        $platforms = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT platform FROM device_tokens WHERE user_id = ? AND is_active = 1");
            $s->execute([$userId]);
            $platforms = $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { /* no device_tokens table */ }
        if (!$platforms) return 'no device';
        $parts = [];
        foreach ($platforms as $p) {
            if ($p === 'android') {
                if (!class_exists('FcmService')) require_once APP_ROOT . '/Services/Push/FcmService.php';
                $parts[] = FcmService::isConfigured() ? 'android' : 'android: FCM not configured';
            } else {
                $parts[] = (string)$p;
            }
        }
        try {
            if (!class_exists('PushDispatcher')) {
                require_once APP_ROOT . '/Services/Push/ApnsService.php';
                require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
            }
            PushDispatcher::notifyUser($userId, $title, $body, $data);
        } catch (Throwable $e) {
            error_log('MissingReceipt push #' . $userId . ': ' . $e->getMessage());
            return 'push failed';
        }
        return implode(', ', $parts);
    }

    /** The whole cron run: scan, nudge, Monday summary. */
    public function run(): array
    {
        $scan = $this->scan();
        $nudge = $this->nudge();
        $summary = $this->weeklySummary();
        return ['scan' => $scan, 'nudge' => $nudge, 'summary' => $summary];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A person is only asked about charges Penny pushes about (or has pushed about, or Tim re-assigned):
     * charges that were already old when first found stay on Tim's list, not on a crew card.
     */
    private const ASKABLE = "(nudges > 0 OR next_nudge_at IS NOT NULL)";

    /**
     * @param ?int $userId null = everything (Tim); a person = what their crew card asks (ASKABLE)
     * @return array{open: int, open_amount: float, no_receipt: int, no_receipt_amount: float}
     */
    public function totals(?int $userId = null): array
    {
        $sql = "SELECT status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM penny_missing_receipts
                WHERE status IN ('open', 'no_receipt')" . ($userId ? " AND user_id = ? AND " . self::ASKABLE : "") . " GROUP BY status";
        $s = $this->db->prepare($sql);
        $s->execute($userId ? [$userId] : []);
        $t = ['open' => 0, 'open_amount' => 0.0, 'no_receipt' => 0, 'no_receipt_amount' => 0.0];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t[$r['status']] = (int)$r['n'];
            $t[$r['status'] . '_amount'] = round((float)$r['amt'], 2);
        }
        return $t;
    }

    /** Open items for a person's crew card (ASKABLE only), newest first. Every open item when $userId is null. */
    public function openFor(?int $userId, int $limit = 30): array
    {
        $s = $this->db->prepare("
            SELECT m.id, m.transaction_id, m.charge_date, m.charge_time, m.amount, m.vendor_label, m.description,
                   m.card_last4, m.user_id, m.basis, m.basis_note, m.nudges, u.full_name
            FROM penny_missing_receipts m LEFT JOIN users u ON u.id = m.user_id
            WHERE m.status = 'open'" . ($userId ? " AND m.user_id = ? AND (m.nudges > 0 OR m.next_nudge_at IS NOT NULL)" : "") . "
            ORDER BY m.charge_date DESC, m.id DESC
            LIMIT " . max(1, min(200, $limit)));
        $s->execute($userId ? [$userId] : []);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function item(int $id): ?array
    {
        $s = $this->db->prepare("SELECT m.*, u.full_name FROM penny_missing_receipts m LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Marked "no receipt" — for Tim (no GST claim on these). */
    public function noReceiptList(int $limit = 20): array
    {
        return $this->db->query("
            SELECT m.id, m.charge_date, m.amount, m.vendor_label, m.reason, m.reason_note, m.resolved_at, u.full_name
            FROM penny_missing_receipts m LEFT JOIN users u ON u.id = m.resolved_by
            WHERE m.status = 'no_receipt' ORDER BY m.resolved_at DESC LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Charlie's brief: missing receipts, and the ones marked "no receipt" this week. */
    public function briefItems(): array
    {
        $t = $this->totals();
        $items = [];
        if ($t['open'] > 0) {
            $items[] = ['key' => 'penny:missing_receipts', 'kind' => 'penny:missing_receipts',
                        'priority' => $t['open_amount'] >= 500 ? 2 : 3,
                        'text' => $t['open'] . ' receipt' . ($t['open'] === 1 ? '' : 's') . ' missing (' . self::money($t['open_amount'], true) . ') — Penny is asking the crew',
                        'url' => '/crm/dashboard_appstack.php#mw-penny', 'value' => $t['open_amount']];
        }
        return $items;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Closing an item
    // ─────────────────────────────────────────────────────────────────────────

    private function canTouch(array $item, array $user): bool
    {
        return (int)($item['user_id'] ?? 0) === (int)($user['id'] ?? -1) || self::isManager($user);
    }

    public static function isManager(array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
    }

    /** "Snap it" / "It's already in": this receipt is the one. */
    public function attach(int $itemId, int $expenseId, array $user): array
    {
        $item = $this->item($itemId);
        if (!$item || !$this->canTouch($item, $user)) return ['ok' => false, 'message' => 'That one isn\'t yours to answer'];
        if ($item['status'] !== 'open') {
            return ['ok' => true, 'message' => 'Already sorted — thanks!', 'status' => $item['status']];
        }
        $s = $this->db->prepare("SELECT id, created_by, status, total FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || in_array($e['status'], ['rejected', 'cancelled'], true)) return ['ok' => false, 'message' => 'Receipt not found'];
        if ((int)$e['created_by'] !== (int)$user['id'] && !self::isManager($user)) {
            return ['ok' => false, 'message' => 'Pick one of your own receipts'];
        }
        $s = $this->db->prepare("SELECT id, reason_note FROM penny_missing_receipts WHERE expense_id = ? AND status = 'received' AND id <> ?");
        $s->execute([$expenseId, $itemId]);
        if ($other = $s->fetch(PDO::FETCH_ASSOC)) {
            // The person's own answer beats Penny's amount-and-date guess (onReceipt): reopen that one.
            if (!preg_match('/^Receipt #\d+ came in$/', (string)$other['reason_note'])) {
                return ['ok' => false, 'message' => 'That receipt already answers another charge'];
            }
            $this->db->prepare("UPDATE penny_missing_receipts SET status = 'open', expense_id = NULL, resolved_by = NULL, resolved_at = NULL, reason_note = NULL
                                WHERE id = ?")->execute([(int)$other['id']]);
        }
        $this->close($itemId, 'received', $expenseId, (int)$user['id'], 'Receipt #' . $expenseId . ' — from the crew card');
        return ['ok' => true, 'message' => 'Got it — thanks! Penny will match it to the bank line.', 'status' => 'received'];
    }

    /** "No receipt": lost / not available — Penny stops asking; Tim is told (no GST claim). */
    public function noReceipt(int $itemId, string $reason, string $note, array $user): array
    {
        if (!isset(self::REASONS[$reason])) return ['ok' => false, 'message' => 'Pick a reason'];
        $item = $this->item($itemId);
        if (!$item || !$this->canTouch($item, $user)) return ['ok' => false, 'message' => 'That one isn\'t yours to answer'];
        if ($item['status'] !== 'open') return ['ok' => true, 'message' => 'Already sorted', 'status' => $item['status']];
        $s = $this->db->prepare("
            UPDATE penny_missing_receipts
               SET status = 'no_receipt', reason = ?, reason_note = ?, resolved_by = ?, resolved_at = ?, next_nudge_at = NULL
             WHERE id = ? AND status = 'open'
        ");
        $s->execute([$reason, mb_substr(trim($note), 0, 255) ?: null, (int)$user['id'], $this->now()->format('Y-m-d H:i:s'), $itemId]);
        return ['ok' => true, 'message' => 'Thanks — I\'ll stop asking. I\'ve let Tim know.', 'status' => 'no_receipt'];
    }

    /** Recent receipts that might be the one ("It's already in"). */
    public function recentReceipts(int $itemId, array $user, int $limit = 10): array
    {
        $item = $this->item($itemId);
        if (!$item || !$this->canTouch($item, $user)) return [];
        $mine = self::isManager($user) ? '' : ' AND e.created_by = ?';
        $s = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, COALESCE(v.name, e.vendor_name_raw) AS vendor, e.status, e.created_by
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN accounting_transactions bt ON bt.matched_expense_id = e.id
            LEFT JOIN penny_missing_receipts pm ON pm.expense_id = e.id AND pm.status = 'received'
            WHERE e.status NOT IN ('rejected', 'cancelled')
              AND bt.id IS NULL AND pm.id IS NULL
              AND e.expense_date BETWEEN DATE_SUB(?, INTERVAL 10 DAY) AND DATE_ADD(?, INTERVAL 10 DAY)" . $mine . "
            ORDER BY ABS(e.total - ?) ASC, ABS(DATEDIFF(e.expense_date, ?)) ASC
            LIMIT " . max(1, min(30, $limit)));
        $args = [$item['charge_date'], $item['charge_date']];
        if ($mine) $args[] = (int)$user['id'];
        $args[] = (float)$item['amount'];
        $args[] = $item['charge_date'];
        $s->execute($args);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['id'], 'date' => substr((string)$r['expense_date'], 0, 10),
                      'vendor' => (string)($r['vendor'] ?: 'Receipt'), 'total' => round((float)$r['total'], 2),
                      'same_amount' => abs((float)$r['total'] - (float)$item['amount']) <= max(0.5, (float)$item['amount'] * 0.02)];
        }
        return $out;
    }

    /**
     * ExpenseGate hook: a receipt was saved / changed — does it close an open item?
     * Same amount (±2 %, at least 50¢) and ±3 days; one item, or the one belonging to whoever saved it.
     * @return ?int the item closed
     */
    public function onReceipt(int $expenseId): ?int
    {
        $s = $this->db->prepare("SELECT id, total, expense_date, created_by, status FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || in_array($e['status'], ['rejected', 'cancelled'], true) || (float)$e['total'] <= 0) return null;
        $s = $this->db->prepare("SELECT 1 FROM penny_missing_receipts WHERE expense_id = ? AND status = 'received' LIMIT 1");
        $s->execute([$expenseId]);
        if ($s->fetchColumn()) return null;
        $total = (float)$e['total'];
        $s = $this->db->prepare("
            SELECT id, user_id FROM penny_missing_receipts
            WHERE status = 'open'
              AND ABS(amount - ?) <= GREATEST(0.5, ? * 0.02)
              AND charge_date BETWEEN DATE_SUB(?, INTERVAL " . self::MATCH_DAYS . " DAY) AND DATE_ADD(?, INTERVAL " . self::MATCH_DAYS . " DAY)
        ");
        $s->execute([$total, $total, $e['expense_date'], $e['expense_date']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $pick = self::pickForReceipt($rows, (int)$e['created_by']);
        if ($pick === null) return null;
        return $this->close($pick, 'received', $expenseId, (int)$e['created_by'] ?: null, 'Receipt #' . $expenseId . ' came in') ? $pick : null;
    }

    /** Pure: which open item a new receipt closes — the only one, or the only one of its saver's. */
    public static function pickForReceipt(array $rows, int $createdBy): ?int
    {
        if (count($rows) === 1) return (int)$rows[0]['id'];
        $mine = array_values(array_filter($rows, fn($r) => (int)$r['user_id'] === $createdBy && $createdBy > 0));
        return count($mine) === 1 ? (int)$mine[0]['id'] : null;
    }

    /** Hook entry point — never throws (the receipt is already saved). */
    public static function onReceiptQuietly(PDO $db, int $expenseId): void
    {
        try {
            $svc = new self($db);
            if ($svc->ready()) $svc->onReceipt($expenseId);
        } catch (Throwable $e) {
            error_log('MissingReceipt onReceipt #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tim's lists: exempt rules and card holders
    // ─────────────────────────────────────────────────────────────────────────

    public function saveRule(string $matchOn, string $pattern, string $label, ?int $userId): array
    {
        $pattern = trim($pattern);
        if (!in_array($matchOn, self::MATCH_ON, true) || $pattern === '' || mb_strlen($pattern) > 80) {
            return ['ok' => false, 'message' => 'Give the rule a pattern'];
        }
        $label = trim($label) !== '' ? mb_substr(trim($label), 0, 80) : $pattern;
        $this->db->prepare("INSERT INTO penny_receipt_exempt_rules (match_on, pattern, label, active, created_by) VALUES (?, ?, ?, 1, ?)
                            ON DUPLICATE KEY UPDATE label = VALUES(label), active = 1")->execute([$matchOn, $pattern, $label, $userId]);
        // Open items it now covers stop being chased at the next scan; nothing else to do.
        return ['ok' => true, 'message' => 'Penny won\'t ask about those'];
    }

    /** Turn a rule off. Lines it had set aside are looked at again on the next scan. */
    public function deleteRule(int $id): array
    {
        $s = $this->db->prepare("SELECT match_on, pattern FROM penny_receipt_exempt_rules WHERE id = ?");
        $s->execute([$id]);
        if (!$s->fetch()) return ['ok' => false, 'message' => 'Rule not found'];
        $this->db->prepare("UPDATE penny_receipt_exempt_rules SET active = 0 WHERE id = ?")->execute([$id]);
        // Lines set aside as exempt are re-judged: forget them so the next scan decides again.
        $this->db->exec("DELETE FROM penny_missing_receipts WHERE status = 'exempt' AND expense_id IS NULL AND resolved_by IS NULL");
        return ['ok' => true, 'message' => 'Rule off — Penny will look at those lines again'];
    }

    public function cards(): array
    {
        return $this->db->query("SELECT h.card_last4, h.user_id, h.label, u.full_name FROM penny_card_holders h
                                 LEFT JOIN users u ON u.id = h.user_id ORDER BY h.card_last4")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveCard(string $last4, ?int $holderId, string $label, ?int $byUser): array
    {
        if (!preg_match('/^\d{4}$/', $last4)) return ['ok' => false, 'message' => 'Last 4 digits of the card'];
        $this->db->prepare("INSERT INTO penny_card_holders (card_last4, user_id, label, updated_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), label = VALUES(label), updated_by = VALUES(updated_by)")
                 ->execute([$last4, $holderId ?: null, mb_substr(trim($label), 0, 60) ?: null, $byUser]);
        // Open items on that card that nobody has nudged yet go to the card's holder.
        if ($holderId) {
            $this->db->prepare("UPDATE penny_missing_receipts SET user_id = ?, basis = 'card', basis_note = ?
                                WHERE status = 'open' AND card_last4 = ? AND nudges = 0")
                     ->execute([$holderId, 'Card ••' . $last4 . ' — from Tim\'s card list', $last4]);
        }
        return ['ok' => true, 'message' => 'Card ••' . $last4 . ' saved'];
    }

    public function deleteCard(string $last4): array
    {
        $this->db->prepare("DELETE FROM penny_card_holders WHERE card_last4 = ?")->execute([$last4]);
        return ['ok' => true, 'message' => 'Card removed'];
    }

    /** People Tim can give a card to. */
    public function people(): array
    {
        return $this->db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Reassign an item to someone else (Tim, from the dashboard). */
    public function reassign(int $itemId, int $userId, ?int $byUser): array
    {
        $s = $this->db->prepare("UPDATE penny_missing_receipts SET user_id = ?, basis = 'manual', basis_note = 'Tim chose who to ask', nudges = 0,
                                        next_nudge_at = ? WHERE id = ? AND status = 'open'");
        $s->execute([$userId, $this->now()->format('Y-m-d H:i:s'), $itemId]);
        return $s->rowCount() ? ['ok' => true, 'message' => 'Penny will ask them'] : ['ok' => false, 'message' => 'Item not open'];
    }
}
