<?php
/**
 * BankTransferDirectionRepair — fix bank deposits the journal posted as money OUT (2026-10-07).
 *
 * The bug: LedgerSyncService::bankRowToEntryArgs() posted every type='transfer' bank line
 * as DR category / CR bank. A customer deposit becomes 'transfer' when reconciliation ties
 * it to invoices; tied to SEVERAL invoices through invoice_payment_allocations it carries no
 * matched_invoice_id, so syncBankImports() posted it — backwards (live: bank row 24438,
 * 2026-07-20, $302.40 e-Transfer from John Hughes). BankLineMoveService's reverse + repost
 * used the same mapper. Found by the income clean-up's "Journal check".
 *
 * The mapper now reads the direction from the statement (LedgerSyncService::transferDirection)
 * and posts nothing for a deposit its invoices carry (their payment entry already debits the
 * bank). This finds every live bank_import entry whose deposit is money IN and whose bank
 * side disagrees with what the mapper posts now:
 *   backwards   — the entry credits the bank (money out) for money that came in;
 *   double_cash — the entry debits the bank for a deposit its invoice payments already debit.
 * apply() corrects each the append-only way (migration 1131): reverse the entry, post what
 * the line should post now (nothing, for a settled deposit). Locked months are skipped and
 * reported — never touched. Money OUT lines are never selected.
 *
 * Run from public/crm/api/run-fix-transfer-direction.php (dry run by default).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';

class BankTransferDirectionRepair
{
    private PDO $db;
    private LedgerService $ledger;
    private LedgerSyncService $sync;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
        $this->sync = new LedgerSyncService($db, $this->ledger);
    }

    /**
     * What would change. Writes nothing.
     * @return array{items: array, locked: array, count: int, amount: float, bank_effect: float,
     *               by_kind: array, locked_count: int, locked_amount: float}
     */
    public function plan(): array
    {
        $rows = $this->db->query("
            SELECT je.id AS entry_id, je.entry_date, t.id, t.transaction_date, t.type, t.amount, t.description,
                   t.bank_account_id
            FROM journal_entries je
            JOIN accounting_transactions t ON t.id = je.source_id
            WHERE je.source_type = 'bank_import' AND je.status = 'posted' AND je.reversed_by_entry_id IS NULL
              AND t.reference_type = 'bank_import' AND t.type = 'transfer'
            ORDER BY t.transaction_date, t.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::summarise([], []);

        $facts = $this->sync->directionFacts();
        $defaultBank = $this->ledger->accountId(LedgerService::ACC_BANK);
        $posted = $this->postedLines(array_map(fn($r) => (int)$r['entry_id'], $rows));

        $items = []; $locked = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $f = $facts[$id] ?? [];
            if (LedgerSyncService::transferDirection($f) !== 'in') continue;   // money out: posted right
            $bankId = !empty($r['bank_account_id']) ? (int)$r['bank_account_id'] : $defaultBank;
            $want = $this->sync->bankEntryArgsFor($id);
            $item = self::judge($r, $posted[(int)$r['entry_id']] ?? [], $want, $bankId, !empty($f['settled']));
            if ($item === null) continue;
            if ($this->ledger->isLocked((string)$r['entry_date']) || $this->ledger->isLocked((string)$r['transaction_date'])) {
                $locked[] = $item;
            } else {
                $items[] = $item;
            }
        }
        return self::summarise($items, $locked);
    }

    /**
     * Correct what plan() finds (it is rebuilt here — nothing is trusted from the browser).
     * @return array{fixed: int, failed: int, skipped_locked: int, errors: string[]}
     */
    public function apply(?int $userId = null): array
    {
        if (!$this->ledger->canRepostSource()) {
            return ['fixed' => 0, 'failed' => 0, 'skipped_locked' => 0,
                    'errors' => ['Run migration 1131 first — the books are corrected with reversing entries, never deletes.']];
        }
        $p = $this->plan();
        $fixed = 0; $failed = 0; $errors = [];
        foreach ($p['items'] as $item) {
            try {
                $this->ledger->reverseEntry((int)$item['entry_id'], $userId,
                    $item['kind'] === 'backwards' ? 'deposit was posted as money out' : 'deposit is carried by its invoice payments', 'owner');
                $args = $this->sync->bankEntryArgsFor((int)$item['id']);
                if ($args) {
                    $args['created_by'] = $userId;
                    $args['proposed_by'] = 'owner';
                    $this->ledger->postManual($args);
                }
                $fixed++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Bank line ' . $item['id'] . ': ' . $e->getMessage();
            }
        }
        return ['fixed' => $fixed, 'failed' => $failed, 'skipped_locked' => count($p['locked']), 'errors' => array_slice($errors, 0, 20)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One money-IN line: does its live entry disagree with what it should post now?
     * @param array      $row     id, entry_id, transaction_date, amount, description
     * @param array      $lines   the entry's lines: [account_id, debit, credit]
     * @param array|null $want    bankRowToEntryArgs() now (null = post nothing)
     * @return array|null the item, or null when the entry is already right
     */
    public static function judge(array $row, array $lines, ?array $want, int $bankId, bool $settled): ?array
    {
        $postedBank = self::bankNet($lines, $bankId);
        $wantBank = $want ? self::bankNet($want['lines'], $bankId) : 0.0;
        if (abs($postedBank - $wantBank) < 0.005) return null;
        return [
            'id'          => (int)$row['id'],
            'entry_id'    => (int)$row['entry_id'],
            'date'        => substr((string)$row['transaction_date'], 0, 10),
            'amount'      => round((float)$row['amount'], 2),
            'description' => (string)($row['description'] ?? ''),
            'kind'        => $postedBank < 0 ? 'backwards' : 'double_cash',
            'settled'     => $settled,
            'posted_bank' => round($postedBank, 2),
            'want_bank'   => round($wantBank, 2),
            'bank_effect' => round($wantBank - $postedBank, 2),
            'repost'      => $want !== null,
        ];
    }

    /** Net debit − credit on the bank account across an entry's lines. Pure. */
    public static function bankNet(array $lines, int $bankId): float
    {
        $n = 0.0;
        foreach ($lines as $l) {
            if ((int)$l['account_id'] !== $bankId) continue;
            $n += (float)($l['debit'] ?? 0) - (float)($l['credit'] ?? 0);
        }
        return round($n, 2);
    }

    /** Totals for the page. Pure. */
    public static function summarise(array $items, array $locked): array
    {
        $byKind = [];
        foreach ($items as $i) {
            $k = $i['kind'];
            $byKind[$k] = $byKind[$k] ?? ['count' => 0, 'amount' => 0.0];
            $byKind[$k]['count']++;
            $byKind[$k]['amount'] = round($byKind[$k]['amount'] + $i['amount'], 2);
        }
        return [
            'items'         => $items,
            'locked'        => $locked,
            'count'         => count($items),
            'amount'        => round(array_sum(array_column($items, 'amount')), 2),
            'bank_effect'   => round(array_sum(array_column($items, 'bank_effect')), 2),
            'by_kind'       => $byKind,
            'locked_count'  => count($locked),
            'locked_amount' => round(array_sum(array_column($locked, 'amount')), 2),
        ];
    }

    /** entry_id => its lines. */
    private function postedLines(array $entryIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($entryIds)), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("SELECT entry_id, account_id, debit, credit FROM journal_lines WHERE entry_id IN ($in)");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $out[(int)$l['entry_id']][] = $l;
            }
        }
        return $out;
    }
}
