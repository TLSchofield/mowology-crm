<?php
/**
 * MiaThanksService — Penny records a payment, Mia drafts the thank-you (Tim, 2026-11-05:
 * "Penny should hand the thank you email to Mia because it's a marketing opportunity").
 *
 *   sweep()  new rows in invoice_payment_allocations (the one place payments are recorded,
 *            InvoiceReconciliationService::applyAllocation()) become one draft per payment. The
 *            first run ever only sets the cursor: old payments never become a surprise backlog.
 *            A failed payment stops the cursor there, so it is tried again next run.
 *   tidy()   a thank-you is only welcome while it's fresh: an ask still unapproved after a day is
 *            redrafted as a plain thank-you; an unapproved one after 5 days expires.
 *
 * The ask (fall cleanup only for now, until services are streamlined) goes in when the property
 * had a priced fall cleanup around this date before (MiaSeasonalHistory), nothing is booked this
 * season, no invoice is more than mia_thanks_overdue_days late (60; Tim: "if they are not horribly
 * overdue we need to do the work"), Sam has no open quote, they aren't muted, and consent allows
 * marketing email. Property managers get a plain thank-you. A claimed e-Transfer folds Penny's
 * "please use the auto-deposit address" request into the same email (EtransferNudgeService hands
 * it over while this is on).
 *
 * Nothing is sent here: MiaDeskService queues it on Tim's tap and sends it at the person's best
 * time. Plan: docs/plans/2026-11-05-payment-thank-you-seasonal-ask.md. Switch:
 * ops_settings.mia_payment_thanks_enabled ('1' default, '0' off). Needs migration 1437.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MiaWording.php';
require_once __DIR__ . '/MiaSeasonalHistory.php';
require_once __DIR__ . '/MiaFinder.php';
require_once dirname(__DIR__, 2) . '/Accounting/Services/EtransferNudgeService.php';

class MiaThanksService
{
    public const KIND = 'payment_thanks';
    /** Real money in. Not 'other' (adjustments) or 'yardi_eft' (a property manager's batch payment). */
    public const PAYMENT_METHODS = ['e_transfer', 'cheque', 'cash', 'credit_card', 'debit', 'stripe'];
    /** Unpaid invoices, as EtransferInboxService::PAYABLE (a draft isn't owed yet). */
    private const OWING = ['sent', 'viewed', 'partial', 'overdue'];

    private const BATCH = 500;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** On, and migration 1437 has run. */
    public static function enabled(PDO $db): bool
    {
        try {
            $db->query("SELECT scheduled_at, sent_at, send_error FROM mia_suggestions LIMIT 0");
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute(['mia_payment_thanks_enabled']);
            $v = $s->fetchColumn();
            return $v === false || trim((string)$v) !== '0';
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function isPaymentMethod($method): bool
    {
        return in_array(strtolower(trim((string)$method)), self::PAYMENT_METHODS, true);
    }

    /** @return array{created: int} */
    public function sweep(DateTimeImmutable $today): array
    {
        try {
            $cursor = $this->setting('mia_thanks_last_alloc_id');
            if ($cursor === null) {
                $max = (int)$this->db->query("SELECT COALESCE(MAX(id), 0) FROM invoice_payment_allocations")->fetchColumn();
                $this->setSetting('mia_thanks_last_alloc_id', (string)$max);
                return ['created' => 0];
            }
            $s = $this->db->prepare("
                SELECT a.id, a.invoice_id, a.etransfer_notification_id, a.transaction_id, a.reference, a.amount,
                       a.payment_date, a.method, i.contact_id, i.company_id, i.client_id, i.property_id, i.invoice_number,
                       co.company_name, p.property_manager_id
                FROM invoice_payment_allocations a
                JOIN invoices i ON i.id = a.invoice_id
                LEFT JOIN companies co ON co.id = i.company_id
                LEFT JOIN properties p ON p.id = i.property_id
                WHERE a.id > ?
                ORDER BY a.id
                LIMIT " . self::BATCH . "
            ");
            $s->execute([(int)$cursor]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Mia thanks sweep: ' . $e->getMessage());
            return ['created' => 0];
        }

        // One payment = the same payer, day and reference (an e-Transfer paying four invoices is one).
        $groups = [];
        $last = (int)$cursor;
        foreach ($rows as $r) {
            if (!self::isPaymentMethod($r['method'] ?? null) || (float)$r['amount'] <= 0) {
                $last = max($last, (int)$r['id']);
                continue;
            }
            $who = !empty($r['contact_id']) ? 'c' . $r['contact_id'] : 'o' . (int)$r['company_id'];
            $ref = $r['etransfer_notification_id'] ?? $r['transaction_id'] ?? $r['reference'] ?? '';
            $groups[$who . '|' . substr((string)$r['payment_date'], 0, 10) . '|' . $ref][] = $r;
        }

        // A full batch may cut the last payment in two: leave it for the next run, whole.
        if (count($rows) === self::BATCH && count($groups) > 1) {
            $cut = array_pop($groups);
            $last = min($last, min(array_map(fn($r) => (int)$r['id'], $cut)) - 1);
        }
        $created = 0;
        foreach ($groups as $group) {
            $ids = array_map(fn($r) => (int)$r['id'], $group);
            try {
                if ($this->create($group, $today)) {
                    $created++;
                } elseif (!empty($group[0]['etransfer_notification_id'])) {
                    // Nobody Mia can thank: Penny's own auto-deposit request still goes (it was handed over to Mia).
                    (new EtransferNudgeService($this->db))->onNotification((int)$group[0]['etransfer_notification_id'], EtransferNudgeService::TRIGGER_MIA_SKIPPED);
                }
                $last = max($last, max($ids));
            } catch (Throwable $e) {
                // Stop before this payment so the next run tries it again (drafts are keyed, so no repeats).
                $last = min($last, min($ids) - 1);
                error_log('Mia thanks sweep: ' . $e->getMessage());
                break;
            }
        }
        $this->setSetting('mia_thanks_last_alloc_id', (string)$last);
        return ['created' => $created];
    }

    /** One payment → one draft. False when there's nobody to thank. */
    private function create(array $rows, DateTimeImmutable $today): bool
    {
        $first = $rows[0];
        $key = 'mia:thanks:' . (int)$first['id'];
        $exists = $this->db->prepare("SELECT 1 FROM mia_suggestions WHERE kind = ? AND subject_key = ?");
        $exists->execute([self::KIND, $key]);
        if ($exists->fetchColumn()) return false;

        // Where this invoice's emails go (its send snapshot, PM routing, then its contact) — as Penny's reminders.
        require_once dirname(__DIR__, 2) . '/Invoices/Services/InvoiceRouting.php';
        // Never thank someone who has left the account (a strata rep who sold her unit, 2026-11-07): the current person takes over.
        require_once dirname(__DIR__, 2) . '/Clients/Services/ContactRoster.php';
        $recipient = (new ContactRoster($this->db))->firstCurrent(
            resolveReminderRecipients((int)$first['invoice_id'], $this->db),
            (int)($first['contact_id'] ?? 0), (int)($first['client_id'] ?? 0), 'thanks', $today
        );
        if (!$recipient) return false;
        $email = strtolower(trim((string)$recipient['email_address']));
        if (EtransferNudgeService::isBusinessEmail($email)) return false;
        $cid = (int)($recipient['contact_id'] ?? 0) ?: (int)($first['contact_id'] ?? 0);
        if ($cid <= 0) return false;   // Mia's desk works per contact (consent, mutes, outcomes)

        $c = $this->db->prepare("SELECT * FROM contacts WHERE id = ?");
        $c->execute([$cid]);
        $contact = $c->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$contact || MiaFinder::isTest($contact + ['company_name' => $first['company_name'] ?? ''])) return false;
        // Consent is the contact's: an ask only goes to the contact's own address, never to another address on the invoice.
        $ownAddress = (int)($recipient['contact_id'] ?? 0) === $cid || strtolower(trim((string)($contact['email'] ?? ''))) === $email;

        $amount = round(array_sum(array_map(fn($r) => (float)$r['amount'], $rows)), 2);
        $numbers = array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['invoice_number'], $rows))));
        $pid = !empty($first['property_id']) ? (int)$first['property_id'] : null;

        $in = implode(',', array_fill(0, count(self::OWING), '?'));
        // A person who just took over the account answers for the account, not for their own contact record.
        $replaced = !empty($recipient['replaced']);
        $owing = $this->db->prepare("SELECT COUNT(*) FROM invoices WHERE " . ($replaced ? 'client_id' : 'contact_id') . " = ? AND balance_due > 0.005 AND status IN ($in)");
        $owing->execute(array_merge([$replaced ? (int)$first['client_id'] : $cid], self::OWING));
        $cleared = (int)$owing->fetchColumn() === 0;

        $history = null;
        // Someone new on the account gets a plain thank-you: last year's work wasn't arranged with them.
        $why = $replaced ? 'new_contact' : ($ownAddress ? $this->whyNoAsk($cid, $pid, $first, $today, $history) : 'other_address');
        $ask = $why === null;

        // A claimed e-Transfer: Penny's one-time "please use the auto-deposit address" goes in this email.
        $nudgeId = !empty($first['etransfer_notification_id']) ? (int)$first['etransfer_notification_id'] : null;
        $address = null;
        if ($nudgeId) {
            try {
                $n = $this->db->prepare("SELECT * FROM etransfer_notifications WHERE id = ?");
                $n->execute([$nudgeId]);
                $note = $n->fetch(PDO::FETCH_ASSOC);
                if ($note && !empty((new EtransferNudgeService($this->db))->plan($note)['send'])) {
                    $address = EtransferNudgeService::autodepositAddress();
                } else {
                    $nudgeId = null;
                }
            } catch (Throwable $e) {
                $nudgeId = null;
            }
        }

        $draft = MiaWording::thanksDraft((string)($contact['first_name'] ?? ''), $amount, $cleared, $ask ? $history : null, $address, $today);
        $reason = [
            'amount' => $amount, 'invoices' => $numbers, 'cleared' => $cleared, 'ask' => $ask,
            'history' => $history, 'why_no_ask' => $why, 'nudge_notification_id' => $nudgeId,
            'recipient_email' => $email, 'priority' => 0, 'value' => $amount,
        ];
        $this->db->prepare("
            INSERT INTO mia_suggestions (kind, subject_key, contact_id, company_id, property_id, reason_json, draft_subject, draft_body, draft_sms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, '')
        ")->execute([self::KIND, $key, $cid, $first['company_id'] ?: null, $pid, json_encode($reason), $draft['subject'], $draft['body']]);
        $this->push((string)($contact['first_name'] ?? ''), $ask);
        return true;
    }

    /** null = ask about the fall cleanup; else the reason not to (Mia's card says it in words). */
    private function whyNoAsk(int $cid, ?int $pid, array $first, DateTimeImmutable $today, ?array &$history): ?string
    {
        if (!empty($first['property_manager_id']) || !empty($first['company_id'])) return 'pm';
        if (!MiaSeasonalHistory::askSeason($today)) return 'not_season';
        $seasonal = new MiaSeasonalHistory($this->db);
        $history = $seasonal->lastFallCleanup($cid, $pid, $today);
        if (!$history) return 'no_history';
        if ($seasonal->alreadyThisSeason($cid, $pid, $today)) return 'done_this_season';
        if ($this->overdue($cid, $today)) return 'overdue';
        if (isset((new MiaFinder($this->db))->samOwns($today)[$cid])) return 'sam';
        if ($this->muted($cid, $today)) return 'muted';
        require_once dirname(__DIR__, 2) . '/Consent/Services/ConsentLedgerService.php';
        if (empty((new ConsentLedgerService($this->db))->allows($cid, 'email')['ok'])) return 'no_consent';
        return null;
    }

    private function overdue(int $cid, DateTimeImmutable $today): bool
    {
        $days = (int)($this->setting('mia_thanks_overdue_days') ?? 60);
        $in = implode(',', array_fill(0, count(self::OWING), '?'));
        $s = $this->db->prepare("SELECT COUNT(*) FROM invoices WHERE contact_id = ? AND balance_due > 0.005 AND status IN ($in) AND due_date < ?");
        $s->execute(array_merge([$cid], self::OWING, [$today->modify("-{$days} days")->format('Y-m-d')]));
        return (int)$s->fetchColumn() > 0;
    }

    private function muted(int $cid, DateTimeImmutable $today): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM mia_mutes WHERE subject_key = ? AND (until_date IS NULL OR until_date >= ?)");
        $s->execute(['mia:contact:' . $cid, $today->format('Y-m-d')]);
        return (bool)$s->fetchColumn();
    }

    /** Tim's phone: "Thank-you for Alice is ready, with a fall cleanup ask". Best effort. */
    private function push(string $first, bool $ask): void
    {
        try {
            $uid = (int)($this->setting('freedom_owner_user_id') ?? 0);
            if ($uid <= 0) return;
            if (!class_exists('PushDispatcher')) {
                require_once dirname(__DIR__, 3) . '/Services/Push/ApnsService.php';
                require_once dirname(__DIR__, 3) . '/Services/Push/PushDispatcher.php';
            }
            PushDispatcher::notifyUser($uid, 'Mia', 'Thank-you for ' . ($first !== '' ? $first : 'a customer') . ' is ready'
                . ($ask ? ', with a fall cleanup ask' : ''), ['route' => 'mia']);
        } catch (Throwable $e) {
            error_log('Mia thanks push: ' . $e->getMessage());
        }
    }

    public function tidy(DateTimeImmutable $now): void
    {
        try {
            $s = $this->db->prepare("SELECT * FROM mia_suggestions WHERE kind = ? AND status = 'open'");
            $s->execute([self::KIND]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $age = $now->getTimestamp() - strtotime((string)$r['created_at']);
                if ($age > 5 * 86400) {
                    $this->db->prepare("UPDATE mia_suggestions SET status = 'expired' WHERE id = ?")->execute([(int)$r['id']]);
                    continue;
                }
                $reason = json_decode((string)$r['reason_json'], true) ?: [];
                if ($age > 86400 && !empty($reason['ask'])) {
                    $d = $this->plainDraft($r, $reason, $now, 'late');
                    $this->db->prepare("UPDATE mia_suggestions SET reason_json = ?, draft_subject = ?, draft_body = ? WHERE id = ?")
                        ->execute([json_encode($d['reason']), $d['subject'], $d['body'], (int)$r['id']]);
                }
            }
        } catch (Throwable $e) {
            error_log('Mia thanks tidy: ' . $e->getMessage());
        }
    }

    /**
     * The same thank-you without the ask (kept: the auto-deposit request). Used when it's too late
     * for the ask, or consent / Sam changed before it went.
     * @return array{subject: string, body: string, reason: array}
     */
    public function plainDraft(array $sg, array $reason, DateTimeImmutable $now, string $why): array
    {
        $reason['ask'] = false;
        $reason['why_no_ask'] = $why;
        $f = $this->db->prepare("SELECT first_name FROM contacts WHERE id = ?");
        $f->execute([(int)$sg['contact_id']]);
        $d = MiaWording::thanksDraft((string)$f->fetchColumn(), (float)($reason['amount'] ?? 0), !empty($reason['cleared']), null,
            !empty($reason['nudge_notification_id']) ? EtransferNudgeService::autodepositAddress() : null, $now);
        return $d + ['reason' => $reason];
    }

    private function setting(string $key): ?string
    {
        $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    private function setSetting(string $key, string $value): void
    {
        $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?");
        $u->execute([$value, $key]);
        if ($u->rowCount() === 0 && $this->setting($key) === null) {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
        }
    }
}
