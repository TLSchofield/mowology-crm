<?php
/**
 * ReceiptReviewService — the review layer: every waiting receipt with the evidence a person checks it against,
 * grouped by vendor, and its suspected duplicates under the copy that is already in the books (owner,
 * 2026-10-20: "I want a review layer … I do still want to work through double-checking receipts accuracy";
 * "a fully approved and linked receipt would show green backgrounded above the suspected duplicate then dupes can
 * be merged into it").
 *
 * Nothing here approves: it gathers. Per receipt —
 *   paper   Penny's read of the PRINTED total (her latest live read) vs the saved total
 *   bank    the statement line it is linked to, else whether exactly one unlinked line has its exact amount
 *           (approving links it — ExpenseGateHooks::linkBank); paid personally: none expected
 *   penny   her category vs the saved one, and her own doubts (notes)
 *   gst     ExpenseGate::taxCheck (impossible / above our rate)
 *   photo   whether there is one
 *   flags   only the ones that need a look (duplicate, GST, no photo …) — never weekend / rapid-submission noise
 * all_pass = every check agrees. An aid for the person, not a decision.
 *
 * Duplicates: DuplicateReceiptService pairs → groups; the KEEPER of a group is the copy in the books —
 * approved and bank-linked, else approved, else linked, else with a photo, else the oldest (keeperOf, pure).
 * Global-namespace service: require_once the file.
 */
require_once __DIR__ . '/ExpenseGate.php';
require_once __DIR__ . '/DuplicateReceiptService.php';

class ReceiptReviewService
{
    public const WAITING = ['draft', 'pending_approval'];
    /** Flags worth a person's look; the rest (WEEKEND_EXPENSE, RAPID_SUBMISSION, ROUND_AMOUNT …) are noise for a field crew. */
    public const REAL_FLAGS = ['DUPLICATE_DAY', 'NO_RECEIPT', 'GST_MISMATCH', 'GST_WRONG', 'GST_CHECK', 'DATE_UNREADABLE',
                               'HIGH_AMOUNT', 'CATEGORY_MISMATCH', 'TOTAL_MISMATCH'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure ──────────────────────────────────────────────────────────────────

    /** The copy that is in the books: approved + bank-linked > approved > linked > has a photo > oldest. PURE. */
    public static function keeperOf(array $members): int
    {
        $rank = static function (array $m): array {
            $approved = in_array($m['status'] ?? '', ['approved', 'forwarded'], true);
            return [($approved && !empty($m['linked'])) ? 0 : 1, $approved ? 0 : 1, !empty($m['linked']) ? 0 : 1,
                    !empty($m['receipt_media_id']) ? 0 : 1, (int)$m['id']];
        };
        usort($members, static fn($a, $b) => $rank($a) <=> $rank($b));
        return (int)$members[0]['id'];
    }

    /** Only the flags that need a look. PURE. */
    public static function realFlags(?string $flags): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', strtoupper((string)$flags)))) as $f) {
            if (in_array($f, self::REAL_FLAGS, true) && !in_array($f, $out, true)) $out[] = $f;
        }
        return $out;
    }

    /**
     * The checks for one receipt row. PURE.
     * @param array $r  total, gst_amount, pst_amount, accounting_category, payment_method, receipt_media_id, status,
     *                  penny_total, penny_category, bank (null|array{date, amount}), bank_candidates (int), in_duplicate (bool)
     * @return array{checks: list<array{key: string, ok: bool, text: string}>, all_pass: bool}
     */
    public static function checks(array $r, float $gstRate = 0.05): array
    {
        $c = [];
        $total = round((float)($r['total'] ?? 0), 2);
        $money = static fn($v) => '$' . number_format((float)$v, 2);
        if ($r['penny_total'] === null) {
            $c[] = ['key' => 'paper', 'ok' => false, 'text' => 'Penny hasn\'t read the print yet'];
        } else {
            $same = abs((float)$r['penny_total'] - $total) < 0.005;
            $c[] = ['key' => 'paper', 'ok' => $same, 'text' => $same ? 'Paper ' . $money($total) . ' = saved'
                : 'Paper says ' . $money($r['penny_total']) . ', saved ' . $money($total)];
        }
        if (($r['payment_method'] ?? '') === 'owner_paid') {
            $c[] = ['key' => 'bank', 'ok' => true, 'text' => 'Paid personally — no bank line'];
        } elseif (!empty($r['bank'])) {
            $same = abs(abs((float)$r['bank']['amount']) - $total) < 0.005;
            $c[] = ['key' => 'bank', 'ok' => $same, 'text' => 'Bank ' . date('M j', strtotime((string)$r['bank']['date'])) . ' ' . $money(abs((float)$r['bank']['amount']))
                . ($same ? '' : ' — not the saved total')];
        } elseif ((int)($r['bank_candidates'] ?? 0) === 1) {
            $c[] = ['key' => 'bank', 'ok' => true, 'text' => 'One bank line matches — linked when approved'];
        } else {
            $n = (int)($r['bank_candidates'] ?? 0);
            $c[] = ['key' => 'bank', 'ok' => false, 'text' => $n > 1 ? $n . ' bank lines have this amount — pick one' : 'No bank line yet'];
        }
        $pc = trim((string)($r['penny_category'] ?? ''));
        if ($pc !== '') {
            $same = strcasecmp($pc, (string)($r['accounting_category'] ?? '')) === 0;
            $c[] = ['key' => 'category', 'ok' => $same, 'text' => $same ? $pc : 'Penny says ' . $pc . ', saved ' . ((string)($r['accounting_category'] ?? '') ?: 'none')];
        } else {
            $c[] = ['key' => 'category', 'ok' => trim((string)($r['accounting_category'] ?? '')) !== '', 'text' => ((string)($r['accounting_category'] ?? '')) ?: 'No category'];
        }
        $tc = ExpenseGate::taxCheck($r, $gstRate);
        if ($tc['level'] !== null) $c[] = ['key' => 'gst', 'ok' => false, 'text' => (string)$tc['message']];
        if (empty($r['receipt_media_id'])) $c[] = ['key' => 'photo', 'ok' => false, 'text' => 'No photo of the receipt'];
        if (!empty($r['in_duplicate'])) $c[] = ['key' => 'duplicate', 'ok' => false, 'text' => 'Suspected duplicate — see below'];
        $all = true;
        foreach ($c as $x) $all = $all && $x['ok'];
        return ['checks' => $c, 'all_pass' => $all];
    }

    /** Groups → [keeper, copies] in display order, keeper first. PURE. */
    public static function duplicateSets(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $members = $g['members'] ?? [];
            if (count($members) < 2) continue;
            $keep = self::keeperOf($members);
            $keeper = null; $copies = [];
            foreach ($members as $m) { if ((int)$m['id'] === $keep) $keeper = $m; else $copies[] = $m; }
            $out[] = ['keeper' => $keeper, 'copies' => $copies, 'why' => $g['why'] ?? [], 'pairs' => $g['pairs'] ?? []];
        }
        return $out;
    }

    // ── The list ─────────────────────────────────────────────────────────────

    /**
     * @return array{groups: list<array{vendor: string, rows: list<array>, all_pass: int}>, duplicates: list<array>, counts: array}
     */
    public function build(int $limit = 400): array
    {
        $rows = $this->db->query("
            SELECT e.id, e.expense_date, e.total, e.gst_amount, e.pst_amount, e.accounting_category, e.payment_method, e.status,
                   e.receipt_media_id, e.anomaly_flags, e.job_id, e.vendor_id, e.created_by, e.description,
                   COALESCE(v.name, e.vendor_name_raw, 'Unknown vendor') AS vendor,
                   u.full_name AS submitted_by
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN users u ON u.id = e.created_by
            WHERE e.status IN ('draft', 'pending_approval')
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['groups' => [], 'duplicates' => [], 'counts' => ['waiting' => 0, 'all_pass' => 0, 'duplicates' => 0]];
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $penny = $this->pennyReads($ids);
        $bank = $this->bankLines($ids);
        $cands = $this->bankCandidates($rows);

        $dup = new DuplicateReceiptService($this->db);
        $sets = [];
        try {
            $sets = self::duplicateSets($this->withLinks(DuplicateReceiptService::groups($dup->pairsFor($ids))));
        } catch (Throwable $e) {
            error_log('[receipt review] duplicates: ' . $e->getMessage());
        }
        $inDup = [];
        foreach ($sets as $s) foreach ($s['copies'] as $c) $inDup[(int)$c['id']] = true;

        $gstRate = 0.05;
        try {
            require_once dirname(__DIR__, 2) . '/Accounting/Services/TaxEngine.php';
            $gstRate = TaxEngine::gstRate($this->db);
        } catch (Throwable $e) { /* 5% */ }

        $byVendor = [];
        $pass = 0;
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $p = $penny[$id] ?? null;
            $row = $r + [
                'penny_total' => $p['total'] ?? null, 'penny_category' => $p['category'] ?? null, 'penny_notes' => $p['notes'] ?? null,
                'bank' => $bank[$id] ?? null, 'bank_candidates' => $cands[$id] ?? 0, 'in_duplicate' => isset($inDup[$id]),
            ];
            $ck = self::checks($row, $gstRate);
            if ($ck['all_pass']) $pass++;
            $key = strtolower(trim((string)$r['vendor']));
            $byVendor[$key]['vendor'] = (string)$r['vendor'];
            $byVendor[$key]['rows'][] = [
                'id' => $id, 'date' => (string)$r['expense_date'], 'total' => (float)$r['total'], 'gst' => (float)$r['gst_amount'],
                'pst' => (float)$r['pst_amount'], 'category' => (string)$r['accounting_category'], 'status' => (string)$r['status'],
                'payment_method' => (string)$r['payment_method'], 'submitted_by' => $r['submitted_by'],
                'photo' => !empty($r['receipt_media_id']) ? '/crm/api/serve-receipt.php?id=' . (int)$r['receipt_media_id'] : null,
                'penny_notes' => $row['penny_notes'], 'flags' => self::realFlags($r['anomaly_flags']),
                'checks' => $ck['checks'], 'all_pass' => $ck['all_pass'], 'in_duplicate' => $row['in_duplicate'],
            ];
        }
        $groups = array_values($byVendor);
        foreach ($groups as &$g) $g['all_pass'] = count(array_filter($g['rows'], static fn($x) => $x['all_pass']));
        unset($g);
        usort($groups, static fn($a, $b) => count($b['rows']) <=> count($a['rows']) ?: strcmp($a['vendor'], $b['vendor']));
        return ['groups' => $groups, 'duplicates' => $sets,
                'counts' => ['waiting' => count($rows), 'all_pass' => $pass, 'duplicates' => count($sets)]];
    }

    /** The suspected COPIES (not keepers) among these receipts — never approvable from the review layer. */
    public function copyIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $out = [];
        foreach (self::duplicateSets($this->withLinks(DuplicateReceiptService::groups((new DuplicateReceiptService($this->db))->pairsFor($ids)))) as $s) {
            foreach ($s['copies'] as $c) $out[] = (int)$c['id'];
        }
        return $out;
    }

    /** Penny's latest live read per receipt: printed total, category, her doubts. */
    private function pennyReads(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT expense_id, suggestion_json FROM expense_suggestions
                                 WHERE source = 'live' AND status <> 'superseded' AND expense_id IN ($in) ORDER BY id");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = json_decode((string)$r['suggestion_json'], true);
            if (!is_array($j)) continue;
            $t = $j['total']['value'] ?? null;
            $out[(int)$r['expense_id']] = [
                'total' => is_numeric($t) && (float)$t > 0 ? round((float)$t, 2) : null,
                'category' => is_string($j['accounting_category']['value'] ?? null) ? $j['accounting_category']['value'] : null,
                'notes' => is_string($j['notes'] ?? null) && trim($j['notes']) !== '' ? mb_substr(trim($j['notes']), 0, 600) : null,
            ];
        }
        return $out;
    }

    /** The statement line each receipt is linked to. */
    private function bankLines(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT matched_expense_id, transaction_date, amount FROM accounting_transactions
                                 WHERE reference_type = 'bank_import' AND matched_expense_id IN ($in)");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['matched_expense_id']] = ['date' => $r['transaction_date'], 'amount' => (float)$r['amount']];
        return $out;
    }

    /** How many UNLINKED statement lines have each receipt's exact amount within a week (one query). */
    private function bankCandidates(array $rows): array
    {
        $out = [];
        // Money OUT only (type 'expense'): a refund or deposit of the same amount is not this purchase (review 2026-10-20).
        $s = $this->db->prepare("SELECT COUNT(*) FROM accounting_transactions WHERE reference_type = 'bank_import' AND type = 'expense' AND matched_expense_id IS NULL
                                 AND ABS(ABS(amount) - ?) < 0.005 AND transaction_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)");
        foreach ($rows as $r) {
            if ((float)$r['total'] <= 0 || ($r['payment_method'] ?? '') === 'owner_paid' || empty($r['expense_date'])) continue;
            $s->execute([round((float)$r['total'], 2), $r['expense_date'], $r['expense_date']]);
            $out[(int)$r['id']] = (int)$s->fetchColumn();
        }
        return $out;
    }

    /** Duplicate group members with whether each is linked to a statement line (the keeper rule needs it). */
    private function withLinks(array $groups): array
    {
        $ids = [];
        foreach ($groups as $g) foreach ($g['members'] ?? [] as $m) $ids[] = (int)$m['id'];
        if (!$ids) return $groups;
        $linked = $this->bankLines(array_values(array_unique($ids)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT e.id, e.status, e.expense_date, e.total, e.receipt_media_id, e.accounting_category,
                                        COALESCE(v.name, e.vendor_name_raw) AS vendor,
                                        (SELECT GROUP_CONCAT(l.name ORDER BY l.sort_order, l.id SEPARATOR ' · ') FROM expense_line_items l WHERE l.expense_id = e.id) AS items
                                 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id IN ($in)");
        $s->execute(array_values(array_unique($ids)));
        $info = array_column($s->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($groups as &$g) {
            foreach ($g['members'] as &$m) {
                $id = (int)$m['id'];
                $m = array_merge($m, $info[$id] ?? []);
                $m['linked'] = isset($linked[$id]);
                $m['bank'] = $linked[$id] ?? null;
                $m['photo'] = !empty($m['receipt_media_id']) ? '/crm/api/serve-receipt.php?id=' . (int)$m['receipt_media_id'] : null;
            }
            unset($m);
        }
        unset($g);
        return $groups;
    }
}
