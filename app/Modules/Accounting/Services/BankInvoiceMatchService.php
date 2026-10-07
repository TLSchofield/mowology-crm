<?php
/**
 * BankInvoiceMatchService — Penny's "Invoice payment" on a bank line (2026-10-06).
 *
 * A client's payment lands on the bank statement as a credit. Booked as income on
 * an account it counts twice: the invoice already counts as income. The right move
 * is to tie the deposit to its invoice(s), which flips it to type='transfer'
 * (InvoiceReconciliationService — the same path as the Invoices page).
 *
 * TD e-Transfer lines carry only OUR name ("e-Transfer credit Ref … TimatMowology"),
 * so the payer can't come from the memo. Penny reads it from the Interac email
 * (etransfer_notifications: same amount, within a few days) and from what she has
 * learned about senders (etransfer_sender_payers, migration 1132).
 *
 * What she offers for a credit, first match wins:
 *   1. Already recorded — payments entered by hand / from the e-Transfer panel with no
 *      bank link, that add up to this deposit. One click links them (no new payment).
 *      Old direct-entry payments (no allocation rows) are closed with
 *      markDepositAlreadyRecorded().
 *   2. The Interac email for it — its invoice number / likely match / oldest-first spread.
 *   3. Open invoices ranked by amount (balance, then total), payer name, date.
 * Recording goes through the existing paths: the email's own recordPayment() then a
 * link, or InvoiceReconciliationService::attach(). Never records on its own.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/InvoiceReconciliationService.php';
require_once __DIR__ . '/EtransferInboxService.php';
require_once __DIR__ . '/EtransferDeskService.php';

class BankInvoiceMatchService
{
    /** An Interac email belongs to a deposit when the amount is equal and the dates are this close. */
    public const EMAIL_DAYS = 10;
    /** Hand-recorded payments considered for a deposit: this many days before … after it. */
    public const RECORDED_BEFORE = 21;
    public const RECORDED_AFTER  = 60;

    private PDO $db;
    private InvoiceReconciliationService $recon;
    private EtransferInboxService $inbox;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->recon = new InvoiceReconciliationService($db);
        $this->inbox = new EtransferInboxService($db);
    }

    /** Everything the card shows for one credit. */
    public function suggest(int $txId): array
    {
        $line = $this->line($txId);
        if (!$line) return ['ok' => false, 'message' => 'That bank line is not an unmatched deposit any more.'];
        $amount = round((float)$line['amount'], 2);
        $date   = substr((string)$line['transaction_date'], 0, 10);

        $email  = $this->emailFor($line);
        $sender = $email['sender_name'] ?? null;
        $payerNames = [];
        if ($sender) {
            $payerNames['Interac email from'] = $sender;
            foreach ($this->learnedPayers($sender) as $p) $payerNames['You\'ve recorded ' . $sender . ' paying for'] = $p;
        }

        $recorded = self::pickRecorded($this->unlinkedPayments($date), $amount, $date, $email ? (int)$email['id'] : null);
        $legacy   = $recorded ? [] : $this->legacyPaid($amount, $date);

        $deposit  = ['id' => (int)$line['id'], 'transaction_date' => $date, 'amount' => $amount,
                     'description' => (string)$line['description'], 'payer_names' => $payerNames];
        $invoices = $this->recon->invoicesForDeposit($deposit, 6);

        $spread = [];
        if ($email && ($email['status'] ?? '') === 'pending') {
            $r = (new EtransferDeskService($this->db))->read($email);
            $spread = array_map(fn($l) => ['invoice_number' => $l['invoice'], 'amount' => $l['amount']], $r['lines']);
            $email['say'] = $r['say'];
        } elseif ($sender) {
            $spread = array_map(fn($l) => ['invoice_number' => $l['invoice_number'], 'amount' => $l['apply_amount']],
                                $this->inbox->suggestFifoAllocation($sender, $amount));
        }
        if ($recorded || $legacy) $spread = [];   // the money is already on an invoice: nothing to pre-tick
        if (!$spread && !$invoices && !$recorded && !$legacy) {
            $v = $this->inbox->suggestFifoAllocationByValue($amount);
            if ($v) $spread = array_map(fn($l) => ['invoice_number' => $l['invoice_number'], 'amount' => $l['apply_amount']], $v['lines']);
        }
        $spread = $this->withInvoiceIds($spread);

        return [
            'ok'       => true,
            'line'     => ['id' => (int)$line['id'], 'date' => $date, 'amount' => $amount, 'description' => $line['description']],
            'email'    => $email ? ['id' => (int)$email['id'], 'sender' => $sender, 'memo' => $email['memo'], 'date' => $email['email_date'],
                                    'status' => $email['status'], 'say' => $email['say'] ?? null] : null,
            'recorded' => $recorded,
            'legacy'   => $legacy,
            'invoices' => $invoices,
            'spread'   => $spread,
            'say'      => self::say($amount, $sender, $recorded, $legacy, $invoices, $spread),
        ];
    }

    /**
     * Record the deposit as payment of these invoices.
     * @param array $allocations [['invoice_id' => int, 'amount' => float], …]
     * @param string|null $sender who sent it (Interac name) — taught to the matcher
     */
    public function record(int $txId, array $allocations, array $user, ?string $sender = null, bool $force = false): array
    {
        $line = $this->line($txId);
        if (!$line) return ['ok' => false, 'message' => 'That bank line is not an unmatched deposit any more.'];
        $userId = (int)$user['id'];
        $amount = round((float)$line['amount'], 2);
        $date   = substr((string)$line['transaction_date'], 0, 10);
        $clean = [];
        foreach ($allocations as $a) {
            $iid = (int)($a['invoice_id'] ?? 0);
            if (!$iid && !empty($a['invoice_number'])) {      // typed on the card
                $q = $this->db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
                $q->execute([strtoupper(trim((string)$a['invoice_number']))]);
                $iid = (int)$q->fetchColumn();
                if (!$iid) return ['ok' => false, 'message' => 'No invoice ' . trim((string)$a['invoice_number']) . '.'];
            }
            $amt = round((float)($a['amount'] ?? 0), 2);
            if ($iid > 0 && $amt > 0) $clean[] = ['invoice_id' => $iid, 'amount' => $amt];
        }
        if (!$clean) return ['ok' => false, 'message' => 'Tick at least one invoice.'];
        if (round(array_sum(array_column($clean, 'amount')), 2) > $amount + 0.005) {
            return ['ok' => false, 'message' => 'That is more than the deposit ($' . number_format($amount, 2) . ').'];
        }
        $email = $this->emailFor($line);
        if (($sender === null || trim($sender) === '') && $email) $sender = $email['sender_name'] ?? null;
        if (!$force) {
            $rec = self::pickRecorded($this->unlinkedPayments($date), $amount, $date, $email ? (int)$email['id'] : null);
            if ($rec || $this->legacyPaid($amount, $date)) {
                return ['ok' => false, 'already' => true, 'message' => 'This money looks already recorded — link it instead, or choose Record anyway.'];
            }
        }

        try {
            if ($email && ($email['status'] ?? '') === 'pending' && abs((float)$email['amount'] - $amount) < 0.01) {
                // The email's own path (marks it recorded, learns the sender), then tie the deposit.
                $r = $this->inbox->recordPayment((int)$email['id'], $clean, $userId);
                if (empty($r['ok'])) return ['ok' => false, 'message' => $r['message'] ?? 'Could not record'];
                $s = $this->db->prepare("SELECT id FROM invoice_payment_allocations WHERE etransfer_notification_id = ? AND transaction_id IS NULL");
                $s->execute([(int)$email['id']]);
                $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
                try {
                    $this->db->beginTransaction();
                    $linked = $this->recon->linkAllocationsToDeposit($txId, $ids, $userId);
                    $this->db->prepare("UPDATE etransfer_notifications SET bank_transaction_id = COALESCE(bank_transaction_id, ?) WHERE id = ?")
                       ->execute([$txId, (int)$email['id']]);
                    $this->review($txId, $userId);
                    $this->db->commit();
                } catch (Throwable $e) {
                    // The payment IS recorded (the email's own path); only the tie to the deposit
                    // failed — the card offers "Already recorded — link" for it next.
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    return ['ok' => true, 'message' => 'Recorded on the invoice, but I couldn\'t tie the bank deposit to it (' . $e->getMessage() . '). Use "Already recorded — link" on this line.'];
                }
                $numbers = $linked['invoice_numbers'];
                $left = 0.0;
            } else {
                $res = $this->recon->attach($txId, $clean, $userId);
                $numbers = array_column($res['recorded'], 'invoice_number');
                $left = (float)$res['deposit_remaining'];
                $this->review($txId, $userId);
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $taught = $sender !== null && trim($sender) !== '' ? $this->teach(trim($sender), array_column($clean, 'invoice_id')) : false;
        return ['ok' => true, 'message' => 'Recorded on ' . implode(', ', $numbers) . ' — the deposit counts once now' .
                ($left > 0.005 ? '; $' . number_format($left, 2) . ' of it is still unexplained' : '') . '.' .
                ($taught ? ' I\'ll know ' . trim($sender) . ' next time.' : '')];
    }

    /** "Already recorded — link": tie the deposit to payments already on the invoices. */
    public function linkRecorded(int $txId, array $user, array $allocationIds = [], bool $legacy = false): array
    {
        $line = $this->line($txId);
        if (!$line) return ['ok' => false, 'message' => 'That bank line is not an unmatched deposit any more.'];
        $userId = (int)$user['id'];
        try {
            $this->db->beginTransaction();
            if ($legacy) {
                $l = $this->legacyPaid(round((float)$line['amount'], 2), substr((string)$line['transaction_date'], 0, 10));
                if (!$l) throw new RuntimeException('I can\'t find that payment any more.');
                $this->recon->markDepositAlreadyRecorded($txId, $userId, 'payment already on ' . $l[0]['invoice_number'] . ' (Penny)');
                // Claim the invoice: legacyPaid() skips invoices a deposit already points at,
                // so the same payment can't be offered for a second deposit.
                $this->db->prepare("UPDATE accounting_transactions SET matched_invoice_id = ? WHERE id = ?")
                   ->execute([(int)$l[0]['invoice_id'], $txId]);
                $numbers = [$l[0]['invoice_number']];
            } else {
                $r = $this->recon->linkAllocationsToDeposit($txId, $allocationIds, $userId);
                $numbers = $r['invoice_numbers'];
            }
            $this->review($txId, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => 'Linked to the payment already on ' . implode(', ', $numbers) . ' — counted once now.'];
    }

    /** Teach: this sender pays for these invoices' payer. True when a new pairing was stored. */
    public function teach(string $sender, array $invoiceIds): bool
    {
        $before = $this->countLearned();
        $this->inbox->learnSender($sender, $invoiceIds);
        return $this->countLearned() > $before || $this->timesFor($sender) > 0;
    }

    /**
     * Read-only: unmatched deposits whose money is already recorded on invoices
     * (counted twice today). For the owner to see before anything is linked.
     */
    public function report(int $limit = 300): array
    {
        $rows = $this->db->query("
            SELECT id, transaction_date, amount, description FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND type = 'income' AND amount > 0.005
            ORDER BY transaction_date DESC, id DESC LIMIT " . max(1, min(1000, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = []; $total = 0.0;
        foreach ($rows as $r) {
            $date = substr((string)$r['transaction_date'], 0, 10);
            $amount = round((float)$r['amount'], 2);
            $email = $this->emailFor($r);
            $rec = self::pickRecorded($this->unlinkedPayments($date), $amount, $date, $email ? (int)$email['id'] : null);
            $leg = $rec ? [] : $this->legacyPaid($amount, $date);
            if (!$rec && !$leg) continue;
            $hit = $rec[0] ?? $leg[0];
            $out[] = ['id' => (int)$r['id'], 'date' => $date, 'amount' => $amount, 'description' => $r['description'],
                      'kind' => $rec ? 'unlinked_payment' : 'legacy_paid', 'invoices' => $hit['invoice_numbers'] ?? [$hit['invoice_number']],
                      'how' => $hit['how'] ?? 'paid directly on the invoice', 'ambiguous' => count($rec ?: $leg) > 1];
            $total += $amount;
        }
        return ['checked' => count($rows), 'found' => count($out), 'total' => round($total, 2), 'lines' => $out];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Loading
    // ─────────────────────────────────────────────────────────────────────────

    private function line(int $txId): ?array
    {
        $s = $this->db->prepare("SELECT id, transaction_date, amount, description, type FROM accounting_transactions
                                 WHERE id = ? AND reference_type = 'bank_import' AND type = 'income' AND amount > 0.005");
        $s->execute([$txId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The Interac email behind this deposit: linked to it, else same amount within EMAIL_DAYS (closest). */
    private function emailFor(array $line): ?array
    {
        try {
            $s = $this->db->prepare("
                SELECT * FROM etransfer_notifications
                WHERE status <> 'dismissed'
                  AND (bank_transaction_id = ?
                       OR (bank_transaction_id IS NULL AND ABS(amount - ?) < 0.01
                           AND email_date BETWEEN DATE_SUB(?, INTERVAL " . self::EMAIL_DAYS . " DAY) AND DATE_ADD(?, INTERVAL " . self::EMAIL_DAYS . " DAY)))
                ORDER BY (bank_transaction_id = ?) DESC, ABS(DATEDIFF(email_date, ?)) ASC
                LIMIT 1
            ");
            $d = substr((string)$line['transaction_date'], 0, 10);
            $s->execute([(int)$line['id'], (float)$line['amount'], $d, $d, (int)$line['id'], $d]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Payments on invoices with no bank deposit linked, dated near the deposit. */
    private function unlinkedPayments(string $date): array
    {
        $s = $this->db->prepare("
            SELECT a.id, a.invoice_id, a.amount, a.payment_date, a.method, a.reference, a.etransfer_notification_id,
                   i.invoice_number,
                   COALESCE(NULLIF(i.bill_to_name, ''), co.company_name,
                            NULLIF(TRIM(CONCAT(COALESCE(ct.first_name,''), ' ', COALESCE(ct.last_name,''))), ''), '') AS payer,
                   n.amount AS email_amount, n.email_date, n.sender_name
            FROM invoice_payment_allocations a
            JOIN invoices i ON i.id = a.invoice_id
            LEFT JOIN companies co ON co.id = i.company_id
            LEFT JOIN contacts  ct ON ct.id = i.contact_id
            LEFT JOIN etransfer_notifications n ON n.id = a.etransfer_notification_id
            WHERE a.transaction_id IS NULL AND a.amount > 0
              AND a.method NOT IN ('stripe', 'credit_card', 'card', 'credit')
              AND a.payment_date BETWEEN DATE_SUB(?, INTERVAL " . self::RECORDED_BEFORE . " DAY) AND DATE_ADD(?, INTERVAL " . self::RECORDED_AFTER . " DAY)
            ORDER BY a.payment_date, a.id
            LIMIT 300
        ");
        $s->execute([$date, $date]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Invoices paid the old way (straight onto the invoice, no allocation rows) for
     * exactly this amount near this date, not by card.
     */
    private function legacyPaid(float $amount, string $date): array
    {
        $s = $this->db->prepare("
            SELECT i.id AS invoice_id, i.invoice_number, i.amount_paid, DATE(COALESCE(i.paid_at, i.updated_at)) AS paid_on
            FROM invoices i
            WHERE i.status = 'paid' AND ABS(i.amount_paid - ?) < 0.01
              AND COALESCE(i.payment_method, '') NOT IN ('stripe', 'credit_card', 'card')
              AND DATE(COALESCE(i.paid_at, i.updated_at)) BETWEEN DATE_SUB(?, INTERVAL " . self::RECORDED_BEFORE . " DAY) AND DATE_ADD(?, INTERVAL " . self::RECORDED_AFTER . " DAY)
              AND NOT EXISTS (SELECT 1 FROM invoice_payment_allocations a WHERE a.invoice_id = i.id)
              AND NOT EXISTS (SELECT 1 FROM accounting_transactions t WHERE t.reference_type = 'bank_import' AND t.matched_invoice_id = i.id)
            ORDER BY ABS(DATEDIFF(COALESCE(i.paid_at, i.updated_at), ?)) LIMIT 3
        ");
        $s->execute([$amount, $date, $date, $date]);
        return array_map(fn($r) => $r + ['how' => 'paid directly on the invoice ' . $r['paid_on']], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Payers this sender has been recorded paying for. */
    private function learnedPayers(string $sender): array
    {
        try {
            $s = $this->db->prepare("SELECT payer_name FROM etransfer_sender_payers WHERE sender_key = ? ORDER BY times DESC LIMIT 3");
            $s->execute([strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $sender))]);
            return $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function timesFor(string $sender): int
    {
        try {
            $s = $this->db->prepare("SELECT COALESCE(SUM(times), 0) FROM etransfer_sender_payers WHERE sender_key = ?");
            $s->execute([strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $sender))]);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function countLearned(): int
    {
        try {
            return (int)$this->db->query("SELECT COALESCE(SUM(times), 0) FROM etransfer_sender_payers")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** invoice_number → invoice_id for spread lines (the card posts ids). */
    private function withInvoiceIds(array $lines): array
    {
        $nums = array_values(array_filter(array_column($lines, 'invoice_number')));
        if (!$nums) return [];
        $in = implode(',', array_fill(0, count($nums), '?'));
        $s = $this->db->prepare("SELECT invoice_number, id, balance_due FROM invoices WHERE invoice_number IN ({$in})");
        $s->execute($nums);
        $map = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['invoice_number']] = $r;
        $out = [];
        foreach ($lines as $l) {
            $m = $map[$l['invoice_number']] ?? null;
            if ($m && (float)$m['balance_due'] > 0.005) {
                $out[] = ['invoice_id' => (int)$m['id'], 'invoice_number' => $l['invoice_number'], 'amount' => round((float)$l['amount'], 2)];
            }
        }
        return $out;
    }

    /** The line is decided: it never comes back to the card. */
    private function review(int $txId, int $userId): void
    {
        try {
            $this->db->prepare("
                INSERT INTO bank_line_reviews (transaction_id, suggested_account_id, final_account_id, outcome, decided_by)
                VALUES (?, NULL, NULL, 'invoice', ?)
                ON DUPLICATE KEY UPDATE outcome = 'invoice', decided_by = VALUES(decided_by), decided_at = NOW()
            ")->execute([$txId, $userId]);
        } catch (Throwable $e) { /* before migration 1129 — the transfer flip already hides it */ }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Which already-recorded payments are this deposit? Best first; empty = none.
     *  1. payments recorded from the Interac email for this deposit (email amount = deposit);
     *  2. one payer's payments that add up exactly — only when exactly ONE payer fits
     *     (several payers fitting by amount alone is a guess, not a match).
     * @param array $payments rows from unlinkedPayments()
     * @return array<int, array{allocation_ids:int[], invoice_numbers:string[], payer:string, how:string}>
     */
    public static function pickRecorded(array $payments, float $amount, string $date, ?int $emailId): array
    {
        $eq = fn($a, $b) => abs(round((float)$a, 2) - round((float)$b, 2)) < 0.005;
        $pack = function (array $rows, string $how) {
            return ['allocation_ids'  => array_map(fn($r) => (int)$r['id'], $rows),
                    'invoice_numbers' => array_values(array_unique(array_map(fn($r) => (string)$r['invoice_number'], $rows))),
                    'payer'           => (string)($rows[0]['payer'] ?? ''), 'how' => $how];
        };
        // 1. Recorded from an Interac email of exactly this amount, near this date.
        $byEmail = [];
        foreach ($payments as $p) {
            $n = (int)($p['etransfer_notification_id'] ?? 0);
            if (!$n || !$eq($p['email_amount'] ?? 0, $amount)) continue;
            if (!empty($p['email_date']) && abs(strtotime(substr((string)$p['email_date'], 0, 10)) - strtotime($date)) > self::EMAIL_DAYS * 86400) continue;
            $byEmail[$n][] = $p;
        }
        uksort($byEmail, fn($a, $b) => ($b === $emailId) <=> ($a === $emailId));
        foreach ($byEmail as $rows) {
            if ($eq(array_sum(array_column($rows, 'amount')), $amount)) {
                return [$pack($rows, 'recorded from ' . ($rows[0]['sender_name'] ?: 'the Interac email') . '\'s e-Transfer on ' . $rows[0]['payment_date']) + ['sure' => true]];
            }
        }
        // 2. One payer's payments (by hand, any method but card) adding up exactly.
        $byPayer = [];
        foreach ($payments as $p) {
            $key = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$p['payer']));
            if ($key === '') $key = 'INV' . $p['invoice_id'];
            $byPayer[$key][] = $p;
        }
        $hits = [];
        foreach ($byPayer as $rows) {
            $pick = InvoiceReconciliationService::exactSubset(array_map(fn($r) => (float)$r['amount'], $rows), $amount);
            if ($pick === null) continue;
            $sel = array_map(fn($i) => $rows[$i], $pick);
            $hits[] = $pack($sel, 'recorded by hand on ' . $sel[0]['payment_date'] . ($sel[0]['reference'] ? ' (ref ' . $sel[0]['reference'] . ')' : '')) + ['sure' => false];
        }
        return $hits;   // more than one = the card shows each; the owner picks
    }

    /** Is this credit probably a client paying (so the card asks about invoices first)? */
    public static function looksLikeClientPayment(string $description): bool
    {
        if (preg_match('/\bSTRIPE\b|\bINTEREST\b|\bREFUND\b|\bREVERSAL\b|\bLOAN\b|TFR-FR|TRANSFER FROM|\bGST\b|CRA\b|RECEIVER GENERAL/i', $description)) return false;
        return (bool)preg_match('/E-?TRANSFER|ETRANSFER|INTERAC|\bEFT\b|PREAUTHORI[SZ]ED CREDIT|CHEQUE DEPOSIT|\bCHQ\b|MOBILE DEPOSIT|BRANCH DEPOSIT|DIRECT DEPOSIT/i', $description);
    }

    /** Penny's sentence for the panel. */
    public static function say(float $amount, ?string $sender, array $recorded, array $legacy, array $invoices, array $spread): string
    {
        $m = '$' . number_format($amount, 2);
        $who = $sender ? $sender . '\'s ' . $m : 'This ' . $m;
        $whoMid = $sender ? $who : 'this ' . $m;
        if (count($recorded) === 1 && !empty($recorded[0]['sure'])) {
            return "{$who} is already recorded on " . implode(', ', $recorded[0]['invoice_numbers']) . " — {$recorded[0]['how']} — but the bank deposit was never tied to it, so it counts twice. Link it and it counts once.";
        }
        if (count($recorded) === 1) {
            $r = $recorded[0];
            return "{$who} matches a payment of the same amount on " . implode(', ', $r['invoice_numbers']) . ($r['payer'] ? " ({$r['payer']})" : '') .
                   " — {$r['how']}. If that's this money, link it so it counts once; if not, record it below.";
        }
        if ($recorded) return "{$who} matches payments already recorded for more than one client. Pick the right one to link.";
        if ($legacy) return "{$who} matches {$legacy[0]['invoice_number']}, {$legacy[0]['how']}. If that's this money, link it so it counts once.";
        if (count($spread) > 1) {
            return "{$who} covers more than one invoice — oldest first: " . implode(', ', array_column($spread, 'invoice_number')) . '.';
        }
        $top = $invoices[0] ?? null;
        if ($top && $top['confidence'] >= 70) {
            return "I think {$whoMid} pays {$top['invoice_number']}" . ($top['payer'] ? " ({$top['payer']})" : '') . ': ' . strtolower(implode(', ', $top['reasons'])) . '.';
        }
        if ($invoices) return "{$who} could be one of these invoices. Tick the right one" . ($sender ? '' : ' — and tell me who sent it, so I know next time') . '.';
        return "I can't find an open invoice for {$m}. If it's for one, search by number; if it isn't a client payment, pick an account below.";
    }
}
