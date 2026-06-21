<?php
/**
 * StatementService — client "Statement of Account" (A/R ledger).
 *
 * Builds a full accounts-receivable statement for a contact over a period:
 * opening balance + every charge (invoice) and payment in date order +
 * running balance + closing balance owing. Feeds the branded PDF template and
 * the email send path.
 *
 * The ledger maths live in the pure buildLedger() (unit-tested); getStatementData()
 * is the DB wrapper.
 */
declare(strict_types=1);

class StatementService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE LEDGER (no DB) — opening balance, period rows, closing balance
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * @param array       $events flat list of ['date'=>Y-m-d,'type'=>'charge'|'payment',
     *                            'desc'=>string,'amount'=>float,'ref'=>?,'receipt'=>?,'invoice_id'=>?]
     *                            (a charge adds to the balance, a payment reduces it)
     * @param string|null $from   inclusive period start (null + null + allOutstanding=true → whole history)
     * @param string|null $to     inclusive period end
     * @param bool        $allOutstanding when true, ignore dates and show the full ledger
     *
     * @return array{opening:float,rows:array,total_charged:float,total_paid:float,closing:float}
     */
    public function buildLedger(array $events, ?string $from, ?string $to, bool $allOutstanding = false): array
    {
        // Stable chronological order: by date, charges before payments on the same day.
        usort($events, static function ($a, $b) {
            $c = strcmp((string)$a['date'], (string)$b['date']);
            if ($c !== 0) return $c;
            $rank = static fn($t) => $t === 'charge' ? 0 : 1;
            return $rank($a['type']) <=> $rank($b['type']);
        });

        $opening = 0.0;
        $running = 0.0;
        $charged = 0.0;
        $paid    = 0.0;
        $rows    = [];

        foreach ($events as $e) {
            $signed = $e['type'] === 'charge' ? (float)$e['amount'] : -(float)$e['amount'];
            $date   = (string)$e['date'];

            // Before the period → folds into the opening balance.
            if (!$allOutstanding && $from !== null && $date < $from) {
                $opening = round($opening + $signed, 2);
                $running = $opening;
                continue;
            }
            // After the period → excluded entirely.
            if (!$allOutstanding && $to !== null && $date > $to) {
                continue;
            }

            $running = round($running + $signed, 2);
            if ($e['type'] === 'charge') {
                $charged = round($charged + (float)$e['amount'], 2);
            } else {
                $paid = round($paid + (float)$e['amount'], 2);
            }
            $rows[] = [
                'date'    => $date,
                'type'    => $e['type'],
                'desc'    => $e['desc'],
                'charge'  => $e['type'] === 'charge'  ? round((float)$e['amount'], 2) : 0.0,
                'payment' => $e['type'] === 'payment' ? round((float)$e['amount'], 2) : 0.0,
                'balance' => $running,
                'invoice_id' => $e['invoice_id'] ?? null,
                'receipt' => $e['receipt'] ?? '',
            ];
        }

        return [
            'opening'       => round($opening, 2),
            'rows'          => $rows,
            'total_charged' => $charged,
            'total_paid'    => $paid,
            'closing'       => round($opening + $charged - $paid, 2),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB — fetch a contact's invoices/payments and assemble the statement
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * @return array full statement: client/recipient info, period, ledger + totals.
     */
    public function getStatementData(int $contactId, ?string $from, ?string $to, bool $allOutstanding = false): array
    {
        $contact = $this->getContact($contactId);
        if (!$contact) {
            throw new RuntimeException("Contact $contactId not found.");
        }

        $invoices = $this->getContactInvoices($contactId);
        $events   = $this->buildEvents($invoices);
        $ledger   = $this->buildLedger($events, $from, $to, $allOutstanding);

        return [
            'contact'   => $contact,
            'recipient' => $this->resolveRecipient($contact),
            'period'    => [
                'from'            => $from,
                'to'              => $to,
                'all_outstanding' => $allOutstanding,
                'label'           => $this->periodLabel($from, $to, $allOutstanding),
            ],
            'ledger'    => $ledger,
            'generated_at' => date('Y-m-d'),
        ];
    }

    private function getContact(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.mobile,
                   c.company_id, co.company_name, co.billing_email,
                   co.billing_address, co.billing_city, co.billing_province, co.billing_postal_code
            FROM contacts c
            LEFT JOIN companies co ON co.id = c.company_id
            WHERE c.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** All invoices billable to a contact: direct, via their properties, or their plans. */
    private function getContactInvoices(int $contactId): array
    {
        $propIds = $this->columnIds("SELECT id FROM properties WHERE site_contact_id = ?", [$contactId]);
        $planIds = $this->columnIds("SELECT id FROM job_plans WHERE contact_id = ?", [$contactId]);

        $where  = ['i.contact_id = ?'];
        $params = [$contactId];
        if ($propIds) {
            $where[] = 'i.property_id IN (' . implode(',', array_fill(0, count($propIds), '?')) . ')';
            $params  = array_merge($params, $propIds);
        }
        if ($planIds) {
            $where[] = 'i.plan_id IN (' . implode(',', array_fill(0, count($planIds), '?')) . ')';
            $params  = array_merge($params, $planIds);
        }
        $stmt = $this->db->prepare("
            SELECT DISTINCT i.id, i.invoice_number, i.status, i.total, i.balance_due,
                   i.issue_date, i.due_date, i.amount_paid, i.payment_method, i.paid_at
            FROM invoices i
            WHERE (" . implode(' OR ', $where) . ")
              AND i.status <> 'draft'
            ORDER BY i.issue_date ASC, i.id ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Flatten invoices + their payments into a chronological event list. */
    private function buildEvents(array $invoices): array
    {
        $events = [];
        if (!$invoices) return $events;

        $invIds = array_column($invoices, 'id');
        $paymentsByInvoice = [];
        $ph = implode(',', array_fill(0, count($invIds), '?'));
        $stmt = $this->db->prepare("
            SELECT invoice_id, amount_cents, webhook_received_at, payment_method_type, stripe_receipt_url
            FROM stripe_payments
            WHERE invoice_id IN ($ph) AND status = 'succeeded'
            ORDER BY webhook_received_at ASC
        ");
        $stmt->execute($invIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
            $paymentsByInvoice[$sp['invoice_id']][] = $sp;
        }

        foreach ($invoices as $inv) {
            $events[] = [
                'date'       => substr((string)($inv['issue_date'] ?? ''), 0, 10) ?: date('Y-m-d'),
                'type'       => 'charge',
                'desc'       => 'Invoice ' . $inv['invoice_number'],
                'amount'     => (float)($inv['total'] ?? 0),
                'invoice_id' => (int)$inv['id'],
                'receipt'    => '',
            ];

            $stripeTotal = 0.0;
            foreach ($paymentsByInvoice[$inv['id']] ?? [] as $sp) {
                $amt = round(((int)$sp['amount_cents']) / 100, 2);
                $stripeTotal += $amt;
                $pm = $sp['payment_method_type'] ?? 'card';
                $label = $pm === 'us_bank_account' ? 'Bank transfer (Stripe)' : 'Card payment (Stripe)';
                $events[] = [
                    'date'       => substr((string)($sp['webhook_received_at'] ?? ''), 0, 10) ?: substr((string)$inv['issue_date'],0,10),
                    'type'       => 'payment',
                    'desc'       => $label . ' — ' . $inv['invoice_number'],
                    'amount'     => $amt,
                    'invoice_id' => (int)$inv['id'],
                    'receipt'    => $sp['stripe_receipt_url'] ?? '',
                ];
            }

            // Manual payments = amount_paid not covered by Stripe rows.
            $manual = round((float)($inv['amount_paid'] ?? 0) - $stripeTotal, 2);
            if ($manual > 0.005) {
                $pm = $inv['payment_method'] ?? '';
                $label = ($pm && $pm !== 'stripe') ? ucwords(str_replace('_', ' ', $pm)) : 'Payment received';
                $events[] = [
                    'date'       => substr((string)($inv['paid_at'] ?? $inv['due_date'] ?? $inv['issue_date'] ?? ''), 0, 10) ?: date('Y-m-d'),
                    'type'       => 'payment',
                    'desc'       => $label . ' — ' . $inv['invoice_number'],
                    'amount'     => $manual,
                    'invoice_id' => (int)$inv['id'],
                    'receipt'    => '',
                ];
            }
        }
        return $events;
    }

    /** Who the statement should be emailed to (company billing email wins, else the contact). */
    private function resolveRecipient(array $contact): array
    {
        $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        if (!empty($contact['company_id']) && !empty($contact['billing_email'])) {
            return [
                'email' => $contact['billing_email'],
                'name'  => $contact['company_name'] ?: $name,
                'via'   => 'company_billing',
            ];
        }
        return ['email' => $contact['email'] ?? '', 'name' => $name, 'via' => 'contact'];
    }

    private function periodLabel(?string $from, ?string $to, bool $allOutstanding): string
    {
        if ($allOutstanding) return 'All outstanding';
        $fmt = static fn($d) => $d ? date('M j, Y', strtotime($d)) : '';
        if ($from && $to) return $fmt($from) . ' – ' . $fmt($to);
        if ($to)          return 'Through ' . $fmt($to);
        return 'All activity';
    }

    /** @return int[] */
    private function columnIds(string $sql, array $params): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return [];
        }
    }
}
