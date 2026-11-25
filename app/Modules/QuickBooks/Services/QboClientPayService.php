<?php
/**
 * QboClientPayService — how a business other than the original one gets paid (Tim 2026-11-09).
 *
 * Ittai launches no charges for those businesses. Their client pays on QuickBooks' own page:
 *   forInvoice()  the stored pay link for the customer invoice page, only when QuickBooks allows online payment.
 *   pullPaid()    reads the balance of unpaid invoices Ittai sent, and records what was paid there, so
 *                 Ittai's ledger and reminders stay right. Nothing else is ever read from QuickBooks.
 *
 * A payment that came from QuickBooks gets a qbo_links row (status from_qbo), so it is never sent back.
 * A balance that goes back up is said to the owner and never un-pays anything by itself.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QboSyncService.php';
if (!class_exists('InvoiceReconciliationService')) require_once dirname(__DIR__, 2) . '/Accounting/Services/InvoiceReconciliationService.php';

class QboClientPayService
{
    public const BATCH = 50;
    public const MAX_INVOICES = 200;
    /** The allocation's own method. invoices.payment_method is an ENUM without it, so that column says 'other'. */
    public const METHOD = 'quickbooks';

    private QboSyncService $sync;
    private QboConnectionService $conn;
    private PDO $db;
    /** @var callable(): int */
    private $clock;

    public function __construct(QboSyncService $sync, QboConnectionService $conn, ?callable $clock = null)
    {
        $this->sync = $sync;
        $this->conn = $conn;
        $this->db = $conn->db();
        $this->clock = $clock ?? 'time';
    }

    /**
     * Pure (tested): the link a client may be sent to, or null. QuickBooks returns a link even with online
     * payment off (seen on the test company 2026-11-09), and that page cannot take a payment.
     */
    public static function payUrl(array $qboInvoice): ?string
    {
        if (empty($qboInvoice['AllowOnlineCreditCardPayment']) && empty($qboInvoice['AllowOnlineACHPayment'])) return null;
        $url = trim((string)($qboInvoice['InvoiceLink'] ?? ''));
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (strpos($url, 'https://') !== 0 || ($host !== 'intuit.com' && substr($host, -11) !== '.intuit.com')) return null;
        return $url;
    }

    /**
     * Pure (tested): what to do with one invoice. $known = what Ittai already matched to QuickBooks;
     * $unsent = Ittai holds a payment not yet in QuickBooks, so its balance cannot be compared yet.
     * @return array{do:string, amount:float}  record | flag | wait | none
     */
    public static function step(float $total, float $balance, float $known, bool $unsent): array
    {
        if ($unsent) return ['do' => 'wait', 'amount' => 0.0];
        $paid = round($total - $balance, 2);
        $gap = round($paid - round($known, 2), 2);
        if ($gap > 0.005) return ['do' => 'record', 'amount' => $gap];
        if ($gap < -0.005) return ['do' => 'flag', 'amount' => -$gap];
        return ['do' => 'none', 'amount' => 0.0];
    }

    /**
     * The pay link for the customer page, or null: the page then shows the business's other ways to pay.
     * Reads only what the 15-minute job stored, so a public page never waits on QuickBooks.
     */
    public function forInvoice(int $invoiceId): ?string
    {
        if (Tenant::takesCards()) return null;
        try {
            $link = $this->invoiceLink($invoiceId);
            return !empty($link['pay_url']) ? (string)$link['pay_url'] : null;
        } catch (Throwable $e) {
            error_log('QuickBooks pay link, invoice ' . $invoiceId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * One run: inside the 15-minute QuickBooks job.
     * @return array{recorded:int, notes:list<string>}
     */
    public function pullPaid(): array
    {
        $out = ['recorded' => 0, 'notes' => []];
        $c = $this->conn->company();
        if (!$c) return $out;
        $s = $this->db->prepare("SELECT l.id AS link_id, l.*, i.id, i.invoice_number FROM qbo_links l
            JOIN invoices i ON i.id = l.entity_id
            WHERE l.site_id = ? AND l.realm_id = ? AND l.entity_type = 'invoice' AND l.status = 'sent' AND l.qbo_id IS NOT NULL
              AND LOWER(COALESCE(i.status, '')) IN ('sent', 'viewed', 'partial', 'overdue')
            ORDER BY i.id LIMIT " . self::MAX_INVOICES);
        $s->execute([$this->conn->siteId(), $c['realm_id']]);
        $byQbo = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $byQbo[(string)$r['qbo_id']] = $r;
        foreach (array_chunk(array_keys($byQbo), self::BATCH, false) as $ids) {
            $sql = 'SELECT * FROM Invoice WHERE Id IN (' . implode(', ', array_map(fn($i) => QboClient::quote((string)$i), $ids)) . ') MAXRESULTS ' . self::BATCH;
            $r = $this->conn->client()->queryWithPayLinks($sql);
            if (!$r['ok']) { $out['notes'][] = 'Paid status not read: ' . $r['error']; return $out; }
            foreach ((array)($r['data']['QueryResponse']['Invoice'] ?? []) as $q) {
                $inv = $byQbo[(string)($q['Id'] ?? '')] ?? null;
                if ($inv) $this->settle($inv, $q, $c, $out);
            }
        }
        return $out;
    }

    private function settle(array $inv, array $q, array $c, array &$out): void
    {
        $id = (int)$inv['id'];
        $url = self::payUrl($q);
        if (($inv['pay_url'] ?? null) !== $url) $this->db->prepare('UPDATE qbo_links SET pay_url = ? WHERE id = ?')->execute([$url, $inv['link_id']]);
        $known = 0.0; $unsent = false;
        foreach ($this->sync->paymentsOf($id) as $p) {
            $l = $this->paymentLink($p['key'], $c);
            if ($l && $l['qbo_id']) $known += (float)$l['qbo_total']; else $unsent = true;
        }
        $step = self::step((float)($q['TotalAmt'] ?? 0), (float)($q['Balance'] ?? 0), $known, $unsent);
        $label = (string)($inv['invoice_number'] ?: '#' . $id);
        if ($step['do'] === 'flag') {
            $out['notes'][] = sprintf('%s: $%s recorded as paid no longer shows as paid in QuickBooks. Check it there; Ittai has not changed it.', $label, number_format($step['amount'], 2));
            return;
        }
        if ($step['do'] !== 'record') return;
        $qboPaymentId = self::lastPaymentId($q);
        $date = $this->paymentDate($qboPaymentId);
        $this->db->beginTransaction();
        try {
            // Income stays on the invoice: this is the one place a customer payment is recorded.
            $applied = (new InvoiceReconciliationService($this->db))->applyAllocation($id, $step['amount'], 'other', 'QuickBooks', $date, null, 0);
            if ($applied === null) { $this->db->commit(); return; }
            $f = $this->db->prepare('SELECT MAX(id) FROM invoice_payment_allocations WHERE invoice_id = ?');
            $f->execute([$id]);
            $allocId = (int)$f->fetchColumn();
            $this->db->prepare('UPDATE invoice_payment_allocations SET method = ? WHERE id = ?')->execute([self::METHOD, $allocId]);
            QboLinkService::save($this->db, $this->conn->siteId(), (string)$c['realm_id'], 'payment', 'alloc:' . $allocId, $qboPaymentId, null,
                                 QboSyncService::requestId($this->conn->siteId(), 'frq', (string)$allocId), 'from_qbo', (float)$applied['applied'],
                                 'Paid in QuickBooks', date('Y-m-d H:i:s', ($this->clock)()));
            $this->db->commit();
            $out['recorded']++;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('QuickBooks paid status, invoice ' . $id . ': ' . $e->getMessage());
            $out['notes'][] = $label . ': the payment from QuickBooks could not be recorded.';
        }
    }

    /** Pure (tested): the QuickBooks payment behind a balance change; a filler when it names none, so qbo_id is set. */
    public static function lastPaymentId(array $qboInvoice): string
    {
        $id = 'qbo';
        foreach ((array)($qboInvoice['LinkedTxn'] ?? []) as $t) {
            if (($t['TxnType'] ?? '') === 'Payment' && !empty($t['TxnId'])) $id = (string)$t['TxnId'];
        }
        return $id;
    }

    /** The day QuickBooks took the payment, so a delayed run does not date it today. */
    private function paymentDate(string $qboPaymentId): string
    {
        $today = date('Y-m-d', ($this->clock)());
        if ($qboPaymentId === 'qbo') return $today;
        $r = $this->conn->client()->query('SELECT * FROM Payment WHERE Id = ' . QboClient::quote($qboPaymentId));
        $d = $r['ok'] ? (string)($r['data']['QueryResponse']['Payment'][0]['TxnDate'] ?? '') : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : $today;
    }

    private function invoiceLink(int $invoiceId): ?array
    {
        $c = $this->conn->company();
        if (!$c) return null;
        $s = $this->db->prepare("SELECT * FROM qbo_links WHERE site_id = ? AND realm_id = ? AND entity_type = 'invoice' AND entity_id = ?");
        $s->execute([$this->conn->siteId(), $c['realm_id'], (string)$invoiceId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function paymentLink(string $key, array $c): ?array
    {
        $s = $this->db->prepare("SELECT qbo_id, qbo_total FROM qbo_links WHERE site_id = ? AND realm_id = ? AND entity_type = 'payment' AND entity_id = ?");
        $s->execute([$this->conn->siteId(), $c['realm_id'], $key]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
