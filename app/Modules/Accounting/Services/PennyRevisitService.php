<?php
declare(strict_types=1);

/**
 * PennyRevisitService — Penny looks again at bank lines she (or the owner) already filed (2026-10-21).
 *
 * Why: a new fact about a payee (a vendor added, a payee identified, a rule learned) never reached lines already
 * filed. bank_line_reviews is one-row-per-line and the desk skips reviewed lines, rules apply only at import, and no
 * save hook re-scans anything. Real case (2026-10-20): "Canev" = Canadian Electric Vehicles (truck repairs). Its four
 * payments sat on 5400 Subcontractors (three in the locked 2025 year) and 6900.
 *
 * What it does (STAGE 1: propose only; nothing moves unless the owner approves a payee group):
 *   scan()   runs Penny's OWN advisor (BankDeskService::advise, not new matching) over bank lines she has not
 *            already filed on evidence, and records what she would now do in penny_revisits;
 *   tiers    A  default account (4900/6900), open month, owner-authored evidence (a vendor the owner added, an
 *               active rule): safe to apply without a click LATER; penny_revisit_mode 'auto_narrow' (ships OFF,
 *               not built into stage 1) is the only thing that would apply these on its own;
 *            B  everything else that is open: the owner approves, one decision per payee;
 *            C  the line is in a locked month: NEVER moved, listed for the accountant ("For the accountant");
 *   apply()  moves each approved line with BankLineMoveService::move (append-only: reverse, repost; all or nothing;
 *            refuses locked months), notes why on the line, and counts as the owner's confirmation for rule learning;
 *   undo()   moves a line back to the account it came from;
 *   dismiss  the owner says no; the same proposal is never made again (an undone line is likewise settled).
 *   dry run  works before migration 1395: a read-only preview of what she would propose on real data.
 *
 * Never touches: payroll lines (Wave debits, wage accounts: the payroll import owns them), lines linked to a receipt or invoice (they post from that side), lines the owner deliberately put on
 * a non-default account (accepted / edited / bulk with that account still current), receipt- or invoice-sourced advice
 * (BankReceiptSweep and the desk's own linking own that), reference types other than bank_import.
 *
 * Global-namespace service: require_once the file (no autoloader in production).
 */
require_once __DIR__ . '/BankDeskService.php';
require_once __DIR__ . '/BankLineMoveService.php';
require_once __DIR__ . '/BankImportService.php';
require_once __DIR__ . '/BankRuleLearning.php';
require_once __DIR__ . '/LedgerService.php';

class PennyRevisitService
{
    /** ops_settings: off | propose | auto_narrow. Stage 1 only reads it ('off' stops scan). */
    public const MODE_KEY = 'penny_revisit_mode';
    public const DEFAULT_MODE = 'propose';
    /** First line date looked at (earlier lines are the locked, filed years: the accountant's list). */
    public const SINCE = '2025-01-01';
    /** Lines advised per scan call (a full pass is a few calls). */
    public const SCAN_MAX = 1500;
    /** Lines per advisor context (one context = a handful of queries). */
    public const CHUNK = 100;
    /** Advice that belongs to the receipt / invoice linking flows, not to this. */
    public const SKIP_SOURCES = ['found_receipt', 'receipt', 'invoice'];
    /** Evidence the owner authored (a vendor they added, a rule they confirmed twice). */
    public const AUTO_SOURCES = ['vendor', 'rule'];
    /** Wage / payroll-liability accounts: the Wave payroll import (WavePayrollImportService) owns every line that belongs there.
     *  Booking a Wave debit as wages first counts wages twice once the month's report is imported. */
    public const PAYROLL_ACCOUNTS = ['5100', '5110', '2310', '2520'];
    public const PAYROLL_TEXT = '/\b(WAVE ?PYRL|PYRL|PAYROLL)\b/i';
    /** bank_line_reviews outcomes that mean the owner deliberately chose the account. */
    public const DELIBERATE = ['accepted', 'edited', 'bulk', 'approved'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ═══ Pure (unit-tested) ═══════════════════════════════════════════════════════════════════

    public static function payeeKey(string $description): string
    {
        return substr(BankImportService::descriptionKey($description), 0, 160);
    }

    /**
     * What to do with one line given what Penny would now suggest.
     *
     * @param array      $line   reference_type, type, status, description, matched_expense_id, matched_invoice_id, account_id,
     *                           account_code, review_outcome, review_final
     * @param array|null $advice BankDeskService::advise() result (account_id, code, source, reason) or null
     * @return array{tier:string,reason:string}  tier: A | B | C | skip
     */
    public static function classify(array $line, ?array $advice, bool $locked): array
    {
        $skip = static function (string $why): array { return ['tier' => 'skip', 'reason' => $why]; };
        if (($line['reference_type'] ?? '') !== 'bank_import') return $skip('not an imported bank line');
        if (in_array((string)($line['status'] ?? ''), ['void', 'deleted'], true)) return $skip('void or deleted');
        if (!empty($line['matched_expense_id']) || !empty($line['matched_invoice_id'])) return $skip('linked to a receipt or invoice');
        if (!$advice || empty($advice['account_id'])) return $skip('no suggestion');
        if ((int)$advice['account_id'] === (int)($line['account_id'] ?? 0)) return $skip('already on that account');
        if (in_array((string)($advice['source'] ?? ''), self::SKIP_SOURCES, true)) return $skip('receipt/invoice linking owns this');
        if (in_array((string)($advice['code'] ?? ''), self::PAYROLL_ACCOUNTS, true) || preg_match(self::PAYROLL_TEXT, (string)($line['description'] ?? ''))) {
            return $skip('payroll import owns this');
        }

        $onDefault = in_array((string)($line['account_code'] ?? ''), BankDeskService::DEFAULT_CODES, true) || empty($line['account_id']);
        $outcome = (string)($line['review_outcome'] ?? '');
        // The owner put it on a real account on purpose and it is still there: not ours to second-guess.
        if (!$onDefault && in_array($outcome, self::DELIBERATE, true) && (int)($line['review_final'] ?? 0) === (int)($line['account_id'] ?? -1)) {
            return $skip('the owner chose this account');
        }

        $reason = trim((string)($advice['reason'] ?? ''));
        if ($locked) return ['tier' => 'C', 'reason' => $reason];   // listed for the accountant, never moved
        $tier = ($onDefault && in_array((string)($advice['source'] ?? ''), self::AUTO_SOURCES, true)) ? 'A' : 'B';
        return ['tier' => $tier, 'reason' => $reason];
    }

    /**
     * Proposals grouped by payee and target account (one decision per group).
     *
     * @param array $rows penny_revisits rows joined with description / account labels:
     *                    id, payee_key, to_account_id, to_label, from_label, tier, source, reason, amount, line_date, description
     * @return array<int,array>  biggest total first
     */
    public static function groupProposals(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $k = $r['payee_key'] . '|' . $r['to_account_id'];
            if (!isset($groups[$k])) {
                $groups[$k] = ['payee_key' => $r['payee_key'], 'label' => (string)($r['description'] ?? $r['payee_key']), 'to_account_id' => (int)$r['to_account_id'],
                               'to_label' => (string)($r['to_label'] ?? ''), 'from_labels' => [], 'tier' => $r['tier'], 'source' => (string)($r['source'] ?? ''),
                               'reason' => (string)($r['reason'] ?? ''), 'count' => 0, 'total' => 0.0, 'first' => null, 'last' => null, 'ids' => []];
            }
            $g = &$groups[$k];
            $g['count']++;
            $g['total'] = round($g['total'] + (float)$r['amount'], 2);
            $g['ids'][] = (int)$r['id'];
            if (!empty($r['from_label']) && !in_array($r['from_label'], $g['from_labels'], true)) $g['from_labels'][] = (string)$r['from_label'];
            $d = (string)($r['line_date'] ?? '');
            if ($d !== '') {
                if ($g['first'] === null || $d < $g['first']) $g['first'] = $d;
                if ($g['last'] === null || $d > $g['last']) $g['last'] = $d;
            }
            if ($r['tier'] === 'B') $g['tier'] = 'B';   // a group is only as sure as its least sure line
            unset($g);
        }
        $out = array_values($groups);
        usort($out, static function ($a, $b) { return $b['total'] <=> $a['total']; });
        return $out;
    }

    /** Plain text for the accountant: locked-month lines Penny now thinks are on the wrong account. */
    public static function accountantText(array $rows): string
    {
        if (!$rows) return "Nothing for the accountant.\n";
        $out = "Lines in closed periods that Penny now thinks belong on a different account. Nothing was moved; each needs a decision (for example an adjusting entry in the current period).\n\n";
        foreach ($rows as $r) {
            $out .= sprintf("%s  $%s  %s\n    now: %s  ->  suggested: %s\n    why: %s\n",
                (string)($r['line_date'] ?? ''), number_format((float)$r['amount'], 2), trim((string)($r['description'] ?? '')),
                (string)($r['from_label'] ?? '?'), (string)($r['to_label'] ?? '?'), (string)($r['reason'] ?? ''));
        }
        return $out;
    }

    /** The note left on a line that was moved. */
    public static function memo(string $reason, string $date): string
    {
        return 'Penny revisit ' . $date . ': ' . trim($reason);
    }

    // ═══ Loaders / writers ════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'penny_revisits'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function mode(): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::MODE_KEY]);
            $v = (string)($s->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $v = '';
        }
        return in_array($v, ['off', 'propose', 'auto_narrow'], true) ? $v : self::DEFAULT_MODE;
    }

    /**
     * Look again. Records proposals; with $dryRun reports what it would record and writes nothing.
     *
     * @return array{mode:string,dry_run:bool,scanned:int,by_tier:array,by_source:array,examples:array}
     */
    public function scan(bool $dryRun = false, string $since = self::SINCE, int $limit = self::SCAN_MAX): array
    {
        $mode = $this->mode();
        $res = ['mode' => $mode, 'dry_run' => $dryRun, 'scanned' => 0, 'by_tier' => ['A' => 0, 'B' => 0, 'C' => 0], 'by_source' => [], 'examples' => []];
        $ready = $this->ready();
        if ($mode === 'off' || (!$ready && !$dryRun)) return $res;   // a dry run needs no table: it is the read-only preview before migration 1395

        $rows = $this->candidates($since, max(1, min(self::SCAN_MAX, $limit)), $ready);
        $ledger = new LedgerService($this->db);
        $lockCache = [];
        $ctxMethod = new ReflectionMethod('BankDeskService', 'context');
        $desk = new BankDeskService($this->db);
        $ins = $dryRun ? null : $this->db->prepare("
            INSERT IGNORE INTO penny_revisits
                (transaction_id, trigger_kind, trigger_ref, payee_key, from_account_id, to_account_id, tier, source, reason, amount, line_date)
            VALUES (?, 'scan', NULL, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // The desk looks up a matching receipt for every unlinked expense line (slow for hundreds of lines). This
            // feature ignores receipt-sourced advice, so mark the chunk "linked" to skip that lookup (-1 matches nothing).
            $forAdvice = array_map(static function ($r) { $r['matched_expense_id'] = $r['matched_expense_id'] ?: -1; return $r; }, $chunk);
            $ctx = $ctxMethod->invoke($desk, $forAdvice);
            foreach ($chunk as $i => $line) {
                $res['scanned']++;
                $advice = BankDeskService::advise($forAdvice[$i], $ctx);
                $ym = substr((string)$line['transaction_date'], 0, 7);
                if (!isset($lockCache[$ym])) $lockCache[$ym] = $ledger->isLocked((string)$line['transaction_date']);
                $c = self::classify($line, $advice, $lockCache[$ym]);
                if ($c['tier'] === 'skip') continue;
                $res['by_tier'][$c['tier']]++;
                $res['by_source'][(string)$advice['source']] = ($res['by_source'][(string)$advice['source']] ?? 0) + 1;
                if (count($res['examples']) < 8) {
                    $res['examples'][] = ['id' => (int)$line['id'], 'date' => $line['transaction_date'], 'amount' => (float)$line['amount'],
                                          'description' => substr((string)$line['description'], 0, 50), 'from' => $line['account_code'], 'to' => $advice['code'], 'tier' => $c['tier']];
                }
                if (!$dryRun) {
                    $ins->execute([(int)$line['id'], self::payeeKey((string)$line['description']), $line['account_id'] ?: null, (int)$advice['account_id'],
                                   $c['tier'], substr((string)$advice['source'], 0, 20), substr($c['reason'], 0, 500), (float)$line['amount'], $line['transaction_date']]);
                }
            }
        }
        return $res;
    }

    /** @return array<int,array>  unlinked bank expense lines from $since that nobody has already settled with a proposal */
    private function candidates(string $since, int $limit, bool $checkExisting = true): array
    {
        $hasInvoice = $this->hasColumn('accounting_transactions', 'matched_invoice_id');
        $hasReviews = $this->hasTable('bank_line_reviews');
        $sql = "SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.account_id, t.matched_expense_id,
                       " . ($hasInvoice ? 't.matched_invoice_id' : 'NULL AS matched_invoice_id') . ",
                       t.bank_account, t.status, t.reference_type, a.code AS account_code, a.name AS account_name,
                       " . ($hasReviews ? 'r.outcome AS review_outcome, r.final_account_id AS review_final' : 'NULL AS review_outcome, NULL AS review_final') . "
                  FROM accounting_transactions t
                  LEFT JOIN chart_of_accounts a ON a.id = t.account_id
                  " . ($hasReviews ? 'LEFT JOIN bank_line_reviews r ON r.transaction_id = t.id' : '') . "
                 WHERE t.reference_type = 'bank_import' AND t.type = 'expense'
                   AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
                   AND t.matched_expense_id IS NULL" . ($hasInvoice ? ' AND t.matched_invoice_id IS NULL' : '') . "
                   AND t.transaction_date >= ?
                   " . ($checkExisting ? "AND NOT EXISTS (SELECT 1 FROM penny_revisits p WHERE p.transaction_id = t.id AND p.status IN ('proposed', 'applied', 'dismissed', 'undone'))" : '') . "
                 ORDER BY t.transaction_date DESC, t.id DESC
                 LIMIT " . (int)$limit;
        $s = $this->db->prepare($sql);
        $s->execute([$since]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * What is waiting, grouped by payee. 'actionable' = tiers A and B (the owner approves); 'accountant' = tier C lines.
     * @return array{actionable:array,accountant:array,accountant_text:string,counts:array}
     */
    public function listGroups(string $status = 'proposed'): array
    {
        $s = $this->db->prepare("
            SELECT p.id, p.transaction_id, p.payee_key, p.to_account_id, p.tier, p.source, p.reason, p.amount, p.line_date, p.status,
                   t.description, CONCAT(fa.code, ' ', fa.name) AS from_label, CONCAT(ta.code, ' ', ta.name) AS to_label
              FROM penny_revisits p
              JOIN accounting_transactions t ON t.id = p.transaction_id
              LEFT JOIN chart_of_accounts fa ON fa.id = p.from_account_id
              LEFT JOIN chart_of_accounts ta ON ta.id = p.to_account_id
             WHERE p.status = ?
             ORDER BY p.line_date DESC, p.id DESC");
        $s->execute([$status]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $act = array_values(array_filter($rows, static function ($r) { return $r['tier'] !== 'C'; }));
        $acc = array_values(array_filter($rows, static function ($r) { return $r['tier'] === 'C'; }));
        return ['actionable' => self::groupProposals($act), 'accountant' => $acc, 'accountant_text' => self::accountantText($acc),
                'counts' => ['groups' => count(self::groupProposals($act)), 'lines' => count($act), 'accountant_lines' => count($acc)]];
    }

    /**
     * The owner approves: move each proposed line (tiers A / B only) to its proposed account.
     * @param int[] $ids penny_revisits ids
     * @return array{moved:int,skipped:array}
     */
    public function apply(array $ids, int $userId): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; }));
        $out = ['moved' => 0, 'skipped' => []];
        if (!$ids) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT p.*, t.account_id AS now_account FROM penny_revisits p JOIN accounting_transactions t ON t.id = p.transaction_id
                                  WHERE p.id IN ($in) AND p.status = 'proposed' ORDER BY p.line_date, p.id");
        $s->execute($ids);
        $mover = new BankLineMoveService($this->db);
        $learned = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if ($p['tier'] === 'C') { $out['skipped'][] = ['id' => (int)$p['id'], 'why' => 'locked month: for the accountant']; continue; }
            if ((int)$p['now_account'] !== (int)$p['from_account_id']) { $out['skipped'][] = ['id' => (int)$p['id'], 'why' => 'the line changed since it was proposed']; continue; }
            $r = $mover->move((int)$p['transaction_id'], (int)$p['to_account_id'], $userId, 'penny');
            if (empty($r['ok'])) { $out['skipped'][] = ['id' => (int)$p['id'], 'why' => (string)($r['message'] ?? 'not moved')]; continue; }
            $this->db->prepare("UPDATE penny_revisits SET status = 'applied', decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$userId ?: null, (int)$p['id']]);
            $this->db->prepare("UPDATE accounting_transactions SET notes = CONCAT_WS(' | ', NULLIF(notes, ''), ?) WHERE id = ?")
                     ->execute([self::memo((string)$p['reason'], date('Y-m-d')), (int)$p['transaction_id']]);
            $out['moved']++;
            // The owner's approval is a confirmation for rule learning, once per payee + account (BankRuleLearning's own rules still apply).
            $k = $p['payee_key'] . '|' . $p['to_account_id'];
            if (empty($learned[$k])) {
                $learned[$k] = true;
                try { (new BankRuleLearning($this->db))->learnFromCorrection((int)$p['transaction_id'], (int)$p['to_account_id'], $userId); }
                catch (Throwable $e) { error_log('[PennyRevisit] learn: ' . $e->getMessage()); }
            }
        }
        return $out;
    }

    /** The owner says no: never proposed again. */
    public function dismiss(array $ids, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; }));
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("UPDATE penny_revisits SET status = 'dismissed', decided_by = ?, decided_at = NOW() WHERE id IN ($in) AND status = 'proposed'");
        $s->execute(array_merge([$userId ?: null], $ids));
        return $s->rowCount();
    }

    /**
     * Put applied lines back where they came from.
     * @return array{undone:int,skipped:array}
     */
    public function undo(array $ids, int $userId): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; }));
        $out = ['undone' => 0, 'skipped' => []];
        if (!$ids) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT p.*, t.account_id AS now_account FROM penny_revisits p JOIN accounting_transactions t ON t.id = p.transaction_id
                                  WHERE p.id IN ($in) AND p.status = 'applied'");
        $s->execute($ids);
        $mover = new BankLineMoveService($this->db);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (empty($p['from_account_id'])) { $out['skipped'][] = ['id' => (int)$p['id'], 'why' => 'it came from no account']; continue; }
            if ((int)$p['now_account'] !== (int)$p['to_account_id']) { $out['skipped'][] = ['id' => (int)$p['id'], 'why' => 'it has been moved again since']; continue; }
            $r = $mover->move((int)$p['transaction_id'], (int)$p['from_account_id'], $userId, 'penny');
            if (empty($r['ok'])) { $out['skipped'][] = ['id' => (int)$p['id'], 'why' => (string)($r['message'] ?? 'not moved')]; continue; }
            $this->db->prepare("UPDATE penny_revisits SET status = 'undone', decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$userId ?: null, (int)$p['id']]);
            $this->db->prepare("UPDATE accounting_transactions SET notes = CONCAT_WS(' | ', NULLIF(notes, ''), ?) WHERE id = ?")
                     ->execute(['Penny revisit undone ' . date('Y-m-d'), (int)$p['transaction_id']]);
            $out['undone']++;
        }
        return $out;
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    private function hasTable(string $t): bool
    {
        try { return $this->db->query("SHOW TABLES LIKE " . $this->db->quote($t))->rowCount() > 0; } catch (Throwable $e) { return false; }
    }

    private function hasColumn(string $table, string $col): bool
    {
        try { return $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->db->quote($col))->rowCount() > 0; } catch (Throwable $e) { return false; }
    }
}
