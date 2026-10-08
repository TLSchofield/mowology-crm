<?php
/**
 * BankAccountSplitService — move the savings accounts' lines out of 1010 Chequing (2026-10-07).
 *
 * Vancity prints three accounts on one statement: chequing ••6801, "Reserve funds" savings
 * ••6819 and "GST Reserves" savings ••6827. Every line of those statements was imported to
 * 1010 Chequing, so the savings lines sit in chequing. The statements check
 * (StatementCoverageService) saw them as lines that "follow a balance of their own", plus
 * chain breaks where the chequing balance seems to drop to $8.60 and back.
 *
 * This service separates them by the running balance printed on each line
 * (bank_import_rows.raw_row → raw_line):
 *   1. segment()  — lines whose balances follow on from each other EXACTLY form a segment.
 *                   Segments are never joined: the first version joined them by balance size
 *                   and, on live data, glued 500+ Mar–Jul 2026 chequing lines onto a Nov-2025
 *                   savings segment across a four-month gap. Each segment is decided alone.
 *   2. evidence() — what its descriptions say: "FROM / TO # …6801" (a transfer naming
 *                   chequing → the line is on ANOTHER account), chequing-type activity
 *                   (Stripe, ICBC, POS, e-Transfer, pre-authorized…), interest, membership shares;
 *   3. suggest()  — savings transfer → 1020 / 1025 (guess()); chequing activity → leave;
 *                   shares → never moved; nothing → leave. The largest chequing-looking
 *                   segment IS chequing. Every other segment is listed with its evidence; the
 *                   owner picks — nothing moves without his pick.
 *   parallelSeries() — two segments over the same days sharing lines are one account read twice
 *                   with different balances (an import problem, not two accounts).
 * Lines imported after the importer learnt the account headers carry the account
 * ("statement_account" in raw_row) and are grouped by it directly.
 *
 * apply(): per line, bank_account_id → the picked account, and the journal is corrected the
 * append-only way (Known-Failure-Patterns; migration 1131): the line's live bank_import entry
 * is reversed and the entry LedgerSyncService::bankEntryArgsFor() builds now is posted, so the
 * bank side lands on 1020 / 1025. Locked months are skipped and reported. Every move is logged
 * in bank_account_split_log (migration 1234); undo() puts a batch back the same way.
 *
 * Run from public/crm/api/bank-account-split.php (dry run by default).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';
require_once __DIR__ . '/StatementCoverageService.php';

class BankAccountSplitService
{
    public const SOURCE_CODE = '1010';
    /** Where a chain can go. default_names: the chart names migration 1234 may rename. */
    public const TARGETS = [
        '1020' => ['suffix' => '6819', 'label' => 'Reserve funds ••6819', 'name' => 'Reserve funds (Savings ••6819)', 'default_names' => ['Savings Account']],
        '1025' => ['suffix' => '6827', 'label' => 'GST Reserves ••6827', 'name' => 'GST Reserves (Savings ••6827)', 'default_names' => ['GST Reserve Account']],
    ];
    public const CONFIRM_PHRASE = 'MOVE-SAVINGS-LINES';
    public const UNDO_PHRASE = 'UNDO-SAVINGS-MOVE';
    /** A segment continues an account only when |opening − last| / size is below this. */
    public const JOIN_MAX_REL = 0.9;
    public const CENT = 0.015;
    /** Lines at or under this are interest — no signal for the guess. */
    public const INTEREST_MAX = 1.00;
    /** Share of a chain's money-in lines that must be whole dollars to look like set-asides. */
    public const ROUND_SHARE = 0.6;

    private PDO $db;
    private LedgerService $ledger;
    private LedgerSyncService $sync;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
        $this->sync = new LedgerSyncService($db, $this->ledger);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plan (read-only)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What the chains are and what would move. Writes nothing.
     */
    public function plan(): array
    {
        $problems = [];
        $chequingId = $this->accountIdOrNull(self::SOURCE_CODE);
        if ($chequingId === null) return ['ready' => false, 'problems' => ['No 1010 account in the chart.'], 'chains' => []];
        $targets = [];
        foreach (self::TARGETS as $code => $t) {
            $a = $this->account($code);
            if ($a) $targets[$code] = $a + ['label' => $t['label']];
            else $problems[] = "Chart account {$code} is missing — run migration 1234 first.";
        }
        if (!$this->hasTable('bank_account_split_log')) $problems[] = 'Run migration 1234 first (the move log table is missing).';
        if (!$this->ledger->canRepostSource()) $problems[] = 'Run migration 1131 first — the books are corrected with reversing entries, never deletes.';

        $lines = $this->loadLines($chequingId);
        $sessionInfo = $this->sessionInfo(array_values(array_unique(array_column($lines, 'session'))));
        $split = self::split($lines);
        $locked = $this->lockedMonths();
        $catCodes = $this->codesById();

        $chains = [];
        foreach ($split['chains'] as $c) {
            $items = [];
            foreach ($c['lines'] as $l) {
                $items[] = self::lineItem($l, $chequingId, $locked, $catCodes, $targets);
            }
            usort($items, fn($a, $b) => [$a['date'], $a['row_id']] <=> [$b['date'], $b['row_id']]);
            $c['items'] = $items;
            $c['movable'] = count(array_filter($items, fn($i) => $i['movable']));
            $c['locked'] = count(array_filter($items, fn($i) => $i['locked']));
            $c['movable_amount'] = round(array_sum(array_map(fn($i) => $i['movable'] ? $i['amount'] : 0, $items)), 2);
            if ($c['suggest'] === 'exclude') $c['movable'] = 0;
            foreach ($c['sessions'] as &$si) $si += $sessionInfo[$si['session']] ?? [];
            unset($si, $c['lines']);
            $chains[] = $c;
        }

        return [
            'ready'     => !$problems,
            'problems'  => $problems,
            'targets'   => $targets,
            'chequing'  => $this->account(self::SOURCE_CODE),
            'chains'    => $chains,
            'lines'     => count($lines),
            'main_segment' => $split['main_segment'],
            'parallel'  => array_map(function ($p) use ($sessionInfo) {
                $p['sessions_a'] = array_map(fn($s) => ['session' => $s] + ($sessionInfo[$s] ?? []), $p['sessions_a']);
                $p['sessions_b'] = array_map(fn($s) => ['session' => $s] + ($sessionInfo[$s] ?? []), $p['sessions_b']);
                return $p;
            }, $split['parallel']),
            'loose'     => $split['loose'],
            'before'    => $split['before'],
            'after'     => $split['after'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apply / undo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Move the chains the owner assigned. The plan is rebuilt here — only chain keys and
     * picks come from the browser.
     * @param array<string, string> $assign chain key => '1020' | '1025' | 'keep'
     * @return array{ok: bool, message: string, batch?: string, moved: int, skipped_locked: int, failed: int, errors: string[], by_target: array}
     */
    public function apply(array $assign, int $userId): array
    {
        $out = ['ok' => false, 'message' => '', 'moved' => 0, 'skipped_locked' => 0, 'failed' => 0, 'errors' => [], 'by_target' => []];
        $plan = $this->plan();
        if (!$plan['ready']) { $out['message'] = implode(' ', $plan['problems']); return $out; }

        $picked = [];
        foreach ($plan['chains'] as $c) {
            $pick = (string)($assign[$c['key']] ?? '');
            if ($c['suggest'] === 'exclude') continue;   // membership shares: never moved
            if (isset(self::TARGETS[$pick])) $picked[] = [$c, $pick];
        }
        if (!$picked) { $out['message'] = 'Pick an account for at least one chain. Nothing was changed.'; return $out; }

        $batch = 'split-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $chequingId = (int)$plan['chequing']['id'];
        foreach ($picked as [$c, $code]) {
            $toId = (int)$plan['targets'][$code]['id'];
            foreach ($c['items'] as $i) {
                if ($i['locked']) { $out['skipped_locked']++; continue; }
                if (!$i['movable']) continue;
                $r = $this->moveLine((int)$i['tx_id'], $chequingId, $toId, $userId, $batch, (string)$c['key'],
                                     $i['mirror'] ?? null, $i['dir']);
                if ($r === true) {
                    $out['moved']++;
                    $out['by_target'][$code] = ($out['by_target'][$code] ?? 0) + 1;
                    if (!empty($i['mirror'])) $out['transfers'] = ($out['transfers'] ?? 0) + 1;
                } elseif ($r === 'locked') {
                    $out['skipped_locked']++;
                } else {
                    $out['failed']++;
                    $out['errors'][] = 'Bank line ' . $i['tx_id'] . ': ' . $r;
                }
            }
        }
        $this->forgetCoverage();
        $out['ok'] = $out['moved'] > 0 || $out['failed'] === 0;
        $out['batch'] = $batch;
        $out['errors'] = array_slice($out['errors'], 0, 20);
        $out['message'] = "Moved {$out['moved']} lines"
            . (!empty($out['transfers']) ? " ({$out['transfers']} chequing ↔ savings transfers made one transfer)" : '')
            . ($out['skipped_locked'] ? ", {$out['skipped_locked']} left alone in locked months" : '')
            . ($out['failed'] ? ", {$out['failed']} failed" : '') . '. Undo key: ' . $batch . '.';
        return $out;
    }

    /**
     * One line: bank account → $toId, journal reversed and posted again. Logged.
     * With a $mirror (the same transfer printed in the chequing section and still on chequing),
     * the two become ONE transfer: this savings line is filed on its own account (posts
     * nothing — LedgerSyncService) and the chequing line's category becomes the savings
     * account (money out → transfer, DR savings / CR chequing; money in → DR chequing / CR savings).
     * @return true|string true, 'locked', or the error
     */
    private function moveLine(int $txId, int $fromId, int $toId, int $userId, string $batch, string $chainKey, ?array $mirror = null, ?int $dir = null)
    {
        $tx = $this->tx($txId);
        if (!$tx) return 'not found';
        $cur = $tx['bank_account_id'] !== null && $tx['bank_account_id'] !== '' ? (int)$tx['bank_account_id'] : $fromId;
        if ($cur !== $fromId) return 'no longer in chequing';
        $entryId = $this->ledger->findEntryIdBySource('bank_import', $txId);
        if ($this->ledger->isLocked((string)$tx['transaction_date']) || ($entryId && $this->ledger->isLocked($this->entryDate($entryId)))) {
            return 'locked';
        }
        $mirrorTx = null;
        if ($mirror && !empty($mirror['tx_id'])) {
            $mirrorTx = $this->tx((int)$mirror['tx_id']);
            $mb = $mirrorTx && $mirrorTx['bank_account_id'] !== null && $mirrorTx['bank_account_id'] !== '' ? (int)$mirrorTx['bank_account_id'] : $fromId;
            $mEntry = $mirrorTx ? $this->ledger->findEntryIdBySource('bank_import', (int)$mirrorTx['id']) : null;
            if (!$mirrorTx || $mb !== $fromId || $this->ledger->isLocked((string)$mirrorTx['transaction_date'])
                || ($mEntry && $this->ledger->isLocked($this->entryDate($mEntry)))) {
                $mirrorTx = null;   // its twin moved or is locked: move this line alone
            }
        }

        $origBank = $tx['bank_account_id'] !== null && $tx['bank_account_id'] !== '' ? (int)$tx['bank_account_id'] : null;
        if ($mirrorTx) {
            $this->db->prepare("UPDATE accounting_transactions SET bank_account_id = ?, account_id = ?, type = 'transfer', gst_amount = 0 WHERE id = ?")
               ->execute([$toId, $toId, $txId]);
        } else {
            $this->db->prepare("UPDATE accounting_transactions SET bank_account_id = ? WHERE id = ?")->execute([$toId, $txId]);
        }
        try {
            $j = $this->rejournal($txId, $userId, 'line belongs to savings, not chequing');
        } catch (Throwable $e) {
            $this->restore($txId, $origBank, $tx);
            try { $this->rejournal($txId, $userId, 'savings move failed — put back'); } catch (Throwable $e2) { /* repost runner */ }
            return $e->getMessage();
        }
        $this->log($batch, $chainKey, 'move', $txId, $origBank, $toId, $j, $userId,
                   $mirrorTx ? $tx : null, $mirrorTx ? $toId : null);

        if ($mirrorTx) {
            $mid = (int)$mirrorTx['id'];
            // the chequing side: money out of chequing when the savings line was money in
            $type = $dir === -1 ? 'income' : 'transfer';
            $this->db->prepare("UPDATE accounting_transactions SET account_id = ?, type = ?, gst_amount = 0 WHERE id = ?")->execute([$toId, $type, $mid]);
            try {
                $mj = $this->rejournal($mid, $userId, 'chequing ↔ savings transfer');
                $mb = $mirrorTx['bank_account_id'] !== null && $mirrorTx['bank_account_id'] !== '' ? (int)$mirrorTx['bank_account_id'] : null;
                $this->log($batch, $chainKey, 'mirror', $mid, $mb, $mb ?? $fromId, $mj, $userId, $mirrorTx, $toId);
            } catch (Throwable $e) {
                $this->restore($mid, null, $mirrorTx, false);
                try { $this->rejournal($mid, $userId, 'transfer pairing failed — put back'); } catch (Throwable $e2) { /* repost runner */ }
                return 'moved, but its chequing twin ' . $mid . ' could not be made the transfer: ' . $e->getMessage();
            }
        }
        return true;
    }

    /** Reverse the live bank_import entry, post what the row says now. @return array{old: ?int, rev: ?int, new: ?int} */
    private function rejournal(int $txId, int $userId, string $why): array
    {
        $entryId = $this->ledger->findEntryIdBySource('bank_import', $txId);
        $revId = $entryId ? $this->ledger->reverseEntry($entryId, $userId, $why, 'owner') : null;
        $newId = null;
        $args = $this->sync->bankEntryArgsFor($txId);
        if ($args) {
            $args['created_by'] = $userId;
            $args['proposed_by'] = 'owner';
            $newId = $this->ledger->postManual($args);
        }
        return ['old' => $entryId, 'rev' => $revId, 'new' => $newId];
    }

    private function tx(int $id): ?array
    {
        $s = $this->db->prepare("SELECT id, transaction_date, bank_account_id, account_id, type, gst_amount FROM accounting_transactions WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Put a row's bank account (and category / type / GST) back. */
    private function restore(int $txId, ?int $bank, array $old, bool $withBank = true): void
    {
        if ($withBank) {
            $this->db->prepare("UPDATE accounting_transactions SET bank_account_id = ?, account_id = ?, type = ?, gst_amount = ? WHERE id = ?")
               ->execute([$bank, $old['account_id'], $old['type'], $old['gst_amount'] ?? 0, $txId]);
        } else {
            $this->db->prepare("UPDATE accounting_transactions SET account_id = ?, type = ?, gst_amount = ? WHERE id = ?")
               ->execute([$old['account_id'], $old['type'], $old['gst_amount'] ?? 0, $txId]);
        }
    }

    private function log(string $batch, string $chainKey, string $kind, int $txId, ?int $fromBank, int $toBank, array $j, int $userId,
                         ?array $oldCat = null, ?int $newAccountId = null): void
    {
        $this->db->prepare("
            INSERT INTO bank_account_split_log
                (batch_id, chain_key, kind, transaction_id, from_bank_account_id, to_bank_account_id,
                 old_account_id, old_type, old_gst_amount, new_account_id,
                 old_entry_id, reversal_entry_id, new_entry_id, moved_by, moved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$batch, $chainKey, $kind, $txId, $fromBank, $toBank,
                     $oldCat ? (int)$oldCat['account_id'] : null, $oldCat ? (string)$oldCat['type'] : null,
                     $oldCat ? (float)($oldCat['gst_amount'] ?? 0) : null, $newAccountId,
                     $j['old'], $j['rev'], $j['new'], $userId ?: null]);
    }

    /**
     * Put a batch back: bank account (and, for a paired transfer, category / type / GST) → what
     * it was; the line's live entry reversed and the line posted again. Lines changed since, or
     * in locked months, are left alone and reported.
     * @return array{ok: bool, message: string, undone: int, skipped: int, failed: int, errors: string[]}
     */
    public function undo(string $batch, int $userId): array
    {
        $out = ['ok' => false, 'message' => '', 'undone' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        if (!$this->hasTable('bank_account_split_log')) { $out['message'] = 'Run migration 1234 first.'; return $out; }
        $s = $this->db->prepare("SELECT * FROM bank_account_split_log WHERE batch_id = ? AND undone_at IS NULL ORDER BY id DESC");
        $s->execute([$batch]);
        $logs = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$logs) { $out['message'] = 'Nothing to undo for ' . $batch . '.'; return $out; }

        foreach ($logs as $log) {
            $txId = (int)$log['transaction_id'];
            $tx = $this->tx($txId);
            $isMove = ($log['kind'] ?? 'move') === 'move';
            $changed = !$tx
                || ($isMove && (int)$tx['bank_account_id'] !== (int)$log['to_bank_account_id'])
                || ($log['new_account_id'] !== null && (int)$tx['account_id'] !== (int)$log['new_account_id']);
            if ($changed) {
                $out['skipped']++; $out['errors'][] = "Bank line {$txId}: changed since the move — left alone"; continue;
            }
            $liveId = $this->ledger->findEntryIdBySource('bank_import', $txId);
            if ($this->ledger->isLocked((string)$tx['transaction_date']) || ($liveId && $this->ledger->isLocked($this->entryDate($liveId)))) {
                $out['skipped']++; $out['errors'][] = "Bank line {$txId}: month is locked — left alone"; continue;
            }
            $from = $log['from_bank_account_id'] !== null ? (int)$log['from_bank_account_id'] : null;
            $sets = []; $vals = [];
            if ($isMove) { $sets[] = 'bank_account_id = ?'; $vals[] = $from; }
            if ($log['old_account_id'] !== null) {
                $sets[] = 'account_id = ?'; $vals[] = (int)$log['old_account_id'];
                $sets[] = 'type = ?'; $vals[] = (string)$log['old_type'];
                $sets[] = 'gst_amount = ?'; $vals[] = (float)$log['old_gst_amount'];
            }
            $vals[] = $txId;
            $this->db->prepare("UPDATE accounting_transactions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            try {
                $j = $this->rejournal($txId, $userId, 'undo savings move ' . $batch);
                $this->db->prepare("UPDATE bank_account_split_log SET undone_at = NOW(), undone_by = ?, undo_reversal_entry_id = ?, undo_entry_id = ? WHERE id = ?")
                   ->execute([$userId ?: null, $j['rev'], $j['new'], (int)$log['id']]);
                $out['undone']++;
            } catch (Throwable $e) {
                $this->restore($txId, $isMove ? (int)$log['to_bank_account_id'] : null, $tx, $isMove);
                $out['failed']++; $out['errors'][] = "Bank line {$txId}: " . $e->getMessage();
            }
        }
        $this->forgetCoverage();
        $out['ok'] = $out['failed'] === 0;
        $out['errors'] = array_slice($out['errors'], 0, 20);
        $out['message'] = "Put back {$out['undone']} lines" . ($out['skipped'] ? ", {$out['skipped']} left alone" : '') . ($out['failed'] ? ", {$out['failed']} failed" : '') . '.';
        return $out;
    }

    /** Past batches for the page: batch, moved, undone, when, to. */
    public function batches(int $limit = 20): array
    {
        if (!$this->hasTable('bank_account_split_log')) return [];
        $s = $this->db->prepare("
            SELECT l.batch_id, MIN(l.moved_at) AS moved_at, SUM(CASE WHEN l.kind = 'mirror' THEN 0 ELSE 1 END) AS moved,
                   SUM(CASE WHEN l.kind = 'mirror' THEN 1 ELSE 0 END) AS transfers,
                   SUM(CASE WHEN l.undone_at IS NULL THEN 0 ELSE 1 END) AS undone,
                   GROUP_CONCAT(DISTINCT c.code) AS to_codes
            FROM bank_account_split_log l
            LEFT JOIN chart_of_accounts c ON c.id = l.to_bank_account_id AND l.kind = 'move'
            GROUP BY l.batch_id
            ORDER BY MIN(l.id) DESC
            LIMIT " . max(1, (int)$limit));
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Split one account's statement lines into chequing and the other accounts' chains.
     * @param array $lines StatementCoverageService::lineFromRow() shape + optional
     *                     marker ('6819'…), tx_id, description, tx_type, tx_bank, category_id,
     *                     matched (bool)
     * @return array{chains: array, main: array, loose: int, before: array, after: array}
     */
    public static function split(array $lines): array
    {
        // Lines that name their account (newer imports) are grouped by it; shares never move.
        $byMarker = [];
        $rest = [];
        $targetSuffixes = array_column(self::TARGETS, 'suffix');
        foreach ($lines as $l) {
            $m = (string)($l['marker'] ?? '');
            if ($m !== '' && (in_array($m, $targetSuffixes, true) || $m === 'shares')) $byMarker[$m][] = $l;
            else $rest[] = $l;
        }

        $ordered = StatementCoverageService::orderLines($rest);
        $before = StatementCoverageService::walkChain(StatementCoverageService::orderLines($lines));
        // Assigned per SEGMENT (2026-10-07, live dry run): joining segments by balance size
        // once glued 500+ chequing lines (Mar–Jul 2026) onto a Nov-2025 savings segment
        // across a four-month gap. A segment is only lines whose balances follow exactly.
        $segments = self::segment($ordered);
        foreach ($segments as &$s) $s['evidence'] = self::evidence($s['lines']);
        unset($s);

        // Chequing: the largest segment with chequing-type activity (else the largest).
        $main = null;
        foreach ($segments as $k => $s) {
            $score = [$s['evidence']['chequing'] > 0 ? 1 : 0, $s['evidence']['savings_transfer'] === 0 ? 1 : 0, count($s['lines'])];
            if ($main === null || $score > $mainScore) { $main = $k; $mainScore = $score; }
        }

        // Lines orderLines() dropped as the second print of a line (same date, amount, balance)
        // go with their twin.
        $inOrdered = [];
        foreach ($ordered as $l) $inOrdered[$l['id']] = true;
        $twinKey = fn($l) => $l['date'] . '|' . number_format((float)$l['amount'], 2, '.', '') . '|' . number_format(abs((float)$l['balance']), 2, '.', '');
        $keyToSeg = [];
        foreach ($segments as $k => $s) foreach ($s['lines'] as $l) $keyToSeg[$twinKey($l)] = $k;
        foreach ($rest as $l) {
            if (isset($inOrdered[$l['id']]) || $l['balance'] === null) continue;
            $k = $keyToSeg[$twinKey($l)] ?? null;
            if ($k !== null) $segments[$k]['twins'][] = $l;
        }

        $chains = [];
        foreach ($byMarker as $suffix => $ls) {
            $suffix = (string)$suffix;
            $code = $suffix === 'shares' ? null : self::codeForSuffix($suffix);
            usort($ls, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $ev = self::evidence($ls);
            $chains[] = self::describe($ls, 'statement', $code ?? 'exclude',
                $suffix === 'shares' ? 'Membership shares — not a bank account; never moved.' : 'The statement line names account ••' . $suffix . '.',
                $suffix, $ev);
        }
        foreach ($segments as $k => $s) {
            if ($k === $main) continue;
            [$suggest, $confidence, $why] = self::suggest($s['lines'], $s['evidence']);
            $chains[] = self::describe(array_merge($s['lines'], $s['twins'] ?? []), $confidence, $suggest, $why, null, $s['evidence']);
        }

        // Chequing without the lines suggested to move (and shares): what the default picks leave.
        $moved = [];
        foreach ($chains as $c) {
            if ($c['suggest'] === 'keep') continue;
            foreach ($c['lines'] as $l) $moved[$l['id']] = true;
        }
        $left = array_values(array_filter($lines, fn($l) => !isset($moved[$l['id']])));

        // A transfer between chequing and a savings account is printed in both sections, so
        // it is in chequing twice: once as the chequing line, once as the savings line. Pair
        // each savings line with its chequing twin (same day, same amount, other direction).
        $pool = [];
        foreach ($chains as $ci => $c) {
            if (!isset(self::TARGETS[$c['suggest']]) && $c['confidence'] !== 'statement') continue;
            foreach ($c['lines'] as $li => $l) {
                $d = self::dirOf($l);
                if ($d !== null) $pool['s' . $ci . '_' . $li] = ['date' => $l['date'], 'amount' => (float)$l['amount'], 'in' => $d === 1, 'other' => true];
            }
        }
        foreach ($left as $mi => $l) {
            $d = self::dirOf($l);
            if ($d !== null && !empty($l['tx_id'])) $pool['m' . $mi] = ['date' => $l['date'], 'amount' => (float)$l['amount'], 'in' => $d === 1, 'other' => false];
        }
        foreach (self::pairMirrors($pool) as $sk => $mk) {
            [$ci, $li] = array_map('intval', explode('_', substr($sk, 1)));
            $m = $left[(int)substr($mk, 1)];
            $chains[$ci]['lines'][$li]['mirror'] = ['row_id' => (int)$m['id'], 'tx_id' => (int)$m['tx_id'], 'description' => (string)($m['description'] ?? '')];
        }
        $after = StatementCoverageService::walkChain(StatementCoverageService::orderLines($left));

        usort($chains, fn($a, $b) => [$a['from'], $a['key']] <=> [$b['from'], $b['key']]);
        $mainLines = $main !== null ? $segments[$main]['lines'] : [];
        return [
            'chains'   => $chains,
            'main'     => $mainLines,
            'main_segment' => $mainLines ? ['from' => min(array_column($mainLines, 'date')), 'to' => max(array_column($mainLines, 'date')),
                                            'count' => count($mainLines), 'evidence' => $segments[$main]['evidence']] : null,
            'parallel' => self::parallelSeries($segments),
            'loose'    => count(array_filter($lines, fn($l) => $l['balance'] === null)),
            'before'   => self::chainSummary($before),
            'after'    => self::chainSummary($after) + ['end_to_end' => !$after['breaks'] && !$after['other_account']],
        ];
    }

    /** Chequing-type activity: a line like this is on the chequing account. */
    public const CHEQUING_RE = '/STRIPE|ICBC|POINT\s*OF\s*SALE|\bPOS\b|E-?\s?TRANSFER|INTERAC|PRE-?\s?AUTH|PREAUTHORI[ZS]ED|\bPAYROLL\b|\bCHEQUE\b|BILL\s*PAYMENT|\bWAVE\b|SERVICE\s*CHARGE|ACCOUNT\s*FEE|\bATM\b|DEBIT\s*MEMO|TD\s*ON-?LINE|VISA|MASTERCARD/i';
    /** A transfer naming the chequing account (#…6801): the line is on ANOTHER account. */
    public const SAVINGS_TRANSFER_RE = '/(?:FROM|TO)\s*(?:ACCOUNT\s*)?#?\s*\d*6801\b|(?:FROM|TO)\s+CHEQUING\b/i';
    public const SHARES_RE = '/\bSHARES?\b|MEMBERSHIP|DIVIDEND|\bEQUITY\b/i';

    /**
     * What a segment's descriptions say about its account. Pure.
     * @return array{chequing: int, savings_transfer: int, interest: int, shares: int, lines: int, examples: string[]}
     */
    public static function evidence(array $lines): array
    {
        $e = ['chequing' => 0, 'savings_transfer' => 0, 'interest' => 0, 'shares' => 0, 'lines' => count($lines), 'examples' => []];
        foreach ($lines as $l) {
            $d = (string)($l['description'] ?? '');
            $hit = null;
            if (preg_match(self::SHARES_RE, $d)) { $e['shares']++; $hit = 'shares'; }
            elseif (preg_match(self::SAVINGS_TRANSFER_RE, $d)) { $e['savings_transfer']++; $hit = 'savings'; }
            elseif (preg_match(self::CHEQUING_RE, $d)) { $e['chequing']++; $hit = 'chequing'; }
            if ((float)$l['amount'] <= self::INTEREST_MAX && preg_match('/INTEREST/i', $d) || ($d === '' && (float)$l['amount'] <= 0.05)) $e['interest']++;
            if ($hit !== null && count($e['examples']) < 4) $e['examples'][] = $hit . ': ' . mb_substr($d, 0, 70);
        }
        return $e;
    }

    /**
     * The default pick for one segment, from its evidence. Pure.
     * @return array{0: string, 1: string, 2: string} [suggest ('1020'|'1025'|'keep'|'exclude'), confidence ('strong'|'weak'|'none'), why]
     */
    public static function suggest(array $lines, array $ev): array
    {
        if ($ev['shares'] > 0) {
            return ['exclude', 'strong', 'Membership shares — not a bank account; never moved to a savings account.'];
        }
        if ($ev['savings_transfer'] > 0 && $ev['savings_transfer'] >= $ev['chequing']) {
            [$code, $why] = self::guess($lines);
            return [$code, $ev['chequing'] ? 'weak' : 'strong',
                    sprintf('%d line(s) transfer FROM / TO chequing #…6801, so they are printed on another account. ', $ev['savings_transfer']) . $why];
        }
        if ($ev['chequing'] > 0) {
            return ['keep', 'strong', sprintf('%d line(s) of chequing activity (Stripe, ICBC, POS, e-Transfer, pre-authorized…) — leave in chequing.', $ev['chequing'])];
        }
        if ($ev['interest'] > 0 && $ev['interest'] === $ev['lines']) {
            return ['1025', 'weak', 'Only interest credits — the GST Reserves account (~$8.6) earns the monthly $0.01 interest; Reserve funds ($0.69) has had no activity.'];
        }
        return ['keep', 'none', 'Nothing in the descriptions says which account — left in chequing unless you pick.'];
    }

    /**
     * Two segments over the same days that share lines (same date and amount) are the SAME
     * account's lines read twice with different balances — not two accounts. A constant
     * difference means one import read a different balance (or skipped a line before them);
     * a varying one means the lines are in a different order. Pure.
     * @return array<int, array{a: string, b: string, from: string, to: string, shared: int, offset: ?float, constant: bool, sessions_a: int[], sessions_b: int[]}>
     */
    public static function parallelSeries(array $segments): array
    {
        $out = [];
        $keys = array_keys($segments);
        foreach ($keys as $x => $ka) {
            foreach (array_slice($keys, $x + 1) as $kb) {
                $a = $segments[$ka]['lines']; $b = $segments[$kb]['lines'];
                $aFrom = min(array_column($a, 'date')); $aTo = max(array_column($a, 'date'));
                $bFrom = min(array_column($b, 'date')); $bTo = max(array_column($b, 'date'));
                if ($aFrom > $bTo || $bFrom > $aTo) continue;
                $byKey = [];
                foreach ($a as $l) $byKey[$l['date'] . '|' . number_format((float)$l['amount'], 2, '.', '')][] = (float)$l['balance'];
                $diffs = [];
                foreach ($b as $l) {
                    $k = $l['date'] . '|' . number_format((float)$l['amount'], 2, '.', '');
                    if (!empty($byKey[$k])) $diffs[] = round(array_shift($byKey[$k]) - (float)$l['balance'], 2);
                }
                if (count($diffs) < 2) continue;
                $constant = max($diffs) - min($diffs) < 0.015;
                $out[] = [
                    'a' => 'c' . min(array_column($a, 'id')), 'b' => 'c' . min(array_column($b, 'id')),
                    'from' => max($aFrom, $bFrom), 'to' => min($aTo, $bTo), 'shared' => count($diffs),
                    'offset' => $constant ? $diffs[0] : null, 'constant' => $constant,
                    'sessions_a' => array_values(array_unique(array_column($a, 'session'))),
                    'sessions_b' => array_values(array_unique(array_column($b, 'session'))),
                ];
            }
        }
        return $out;
    }

    /**
     * Lines (in statement order) whose balances follow on from each other. A line whose
     * balance follows no open segment starts a new one. Either sign is accepted: a PDF line's
     * type was read from the balance before it, which at the start of a second account's
     * section is the OTHER account's balance — so its stored direction can be wrong. Each
     * line gets 'dir' (+1 in / −1 out) from its balance where it can be read.
     */
    public static function segment(array $ordered): array
    {
        $segs = [];
        $idx = 0;
        foreach ($ordered as $l) {
            $i = $idx++;
            if ($l['balance'] === null) continue;
            $bal = (float)$l['balance'];
            $amt = (float)$l['amount'];
            $hit = null; $dir = null;
            // most recently extended segment first
            $order = array_keys($segs);
            usort($order, fn($a, $b) => $segs[$b]['last_idx'] <=> $segs[$a]['last_idx']);
            foreach ($order as $s) {
                $prev = $segs[$s]['last'];
                if (abs($prev + $amt - $bal) < self::CENT) { $hit = $s; $dir = 1; break; }
                if (abs($prev - $amt - $bal) < self::CENT) { $hit = $s; $dir = -1; break; }
            }
            if ($hit === null) {
                $l['dir'] = $l['signed'] === null ? null : ($l['signed'] >= 0 ? 1 : -1);
                $l['_i'] = $i;
                $segs[] = ['lines' => [$l], 'first_idx' => $i, 'last_idx' => $i, 'last' => $bal, 'first' => $l];
                continue;
            }
            $l['dir'] = $dir;
            $l['_i'] = $i;
            $segs[$hit]['lines'][] = $l;
            $segs[$hit]['last'] = $bal;
            $segs[$hit]['last_idx'] = $i;
        }
        usort($segs, fn($a, $b) => $a['first_idx'] <=> $b['first_idx']);
        return $segs;
    }

    /**
     * Which savings account a chain most likely is. Tim's rule: the account that only ever
     * RECEIVES money from chequing, in round amounts (regular set-asides), is GST Reserves;
     * anything else is Reserve funds. Interest-sized lines don't count either way.
     * @return array{0: string, 1: string} [code, why]
     */
    public static function guess(array $lines): array
    {
        $moves = array_values(array_filter($lines, fn($l) => (float)$l['amount'] > self::INTEREST_MAX));
        if (!$moves) {
            // Vancity, Sept 2026 statement: Reserve funds ••6819 sat at $0.69 with no activity;
            // GST Reserves ••6827 (~$8.6) earns a $0.01 interest credit each month.
            return ['1025', 'Only interest credits — the GST Reserves account (~$8.6) earns the monthly $0.01 interest; Reserve funds ($0.69) has had no activity.'];
        }
        $in = array_filter($moves, fn($l) => self::dirOf($l) === 1);
        $out = count($moves) - count($in);
        $round = array_filter($in, fn($l) => abs(round((float)$l['amount']) - (float)$l['amount']) < 0.005);
        if ($out === 0 && count($round) >= self::ROUND_SHARE * count($moves)) {
            return ['1025', sprintf('Only money in, %d of %d in whole dollars — looks like regular set-asides (GST Reserves).', count($round), count($moves))];
        }
        return ['1020', sprintf('%d in / %d out — not only set-asides, so Reserve funds.', count($in), $out)];
    }

    /**
     * Pair lines printed for another account with their twin in the statement's own account:
     * same date, same amount, opposite direction; each line used once, first come first served.
     * @param array $rows key => [date, amount, in (bool), other (bool: the savings side)]
     * @return array<string|int, string|int> savings key => chequing key
     */
    public static function pairMirrors(array $rows): array
    {
        $mains = [];
        foreach ($rows as $k => $r) if (empty($r['other'])) $mains[$k] = $r;
        $used = [];
        $out = [];
        foreach ($rows as $k => $r) {
            if (empty($r['other'])) continue;
            foreach ($mains as $mk => $m) {
                if (isset($used[$mk])) continue;
                if ($m['date'] === $r['date'] && abs((float)$m['amount'] - (float)$r['amount']) < 0.005 && (bool)$m['in'] !== (bool)$r['in']) {
                    $out[$k] = $mk;
                    $used[$mk] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /** +1 money in, −1 out, null unknown: the balance-read direction first, the stored one second. */
    public static function dirOf(array $l): ?int
    {
        if (isset($l['dir']) && $l['dir'] !== null) return (int)$l['dir'];
        if ($l['signed'] === null) return null;
        return $l['signed'] >= 0 ? 1 : -1;
    }

    /** Chart code for a statement account suffix, or null (chequing / unknown). */
    public static function codeForSuffix(string $suffix): ?string
    {
        foreach (self::TARGETS as $code => $t) if ($t['suffix'] === $suffix) return (string)$code;
        return null;
    }

    private static function describe(array $lines, string $confidence, string $suggest, string $why, ?string $marker, array $evidence): array
    {
        $bals = array_values(array_filter(array_column($lines, 'balance'), fn($b) => $b !== null));
        $sorted = $lines;
        usort($sorted, fn($a, $b) => [$a['date'], $a['_i'] ?? 0, $a['id']] <=> [$b['date'], $b['_i'] ?? 0, $b['id']]);
        $first = $sorted[0];
        $last = end($sorted);
        $open = null;
        if ($first['balance'] !== null) {
            $d = self::dirOf($first);
            $open = $d === null ? null : round((float)$first['balance'] - $d * (float)$first['amount'], 2);
        }
        $sessions = [];
        foreach ($lines as $l) {
            $sid = (int)($l['session'] ?? 0);
            $sessions[$sid] = $sessions[$sid] ?? ['session' => $sid, 'lines' => 0, 'format' => (string)($l['format'] ?? ''), 'duplicates' => 0];
            $sessions[$sid]['lines']++;
            if (!empty($l['duplicate'])) $sessions[$sid]['duplicates']++;
        }
        [$guess] = self::guess($lines);
        return [
            'key'         => 'c' . min(array_column($lines, 'id')),
            'lines'       => $lines,
            'count'       => count($lines),
            'from'        => $first['date'],
            'to'          => $last['date'],
            'min_balance' => $bals ? round(min($bals), 2) : null,
            'max_balance' => $bals ? round(max($bals), 2) : null,
            'opening'     => $open,
            'closing'     => $last['balance'] !== null ? round((float)$last['balance'], 2) : null,
            'confidence'  => $confidence,
            'suggest'     => $suggest,
            'guess'       => isset(self::TARGETS[$suggest]) ? $suggest : $guess,
            'guess_reason'=> $why,
            'evidence'    => $evidence,
            'marker'      => $marker,
            'sessions'    => array_values($sessions),
            // the stored statement text of the first lines — to see how they were read
            'samples'     => array_values(array_filter(array_map(fn($l) => (string)($l['raw_line'] ?? ''), array_slice($sorted, 0, 3)))),
        ];
    }

    /**
     * One line for the page. Movable = has a CRM transaction still on chequing and is not in
     * a locked month. Flags: sign (the CRM records the other direction from the balance),
     * self (its category IS the savings account), revenue (booked as revenue), matched (tied
     * to an invoice / receipt — its cash posts from that side, so only the bank list moves).
     */
    public static function lineItem(array $l, int $chequingId, array $lockedMonths, array $codeOf, array $targets): array
    {
        $txId = !empty($l['tx_id']) ? (int)$l['tx_id'] : null;
        $onChequing = $txId !== null && (empty($l['tx_bank']) || (int)$l['tx_bank'] === $chequingId);
        $locked = in_array(substr((string)$l['date'], 0, 7), $lockedMonths, true);
        $flags = [];
        $dir = self::dirOf($l);
        $txType = (string)($l['tx_type'] ?? '');
        if ($dir !== null && $txType !== '' && $txType !== 'transfer' && (($dir === 1) !== ($txType === 'income'))) $flags[] = 'sign';
        $cat = !empty($l['category_id']) ? (int)$l['category_id'] : null;
        $targetIds = array_map(fn($t) => (int)$t['id'], $targets);
        if ($cat !== null && in_array($cat, $targetIds, true)) $flags[] = 'self';
        if (($l['category_type'] ?? '') === 'revenue') $flags[] = 'revenue';
        if (!empty($l['matched'])) $flags[] = 'matched';
        return [
            'row_id'      => (int)$l['id'],
            'tx_id'       => $txId,
            'date'        => (string)$l['date'],
            'amount'      => round((float)$l['amount'], 2),
            'dir'         => $dir,
            'balance'     => $l['balance'] !== null ? round((float)$l['balance'], 2) : null,
            'description' => (string)($l['description'] ?? ''),
            'category'    => $cat !== null ? ($codeOf[$cat] ?? (string)$cat) : '',
            'flags'       => $flags,
            'locked'      => $locked && $onChequing,
            'movable'     => $onChequing && !$locked,
            'mirror'      => !empty($l['mirror']) ? $l['mirror'] : null,
            'why_not'     => $txId === null ? 'not in the CRM (skipped as already imported)' : (!$onChequing ? 'already on another account' : ($locked ? 'locked month' : '')),
        ];
    }

    private static function chainSummary(array $w): array
    {
        return ['breaks' => $w['breaks'], 'other_account' => $w['other_account'], 'linked' => $w['linked'],
                'with_balance' => $w['with_balance'], 'lines' => $w['lines']];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DB helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every statement line the statements check walks for chequing: rows of imported sessions
     * saved to 1010 (or saved with no account under chequing's bank name), minus lines whose
     * CRM transaction already sits on another bank account.
     */
    private function loadLines(int $chequingId): array
    {
        $names = [];
        if ($this->hasTable('bank_statement_accounts')) {
            $s = $this->db->prepare("SELECT LOWER(TRIM(bank_name_match)) FROM bank_statement_accounts WHERE account_id = ? AND bank_name_match IS NOT NULL AND bank_name_match <> ''");
            $s->execute([$chequingId]);
            $names = $s->fetchAll(PDO::FETCH_COLUMN);
        }
        $sessions = [];
        foreach ($this->db->query("SELECT id, bank_account_id, bank_name FROM bank_import_sessions WHERE status = 'imported'")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ((int)$s['bank_account_id'] === $chequingId
                || (empty($s['bank_account_id']) && in_array(strtolower(trim((string)$s['bank_name'])), $names, true))) {
                $sessions[] = (int)$s['id'];
            }
        }
        if (!$sessions) return [];
        $matchedCols = $this->hasTxColumn('matched_invoice_id') ? 't.matched_invoice_id, t.matched_expense_id' : 'NULL AS matched_invoice_id, NULL AS matched_expense_id';
        $in = implode(',', array_fill(0, count($sessions), '?'));
        $q = $this->db->prepare("
            SELECT r.id, r.session_id, r.transaction_date, r.type, r.amount, r.raw_amount, r.is_duplicate, r.raw_row,
                   t.id AS tx_id, t.bank_account_id AS tx_bank, t.type AS tx_type, t.description AS tx_description,
                   t.account_id AS category_id, c.type AS category_type, {$matchedCols}
            FROM bank_import_rows r
            LEFT JOIN accounting_transactions t ON t.id = r.transaction_id
            LEFT JOIN chart_of_accounts c ON c.id = t.account_id
            WHERE r.session_id IN ($in)
            ORDER BY r.session_id, r.id
        ");
        $q->execute($sessions);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['tx_id']) && !empty($r['tx_bank']) && (int)$r['tx_bank'] !== $chequingId) continue;   // already moved
            $raw = json_decode((string)$r['raw_row'], true) ?: [];
            $l = StatementCoverageService::lineFromRow($r, $raw, 'bank');
            $l['marker'] = isset($raw['statement_account']) ? (string)$raw['statement_account'] : '';
            $l['format'] = self::lineFormat((string)($raw['raw_line'] ?? ''));
            $l['raw_line'] = mb_substr((string)($raw['raw_line'] ?? ''), 0, 160);
            $l['tx_id'] = $r['tx_id'] !== null ? (int)$r['tx_id'] : null;
            $l['tx_bank'] = $r['tx_bank'] !== null ? (int)$r['tx_bank'] : null;
            $l['tx_type'] = $r['tx_type'];
            $l['description'] = (string)($r['tx_description'] ?? ($raw['description'] ?? ''));
            $l['category_id'] = $r['category_id'] !== null ? (int)$r['category_id'] : null;
            $l['category_type'] = $r['category_type'];
            $l['matched'] = !empty($r['matched_invoice_id']) || !empty($r['matched_expense_id']);
            $out[] = $l;
        }
        return $out;
    }

    /** csv | pdf | none — how a stored line was read. Pure. */
    public static function lineFormat(string $rawLine): string
    {
        $t = trim($rawLine);
        if ($t === '') return 'none';
        return count(str_getcsv($t, ',', '"', '')) >= 4 ? 'csv' : 'pdf';
    }

    /** session id => filename, bank_name, created_at, imported, duplicates, date range. */
    private function sessionInfo(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $cols = 'id';
        foreach (['filename', 'bank_name', 'created_at', 'imported_count', 'duplicate_count', 'date_from', 'date_to'] as $c) {
            try { $this->db->query("SELECT {$c} FROM bank_import_sessions LIMIT 0"); $cols .= ', ' . $c; } catch (Throwable $e) { /* older schema */ }
        }
        $s = $this->db->prepare("SELECT {$cols} FROM bank_import_sessions WHERE id IN ($in)");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            unset($r['id']);
            $out[$id] = $r;
        }
        return $out;
    }

    private function account(string $code): ?array
    {
        $s = $this->db->prepare("SELECT id, code, name FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $s->execute([$code]);
        $a = $s->fetch(PDO::FETCH_ASSOC);
        return $a ? ['id' => (int)$a['id'], 'code' => (string)$a['code'], 'name' => (string)$a['name']] : null;
    }

    private function accountIdOrNull(string $code): ?int
    {
        $a = $this->account($code);
        return $a ? $a['id'] : null;
    }

    private function codesById(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, code, name FROM chart_of_accounts")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r['code'] . ' ' . $r['name'];
        }
        return $out;
    }

    /** 'YYYY-MM' of every locked period. */
    private function lockedMonths(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
            }
        } catch (Throwable $e) { /* no periods table → nothing locked */ }
        return $out;
    }

    private function entryDate(int $entryId): string
    {
        $s = $this->db->prepare("SELECT entry_date FROM journal_entries WHERE id = ?");
        $s->execute([$entryId]);
        return (string)$s->fetchColumn();
    }

    private function forgetCoverage(): void
    {
        try { (new StatementCoverageService($this->db))->forget(); } catch (Throwable $e) { /* no cache */ }
    }

    private function hasTable(string $t): bool
    {
        try {
            $this->db->query("SELECT 1 FROM {$t} LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasTxColumn(string $col): bool
    {
        try {
            $this->db->query("SELECT {$col} FROM accounting_transactions LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
