<?php
/**
 * EtransferDeskService — Penny's view of the pending Interac e-Transfers.
 *
 * The same reading the Invoices page's "Pending e-Transfers" panel makes (exact match,
 * likely match, possibly already recorded, oldest-invoice-first spread, value-only
 * match, partly applied), moved out of the page into a service so Penny's card and the
 * page agree. Everything comes from EtransferInboxService; recording, dismissing and
 * merging still go through /crm/api/etransfer-confirm.php (the page's own endpoint).
 * Penny never records on her own: the owner presses Record.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/EtransferInboxService.php';
require_once __DIR__ . '/EtransferNudgeService.php';

class EtransferDeskService
{
    private PDO $db;
    private EtransferInboxService $inbox;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->inbox = new EtransferInboxService($db);
    }

    /** @return array{waiting: int, items: array, nudged: int} */
    public function queue(int $limit = 10): array
    {
        $rows = $this->inbox->listPending();
        $items = [];
        foreach (array_slice($rows, 0, max(1, min(25, $limit))) as $et) {
            $items[] = $this->read($et);
        }
        // Card fact: clients Penny has asked to use the Auto-deposit address (migration 1301).
        return ['waiting' => count($rows), 'items' => $items, 'nudged' => (new EtransferNudgeService($this->db))->countSent()];
    }

    /** One transfer, read the way the Invoices panel reads it — plus Penny's sentence. */
    public function read(array $et): array
    {
        $amount    = $et['amount'] !== null ? (float)$et['amount'] : 0.0;
        $matchedNo = (string)($et['matched_invoice_number'] ?? '');
        $conf      = $et['match_confidence'] ?? 'none';
        $allocated = (float)($et['allocated_amount'] ?? 0);
        $partial   = $allocated > 0.005;
        $active    = $partial ? round($amount - $allocated, 2) : $amount;
        // Part recorded, and the invoice it matched is now paid (by this transfer): what's left isn't for it.
        if ($partial && $matchedNo !== '') {
            $q = $this->db->prepare("SELECT balance_due FROM invoices WHERE invoice_number = ? LIMIT 1");
            $q->execute([$matchedNo]);
            $b = $q->fetchColumn();
            if ($b !== false && (float)$b <= 0.005) {
                $matchedNo = '';
                $conf = 'none';
                $et['invoice_hint'] = null;
            }
        }
        $hasInvoice = $conf === 'high';
        $hasBank    = !empty($et['bank_transaction_id']);

        $duplicate = null; $spread = []; $value = null;
        if (!$hasInvoice && $active > 0) {
            $duplicate = $this->inbox->findLikelyDuplicatePayment($et['sender_name'] ?? null, $active, $et['email_date'] ?? null);
            if (!$duplicate) {
                $spread = $this->inbox->suggestFifoAllocation($et['sender_name'] ?? null, $active);
                if (!$spread) $value = $this->inbox->suggestFifoAllocationByValue($active, $et['sender_name'] ?? null, $et['memo'] ?? null);
            }
        }
        $lines = $value['lines'] ?? $spread;
        if (!$lines && ($matchedNo !== '' || !empty($et['invoice_hint']))) {
            $lines = [['invoice_number' => $matchedNo ?: (string)$et['invoice_hint'], 'apply_amount' => $active]];
        }
        // More than the invoice owes (Alberta 3025 Strata, 2026-10-14: "Invs 2026-0251 and 0383", 0251 already
        // paid): the line takes what is owed and the rest is offered as the client's credit.
        $over = 0.0; $alreadyPaid = [];
        if (!$duplicate && count($lines) === 1 && $lines[0]['invoice_number'] !== '') {
            $q = $this->db->prepare("SELECT balance_due FROM invoices WHERE invoice_number = ? LIMIT 1");
            $q->execute([$lines[0]['invoice_number']]);
            $bal = $q->fetchColumn();
            if ($bal !== false && (float)$bal > 0.005 && (float)$bal < $active - 0.005) {
                $lines[0]['apply_amount'] = round((float)$bal, 2);
                $over = round($active - (float)$bal, 2);
            }
        }
        if ($over > 0 && !empty($et['memo'])) {
            foreach (EtransferInboxService::extractInvoiceNumbers((string)$et['memo']) as $no) {
                $q = $this->db->prepare("SELECT invoice_number, balance_due, paid_at FROM invoices WHERE invoice_number = ? LIMIT 1");
                $q->execute([$no]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if ($r && (float)$r['balance_due'] <= 0.005) $alreadyPaid[] = $r['invoice_number'] . ($r['paid_at'] ? ' (paid ' . date('M j', strtotime((string)$r['paid_at'])) . ')' : '');
            }
        }
        $canCredit = $over > 0 || ($partial && $active > 0.005);

        // Learned from your past recordings: this sender pays someone else's invoices.
        $learnedPayer = null;
        $payer = (string)($spread[0]['payer_name'] ?? '');
        if ($spread && $payer !== '' && !EtransferInboxService::samePayer((string)($et['sender_name'] ?? ''), $payer)
            && $this->inbox->timesPaidFor($et['sender_name'] ?? null, $payer) > 0) {
            $learnedPayer = $payer;
        }
        $kind = $duplicate ? 'duplicate' : ($conf === 'high' ? 'match' : ($conf === 'medium' ? 'likely'
              : ($value ? 'value' : (count($lines) > 1 ? 'spread' : ($partial && !$lines ? 'leftover' : ($lines ? 'hint' : 'none'))))));

        return [
            'id'        => (int)$et['id'],
            'sender'    => $et['sender_name'] ?: 'Unknown sender',
            'amount'    => $amount,
            'active'    => $active,
            'memo'      => $et['memo'] ?? null,
            'date'      => $et['email_date'] ?? null,
            'claim'     => ($et['transfer_type'] ?? '') === 'claim',
            'kind'      => $kind,
            'lines'     => array_map(fn($l) => ['invoice' => (string)$l['invoice_number'], 'amount' => round((float)$l['apply_amount'], 2)], $lines),
            'confirmed' => 1 + ($hasInvoice ? 1 : 0) + ($hasBank ? 1 : 0),
            'missing'   => array_values(array_filter([$hasInvoice ? null : 'invoice match', $hasBank ? null : 'bank deposit'])),
            'duplicate' => $duplicate ? ['date' => $duplicate['pay_date'], 'invoices' => $duplicate['invoice_numbers']] : null,
            // Keep the rest as the client's credit (ClientCreditService::addOverpayment): $over after these lines,
            // or everything left on a part-recorded transfer.
            'credit'    => $canCredit ? ['amount' => $over > 0 ? $over : $active, 'with_record' => $over > 0, 'already_paid' => $alreadyPaid] : null,
            // The clues (memo / name + the oldest invoices to the cent) say WHY — as a person would explain it.
            'say'       => !empty($value['clue']) && $over <= 0
                ? ($et['sender_name'] ?: 'Someone') . ' sent $' . number_format($active, 2) . ': ' . $value['clue'] . ' (' . implode(', ', array_map(fn($l) => $l['invoice_number'], $lines)) . '). Check, then record.'
                : ($over > 0 ? self::sayOver($et['sender_name'] ?: 'Someone', $active, $lines[0], $over, $alreadyPaid) : self::say($learnedPayer && in_array($kind, ['spread', 'hint'], true) ? 'learned' : $kind, $et['sender_name'] ?: 'Someone', $active, $allocated, $lines,
                                     $value['payer_name'] ?? $learnedPayer, $duplicate, $matchedNo)),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Pure (tested): a payment bigger than the invoice it names. */
    public static function sayOver(string $sender, float $active, array $line, float $over, array $alreadyPaid): string
    {
        $m = fn($v) => '$' . number_format((float)$v, 2);
        return "{$sender} sent {$m($active)}: {$m($line['apply_amount'])} pays " . ($line['invoice_number'] ?? $line['invoice'] ?? '') . " and {$m($over)} is extra"
             . ($alreadyPaid ? ' — they named ' . implode(', ', $alreadyPaid) . ', already paid' : '')
             . ". Record it and keep {$m($over)} as credit for their next invoice? (A refund is yours to send.)";
    }

    public static function say(string $kind, string $sender, float $active, float $allocated, array $lines, ?string $payer, ?array $dup, string $matchedNo): string
    {
        $m = fn($v) => '$' . number_format((float)$v, 2);
        $invList = implode(', ', array_map(fn($l) => $l['invoice_number'] ?? $l['invoice'] ?? '', $lines));
        switch ($kind) {
            case 'duplicate':
                return "{$sender} sent {$m($active)}, and that exact amount was already recorded on " . date('M j', strtotime((string)$dup['pay_date'])) .
                       ' against ' . implode(', ', $dup['invoice_numbers']) . ". It's probably the same transfer — check, then dismiss it rather than record it twice.";
            case 'match':
                return "{$sender} paid {$m($active)} for {$matchedNo} — the invoice number is on the transfer. Record it?";
            case 'likely':
                return "{$sender} paid {$m($active)}. I think it's for {$matchedNo} — the amount and name fit. Check, then record.";
            case 'value':
                return "{$sender} isn't a client, but {$m($active)} exactly clears {$payer}'s open balance ({$invList}) — maybe paying on their behalf. Check before recording.";
            case 'learned':
                return "{$sender} paid {$m($active)}. You've recorded {$sender} paying {$payer}'s invoices before, so oldest first it covers {$invList}.";
            case 'spread':
                return "{$sender} paid {$m($active)}, more than any one invoice. Oldest first, it covers {$invList}.";
            case 'leftover':
                return "{$m($allocated)} of this transfer is already recorded and {$m($active)} is left, but {$sender} has no open invoices for it. If they overpaid, keep it as credit for their next invoice; if it's an invoice not in the system yet, record it there.";
            case 'hint':
                return "{$sender} paid {$m($active)}. The transfer mentions {$invList} — check it, then record.";
            default:
                return "{$sender} sent {$m($active)} and I can't tell which invoice it's for. Type the invoice number.";
        }
    }
}
