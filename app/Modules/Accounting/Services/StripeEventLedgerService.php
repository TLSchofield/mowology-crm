<?php
/**
 * StripeEventLedgerService — Stripe disputes and refunds reach the books (security review #21, 2026-10-12).
 *
 * Before this, a chargeback or a refund changed nothing in the CRM: the invoice stayed paid and its
 * income stayed booked. Now, from the signed webhook (public/api/stripe/webhook.php):
 *
 *   charge.dispute.created  → the invoice is flagged dispute_status = 'disputed'; the money Stripe took
 *                             back is posted DR 1100 Accounts Receivable / CR 1010 Bank (the customer
 *                             owes it again); an activity_log 'stripe_dispute' row makes Charlie email
 *                             Tim at once (CharlieUrgentService).
 *   charge.dispute.closed   → won: that entry is reversed (the money came back), flag 'won';
 *                             lost: flag 'lost', the entry stays.
 *   charge.refunded         → the newly refunded amount (Stripe sends the running total) is posted
 *                             DR 2160 Customer Deposits / CR 1010 Bank — refunds go through Customer
 *                             Deposits, never an expense (Penny traps, 2026-10-12). Whether the invoice's
 *                             revenue should also come out is a person's call (a refund is often a
 *                             duplicate-charge reversal), so the invoice is not changed; it is logged
 *                             as 'stripe_refund' for Penny / Tim.
 *
 * Idempotent per Stripe event id (stripe_webhook_events, migration 1342): a redelivered event does
 * nothing. Penny never moves money — this only RECORDS what Stripe already did.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/LedgerService.php';

class StripeEventLedgerService
{
    public const ACC_DEPOSITS = '2160';

    private PDO $db;
    private LedgerService $ledger;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
    }

    /** stripe_webhook_events exists (migration 1342). */
    public function ready(): bool
    {
        try {
            $this->db->query("SELECT event_id FROM stripe_webhook_events LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * charge.dispute.created.
     * @param array{event_id: string, dispute_id: string, charge_id: ?string, payment_intent_id: ?string, amount_cents: int, reason?: ?string, event_time?: int} $d
     * @return array{status: string, invoice_id?: int, entry_id?: ?int}
     */
    public function disputeCreated(array $d): array
    {
        if (!$this->ready()) return ['status' => 'not_ready'];
        $invoice = $this->invoiceFor($d['payment_intent_id'] ?? null, $d['charge_id'] ?? null);
        return $this->once($d['event_id'], 'charge.dispute.created', (string)$d['dispute_id'], (int)$d['amount_cents'], function (int $eventRow) use ($d, $invoice) {
            $amount = round((int)$d['amount_cents'] / 100, 2);
            $invoiceId = $invoice ? (int)$invoice['id'] : null;
            $early = $this->db->prepare("SELECT id FROM stripe_webhook_events WHERE event_type = 'charge.dispute.won_early' AND object_id = ? LIMIT 1");
            $early->execute([(string)$d['dispute_id']]);
            if ($early->fetchColumn()) {
                if ($invoiceId) {
                    $this->db->prepare("UPDATE invoices SET dispute_status = 'won' WHERE id = ?")->execute([$invoiceId]);
                }
                $this->log('stripe_dispute_won_before_created', $invoiceId, ['dispute_id' => $d['dispute_id']]);
                return ['status' => 'done', 'invoice_id' => $invoiceId, 'entry_id' => null];
            }
            if ($invoiceId) {
                $this->db->prepare("UPDATE invoices SET dispute_status = 'disputed', disputed_at = NOW() WHERE id = ?")->execute([$invoiceId]);
            }
            $entryId = $this->post('stripe_dispute', $eventRow, $amount, LedgerService::ACC_AR, $this->stripeCash($d['event_time'] ?? null),
                'Stripe dispute ' . $d['dispute_id'] . ($invoice ? ' — invoice ' . $invoice['invoice_number'] : '') . ' (' . ($d['reason'] ?? 'no reason given') . ')',
                $invoice['contact_id'] ?? null, $d['event_time'] ?? null);
            $this->log('stripe_dispute', $invoiceId, [
                'dispute_id' => $d['dispute_id'], 'charge_id' => $d['charge_id'] ?? null, 'amount' => $amount,
                'reason' => $d['reason'] ?? null, 'invoice_number' => $invoice['invoice_number'] ?? null, 'journal_entry_id' => $entryId,
            ]);
            return ['status' => 'done', 'invoice_id' => $invoiceId, 'entry_id' => $entryId];
        });
    }

    /**
     * charge.dispute.closed — won: reverse the dispute entry; lost (or anything else): keep it.
     * @param array{event_id: string, dispute_id: string, charge_id: ?string, payment_intent_id: ?string, status: string, event_time?: int} $d
     */
    public function disputeClosed(array $d): array
    {
        if (!$this->ready()) return ['status' => 'not_ready'];
        $invoice = $this->invoiceFor($d['payment_intent_id'] ?? null, $d['charge_id'] ?? null);
        return $this->once($d['event_id'], 'charge.dispute.closed', (string)$d['dispute_id'], 0, function () use ($d, $invoice) {
            $won = ($d['status'] ?? '') === 'won';
            $invoiceId = $invoice ? (int)$invoice['id'] : null;
            if ($invoiceId) {
                $this->db->prepare("UPDATE invoices SET dispute_status = ? WHERE id = ?")->execute([$won ? 'won' : 'lost', $invoiceId]);
            }
            $reversed = null;
            if ($won) {
                $s = $this->db->prepare("SELECT id FROM stripe_webhook_events WHERE event_type = 'charge.dispute.created' AND object_id = ?");
                $s->execute([(string)$d['dispute_id']]);
                $createdRows = $s->fetchAll(PDO::FETCH_COLUMN);
                $hasDisputeEntry = false;
                foreach ($createdRows as $rowId) {
                    $eid = $this->ledger->findEntryIdBySource('stripe_dispute', (int)$rowId);
                    if ($eid !== null) {
                        $hasDisputeEntry = true;
                        $reversed = $this->ledger->reverseEntry($eid, null, 'Stripe dispute ' . $d['dispute_id'] . ' won — money returned', 'penny');
                    }
                }
                if (!$hasDisputeEntry) {
                    $this->db->prepare("INSERT INTO stripe_webhook_events (event_id, event_type, object_id, amount_cents, processed_at) VALUES (?, 'charge.dispute.won_early', ?, 0, NOW())")
                             ->execute([(string)$d['event_id'] . ':early', (string)$d['dispute_id']]);
                }
            }
            $this->log('stripe_dispute_closed', $invoiceId, ['dispute_id' => $d['dispute_id'], 'status' => $d['status'] ?? null, 'reversal_entry_id' => $reversed]);
            return ['status' => 'done', 'invoice_id' => $invoiceId, 'entry_id' => $reversed];
        });
    }

    /**
     * charge.refunded — Stripe sends the charge's running refunded total; only the new part is booked.
     * @param array{event_id: string, charge_id: string, payment_intent_id: ?string, amount_refunded_cents: int, event_time?: int} $d
     */
    public function refunded(array $d): array
    {
        if (!$this->ready()) return ['status' => 'not_ready'];
        $lock = 'stripe_refund_' . md5((string)$d['charge_id']);
        $hasAdvisoryLock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($hasAdvisoryLock) {
            $got = $this->db->prepare('SELECT GET_LOCK(?, 10)');
            $got->execute([$lock]);
            if ((int)$got->fetchColumn() !== 1) throw new RuntimeException('Could not lock Stripe refund charge');
        }
        try {
            $invoice = $this->invoiceFor($d['payment_intent_id'] ?? null, $d['charge_id'] ?? null);
            $s = $this->db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM stripe_webhook_events WHERE event_type = 'charge.refunded' AND object_id = ?");
            $s->execute([(string)$d['charge_id']]);
            $delta = max(0, (int)$d['amount_refunded_cents'] - (int)$s->fetchColumn());
            return $this->once($d['event_id'], 'charge.refunded', (string)$d['charge_id'], $delta, function (int $eventRow) use ($d, $invoice, $delta) {
            if ($delta <= 0) return ['status' => 'nothing_new'];
            $amount = round($delta / 100, 2);
            $invoiceId = $invoice ? (int)$invoice['id'] : null;
            $entryId = $this->post('stripe_refund', $eventRow, $amount, self::ACC_DEPOSITS, $this->stripeCash($d['event_time'] ?? null),
                'Stripe refund on charge ' . $d['charge_id'] . ($invoice ? ' — invoice ' . $invoice['invoice_number'] : '') . ' (through Customer Deposits)',
                $invoice['contact_id'] ?? null, $d['event_time'] ?? null);
            $this->log('stripe_refund', $invoiceId, ['charge_id' => $d['charge_id'], 'amount' => $amount,
                       'invoice_number' => $invoice['invoice_number'] ?? null, 'journal_entry_id' => $entryId,
                       'note' => 'Invoice left as it is — decide whether its revenue comes out (duplicate charge vs real refund).']);
            return ['status' => 'done', 'invoice_id' => $invoiceId, 'entry_id' => $entryId];
            });
        } finally {
            if ($hasAdvisoryLock) {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lock]);
            }
        }
    }

    /** Run $fn once per Stripe event id, in one transaction with the claim. */
    private function once(string $eventId, string $type, string $objectId, int $amountCents, callable $fn): array
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            try {
                $this->db->prepare("INSERT INTO stripe_webhook_events (event_id, event_type, object_id, amount_cents, processed_at) VALUES (?, ?, ?, ?, NOW())")
                         ->execute([$eventId, $type, $objectId, $amountCents]);
            } catch (PDOException $e) {
                if ($own && $this->db->inTransaction()) $this->db->rollBack();
                if ((string)$e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false || stripos($e->getMessage(), 'duplicate') !== false) {
                    return ['status' => 'duplicate'];
                }
                throw $e;
            }
            $r = $fn((int)$this->db->lastInsertId());
            if ($own) $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Post DR $debit / CR $credit. A ledger failure is logged, never lost: the alert says to post it by hand. */
    /** Where Stripe takes money back from: 1015 Stripe clearing once the holding switch is on, else chequing. */
    private function stripeCash(?int $eventTime = null): string
    {
        return LedgerService::paymentAccount('stripe_payment', $this->bookingDate($eventTime), $this->ledger->holdingFrom());
    }

    private function post(string $source, int $sourceId, float $amount, string $debit, string $credit, string $memo, $contactId, ?int $eventTime = null): ?int
    {
        if ($amount <= 0) return null;
        try {
            return $this->ledger->postManual([
                'entry_date' => $this->bookingDate($eventTime), 'memo' => $memo, 'source_type' => $source, 'source_id' => $sourceId, 'proposed_by' => 'penny',
                'lines' => [
                    ['account' => $debit, 'debit' => $amount, 'credit' => 0, 'contact_id' => $contactId ? (int)$contactId : null, 'description' => $memo],
                    ['account' => $credit, 'debit' => 0, 'credit' => $amount, 'contact_id' => $contactId ? (int)$contactId : null, 'description' => $memo],
                ],
            ]);
        } catch (Throwable $e) {
            error_log('[StripeEventLedger] ' . $source . ' not posted: ' . $e->getMessage());
            $this->log('stripe_ledger_failed', null, ['source' => $source, 'source_id' => $sourceId, 'amount' => $amount, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** The event's own date, unless that month is already closed: a late event books today instead of failing the webhook for ever. */
    private function bookingDate(?int $eventTime): string
    {
        $d = self::entryDate($eventTime);
        try {
            return $this->ledger->isLocked($d) ? date('Y-m-d') : $d;
        } catch (Throwable $e) {
            return date('Y-m-d');
        }
    }

    public static function entryDate(?int $eventTime): string
    {
        if (!$eventTime || $eventTime < 1) return date('Y-m-d');
        $date = new DateTimeImmutable('@' . $eventTime);
        return $date->setTimezone(new DateTimeZone('America/Vancouver'))->format('Y-m-d');
    }

    /** The invoice a charge paid: by PaymentIntent (stripe_payments / invoices), else by charge id. */
    private function invoiceFor(?string $pi, ?string $charge): ?array
    {
        $q = [];
        if ($pi) {
            $q[] = ["SELECT i.id, i.invoice_number, i.contact_id FROM stripe_payments sp JOIN invoices i ON i.id = sp.invoice_id WHERE sp.payment_intent_id = ? LIMIT 1", $pi];
            $q[] = ["SELECT id, invoice_number, contact_id FROM invoices WHERE stripe_payment_intent_id = ? LIMIT 1", $pi];
        }
        if ($charge) $q[] = ["SELECT id, invoice_number, contact_id FROM invoices WHERE stripe_charge_id = ? LIMIT 1", $charge];
        foreach ($q as [$sql, $v]) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute([$v]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) return $r;
            } catch (Throwable $e) { /* column missing — try the next */ }
        }
        return null;
    }

    private function log(string $action, ?int $invoiceId, array $details): void
    {
        $this->db->prepare("INSERT INTO activity_log (user_id, action, details, invoice_id, created_at) VALUES (NULL, ?, ?, ?, NOW())")
                 ->execute([$action, json_encode($details), $invoiceId]);
    }

    /**
     * Pure: the stripe_payments upsert for payment_intent.payment_failed. A late or out-of-order failure
     * event must never turn a succeeded payment back into 'failed' (security review, low).
     */
    public static function paymentFailedUpsertSql(): string
    {
        return "INSERT INTO stripe_payments
                    (invoice_id, payment_intent_id, amount_cents, currency,
                     status, failure_code, failure_message,
                     raw_event_type, webhook_received_at)
                VALUES (?, ?, ?, 'cad', 'failed', ?, ?, 'payment_intent.payment_failed', NOW())
                ON DUPLICATE KEY UPDATE
                    failure_code        = IF(status = 'succeeded', failure_code, VALUES(failure_code)),
                    failure_message     = IF(status = 'succeeded', failure_message, VALUES(failure_message)),
                    raw_event_type      = IF(status = 'succeeded', raw_event_type, VALUES(raw_event_type)),
                    webhook_received_at = IF(status = 'succeeded', webhook_received_at, NOW()),
                    status              = IF(status = 'succeeded', status, 'failed')";
    }
}
