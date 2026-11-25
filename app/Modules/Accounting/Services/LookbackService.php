<?php
/**
 * LookbackService — Penny's look-back review of 2026 (backlog item 7, migration 1250).
 *
 * Re-checks everything booked in 2026 with what Penny knows now and stores PROPOSALS only
 * (lookback_proposals); Tim approves or skips each one on /crm/accounting/lookback.php.
 *   receipts  approved + forwarded: Tim's rules, vendor memory, split by line, duplicates
 *             (photo / ticket number), GST / PST sanity, personal-spend signals, job attribution.
 *   bank      2026 bank + card lines: TD loan → 2610, card payments → 2400, savings → 1020/1025,
 *             CRA payments by the accountant's amounts (never an expense), payee consistency,
 *             default accounts (4900/6900). Payroll (Wave PYRL) is left to the payroll import,
 *             deposits matching invoices to the income clean-up, missing receipts to the chaser —
 *             listed as information (count + $), never proposed.
 *   journal   2026 entries vs their source (account / amount / GST line), unbalanced entries,
 *             entries for deleted records, sources posted twice.
 * Rules first (LookbackRules, free). Only what the rules can't decide goes to Claude, ONE call per
 * vendor / payee (claude-sonnet-5-5), under the total budget ops_settings penny_lookback_budget
 * (USD, default 15); every call is logged in lookback_ai_calls and the answer is reused
 * (lookback_rules for vendors; bank_guidance — Penny's own bank cache — for payees).
 *
 * Applying (approve): receipts through ExpenseGate (re-post append-only, Penny learns), bank lines
 * through BankLineMoveService (+ BankRuleLearning owner confirmation), journal repairs through
 * LedgerService::reverseEntry / ExpenseGateHooks::repost / BankLineMoveService::rejournal.
 * Locked months are refused. A record changed since the proposal is refused (stale). Every
 * applied change keeps undo data. 2025 is never read for proposals (filed and locked).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}
require_once __DIR__ . '/LookbackRules.php';
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';
require_once __DIR__ . '/BankImportService.php';
require_once __DIR__ . '/BankLineMoveService.php';
require_once __DIR__ . '/BankRuleLearning.php';
require_once __DIR__ . '/BankGuidanceService.php';
require_once __DIR__ . '/LedgerAccountMap.php';
require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseSplitService.php';
require_once dirname(__DIR__, 2) . '/Expenses/Services/ReceiptFactsService.php';

class LookbackService
{
    public const FROM = '2026-01-01';
    public const TO   = '2026-12-31';
    public const CUTOVER = '2026-02-25';          // first CRM invoice paid (IncomeCleanupService::CUTOVER)
    public const API_URL = 'https://api.anthropic.com/v1/messages';
    public const MODEL = 'claude-sonnet-5-5';
    public const PRICE_IN = 3.0;                  // USD per million tokens (BankGuidanceService)
    public const PRICE_OUT = 15.0;
    public const BUDGET_KEY = 'penny_lookback_budget';
    public const DEFAULT_BUDGET = 15.0;
    public const CALL_RESERVE = 0.05;             // a call is only made while this much budget is left
    public const MISSING_RECEIPT_MIN = 25.0;
    public const JOB_LOOKUPS_PER_SCAN = 60;
    public const FAMILIES = ['receipt', 'bank', 'journal'];
    /** Statuses a re-scan never touches (Tim decided). */
    public const DECIDED = ['applied', 'skipped', 'undone'];

    private PDO $db;
    /** @var callable|null fn(array $body): array{code: int, body: string} */
    private $transport;
    private ?BankGuidanceService $guidance;
    private LedgerService $ledger;
    private ?array $chart = null;
    private array $aiLog = [];

    public function __construct(PDO $db, ?callable $transport = null, ?BankGuidanceService $guidance = null, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->transport = $transport;
        $this->guidance = $guidance;
        $this->ledger = $ledger ?? new LedgerService($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM lookback_proposals LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scan
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find proposals and store them (idempotent).
     * @param array $opts families (list), ai_calls (int, 0 = rules only), dry_run (bool)
     * @return array{proposals: int, by_kind: array, stored?: array, ai: array, dry_run: bool, list?: array}
     */
    public function scan(array $opts = []): array
    {
        $families = array_values(array_intersect((array)($opts['families'] ?? self::FAMILIES), self::FAMILIES)) ?: self::FAMILIES;
        $aiCalls = max(0, (int)($opts['ai_calls'] ?? 0));
        $dry = !empty($opts['dry_run']);
        $this->aiLog = ['calls' => 0, 'cost' => 0.0, 'reused' => 0, 'skipped_budget' => 0, 'pending' => 0, 'errors' => []];
        $props = [];
        if (in_array('receipt', $families, true)) $props = array_merge($props, $this->receiptProposals($dry ? 0 : $aiCalls));
        if (in_array('bank', $families, true)) {
            $left = $dry ? 0 : max(0, $aiCalls - $this->aiLog['calls']);
            $props = array_merge($props, $this->bankProposals($left));
        }
        if (in_array('journal', $families, true)) $props = array_merge($props, $this->journalProposals());

        $byKind = [];
        foreach ($props as $p) $byKind[$p['kind']] = ($byKind[$p['kind']] ?? 0) + 1;
        ksort($byKind);
        $out = ['proposals' => count($props), 'by_kind' => $byKind, 'ai' => $this->aiLog, 'dry_run' => $dry];
        if ($dry) {
            $out['list'] = array_slice($props, 0, 500);
            return $out;
        }
        $out['stored'] = $this->store($props, $families);
        $this->setSetting('penny_lookback_last_scan', date('Y-m-d H:i:s'));
        return $out;
    }

    /** Receipts approved / forwarded in 2026. */
    public function receiptProposals(int $aiCalls = 0): array
    {
        $rows = $this->db->prepare("
            SELECT e.*, v.name AS vendor_name
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.status IN ('approved', 'forwarded') AND e.expense_date BETWEEN ? AND ?
            ORDER BY e.expense_date, e.id
        ");
        $rows->execute([self::FROM, self::TO]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $ids = array_map(fn($r) => (int)$r['id'], $rows);

        $lines = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("SELECT id, expense_id, name, line_total, product_id FROM expense_line_items WHERE expense_id IN ({$in}) ORDER BY expense_id, sort_order, id");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) $lines[(int)$l['expense_id']][] = $l;
        }
        $split = new ExpenseSplitService($this->db);
        $splitIds = $split->ready() ? array_flip($split->splitExpenseIds($ids)) : [];
        [$mealsCats, $mealsRate] = $split->mealsSettings();
        $facts = [];
        try {
            $rf = new ReceiptFactsService($this->db);
            if ($rf->ready()) $facts = $rf->forExpenses($ids);
        } catch (Throwable $e) { /* facts are a bonus (migration 1227) */ }

        // Vendor memory: every approved receipt Tim filed (any year — his decisions), per vendor.
        $history = []; $gstSeen = [];
        foreach ($this->db->query("SELECT e.id, LOWER(TRIM(COALESCE(NULLIF(v.name, ''), e.vendor_name_raw, ''))) AS vk,
                                          LOWER(TRIM(COALESCE(e.accounting_category, ''))) AS cat, e.gst_amount
                                   FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                                   WHERE e.status IN ('approved', 'forwarded')")->fetchAll(PDO::FETCH_ASSOC) as $h) {
            if ($h['vk'] === '') continue;
            $history[$h['vk']][(int)$h['id']] = $h['cat'];
            $gstSeen[$h['vk']][(int)$h['id']] = (float)$h['gst_amount'] > 0;
        }
        $learned = $this->vendorRules();
        $dismissed = $this->dismissedPairs();

        $ctxFor = function (array $e) use ($history, $gstSeen, &$learned, $mealsCats, $mealsRate, $split, $lines): array {
            $vk = self::vendorKey($e);
            $others = $history[$vk] ?? [];
            unset($others[(int)$e['id']]);
            $counts = [];
            foreach ($others as $c) if ($c !== '' && $c !== 'other') $counts[$c] = ($counts[$c] ?? 0) + 1;
            $g = $gstSeen[$vk] ?? [];
            unset($g[(int)$e['id']]);
            $lessons = [];
            if (count($lines[(int)$e['id']] ?? []) >= 2 && !empty($e['vendor_id']) && $split->ready()) {
                try { $lessons = $split->lessons((int)$e['vendor_id'], array_column($lines[(int)$e['id']], 'name')); } catch (Throwable $ex) { /* no lessons yet */ }
            }
            return ['vendor_history' => $counts, 'vendor_gst_share' => count($g) >= 3 ? array_sum($g) / count($g) : null,
                    'meals_categories' => $mealsCats, 'meals_rate' => $mealsRate, 'lessons' => $lessons,
                    'learned_category' => $learned[$vk] ?? null];
        };

        $prep = [];
        foreach ($rows as $e) {
            $id = (int)$e['id'];
            $e['lines'] = $lines[$id] ?? [];
            $e['has_split'] = isset($splitIds[$id]);
            $e['ocr_text'] = ReceiptFactsService::ocrText($e['raw_ocr_json'] ?? null);
            $e['facts'] = $facts[$id] ?? null;
            $prep[$id] = $e;
        }

        $out = [];
        $ask = [];
        $catCodes = $this->categoryCodes();
        foreach ($prep as $id => $e) {
            $ps = LookbackRules::receipt($e, $ctxFor($e));
            $out[$id] = $ps;
            $catKinds = array_intersect(array_column($ps, 'kind'), ['fuel_rule', 'ego_rule', 'vendor_category', 'ai_category', 'meals_category', 'split', 'personal']);
            $cat = trim((string)$e['accounting_category']);
            $unmapped = $cat === '' || strcasecmp($cat, 'Other') === 0 || !isset($catCodes[strtolower($cat)]);
            if (!$catKinds && $unmapped && !$e['has_split'] && self::vendorKey($e) !== '') $ask[self::vendorKey($e)][] = $id;
        }

        // Claude, once per vendor the rules couldn't place (budget permitting); answers become rules.
        $unasked = array_diff_key($ask, $learned, $this->askedVendors());
        if ($unasked && $aiCalls > 0) {
            foreach ($unasked as $vk => $eids) {
                if ($this->aiLog['calls'] >= $aiCalls) break;
                if (!$this->canSpend()) { $this->aiLog['skipped_budget']++; break; }
                $this->askVendor($vk, array_map(fn($i) => $prep[$i], array_slice($eids, 0, 8)));
            }
            $learned = $this->vendorRules();
            foreach ($ask as $vk => $eids) {
                if (!isset($learned[$vk])) continue;
                foreach ($eids as $i) $out[$i] = LookbackRules::receipt($prep[$i], $ctxFor($prep[$i]));
            }
        }

        $this->aiLog['pending'] += count(array_diff_key($ask, $this->vendorRules(), $this->askedVendors()));
        $flat = array_merge(...array_values($out));
        $dupRows = [];
        foreach ($prep as $e) {
            $dupRows[] = ['id' => (int)$e['id'], 'expense_date' => $e['expense_date'], 'total' => $e['total'], 'gst_amount' => $e['gst_amount'],
                          'vendor_key' => self::vendorKey($e), 'receipt_media_id' => $e['receipt_media_id'] ?? null, 'status' => $e['status'],
                          'facts' => $e['facts']];
        }
        $flat = array_merge($flat, LookbackRules::duplicates($dupRows, $dismissed));
        return array_merge($flat, $this->jobProposals($prep));
    }

    /** Materials with no job: where the truck / crew went after the purchase (ReceiptTrailService). */
    private function jobProposals(array $prep): array
    {
        $file = dirname(__DIR__, 2) . '/Expenses/Services/ReceiptTrailService.php';
        if (!is_file($file)) return [];
        require_once $file;
        $trail = new ReceiptTrailService($this->db);
        $out = []; $n = 0;
        foreach ($prep as $e) {
            if (strcasecmp((string)$e['accounting_category'], 'Materials') !== 0 || !empty($e['job_id']) || $e['has_split']) continue;
            if (++$n > self::JOB_LOOKUPS_PER_SCAN) break;
            try {
                $c = $trail->forReceipt($e, substr((string)$e['expense_date'], 0, 10), $e['facts']['time_first'] ?? null);
            } catch (Throwable $ex) {
                continue;
            }
            if (!$c) continue;
            $plans = array_unique(array_map(fn($x) => (int)$x['plan_id'], $c));
            if (count($plans) !== 1) continue;                 // two places that day: Tim knows, rules don't
            $best = $c[0];
            $out[] = ['family' => 'receipt', 'kind' => 'job', 'subject_type' => 'expense', 'subject_id' => (int)$e['id'],
                      'date' => substr((string)$e['expense_date'], 0, 10), 'title' => 'Materials for ' . (string)($best['job'] ?? ('job #' . $best['plan_id'])),
                      'before' => ['job_id' => null], 'after' => ['job_id' => (int)$best['plan_id']],
                      'evidence' => [(string)$best['why'], 'Materials are almost never carried for two days (Tim).'],
                      'confidence' => in_array('photo', (array)($best['sources'] ?? []), true) ? 75 : 65, 'source' => 'rules',
                      'amount' => round((float)$e['total'], 2), 'gst' => 0.0];
        }
        return $out;
    }

    /** 2026 bank and card lines. */
    public function bankProposals(int $aiCalls = 0): array
    {
        $codes = $this->chart();
        $byId = [];
        foreach ($codes as $code => $a) $byId[(int)$a['id']] = $code;
        $invoiceCol = $this->hasColumn('accounting_transactions', 'matched_invoice_id') ? 't.matched_invoice_id' : 'NULL AS matched_invoice_id';
        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.gst_amount, t.description, t.account_id, t.status,
                   t.matched_expense_id, {$invoiceCol}
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.transaction_date BETWEEN ? AND ?
              AND (t.status IS NULL OR t.status NOT IN ('void', 'deleted'))
            ORDER BY t.transaction_date, t.id
        ");
        $s->execute([self::FROM, self::TO]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $sync = new LedgerSyncService($this->db, $this->ledger);
        $dir = $sync->directionFacts();
        $invoiceTotals = $this->invoiceTotals();

        $booked = $this->depositBookedIds();
        $out = []; $rest = [];
        foreach ($rows as $r) {
            if (isset($booked[(int)$r['id']])) continue;             // booked as a deposit (Jobber import / balance check) — settled there
            $r['code'] = $byId[(int)$r['account_id']] ?? '';
            $r['money_in'] = ($r['type'] ?? '') === 'income'
                || (($r['type'] ?? '') === 'transfer' && LedgerSyncService::transferDirection($dir[(int)$r['id']] ?? []) === 'in');
            $r['linked'] = !empty($r['matched_expense_id']) || !empty($r['matched_invoice_id']);
            $r['key'] = BankImportService::descriptionKey((string)$r['description']);
            $date = substr((string)$r['transaction_date'], 0, 10);
            $amount = round(abs((float)$r['amount']), 2);

            if (!$r['linked'] && ($p = LookbackRules::bankLine($r, $codes))) { $out[] = $p; continue; }
            // Deferred to other pages — counted, never proposed here.
            if (($r['type'] ?? '') === 'income' && !$r['linked'] && $date >= self::CUTOVER && isset($invoiceTotals[number_format($amount, 2, '.', '')])) {
                $out[] = $this->info('deposit_invoice', $r, 'Deposit $' . number_format($amount, 2) . ' equals invoice ' . $invoiceTotals[number_format($amount, 2, '.', '')],
                                     ['Still counted as income next to the invoice — book it on the Income clean-up page.']);
                continue;
            }
            if (($r['type'] ?? '') === 'expense' && empty($r['matched_expense_id']) && $amount >= self::MISSING_RECEIPT_MIN
                && !in_array($codes[$r['code']]['type'] ?? '', ['asset', 'liability', 'equity'], true)
                && !LookbackRules::gstExemptReason((string)$r['description'])) {
                $out[] = $this->info('missing_receipt', $r, 'No receipt for $' . number_format($amount, 2) . ' — ' . trim((string)$r['description']),
                                     ['The receipt chaser asks for it.']);
            }
            $rest[] = $r;
        }
        $rules = $this->ownerBankRules();
        $pay = LookbackRules::payees($rest, $codes, $rules);
        $out = array_merge($out, $pay['proposals']);

        // Lines still on a default account with nothing to go on: Penny's bank cache, then Claude once per payee.
        if ($pay['ask']) {
            $txById = [];
            foreach ($rest as $r) $txById[(int)$r['id']] = $r;
            foreach ($pay['ask'] as $key => $ids) {
                $g = $this->payeeGuidance((string)$txById[$ids[0]]['description'], $ids[0], $aiCalls, abs((float)$txById[$ids[0]]['amount']));
                if (!$g) continue;
                $to = $g['account_code'];
                if (!isset($codes[$to])) continue;
                foreach ($ids as $id) {
                    $r = $txById[$id];
                    if ($r['code'] === $to) continue;
                    $gst = round((float)($r['gst_amount'] ?? 0), 2);
                    $zero = in_array($codes[$to]['type'], ['asset', 'liability', 'equity'], true);
                    $out[] = ['family' => 'bank', 'kind' => 'default_account', 'subject_type' => 'bank', 'subject_id' => $id,
                              'date' => substr((string)$r['transaction_date'], 0, 10),
                              'title' => 'Off ' . ($r['code'] ?: 'no account') . ' → ' . $to . ' ' . $codes[$to]['name'],
                              'before' => ['account' => $r['code']], 'after' => ['account' => $to],
                              'evidence' => array_values(array_filter([trim(($g['merchant'] ?? '') . ' — ' . ($g['what_it_is'] ?? ''), ' —'), (string)($g['reason'] ?? ''),
                                            !empty($g['_cached']) ? 'Penny\'s earlier answer for this payee, reused (free).' : 'Asked Claude once for this payee.',
                                            '"' . trim((string)$r['description']) . '"'])),
                              'confidence' => (int)min(80, round((float)($g['confidence'] ?? 0) * 100)), 'source' => 'ai',
                              'amount' => round(abs((float)$r['amount']), 2), 'gst' => $zero && $gst > 0 ? -$gst : 0.0];
                }
            }
        }
        return $out;
    }

    /** 2026 journal entries vs their sources. */
    public function journalProposals(): array
    {
        $codes = $this->chart();
        $itc = (int)($codes[LedgerService::ACC_GST_ITC]['id'] ?? 0);
        $s = $this->db->prepare("SELECT id, entry_date, source_type, source_id, memo FROM journal_entries
                                 WHERE status = 'posted' AND reversed_by_entry_id IS NULL AND entry_date BETWEEN ? AND ?");
        $s->execute([self::FROM, self::TO]);
        $entries = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$entries) return [];
        $lines = [];
        $l = $this->db->prepare("SELECT jl.entry_id, jl.account_id, jl.debit, jl.credit FROM journal_lines jl
                                 JOIN journal_entries je ON je.id = jl.entry_id
                                 WHERE je.status = 'posted' AND je.reversed_by_entry_id IS NULL AND je.entry_date BETWEEN ? AND ?");
        $l->execute([self::FROM, self::TO]);
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $ln) $lines[(int)$ln['entry_id']][] = $ln;

        $out = [];
        $mk = function (string $kind, array $e, string $title, array $after, array $evidence, int $conf, float $amount) {
            return ['family' => 'journal', 'kind' => $kind, 'subject_type' => 'journal', 'subject_id' => (int)$e['id'],
                    'date' => substr((string)$e['entry_date'], 0, 10), 'title' => $title,
                    'before' => ['entry_id' => (int)$e['id'], 'live' => 1], 'after' => $after, 'evidence' => $evidence,
                    'confidence' => $conf, 'source' => 'rules', 'amount' => round($amount, 2), 'gst' => 0.0];
        };
        $debits = fn(array $ls) => round(array_sum(array_map(fn($x) => (float)$x['debit'], $ls)), 2);

        // Posted twice: keep the newest live entry, reverse the rest.
        $doubled = [];
        foreach (LookbackRules::doubles($entries) as $d) {
            $e = ['id' => $d['entry_id'], 'entry_date' => $d['date']];
            $doubled[$d['entry_id']] = true;
            $out[] = $mk('journal_double', $e, ucfirst(str_replace('_', ' ', $d['source_type'])) . ' #' . $d['source_id'] . ' is posted twice — reverse entry #' . $d['entry_id'],
                ['action' => 'reverse_entry', 'entry_id' => $d['entry_id']],
                ['Entries #' . $d['entry_id'] . ' and #' . $d['keep'] . ' both carry it; #' . $d['keep'] . ' (the newest) stays.'], 90, $debits($lines[$d['entry_id']] ?? []));
        }

        $expenseIds = []; $txIds = [];
        foreach ($entries as $e) {
            if ($e['source_type'] === 'expense') $expenseIds[] = (int)$e['source_id'];
            if ($e['source_type'] === 'bank_import') $txIds[] = (int)$e['source_id'];
        }
        $expenses = $this->rowsById("SELECT e.id, e.expense_date, e.total, e.gst_amount, e.pst_amount, e.accounting_category, e.payment_method, e.vendor_id,
                                            e.job_id, e.contact_id, e.asset_tag, e.vendor_name_raw, e.status, v.name AS vendor_name
                                     FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id IN (%s)", $expenseIds);
        $txs = $this->rowsById("SELECT id FROM accounting_transactions WHERE id IN (%s)", $txIds);
        $sync = new LedgerSyncService($this->db, $this->ledger);
        $depositBooked = $this->depositBookedIds();

        foreach ($entries as $e) {
            $id = (int)$e['id'];
            if (isset($doubled[$id])) continue;
            $ls = $lines[$id] ?? [];
            if (!LookbackRules::balanced($ls)) {
                $d = $debits($ls); $c = round(array_sum(array_map(fn($x) => (float)$x['credit'], $ls)), 2);
                $out[] = $mk('journal_unbalanced', $e, 'Entry #' . $id . ' does not balance (debits $' . number_format($d, 2) . ', credits $' . number_format($c, 2) . ')',
                    [], ['A posted entry must balance — fix it by hand or ask the accountant.', (string)($e['memo'] ?? '')], 100, abs($d - $c));
                continue;
            }
            if ($e['source_type'] === 'expense') {
                $x = $expenses[(int)$e['source_id']] ?? null;
                if (!$x || !in_array($x['status'], ['approved', 'forwarded'], true)) {
                    $out[] = $mk('journal_orphan', $e, 'Entry #' . $id . ' books receipt #' . (int)$e['source_id'] . ($x ? ' (now ' . $x['status'] . ')' : ' (deleted)') . ' — reverse it',
                        ['action' => 'reverse_entry', 'entry_id' => $id],
                        [$x ? 'The receipt is ' . $x['status'] . ', so it should carry nothing in the books.' : 'The receipt no longer exists.'], 90, $debits($ls));
                    continue;
                }
                try {
                    $want = $this->ledger->buildExpenseEntry($this->ledger->withAllocations($sync->expenseArgs($x)));
                    $exp = [];
                    foreach ($want['lines'] as $wl) {
                        $aid = isset($wl['account_id']) ? (int)$wl['account_id'] : (int)($codes[(string)($wl['account'] ?? '')]['id'] ?? 0);
                        $exp[] = ['account_id' => $aid, 'debit' => $wl['debit'] ?? 0, 'credit' => $wl['credit'] ?? 0];
                    }
                    $kind = LookbackRules::compareEntry($ls, $exp, $itc);
                } catch (Throwable $ex) {
                    $kind = null;
                }
                if ($kind) {
                    $out[] = $mk($kind, $e, 'Receipt #' . (int)$x['id'] . ': entry #' . $id . ' no longer matches the receipt — re-post it',
                        ['action' => 'repost_expense', 'expense_id' => (int)$x['id']],
                        [self::kindWhy($kind), 'The entry is reversed and posted again from the receipt as it is now (append-only).'], 90, (float)$x['total']);
                }
            } elseif ($e['source_type'] === 'bank_import') {
                if (isset($depositBooked[(int)$e['source_id']])) {
                    // Booked as a deposit (Jobber import / 1236). JobberLedgerService never books a deposit that still has a
                    // live bank_import entry, so both live = counted twice: reverse this one, the deposit entry stays.
                    $out[] = $mk('journal_double', $e, 'Bank line #' . (int)$e['source_id'] . ' is posted twice — as a bank line (#' . $id . ') and as a deposit — reverse #' . $id,
                        ['action' => 'reverse_entry', 'entry_id' => $id],
                        ['The deposit booking (Jobber import / bank balance check) carries this line; this older bank-line entry counts it again.'], 90, $debits($ls));
                    continue;
                }
                if (!isset($txs[(int)$e['source_id']])) {
                    $out[] = $mk('journal_orphan', $e, 'Entry #' . $id . ' books bank line #' . (int)$e['source_id'] . ', which was deleted — reverse it',
                        ['action' => 'reverse_entry', 'entry_id' => $id], ['The bank line is gone (rolled back or removed); its journal entry stayed.'], 90, $debits($ls));
                    continue;
                }
                try {
                    $args = $sync->bankEntryArgsFor((int)$e['source_id']);
                } catch (Throwable $ex) {
                    continue;
                }
                if (!$args) {
                    $out[] = $mk('journal_orphan', $e, 'Bank line #' . (int)$e['source_id'] . ' is carried by its receipt / invoice — reverse entry #' . $id,
                        ['action' => 'rejournal_bank', 'tx_id' => (int)$e['source_id']],
                        ['The line is linked to a receipt or invoice (or is a revenue deposit), which posts it — this entry counts it twice.'], 90, $debits($ls));
                    continue;
                }
                $kind = LookbackRules::compareEntry($ls, $args['lines'], $itc);
                if ($kind) {
                    $out[] = $mk($kind, $e, 'Bank line #' . (int)$e['source_id'] . ': entry #' . $id . ' no longer matches the line — re-post it',
                        ['action' => 'rejournal_bank', 'tx_id' => (int)$e['source_id']],
                        [self::kindWhy($kind), 'Usually a line re-categorised before 2026-10-07, when moving a line didn\'t move its journal entry.'], 90, $debits($ls));
                }
            }
        }

        // Invoices: LedgerRepostService knows the revenue split; listed, fixed by its runner.
        if ($this->hasColumn('invoices', 'id')) try {
            require_once __DIR__ . '/LedgerRepostService.php';
            $plan = (new LedgerRepostService($this->db))->plan();
            $dates = [];
            foreach ($entries as $e) $dates[(int)$e['id']] = substr((string)$e['entry_date'], 0, 10);
            foreach ($plan['invoices'] as $inv) {
                if (!isset($dates[(int)$inv['entry_id']])) continue;          // 2026 entries only
                $out[] = $mk('journal_account', ['id' => $inv['entry_id'], 'entry_date' => $dates[(int)$inv['entry_id']]],
                    'Invoice #' . (int)$inv['id'] . ': revenue account(s) changed since it was posted', [],
                    ['Posted ' . self::codesSay($inv['from']) . '; the map now says ' . self::codesSay($inv['to']) . '.', 'Fixed for all invoices at once by the ledger repost (run-repost-ledger.php).'],
                    90, array_sum($inv['from']));
            }
        } catch (Throwable $ex) {
            error_log('Lookback: invoice repost plan failed: ' . $ex->getMessage());
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Store
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert proposals: an open / info / stale row is refreshed; a decided one (applied, skipped,
     * undone) is never touched — so a skipped item is not proposed again. Open rows the scan no
     * longer finds (fixed elsewhere) are removed.
     * @return array{inserted: int, updated: int, kept_decided: int, removed: int}
     */
    public function store(array $props, array $families = self::FAMILIES): array
    {
        $now = date('Y-m-d H:i:s');
        $seen = [];
        $res = ['inserted' => 0, 'updated' => 0, 'kept_decided' => 0, 'removed' => 0];
        $get = $this->db->prepare("SELECT id, status FROM lookback_proposals WHERE scan_key = ?");
        $ins = $this->db->prepare("INSERT INTO lookback_proposals (scan_key, family, kind, subject_type, subject_id, txn_date, title, before_json, after_json,
                                     evidence, confidence, source, amount_impact, gst_impact, signature, status, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $upd = $this->db->prepare("UPDATE lookback_proposals SET title = ?, before_json = ?, after_json = ?, evidence = ?, confidence = ?, source = ?,
                                     amount_impact = ?, gst_impact = ?, signature = ?, status = ?, txn_date = ?, result_note = NULL, updated_at = ? WHERE id = ?");
        foreach ($props as $p) {
            $key = self::scanKey($p);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $actionable = (LookbackRules::KINDS[$p['kind']][2] ?? false) && !empty($p['after']);
            $status = $actionable ? 'open' : 'info';
            $vals = [mb_substr($p['title'], 0, 255), json_encode($p['before']), json_encode($p['after']), json_encode(array_values($p['evidence'])),
                     (int)$p['confidence'], $p['source'], round((float)$p['amount'], 2), round((float)$p['gst'], 2), LookbackRules::signature($p['before']), $status];
            $get->execute([$key]);
            $row = $get->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $ins->execute(array_merge([$key, $p['family'], $p['kind'], $p['subject_type'], (int)$p['subject_id'], $p['date'] ?: null],
                                          [$vals[0], $vals[1], $vals[2], $vals[3], $vals[4], $vals[5], $vals[6], $vals[7], $vals[8], $vals[9], $now, $now]));
                $res['inserted']++;
            } elseif (in_array($row['status'], self::DECIDED, true)) {
                $res['kept_decided']++;
            } else {
                $upd->execute(array_merge($vals, [$p['date'] ?: null, $now, (int)$row['id']]));
                $res['updated']++;
            }
        }
        // Open / info rows of the scanned families the scan no longer finds.
        $in = implode(',', array_fill(0, count($families), '?'));
        $old = $this->db->prepare("SELECT id, scan_key FROM lookback_proposals WHERE status IN ('open', 'info', 'stale', 'failed') AND family IN ({$in})");
        $old->execute($families);
        $del = $this->db->prepare("DELETE FROM lookback_proposals WHERE id = ?");
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (!isset($seen[$o['scan_key']])) { $del->execute([(int)$o['id']]); $res['removed']++; }
        }
        return $res;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Read (page / card)
    // ─────────────────────────────────────────────────────────────────────────

    /** Summary by kind (open + info + applied), GST per quarter, AI spend. */
    public function summary(): array
    {
        $rows = $this->db->query("SELECT kind, family, status, COUNT(*) AS n, COALESCE(SUM(amount_impact), 0) AS amt, COALESCE(SUM(gst_impact), 0) AS gst,
                                         SUM(CASE WHEN confidence >= " . LookbackRules::BULK_CONFIDENCE . " THEN 1 ELSE 0 END) AS high
                                  FROM lookback_proposals GROUP BY kind, family, status")->fetchAll(PDO::FETCH_ASSOC);
        $kinds = [];
        foreach ($rows as $r) {
            $k = $r['kind'];
            $kinds[$k] = $kinds[$k] ?? ['kind' => $k, 'family' => $r['family'], 'label' => LookbackRules::KINDS[$k][1] ?? $k,
                                        'actionable' => (bool)(LookbackRules::KINDS[$k][2] ?? false),
                                        'open' => 0, 'open_amount' => 0.0, 'open_gst' => 0.0, 'open_high' => 0, 'info' => 0, 'info_amount' => 0.0,
                                        'applied' => 0, 'skipped' => 0, 'stale' => 0];
            if ($r['status'] === 'open') {
                $kinds[$k]['open'] += (int)$r['n']; $kinds[$k]['open_amount'] += (float)$r['amt']; $kinds[$k]['open_gst'] += (float)$r['gst'];
                $kinds[$k]['open_high'] += (int)$r['high'];
            } elseif ($r['status'] === 'info') {
                $kinds[$k]['info'] += (int)$r['n']; $kinds[$k]['info_amount'] += (float)$r['amt'];
            } elseif (isset($kinds[$k][$r['status']])) {
                $kinds[$k][$r['status']] += (int)$r['n'];
            }
        }
        $order = array_flip(array_keys(LookbackRules::KINDS));
        uasort($kinds, fn($a, $b) => ($order[$a['kind']] ?? 99) <=> ($order[$b['kind']] ?? 99));
        $g = $this->db->query("SELECT txn_date AS date, gst_impact AS gst, status FROM lookback_proposals WHERE status IN ('open', 'applied') AND gst_impact <> 0")
                      ->fetchAll(PDO::FETCH_ASSOC);
        $totals = ['open' => 0, 'open_amount' => 0.0, 'open_gst' => 0.0, 'info' => 0, 'applied' => 0];
        foreach ($kinds as $k) {
            $totals['open'] += $k['open']; $totals['open_amount'] += $k['open_amount']; $totals['open_gst'] += $k['open_gst'];
            $totals['info'] += $k['info']; $totals['applied'] += $k['applied'];
        }
        return ['kinds' => array_values($kinds), 'totals' => $totals,
                'gst' => LookbackRules::gstByQuarter($g, $this->filings()),
                'locked' => $this->lockedMonths(), 'ai' => $this->spend(),
                'last_scan' => $this->setting('penny_lookback_last_scan'), 'bulk_confidence' => LookbackRules::BULK_CONFIDENCE];
    }

    /** Proposals of one kind (or all), open first. */
    public function proposals(?string $kind = null, string $status = 'open', int $limit = 200, int $offset = 0): array
    {
        $where = []; $args = [];
        if ($kind !== null && $kind !== '') { $where[] = 'kind = ?'; $args[] = $kind; }
        if ($status !== 'all') { $where[] = 'status = ?'; $args[] = $status; }
        $sql = "SELECT * FROM lookback_proposals" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY confidence DESC, txn_date, id LIMIT " . max(1, min(1000, $limit)) . " OFFSET " . max(0, $offset);
        $s = $this->db->prepare($sql);
        $s->execute($args);
        $locked = $this->lockedMonths();
        $filings = $this->filings();
        return array_map(function ($r) use ($locked, $filings) {
            $r['before'] = json_decode((string)$r['before_json'], true) ?: [];
            $r['after'] = json_decode((string)$r['after_json'], true) ?: [];
            $r['evidence'] = json_decode((string)$r['evidence'], true) ?: [];
            unset($r['before_json'], $r['after_json'], $r['undo_json']);
            $r['locked'] = in_array(substr((string)$r['txn_date'], 0, 7), $locked, true);
            $r['gst_filed'] = abs((float)$r['gst_impact']) >= 0.005 && (bool)array_filter($filings, fn($f) => (string)$f['period_from'] <= (string)$r['txn_date'] && (string)$f['period_to'] >= (string)$r['txn_date']);
            return $r;
        }, $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** "Look-back: N proposals ($X, GST $Y)" while there are open items; null otherwise. */
    public function cardLine(): ?array
    {
        if (!$this->ready()) return null;
        $r = $this->db->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_impact), 0) AS amt, COALESCE(SUM(gst_impact), 0) AS gst FROM lookback_proposals WHERE status = 'open'")
                      ->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['n'] === 0) return null;
        return ['count' => (int)$r['n'], 'amount' => round((float)$r['amt'], 2), 'gst' => round((float)$r['gst'], 2),
                'text' => self::cardText((int)$r['n'], (float)$r['amt'], (float)$r['gst'])];
    }

    public static function cardText(int $n, float $amount, float $gst): string
    {
        return 'Look-back: ' . $n . ' proposal' . ($n === 1 ? '' : 's') . ' ($' . number_format($amount, 0)
             . ', GST ' . ($gst < 0 ? '−' : '') . '$' . number_format(abs($gst), 2) . ')';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Decide
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Apply one proposal. @param array $user getCurrentUser()
     * @return array{ok: bool, message: string, status?: string}
     */
    public function approve(int $id, array $user): array
    {
        $p = $this->row($id);
        if (!$p) return ['ok' => false, 'message' => 'Proposal not found'];
        if ($p['status'] !== 'open') return ['ok' => false, 'message' => 'Already ' . $p['status'] . '.'];
        $userId = (int)($user['id'] ?? 0);
        $before = json_decode((string)$p['before_json'], true) ?: [];
        $after = json_decode((string)$p['after_json'], true) ?: [];
        if (!$after) return ['ok' => false, 'message' => 'Information only — nothing to apply here.'];
        try {
            if ($p['subject_type'] === 'expense') $r = $this->applyExpense($p, $before, $after, $user);
            elseif ($p['subject_type'] === 'bank') $r = $this->applyBank($p, $before, $after, $userId);
            else $r = $this->applyJournal($p, $after, $userId);
        } catch (Throwable $e) {
            error_log('Lookback approve #' . $id . ': ' . $e->getMessage());
            $this->mark($id, 'failed', mb_substr($e->getMessage(), 0, 400), $userId);
            return ['ok' => false, 'message' => 'That didn\'t apply: ' . $e->getMessage(), 'status' => 'failed'];
        }
        if (!empty($r['stale'])) {
            $this->mark($id, 'stale', $r['message'], $userId);
            return ['ok' => false, 'message' => $r['message'], 'status' => 'stale'];
        }
        if (empty($r['ok'])) return ['ok' => false, 'message' => $r['message']];      // locked etc. — stays open
        $this->db->prepare("UPDATE lookback_proposals SET status = 'applied', result_note = ?, undo_json = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?")
           ->execute([mb_substr($r['message'], 0, 500), json_encode($r['undo'] ?? null), $userId ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'message' => $r['message'], 'status' => 'applied'];
    }

    /**
     * "Approve all of this kind" — only the high-confidence open ones (re-read here, never from the browser).
     * @return array{ok: bool, applied: int, refused: int, messages: list<string>}
     */
    public function approveKind(string $kind, array $user, int $max = 300): array
    {
        if (!(LookbackRules::KINDS[$kind][2] ?? false)) return ['ok' => false, 'applied' => 0, 'refused' => 0, 'messages' => ['Nothing to approve for that kind.']];
        $s = $this->db->prepare("SELECT id FROM lookback_proposals WHERE kind = ? AND status = 'open' AND confidence >= ? ORDER BY txn_date, id LIMIT " . max(1, min(500, $max)));
        $s->execute([$kind, LookbackRules::BULK_CONFIDENCE]);
        $applied = 0; $refused = 0; $msgs = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = $this->approve((int)$id, $user);
            if ($r['ok']) $applied++;
            else { $refused++; if (count($msgs) < 5) $msgs[] = '#' . $id . ': ' . $r['message']; }
        }
        return ['ok' => true, 'applied' => $applied, 'refused' => $refused, 'messages' => $msgs];
    }

    public function skip(int $id, array $user, string $note = ''): array
    {
        $p = $this->row($id);
        if (!$p || !in_array($p['status'], ['open', 'stale', 'failed'], true)) return ['ok' => false, 'message' => 'Nothing to skip.'];
        $this->mark($id, 'skipped', $note !== '' ? mb_substr($note, 0, 400) : 'Skipped', (int)($user['id'] ?? 0));
        // A Claude answer Tim skipped is not reused.
        if ($p['source'] === 'ai' && $p['subject_type'] === 'expense') {
            $vk = $this->vendorKeyOf((int)$p['subject_id']);
            if ($vk !== '') $this->db->prepare("UPDATE lookback_rules SET rejected = 1, updated_at = ? WHERE rule_kind = 'vendor' AND rule_key = ? AND source = 'ai'")
                                     ->execute([date('Y-m-d H:i:s'), $vk]);
        }
        return ['ok' => true, 'message' => 'Skipped — I won\'t propose it again.'];
    }

    /** Put an applied change back. */
    public function undo(int $id, array $user): array
    {
        $p = $this->row($id);
        if (!$p || $p['status'] !== 'applied') return ['ok' => false, 'message' => 'Only an applied change can be undone.'];
        $undo = json_decode((string)$p['undo_json'], true);
        if (!is_array($undo) || empty($undo['type'])) return ['ok' => false, 'message' => 'Nothing recorded to undo.'];
        $userId = (int)($user['id'] ?? 0);
        try {
            if ($undo['type'] === 'expense') {
                $e = $this->expense((int)$p['subject_id']);
                if (!$e) return ['ok' => false, 'message' => 'The receipt is gone.'];
                if ($this->ledger->isLocked((string)$e['expense_date'])) return ['ok' => false, 'message' => substr((string)$e['expense_date'], 0, 7) . ' is locked.'];
                require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGate.php';
                $changes = (array)($undo['fields'] ?? []);
                $opts = ['allow_locked' => true];
                if (isset($undo['status'])) {
                    $changes['status'] = $undo['status'];
                    $opts['transition'] = $undo['status'] === 'forwarded' ? 'forward' : 'approve';
                }
                if (array_key_exists('allocations', $undo)) $changes['allocations'] = $undo['allocations'];
                (new ExpenseGate($this->db))->apply((int)$p['subject_id'], $changes, ExpenseGate::actor($user), 'lookback_undo', $opts);
                $msg = 'Put back as it was.';
            } elseif ($undo['type'] === 'bank') {
                $r = (new BankLineMoveService($this->db, $this->ledger))->move((int)$p['subject_id'], (int)$undo['account_id'], $userId, 'owner');
                if (empty($r['ok'])) return ['ok' => false, 'message' => $r['message']];
                $msg = 'Moved back. ' . $r['message'];
            } elseif ($undo['type'] === 'reversal') {
                $this->ledger->reverseEntry((int)$undo['reversal_id'], $userId, 'look-back undo', 'owner');
                $msg = 'The reversal is reversed — the entry counts again.';
            } else {
                return ['ok' => false, 'message' => 'A re-post brings the books in line with the record; there is nothing to put back.'];
            }
        } catch (Throwable $e) {
            error_log('Lookback undo #' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Undo failed: ' . $e->getMessage()];
        }
        $this->db->prepare("UPDATE lookback_proposals SET status = 'undone', undone_by = ?, undone_at = ?, updated_at = ? WHERE id = ?")
           ->execute([$userId ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'message' => $msg];
    }

    private function applyExpense(array $p, array $before, array $after, array $user): array
    {
        $id = (int)$p['subject_id'];
        $e = $this->expense($id);
        if (!$e) return ['stale' => true, 'message' => 'The receipt is gone.'];
        if ($this->ledger->isLocked((string)$e['expense_date'])) {
            return ['ok' => false, 'message' => substr((string)$e['expense_date'], 0, 7) . ' is locked — I can\'t change a receipt in a closed month.'];
        }
        require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGate.php';
        $split = new ExpenseSplitService($this->db);
        $now = self::currentBefore($e, $before, $split->ready() && $split->hasSplit($id));
        if (LookbackRules::signature($now) !== LookbackRules::signature($before)) {
            return ['stale' => true, 'message' => 'The receipt changed since I proposed this — I\'ll look again on the next scan.'];
        }
        $gate = new ExpenseGate($this->db);
        $actor = ExpenseGate::actor($user);
        $undo = ['type' => 'expense', 'fields' => []];
        if ($p['kind'] === 'duplicate') {
            $gate->apply($id, ['status' => 'cancelled'], $actor, 'lookback', ['transition' => 'cancel', 'allow_locked' => true]);
            $undo['status'] = $e['status'];
            return ['ok' => true, 'message' => 'Copy #' . $id . ' cancelled; its entry is reversed. #' . (int)($after['copy_of'] ?? 0) . ' stays.', 'undo' => $undo];
        }
        if ($p['kind'] === 'split') {
            $gate->apply($id, ['allocations' => (array)$after['allocations']], $actor, 'lookback', ['allow_locked' => true]);
            $undo['allocations'] = [];
            return ['ok' => true, 'message' => 'Split by line and re-posted.', 'undo' => $undo];
        }
        $changes = array_intersect_key($after, array_flip(ExpenseGate::COLUMNS));
        foreach ($changes as $k => $v) $undo['fields'][$k] = $e[$k] ?? null;
        $res = $gate->apply($id, $changes, $actor, 'lookback', ['allow_locked' => true]);
        if (isset($changes['accounting_category'])) $this->confirmVendor($e, (string)$changes['accounting_category']);
        return ['ok' => true, 'message' => 'Receipt #' . $id . ' updated' . (!empty($res['reposted']) ? ' and re-posted' : '') . '.', 'undo' => $undo];
    }

    private function applyBank(array $p, array $before, array $after, int $userId): array
    {
        $txId = (int)$p['subject_id'];
        $s = $this->db->prepare("SELECT t.id, t.transaction_date, t.account_id, c.code FROM accounting_transactions t
                                 LEFT JOIN chart_of_accounts c ON c.id = t.account_id WHERE t.id = ?");
        $s->execute([$txId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx) return ['stale' => true, 'message' => 'The bank line is gone.'];
        if ((string)($tx['code'] ?? '') !== (string)($before['account'] ?? '')) {
            return ['stale' => true, 'message' => 'The line was moved since I proposed this — I\'ll look again on the next scan.'];
        }
        $to = $this->chart()[(string)$after['account']] ?? null;
        if (!$to) return ['ok' => false, 'message' => 'Account ' . $after['account'] . ' doesn\'t exist — run migration 1250.'];
        $r = (new BankLineMoveService($this->db, $this->ledger))->move($txId, (int)$to['id'], $userId, $p['source'] === 'rules' ? 'penny' : 'owner');
        if (empty($r['ok'])) return ['ok' => false, 'message' => $r['message']];
        // Penny learns: an owner confirmation for the payee (bank rule after 2) + the bank card's review row.
        try { (new BankRuleLearning($this->db))->learnFromCorrection($txId, (int)$to['id'], $userId); } catch (Throwable $e) { error_log('Lookback bank lesson: ' . $e->getMessage()); }
        $this->review($txId, (int)$to['id'], $userId);
        return ['ok' => true, 'message' => $r['message'] . (($r['journal'] ?? '') !== '' ? ' Journal: ' . $r['journal'] . '.' : ''),
                'undo' => ['type' => 'bank', 'account_id' => (int)$tx['account_id']]];
    }

    private function applyJournal(array $p, array $after, int $userId): array
    {
        $action = (string)($after['action'] ?? '');
        $entryId = (int)$p['subject_id'];
        $s = $this->db->prepare("SELECT id, entry_date, reversed_by_entry_id FROM journal_entries WHERE id = ?");
        $s->execute([$entryId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || !empty($e['reversed_by_entry_id'])) return ['stale' => true, 'message' => 'That entry has already been reversed.'];
        if ($this->ledger->isLocked((string)$e['entry_date'])) return ['ok' => false, 'message' => substr((string)$e['entry_date'], 0, 7) . ' is locked.'];
        if ($action === 'reverse_entry') {
            $rev = $this->ledger->reverseEntry($entryId, $userId, 'look-back: ' . $p['title'], 'owner');
            return ['ok' => true, 'message' => 'Entry #' . $entryId . ' reversed (#' . $rev . ').', 'undo' => ['type' => 'reversal', 'reversal_id' => $rev]];
        }
        if ($action === 'repost_expense') {
            require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGateHooks.php';
            $ok = (new ExpenseGateHooks($this->db))->repost((int)$after['expense_id'], $userId, 'look-back: entry no longer matched the receipt');
            return $ok ? ['ok' => true, 'message' => 'Re-posted from the receipt.', 'undo' => ['type' => 'repost']]
                       : ['ok' => false, 'message' => 'Could not re-post (migration 1131 not run?) — see the PHP log.'];
        }
        if ($action === 'rejournal_bank') {
            $r = (new BankLineMoveService($this->db, $this->ledger))->rejournal((int)$after['tx_id'], $userId, 'owner', 'look-back: entry no longer matched the line');
            return in_array($r, ['reposted', 'reversed', 'posted'], true) ? ['ok' => true, 'message' => 'Journal ' . $r . '.', 'undo' => ['type' => 'repost']]
                                                                         : ['ok' => false, 'message' => 'Journal ' . $r . ' — see the PHP log.'];
        }
        return ['ok' => false, 'message' => 'Nothing to apply.'];
    }

    /** The proposal's "before" fields read from the receipt as it is now. Pure. */
    public static function currentBefore(array $e, array $before, bool $hasSplit): array
    {
        $now = [];
        foreach ($before as $k => $v) {
            if ($k === 'allocations') $now[$k] = $hasSplit ? ['split'] : [];
            elseif ($k === 'copy_of') $now[$k] = $v;
            elseif (in_array($k, ['gst_amount', 'amount', 'pst_amount', 'total'], true)) $now[$k] = round((float)($e[$k] ?? 0), 2);
            elseif ($k === 'job_id') $now[$k] = !empty($e[$k]) ? (int)$e[$k] : null;
            else $now[$k] = ($e[$k] ?? null) === '' ? null : ($e[$k] ?? null);
        }
        return $now;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AI — one call per vendor / payee, under the budget
    // ─────────────────────────────────────────────────────────────────────────

    public function budget(): float
    {
        $v = $this->setting(self::BUDGET_KEY);
        return $v !== null && is_numeric($v) ? max(0.0, (float)$v) : self::DEFAULT_BUDGET;
    }

    public function spent(): float
    {
        try {
            return round((float)$this->db->query("SELECT COALESCE(SUM(cost_usd), 0) FROM lookback_ai_calls")->fetchColumn(), 4);
        } catch (Throwable $e) {
            return PHP_FLOAT_MAX;   // can't count → don't spend
        }
    }

    public function spend(): array
    {
        $calls = 0;
        try { $calls = (int)$this->db->query("SELECT COUNT(*) FROM lookback_ai_calls")->fetchColumn(); } catch (Throwable $e) { /* before 1250 */ }
        $spent = $this->spent();
        return ['budget' => $this->budget(), 'spent' => $spent >= PHP_FLOAT_MAX ? 0.0 : $spent, 'calls' => $calls, 'key' => $this->hasKey()];
    }

    public function canSpend(): bool
    {
        return $this->hasKey() && $this->spent() + self::CALL_RESERVE <= $this->budget();
    }

    public static function cost(int $in, int $out): float
    {
        return round(($in * self::PRICE_IN + $out * self::PRICE_OUT) / 1_000_000, 4);
    }

    private function hasKey(): bool
    {
        return $this->transport !== null || (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '');
    }

    public const VENDOR_PROMPT = <<<'TXT'
You are Penny, the bookkeeper for Mowology, a small landscaping company in Vancouver, BC (GST 5%, BC PST 7%).
Pick the expense category for receipts from ONE vendor. Categories: {CATEGORIES}.
Owner's rules: diesel is for the Dodge Ram truck (Fuel); regular gas under $50 is for equipment (Fuel);
EGO products are usually equipment (Tools/Equipment); food and drink is Meals (50% of GST claimable);
landfill / dump fees are Disposal/Dump (no GST); a gym, streaming, groceries or anything personal is Personal.
Answer only from what the receipts show; if unsure, lower the confidence (0..1). Use "Other" only if nothing fits.
TXT;

    public static function vendorSchema(): array
    {
        return ['type' => 'object', 'properties' => [
                    'category'   => ['type' => 'string'],
                    'confidence' => ['type' => 'number'],
                    'reason'     => ['type' => 'string'],
                ], 'required' => ['category', 'confidence', 'reason'], 'additionalProperties' => false];
    }

    public static function vendorRequest(string $vendor, array $receipts, array $categories): array
    {
        $lines = [];
        foreach ($receipts as $e) {
            $items = array_slice(array_map(fn($l) => trim((string)$l['name']) . ' $' . number_format((float)$l['line_total'], 2), (array)($e['lines'] ?? [])), 0, 8);
            $lines[] = '- ' . substr((string)$e['expense_date'], 0, 10) . ' total $' . number_format((float)$e['total'], 2) . ', GST $' . number_format((float)$e['gst_amount'], 2)
                     . ', filed as "' . ($e['accounting_category'] ?: 'none') . '"' . ($items ? '; lines: ' . implode('; ', $items) : '')
                     . (!empty($e['description']) ? '; note: ' . mb_substr((string)$e['description'], 0, 120) : '');
        }
        return [
            'model' => self::MODEL, 'max_tokens' => 600,
            'system' => [['type' => 'text', 'text' => str_replace('{CATEGORIES}', implode(', ', $categories), self::VENDOR_PROMPT), 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::vendorSchema()]],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Vendor: ' . $vendor . "\nReceipts:\n" . implode("\n", $lines)]]]],
        ];
    }

    /** One Claude call for a vendor; the answer becomes a lookback_rules row. */
    private function askVendor(string $vk, array $receipts): void
    {
        $cats = array_merge(defined('EXPENSE_ACCOUNTING_CATEGORIES') ? EXPENSE_ACCOUNTING_CATEGORIES : ['Materials', 'Fuel', 'Tools/Equipment', 'Meals', 'Other'], []);
        if (!in_array('Personal', $cats, true)) $cats[] = 'Personal';
        $vendorName = (string)($receipts[0]['vendor_name'] ?: $receipts[0]['vendor_name_raw'] ?: $vk);
        $res = $this->send(self::vendorRequest($vendorName, $receipts, $cats));
        [$parsed, $in, $out, $error] = self::readResponse($res);
        $cat = null;
        if (!$error) {
            foreach ($cats as $c) if (strcasecmp($c, trim((string)($parsed['category'] ?? ''))) === 0) $cat = $c;
            if (!$cat) $error = 'category not in the list';
            elseif ($cat === 'Other') $error = 'no better category than Other';
        }
        $cost = self::cost($in, $out);
        $this->logCall('vendor', $vk, $in, $out, $cost, $error);
        if ($error) { $this->aiLog['errors'][] = $vendorName . ': ' . $error; return; }
        $now = date('Y-m-d H:i:s');
        $conf = (int)round(max(0.0, min(1.0, (float)($parsed['confidence'] ?? 0))) * 100);
        $this->db->prepare("INSERT INTO lookback_rules (rule_kind, rule_key, category, confidence, reason, source, confirmations, rejected, created_at, updated_at)
                            VALUES ('vendor', ?, ?, ?, ?, 'ai', 0, 0, ?, ?)")
           ->execute([mb_substr($vk, 0, 120), $cat, $conf, mb_substr((string)($parsed['reason'] ?? ''), 0, 500), $now, $now]);
    }

    /**
     * Guidance for a payee: Penny's own bank cache (bank_guidance, free), else one Claude call
     * through BankGuidanceService (its cache is reused by the bank card).
     */
    private function payeeGuidance(string $description, int $txId, int $aiCalls, float $amount): ?array
    {
        $key = BankGuidanceService::payeeKey($description);
        if ($key === '') return null;
        $g = $this->guidance ?? new BankGuidanceService($this->db, $this->transport);
        if (!$g->ready()) return null;
        $chart = $g->chart();
        try {
            $c = $this->db->prepare("SELECT guidance_json FROM bank_guidance WHERE payee_key = ? AND guidance_json IS NOT NULL AND error IS NULL ORDER BY id DESC LIMIT 1");
            $c->execute([$key]);
            $cached = json_decode((string)$c->fetchColumn(), true);
            if (is_array($cached)) {
                $cached['split'] = [];                         // a split was for that line's amount, not this one
                $v = BankGuidanceService::validate($cached, $chart, $amount);
                if ($v['ok']) { $this->aiLog['reused']++; return $v['guidance'] + ['_cached' => true]; }
            }
        } catch (Throwable $e) { /* no cache */ }
        if ($this->askedPayee($key)) return null;                     // asked before and it failed: not again
        if ($aiCalls <= 0 || $this->aiLog['calls'] >= $aiCalls) { $this->aiLog['pending']++; return null; }
        if (!$this->canSpend()) { $this->aiLog['skipped_budget']++; $this->aiLog['pending']++; return null; }
        $r = $g->guide($txId, '', 0);
        $in = 0; $out = 0; $err = $r['ok'] ? null : mb_substr((string)($r['message'] ?? 'failed'), 0, 255);
        if (!empty($r['guidance_id'])) {
            try {
                $s = $this->db->prepare("SELECT input_tokens, output_tokens FROM bank_guidance WHERE id = ?");
                $s->execute([(int)$r['guidance_id']]);
                $t = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                $in = (int)($t['input_tokens'] ?? 0); $out = (int)($t['output_tokens'] ?? 0);
            } catch (Throwable $e) { /* tokens unknown */ }
        }
        if (empty($r['from_earlier'])) $this->logCall('payee', mb_substr($key, 0, 120), $in, $out, self::cost($in, $out), $err);
        return $r['ok'] ? (array)$r['guidance'] : null;
    }

    /** @return array{0: ?array, 1: int, 2: int, 3: ?string} parsed, input tokens, output tokens, error */
    public static function readResponse(array $res): array
    {
        if (($res['code'] ?? 0) !== 200) return [null, 0, 0, 'HTTP ' . ($res['code'] ?? 0)];
        $resp = json_decode((string)$res['body'], true) ?: [];
        $u = $resp['usage'] ?? [];
        $in = (int)($u['input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0);
        $out = (int)($u['output_tokens'] ?? 0);
        if (($resp['stop_reason'] ?? '') === 'refusal') return [null, $in, $out, 'declined'];
        if (($resp['stop_reason'] ?? '') === 'max_tokens') return [null, $in, $out, 'answer cut off'];
        $parsed = null;
        foreach ($resp['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $parsed = json_decode((string)$b['text'], true);
        return is_array($parsed) ? [$parsed, $in, $out, null] : [null, $in, $out, 'unparseable answer'];
    }

    private function send(array $body): array
    {
        if ($this->transport) return ($this->transport)($body);
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) error_log('Lookback curl: ' . curl_error($ch));
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$raw];
    }

    private function logCall(string $kind, string $key, int $in, int $out, float $cost, ?string $error): void
    {
        $this->aiLog['calls']++;
        $this->aiLog['cost'] = round($this->aiLog['cost'] + $cost, 4);
        $this->db->prepare("INSERT INTO lookback_ai_calls (call_kind, call_key, model, input_tokens, output_tokens, cost_usd, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$kind, mb_substr($key, 0, 120), self::MODEL, $in, $out, $cost, $error ? mb_substr($error, 0, 255) : null, date('Y-m-d H:i:s')]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Learning
    // ─────────────────────────────────────────────────────────────────────────

    /** vendor key => {category, source, confidence, reason, confirmations}, rejected ones left out. */
    public function vendorRules(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT rule_key, category, source, confidence, reason, confirmations FROM lookback_rules
                                       WHERE rule_kind = 'vendor' AND rejected = 0 AND category IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['rule_key']] = $r;
            }
        } catch (Throwable $e) { /* before migration 1250 */ }
        return $out;
    }

    /** vendor key => true: asked Claude already (answer or not) — never asked twice. */
    private function askedVendors(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT DISTINCT call_key FROM lookback_ai_calls WHERE call_kind = 'vendor'")->fetchAll(PDO::FETCH_COLUMN) as $k) $out[(string)$k] = true;
        } catch (Throwable $e) { /* before 1250 */ }
        return $out;
    }

    private function askedPayee(string $key): bool
    {
        try {
            $s = $this->db->prepare("SELECT 1 FROM lookback_ai_calls WHERE call_kind = 'payee' AND call_key = ? LIMIT 1");
            $s->execute([mb_substr($key, 0, 120)]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Tim approved a category for this vendor: an owner rule (confirmations + 1), reused by the next scan. */
    private function confirmVendor(array $e, string $category): void
    {
        $vk = self::vendorKey($e);
        if ($vk === '') return;
        $now = date('Y-m-d H:i:s');
        $s = $this->db->prepare("SELECT id, category, confirmations, source FROM lookback_rules WHERE rule_kind = 'vendor' AND rule_key = ?");
        $s->execute([$vk]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $this->db->prepare("INSERT INTO lookback_rules (rule_kind, rule_key, category, confidence, reason, source, confirmations, rejected, created_at, updated_at)
                                VALUES ('vendor', ?, ?, 100, 'Confirmed by you in the look-back', 'owner', 1, 0, ?, ?)")->execute([$vk, $category, $now, $now]);
            return;
        }
        $same = strcasecmp((string)$r['category'], $category) === 0 && $r['source'] === 'owner';
        $this->db->prepare("UPDATE lookback_rules SET category = ?, source = 'owner', confidence = 100, confirmations = ?, rejected = 0,
                                   reason = 'Confirmed by you in the look-back', updated_at = ? WHERE id = ?")
           ->execute([$category, $same ? (int)$r['confirmations'] + 1 : 1, $now, (int)$r['id']]);
    }

    /** The bank card's review row, so the line doesn't come back to her card. */
    private function review(int $txId, int $accountId, int $userId): void
    {
        try {
            $this->db->prepare("INSERT INTO bank_line_reviews (transaction_id, suggested_account_id, final_account_id, outcome, decided_by) VALUES (?, ?, ?, 'accepted', ?)")
               ->execute([$txId, $accountId, $accountId, $userId ?: null]);
        } catch (Throwable $e) {
            try {
                $this->db->prepare("UPDATE bank_line_reviews SET final_account_id = ?, outcome = 'accepted', decided_by = ? WHERE transaction_id = ?")
                   ->execute([$accountId, $userId ?: null, $txId]);
            } catch (Throwable $e2) { /* no reviews table (migration 1129) */ }
        }
    }

    /** key => code: owner-confirmed active learned bank rules. */
    private function ownerBankRules(): array
    {
        $out = [];
        try {
            $owner = (new BankRuleLearning($this->db))->hasOwnerColumns();
            $q = $this->db->query("SELECT r.condition_value, c.code FROM transaction_rules r JOIN chart_of_accounts c ON c.id = r.account_id
                                   WHERE r.source = 'learned' AND r.is_active = 1 AND r.condition_field = 'description'"
                                   . ($owner ? ' AND r.owner_confirmations >= ' . BankRuleLearning::CONFIRMATIONS : ''));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['condition_value']] = (string)$r['code'];
        } catch (Throwable $e) { /* no rules */ }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public static function scanKey(array $p): string
    {
        return mb_substr($p['family'] . ':' . $p['kind'] . ':' . $p['subject_type'] . ':' . (int)$p['subject_id'], 0, 80);
    }

    public static function vendorKey(array $e): string
    {
        $v = trim((string)($e['vendor_name'] ?? '')) ?: trim((string)($e['vendor_name_raw'] ?? ''));
        return mb_substr(strtolower(preg_replace('/\s+/', ' ', $v)), 0, 120);
    }

    private static function kindWhy(string $kind): string
    {
        return ['journal_gst' => 'The GST (ITC) line differs from the GST on the record.',
                'journal_amount' => 'The amount posted differs from the record.',
                'journal_account' => 'It sits on a different account than the record says now.'][$kind] ?? '';
    }

    private static function codesSay(array $m): string
    {
        $b = [];
        foreach ($m as $code => $amt) $b[] = $code . ' $' . number_format((float)$amt, 2);
        return $b ? implode(' + ', $b) : 'nothing';
    }

    private function info(string $kind, array $r, string $title, array $evidence): array
    {
        return ['family' => 'bank', 'kind' => $kind, 'subject_type' => 'bank', 'subject_id' => (int)$r['id'],
                'date' => substr((string)$r['transaction_date'], 0, 10), 'title' => $title, 'before' => ['account' => (string)($r['code'] ?? '')],
                'after' => [], 'evidence' => $evidence, 'confidence' => 100, 'source' => 'rules', 'amount' => round(abs((float)$r['amount']), 2), 'gst' => 0.0];
    }

    /** code => {id, code, name, type} — active accounts. */
    public function chart(): array
    {
        if ($this->chart === null) {
            $this->chart = [];
            foreach ($this->db->query("SELECT id, code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code, id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if (!isset($this->chart[(string)$a['code']])) $this->chart[(string)$a['code']] = $a;
            }
        }
        return $this->chart;
    }

    /**
     * Bank lines booked as a deposit by another tool — the Jobber import (journal source
     * 'bank_deposit', jobber_ledger_log) or the bank balance check (1236). The look-back never
     * proposes anything for them: they are settled there, and their undo lives there.
     * @return array<int, true>
     */
    public function depositBookedIds(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT DISTINCT source_id FROM journal_entries WHERE source_type = 'bank_deposit' AND reversed_by_entry_id IS NULL AND source_id IS NOT NULL")
                         ->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = true;
        } catch (Throwable $e) { /* no journal */ }
        try {
            foreach ($this->db->query("SELECT DISTINCT transaction_id FROM jobber_ledger_log WHERE transaction_id IS NOT NULL AND undone_at IS NULL")
                         ->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = true;
        } catch (Throwable $e) { /* before migration 1239 */ }
        return $out;
    }

    /** category(lower) => code, the way the nightly sync reads it (owner map first, then chart aliases). */
    private function categoryCodes(): array
    {
        $codes = (new LedgerAccountMap($this->db))->categoryCodes();
        try {
            foreach ($this->db->query("SELECT code, expense_category_alias FROM chart_of_accounts WHERE expense_category_alias IS NOT NULL AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $codes += [strtolower((string)$r['expense_category_alias']) => (string)$r['code']];
            }
        } catch (Throwable $e) { /* no alias column */ }
        return $codes;
    }

    /** "1234.56" => "INV-2026-0042" — 2026 invoices not cancelled. */
    private function invoiceTotals(): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT invoice_number, total FROM invoices WHERE COALESCE(issue_date, created_at) BETWEEN ? AND ? AND status NOT IN ('draft', 'cancelled', 'void')");
            $s->execute([self::FROM, self::TO . ' 23:59:59']);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $i) $out[number_format((float)$i['total'], 2, '.', '')] = (string)$i['invoice_number'];
        } catch (Throwable $e) { /* no invoices table */ }
        return $out;
    }

    private function rowsById(string $sqlWithIn, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $s = $this->db->prepare(sprintf($sqlWithIn, implode(',', array_fill(0, count($chunk), '?'))));
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    private function dismissedPairs(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT expense_a, expense_b FROM expense_duplicate_dismissals")->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $a = (int)$d['expense_a']; $b = (int)$d['expense_b'];
                $out[min($a, $b) . ':' . max($a, $b)] = true;
            }
        } catch (Throwable $e) { /* no dismissals table */ }
        return $out;
    }

    private function filings(): array
    {
        try {
            return $this->db->query("SELECT period_from, period_to, filed_on FROM gst_filings ORDER BY period_from")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // before migration 1228
        }
    }

    private function lockedMonths(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
            }
        } catch (Throwable $e) { /* nothing locked */ }
        return $out;
    }

    private function row(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM lookback_proposals WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function expense(int $id): ?array
    {
        $s = $this->db->prepare("SELECT e.*, v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function vendorKeyOf(int $expenseId): string
    {
        $e = $this->expense($expenseId);
        return $e ? self::vendorKey($e) : '';
    }

    private function mark(int $id, string $status, string $note, int $userId): void
    {
        $this->db->prepare("UPDATE lookback_proposals SET status = ?, result_note = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?")
           ->execute([$status, mb_substr($note, 0, 500), $userId ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    }

    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function setSetting(string $key, string $value): void
    {
        try {
            $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?");
            $u->execute([$value, $key]);
            if ($u->rowCount() === 0 && $this->setting($key) === null) {
                $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
            }
        } catch (Throwable $e) { /* no ops_settings */ }
    }

    private function hasColumn(string $table, string $col): bool
    {
        try {
            $this->db->query("SELECT {$col} FROM {$table} LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
