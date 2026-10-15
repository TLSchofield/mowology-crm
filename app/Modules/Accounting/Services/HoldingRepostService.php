<?php
/**
 * HoldingRepostService — moves 2026 from "payments post to chequing" to the holding accounts
 * (migration 1364; plan docs/plans/2026-10-14-deposit-matcher.md). Append-only: every change is a
 * reversal of the live entry plus a new entry (LedgerService), never an edit or a delete.
 *
 * With the switch date $from (ops_settings ledger_holding_from), for everything dated on/after it:
 *   1. customer payments (payment, payment_allocation, stripe_payment), overpayment credits (client_credit)
 *      and Stripe refunds/disputes whose chequing (1010) line should now be on 1015 / 1016 → re-accounted;
 *   2. every bank line whose posting differs under the new rules (customer deposits that posted nothing
 *      or posted as income, Stripe fee lines, matched single-charge payouts) → reversed and/or posted.
 * Locked months are refused (listed, not touched). plan() reads only; apply() books, inside the caller's
 * transaction when there is one (the production preview runs it and rolls back).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';

class HoldingRepostService
{
    public const PAYMENT_SOURCES = ['payment', 'payment_allocation', 'stripe_payment', 'client_credit', 'stripe_dispute', 'stripe_refund'];

    private PDO $db;
    private LedgerService $ledger;
    private LedgerSyncService $sync;

    public function __construct(PDO $db, string $from)
    {
        $this->db = $db;
        $this->ledger = (new LedgerService($db))->withHoldingFrom($from);
        $this->sync = new LedgerSyncService($db, $this->ledger);
    }

    /** @return array{payments: list<array>, bank: list<array>, locked: list<array>} */
    public function plan(): array
    {
        $from = (string)$this->ledger->holdingFrom();
        $bank = $this->ledger->accountId(LedgerService::ACC_BANK);
        $out = ['payments' => [], 'bank' => [], 'locked' => []];

        // 1. Payment-type entries with a chequing line, on/after the switch.
        $in = implode(',', array_fill(0, count(self::PAYMENT_SOURCES), '?'));
        $s = $this->db->prepare("
            SELECT je.id, je.entry_date, je.source_type, je.source_id, SUM(jl.debit) - SUM(jl.credit) AS net
            FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id AND jl.account_id = ?
            WHERE je.source_type IN ($in) AND je.status = 'posted' AND je.reversed_by_entry_id IS NULL AND je.entry_date >= ?
            GROUP BY je.id, je.entry_date, je.source_type, je.source_id");
        $s->execute(array_merge([$bank], self::PAYMENT_SOURCES, [$from]));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (abs((float)$r['net']) < 0.005) continue;   // reversal pairs etc.
            $kind = in_array($r['source_type'], ['stripe_payment', 'stripe_dispute', 'stripe_refund'], true) ? 'stripe_payment' : 'payment';
            $to = LedgerService::paymentAccount($kind, (string)$r['entry_date'], $from);
            $item = ['entry_id' => (int)$r['id'], 'date' => (string)$r['entry_date'], 'source' => $r['source_type'] . ':' . $r['source_id'],
                     'amount' => round((float)$r['net'], 2), 'to' => $to];
            if ($this->ledger->isLocked((string)$r['entry_date'])) $out['locked'][] = $item; else $out['payments'][] = $item;
        }

        // 2. Bank lines whose posting differs under the new rules.
        $rows = $this->db->prepare("
            SELECT id, transaction_date FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND transaction_date >= ? AND matched_expense_id IS NULL
              AND COALESCE(status, '') NOT IN ('void', 'deleted')");
        $rows->execute([$from]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tx = (int)$r['id'];
            $want = $this->sync->bankEntryArgsFor($tx);
            $have = $this->liveBankEntry($tx);
            $wantSig = $want ? self::signature($want['lines'], $this->ledger) : '';
            $haveSig = $have ? $have['sig'] : '';
            if ($wantSig === $haveSig) continue;
            $item = ['tx' => $tx, 'date' => (string)$r['transaction_date'], 'live_entry' => $have['id'] ?? null, 'post' => $want !== null,
                     'from' => $haveSig, 'to' => $wantSig];
            if ($this->ledger->isLocked((string)$r['transaction_date'])) $out['locked'][] = $item; else $out['bank'][] = $item;
        }
        return $out;
    }

    /** Book the plan. @return array{payments:int, bank_reversed:int, bank_posted:int, locked:int} */
    public function apply(int $userId): array
    {
        $p = $this->plan();
        $done = ['payments' => 0, 'bank_reversed' => 0, 'bank_posted' => 0, 'locked' => count($p['locked'])];
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            foreach ($p['payments'] as $it) {
                $this->reaccount($it['entry_id'], $it['to'], $userId);
                $done['payments']++;
            }
            foreach ($p['bank'] as $it) {
                if ($it['live_entry']) {
                    $this->ledger->reverseEntry((int)$it['live_entry'], $userId, 'holding accounts (migration 1364)', 'penny');
                    $done['bank_reversed']++;
                }
                if ($it['post']) {
                    $args = $this->sync->bankEntryArgsFor((int)$it['tx']);
                    if ($args) {
                        $args['created_by'] = $userId;
                        $args['proposed_by'] = 'penny';
                        $this->ledger->postManual($args);
                        $done['bank_posted']++;
                    }
                }
            }
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $done;
    }

    /** Reverse an entry and post the same lines with its chequing line moved to $toCode. */
    private function reaccount(int $entryId, string $toCode, int $userId): void
    {
        $e = $this->db->prepare("SELECT entry_date, memo, source_type, source_id FROM journal_entries WHERE id = ?");
        $e->execute([$entryId]);
        $entry = $e->fetch(PDO::FETCH_ASSOC);
        $l = $this->db->prepare("SELECT account_id, debit, credit, description, contact_id FROM journal_lines WHERE entry_id = ? ORDER BY id");
        $l->execute([$entryId]);
        $bank = $this->ledger->accountId(LedgerService::ACC_BANK);
        $to = $this->ledger->accountId($toCode);
        $lines = [];
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $lines[] = ['account_id' => (int)$x['account_id'] === $bank ? $to : (int)$x['account_id'],
                        'debit' => (float)$x['debit'], 'credit' => (float)$x['credit'],
                        'description' => $x['description'], 'contact_id' => $x['contact_id'] !== null ? (int)$x['contact_id'] : null];
        }
        $this->ledger->reverseEntry($entryId, $userId, 'holding accounts (migration 1364)', 'penny');
        $this->ledger->postManual(['entry_date' => $entry['entry_date'], 'memo' => $entry['memo'], 'source_type' => $entry['source_type'],
                                   'source_id' => $entry['source_id'] !== null ? (int)$entry['source_id'] : null,
                                   'created_by' => $userId, 'proposed_by' => 'penny', 'lines' => $lines]);
    }

    /** The live bank_import / bank_deposit entry of a line, with its line signature. */
    private function liveBankEntry(int $tx): ?array
    {
        $s = $this->db->prepare("SELECT id FROM journal_entries WHERE source_type = 'bank_import' AND source_id = ? AND reversed_by_entry_id IS NULL AND status = 'posted' ORDER BY id DESC LIMIT 1");
        $s->execute([$tx]);
        $id = $s->fetchColumn();
        if (!$id) return null;
        $l = $this->db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE entry_id = ?");
        $l->execute([(int)$id]);
        return ['id' => (int)$id, 'sig' => self::signature($l->fetchAll(PDO::FETCH_ASSOC), $this->ledger)];
    }

    /** "acct:Dr-Cr" per account, sorted — what an entry does, ignoring order and descriptions. Pure-ish. */
    public static function signature(array $lines, LedgerService $ledger): string
    {
        $net = [];
        foreach ($lines as $x) {
            $a = isset($x['account_id']) ? (int)$x['account_id'] : $ledger->accountId((string)$x['account']);
            $net[$a] = round(($net[$a] ?? 0) + (float)($x['debit'] ?? 0) - (float)($x['credit'] ?? 0), 2);
        }
        $net = array_filter($net, fn($v) => abs($v) >= 0.005);
        ksort($net);
        return implode('|', array_map(fn($k, $v) => $k . ':' . number_format($v, 2, '.', ''), array_keys($net), $net));
    }
}
