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

class EtransferDeskService
{
    private PDO $db;
    private EtransferInboxService $inbox;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->inbox = new EtransferInboxService($db);
    }

    /** @return array{waiting: int, items: array} */
    public function queue(int $limit = 10): array
    {
        $rows = $this->inbox->listPending();
        $items = [];
        foreach (array_slice($rows, 0, max(1, min(25, $limit))) as $et) {
            $items[] = $this->read($et);
        }
        return ['waiting' => count($rows), 'items' => $items];
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
        $hasInvoice = $conf === 'high';
        $hasBank    = !empty($et['bank_transaction_id']);

        $duplicate = null; $spread = []; $value = null;
        if (!$hasInvoice && $active > 0) {
            $duplicate = $this->inbox->findLikelyDuplicatePayment($et['sender_name'] ?? null, $active, $et['email_date'] ?? null);
            if (!$duplicate) {
                $spread = $this->inbox->suggestFifoAllocation($et['sender_name'] ?? null, $active);
                if (!$spread) $value = $this->inbox->suggestFifoAllocationByValue($active);
            }
        }
        $lines = $value['lines'] ?? $spread;
        if (!$lines && ($matchedNo !== '' || !empty($et['invoice_hint']))) {
            $lines = [['invoice_number' => $matchedNo ?: (string)$et['invoice_hint'], 'apply_amount' => $active]];
        }
        // Learned from your past recordings: this sender pays someone else's invoices.
        $learnedPayer = null;
        if ($spread && !self::sameName((string)($et['sender_name'] ?? ''), (string)($spread[0]['payer_name'] ?? ''))) {
            $learnedPayer = $spread[0]['payer_name'] ?? null;
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
            'say'       => self::say($learnedPayer && in_array($kind, ['spread', 'hint'], true) ? 'learned' : $kind, $et['sender_name'] ?: 'Someone', $active, $allocated, $lines,
                                     $value['payer_name'] ?? $learnedPayer, $duplicate, $matchedNo),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    private static function sameName(string $a, string $b): bool
    {
        $n = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        return $n($a) !== '' && $n($a) === $n($b);
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
                return "{$m($allocated)} of this transfer is already recorded and {$m($active)} is left, but {$sender} has no open invoices for it — a duplicate, an overpayment, or an invoice not in the system yet?";
            case 'hint':
                return "{$sender} paid {$m($active)}. The transfer mentions {$invList} — check it, then record.";
            default:
                return "{$sender} sent {$m($active)} and I can't tell which invoice it's for. Type the invoice number.";
        }
    }
}
