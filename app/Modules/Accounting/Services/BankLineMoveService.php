<?php
/**
 * BankLineMoveService — the one way a bank line moves to another account (2026-10-07).
 *
 * Recategorising used to change only accounting_transactions.account_id; the line's
 * journal entry (journal_entries source_type='bank_import', source_id = the bank row)
 * kept debiting the old account, so the ledger and the bank list disagreed silently
 * (Known-Failure-Patterns 2026-10-07; migrations 1212–1214 cleaned up the TD loan).
 * Now every move — Penny's bank card (one line, or "also put the other N lines from
 * <payee> on <account>"), and recategorize on the transactions screen — goes through
 * move(), which also moves the books:
 *   - the line's type follows the account, the way LedgerSyncService::bankRowToEntryArgs
 *     reads it: money OUT to an asset / liability / equity account is a 'transfer'
 *     (not a cost), money out to anything else an 'expense'; money IN stays 'income'
 *     (bankRowToEntryArgs credits a non-revenue account, skips a revenue one);
 *   - GST is zeroed when the line moves to an asset / liability / equity account;
 *   - the journal is corrected the append-only way (migration 1131): the line's entry is
 *     reversed — every line it really holds, so a line still sitting on an older account
 *     (the 6130 stragglers) is reversed too — and the entry is posted again from the row
 *     as it is now. A revenue deposit posts nothing (the invoice carries it).
 * Locked months are refused. Lines tied to a receipt or invoice post from that side
 * and are left alone.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';
require_once __DIR__ . '/BankImportService.php';
require_once __DIR__ . '/BankInvoiceMatchService.php';

class BankLineMoveService
{
    /** Account types a payment OUT is a transfer to (not a cost). */
    public const TRANSFER_TYPES = ['asset', 'liability', 'equity'];
    /** Never bulk-file onto these (Other Services, Miscellaneous). */
    public const DEFAULT_CODES = ['4900', '6900'];
    /** One bulk click moves at most this many lines. */
    public const BULK_MAX = 200;

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
     * Move one bank line (or any transaction) to an account, with its journal entry.
     * @param bool $journal false = the caller links a receipt next (that reverses the entry itself)
     * @return array{ok: bool, message: string, journal?: string, type?: string, account?: array}
     */
    public function move(int $txId, int $accountId, int $userId, string $proposedBy = 'owner', bool $journal = true): array
    {
        $s = $this->db->prepare("SELECT * FROM accounting_transactions WHERE id = ?");
        $s->execute([$txId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx) return ['ok' => false, 'message' => 'Transaction not found'];
        if ($this->ledger->isLocked((string)$tx['transaction_date'])) {
            return ['ok' => false, 'message' => substr((string)$tx['transaction_date'], 0, 7) . ' is locked — I can\'t change a line in a closed month.'];
        }
        $acct = $this->account($accountId);
        if (!$acct) return ['ok' => false, 'message' => 'Unknown account'];

        $moneyIn = ($tx['type'] ?? '') === 'transfer'
            && LedgerSyncService::transferDirection($this->sync->directionFacts($txId)[$txId] ?? []) === 'in';
        $type = self::typeFor((string)$tx['type'], (string)$acct['type'], $moneyIn);
        $zeroGst = in_array($acct['type'], self::TRANSFER_TYPES, true);
        $this->db->prepare("UPDATE accounting_transactions SET account_id = ?, type = ?, is_auto_categorized = 0"
                           . ($zeroGst ? ', gst_amount = 0' : '') . " WHERE id = ?")
           ->execute([$accountId, $type, $txId]);

        $done = 'none';
        if ($journal && ($tx['reference_type'] ?? '') === 'bank_import') {
            $done = $this->rejournal($txId, $userId, $proposedBy, 'moved to ' . $acct['code'] . ' ' . $acct['name']);
        }
        return ['ok' => true, 'message' => 'Moved to ' . $acct['code'] . ' ' . $acct['name'] . '.', 'journal' => $done, 'type' => $type, 'account' => $acct];
    }

    /**
     * Bring a bank line's journal entry in line with the row: reverse what is posted,
     * post it again as the row is now.
     * @return string 'reposted' | 'posted' | 'reversed' | 'none' | 'blocked' | 'failed'
     */
    public function rejournal(int $txId, int $userId, string $proposedBy = 'owner', string $why = 'bank line moved'): string
    {
        try {
            $entryId = $this->ledger->findEntryIdBySource('bank_import', $txId);
            $args = $this->sync->bankEntryArgsFor($txId);
            if ($entryId && !$this->ledger->canRepostSource()) {
                error_log("Bank line {$txId} moved but its journal entry #{$entryId} can't be re-posted before migration 1131");
                return 'blocked';
            }
            if ($entryId) $this->ledger->reverseEntry($entryId, $userId, $why, $proposedBy);
            if ($args) {
                $args['created_by'] = $userId;
                $args['proposed_by'] = $proposedBy;
                $this->ledger->postManual($args);
            }
            return $entryId ? ($args ? 'reposted' : 'reversed') : ($args ? 'posted' : 'none');
        } catch (Throwable $e) {
            // The repost runner (LedgerRepostService) finds a bank line whose entry disagrees.
            error_log("Bank line {$txId} journal move failed: " . $e->getMessage());
            return 'failed';
        }
    }

    /**
     * The other lines from the same payee the owner could file on this account in one click.
     * @return array<int, array{id: int, transaction_date: string, amount: float, description: string, account_id: ?int}>
     */
    public function bulkCandidates(int $txId, int $accountId): array
    {
        $s = $this->db->prepare("SELECT id, description, type FROM accounting_transactions WHERE id = ?");
        $s->execute([$txId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx) return [];
        $acct = $this->account($accountId);
        if (!$acct || in_array((string)$acct['code'], self::DEFAULT_CODES, true)) return [];
        $key = BankImportService::descriptionKey((string)$tx['description']);
        if (strlen($key) < 4) return [];

        // Narrow in SQL by the key's first word; the exact key is compared in PHP.
        $first = explode(' ', $key)[0];
        $invoiceCol = $this->hasColumn('matched_invoice_id') ? 't.matched_invoice_id' : 'NULL AS matched_invoice_id';
        $reviewed = $this->hasTable('bank_line_reviews')
            ? 'AND NOT EXISTS (SELECT 1 FROM bank_line_reviews r WHERE r.transaction_id = t.id)' : '';
        $q = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.account_id, t.status,
                   t.matched_expense_id, {$invoiceCol}
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.id <> ? AND t.description LIKE ? {$reviewed}
            ORDER BY t.transaction_date, t.id
        ");
        $q->execute([$txId, '%' . $first . '%']);
        $locked = [];
        try {
            foreach ($this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $locked[] = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
            }
        } catch (Throwable $e) { /* no periods table → nothing locked */ }

        $rows = self::pickBulk($q->fetchAll(PDO::FETCH_ASSOC), $key, $accountId, $locked, $txId, ($tx['type'] ?? '') === 'income');
        return array_slice(array_map(fn($r) => ['id' => (int)$r['id'], 'transaction_date' => substr((string)$r['transaction_date'], 0, 10),
                                                'amount' => round((float)$r['amount'], 2), 'description' => (string)$r['description'],
                                                'account_id' => $r['account_id'] !== null ? (int)$r['account_id'] : null], $rows), 0, self::BULK_MAX);
    }

    /** What the card offers after a decision, or null when there is nothing else to move. */
    public function bulkOffer(int $txId, int $accountId): ?array
    {
        $c = $this->bulkCandidates($txId, $accountId);
        if (!$c) return null;
        $acct = $this->account($accountId);
        $s = $this->db->prepare("SELECT description FROM accounting_transactions WHERE id = ?");
        $s->execute([$txId]);
        $desc = (string)$s->fetchColumn();
        return ['count' => count($c), 'total' => round(array_sum(array_column($c, 'amount')), 2),
                'payee' => self::payeeLabel($desc, BankImportService::descriptionKey($desc)),
                'account_id' => $accountId, 'account' => $acct['code'] . ' ' . $acct['name'],
                'from' => $c[0]['transaction_date'], 'to' => end($c)['transaction_date']];
    }

    /**
     * "Also put the other N lines from <payee> on <account>": moves each (with its journal
     * entry) and marks it reviewed. Candidates are found again here — never trusted from
     * the browser. @return array{moved: int, failed: int, ids: int[]}
     */
    public function bulkApply(int $txId, int $accountId, int $userId): array
    {
        $moved = []; $failed = 0;
        foreach ($this->bulkCandidates($txId, $accountId) as $c) {
            $r = $this->move($c['id'], $accountId, $userId, 'owner');
            if (empty($r['ok'])) { $failed++; continue; }
            $moved[] = $c['id'];
            if ($this->hasTable('bank_line_reviews')) {
                try {
                    $this->db->prepare("INSERT INTO bank_line_reviews (transaction_id, suggested_account_id, final_account_id, outcome, decided_by)
                                        VALUES (?, NULL, ?, 'bulk', ?)")->execute([$c['id'], $accountId, $userId]);
                } catch (Throwable $e) { /* already reviewed meanwhile — the move stands */ }
            }
        }
        return ['moved' => count($moved), 'failed' => $failed, 'ids' => $moved];
    }

    private function account(int $id): ?array
    {
        $a = $this->db->prepare("SELECT id, code, name, type FROM chart_of_accounts WHERE id = ? AND is_active = 1");
        $a->execute([$id]);
        return $a->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function hasColumn(string $col): bool
    {
        try {
            $this->db->query("SELECT {$col} FROM accounting_transactions LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->db->query("SELECT 1 FROM {$table} LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The line's type on its new account, read the way bankRowToEntryArgs posts it.
     * A deposit already flipped to 'transfer' (money IN, $moneyIn) stays a transfer: it is
     * carried by its invoices, and as an 'expense' it would post as money out.
     */
    public static function typeFor(string $oldType, string $accountType, bool $moneyIn = false): string
    {
        if ($oldType === 'income') return 'income';
        if ($oldType === 'transfer' && $moneyIn) return 'transfer';
        return in_array($accountType, self::TRANSFER_TYPES, true) ? 'transfer' : 'expense';
    }

    /**
     * Which lines a bulk click moves: same payee key, same direction (in / out), not
     * already on the account, not tied to a receipt or invoice, not void / reconciled,
     * not in a locked month, and never a client's payment (TD e-Transfer credits all
     * read the same; each belongs to an invoice).
     * @param string[] $lockedMonths 'YYYY-MM'
     */
    public static function pickBulk(array $rows, string $key, int $accountId, array $lockedMonths, int $exceptId, bool $income): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['id'] === $exceptId) continue;
            if (BankImportService::descriptionKey((string)$r['description']) !== $key) continue;
            if ((($r['type'] ?? '') === 'income') !== $income) continue;
            if ((int)($r['account_id'] ?? 0) === $accountId) continue;
            if (!empty($r['matched_expense_id']) || !empty($r['matched_invoice_id'])) continue;
            if (in_array((string)($r['status'] ?? ''), ['void', 'deleted', 'reconciled'], true)) continue;
            if (in_array(substr((string)$r['transaction_date'], 0, 7), $lockedMonths, true)) continue;
            if ($income && BankInvoiceMatchService::looksLikeClientPayment((string)$r['description'])) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** "Wave PYRL" from "WAVE PYRL 12345 …" — the key's words as the bank wrote them. */
    public static function payeeLabel(string $description, string $key): string
    {
        $words = [];
        foreach (array_slice(explode(' ', $key), 0, 4) as $w) {
            if ($w === '') continue;
            $w = preg_match('/\b(' . preg_quote($w, '/') . ')\b/i', $description, $m) ? $m[1] : $w;
            // All capitals reads as shouting: "WAVE" → "Wave", but abbreviations stay ("PYRL", "TD", "ICBC").
            if (preg_match('/^[A-Z]+$/', $w)) {
                $vowels = preg_match_all('/[AEIOU]/', $w);
                $len = strlen($w);
                if ($vowels > 0 && $len <= 2) $w = strtolower($w);
                elseif ($vowels >= 2 || ($vowels === 1 && $len >= 5)) $w = ucfirst(strtolower($w));
            }
            $words[] = $w;
        }
        return $words ? implode(' ', $words) : $key;
    }
}
