<?php
declare(strict_types=1);

/**
 * GifiService — the GIFI mapping screen's data: every account with its GIFI code, lead group, status
 * and the year's balances; saving a mapping; pre-filling suggestions; the validator (GifiRules::build).
 *
 * Who may change a mapping: ledger.post (admin) or ledger.propose (the accountant) — the page decides;
 * this service records who and when, and logs every change to activity_log ('ledger.gifi').
 * A suggestion is stored with gifi_status = 'suggested'; only a person's save makes it 'confirmed'.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/GifiRules.php';

class GifiService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Fiscal year bounds (calendar year; Mowology's FY ends Dec 31). */
    public static function yearBounds(int $year): array
    {
        return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
    }

    /** code => name from the shared gifi_codes table. */
    public function knownCodes(): array
    {
        try {
            return $this->db->query("SELECT code, name FROM gifi_codes ORDER BY sort_order, code")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Every account with its mapping and the year's figures (end = balance at year end, debit − credit;
     * period = the year's activity without the FY opening entry and its reversals).
     */
    public function accounts(int $year): array
    {
        [$from, $to] = self::yearBounds($year);
        $sql = "
            SELECT coa.id, coa.code, coa.name, coa.type, coa.sub_type, coa.normal_balance, coa.is_active,
                   coa.gifi_code, coa.gifi_status, coa.gifi_note, coa.gifi_confirmed_by, coa.gifi_confirmed_at, coa.lead_group,
                   u.full_name AS confirmed_by_name,
                   COALESCE(e.end_bal, 0) AS end_bal,
                   COALESCE(p.period_bal, 0) AS period_bal,
                   COALESCE(p.n, 0) AS period_lines
            FROM chart_of_accounts coa
            LEFT JOIN users u ON u.id = coa.gifi_confirmed_by
            LEFT JOIN (
                SELECT jl.account_id, SUM(jl.debit) - SUM(jl.credit) AS end_bal
                FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                WHERE je.status = 'posted' AND je.entry_date <= ?
                GROUP BY jl.account_id
            ) e ON e.account_id = coa.id
            LEFT JOIN (
                SELECT jl.account_id, SUM(jl.debit) - SUM(jl.credit) AS period_bal, COUNT(*) AS n
                FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                WHERE je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                  AND COALESCE(je.source_type, '') <> 'fy_opening'
                  AND NOT (COALESCE(je.source_type, '') = 'adjusting'
                           AND je.source_id IN (SELECT fo.id FROM (SELECT id FROM journal_entries WHERE source_type = 'fy_opening') fo))
                GROUP BY jl.account_id
            ) p ON p.account_id = coa.id
            ORDER BY coa.code";
        $st = $this->db->prepare($sql);
        $st->execute([$to, $from, $to]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['end']    = round((float)$r['end_bal'], 2);
            $r['period'] = round((float)$r['period_bal'], 2);
            $r['lines']  = (int)$r['period_lines'];
            $r['suggestion'] = GifiRules::suggest($r);
            $rows[] = $r;
        }
        return $rows;
    }

    /** Net income for the year straight from the ledger (revenue − expenses), the validator's cross-check. */
    public static function ledgerNetIncome(array $accounts): float
    {
        $ni = 0.0;
        foreach ($accounts as $a) {
            if ($a['type'] === 'revenue') $ni -= (float)$a['period'];
            elseif ($a['type'] === 'expense') $ni -= (float)$a['period'];
        }
        return round($ni, 2);
    }

    /** The GIFI statements + validator for a year. */
    public function report(int $year): array
    {
        $accounts = $this->accounts($year);
        $known = $this->knownCodes();
        $out = GifiRules::build($accounts, $known, self::ledgerNetIncome($accounts));
        $out['accounts'] = $accounts;
        $out['known'] = $known;
        $out['year'] = $year;
        return $out;
    }

    /**
     * Save one account's mapping as confirmed by a person. An empty code clears it.
     * @throws InvalidArgumentException on an unknown or total code
     */
    public function save(int $accountId, string $code, ?string $lead, ?string $note, int $userId): array
    {
        $code = trim($code);
        $known = $this->knownCodes();
        if ($code !== '') {
            if (!preg_match('/^\d{4}$/', $code)) throw new InvalidArgumentException('A GIFI code is four digits.');
            if ($known && !isset($known[$code])) throw new InvalidArgumentException("$code is not in the GIFI list.");
            $sec = GifiRules::sectionOf($code);
            if ($sec === null || $sec === 'total' || in_array($code, GifiRules::COMPUTED_RE, true)) {
                throw new InvalidArgumentException("$code is a total or computed item — choose a detail line.");
            }
        }
        $lead = $lead !== null ? mb_substr(trim($lead), 0, 20) : null;
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;

        $before = $this->db->prepare("SELECT code, gifi_code, gifi_status, lead_group FROM chart_of_accounts WHERE id = ?");
        $before->execute([$accountId]);
        $old = $before->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new InvalidArgumentException('No such account.');

        $this->db->prepare("UPDATE chart_of_accounts
                               SET gifi_code = ?, gifi_status = ?, gifi_note = ?, lead_group = ?,
                                   gifi_confirmed_by = ?, gifi_confirmed_at = NOW()
                             WHERE id = ?")
            ->execute([$code === '' ? null : $code, $code === '' ? null : 'confirmed', $note ?: null,
                       ($lead ?: GifiRules::leadFor($code)) ?: null, $userId, $accountId]);

        if (function_exists('logActivity')) {
            logActivity($userId, null, 'ledger.gifi', json_encode([
                'account' => $old['code'], 'from' => $old['gifi_code'], 'from_status' => $old['gifi_status'],
                'to' => $code === '' ? null : $code, 'lead' => $lead,
            ], JSON_UNESCAPED_SLASHES));
        }
        return ['account' => $old['code'], 'gifi_code' => $code === '' ? null : $code];
    }

    /**
     * Store a suggestion on every account that has no GIFI code yet (status 'suggested'). Accounts the
     * rules can't place are left empty for a person. Never overwrites a mapping.
     * @return int accounts filled
     */
    public function prefillSuggestions(): int
    {
        $rows = $this->db->query("SELECT id, code, name, type, sub_type FROM chart_of_accounts
                                  WHERE gifi_code IS NULL OR gifi_code = ''")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $this->db->prepare("UPDATE chart_of_accounts
                                      SET gifi_code = ?, gifi_status = 'suggested', gifi_note = ?, lead_group = COALESCE(lead_group, ?)
                                    WHERE id = ? AND (gifi_code IS NULL OR gifi_code = '')");
        $n = 0;
        foreach ($rows as $r) {
            $s = GifiRules::suggest($r);
            if ($s['code'] === null) {
                // Keep the reason so the screen can say what to ask.
                $this->db->prepare("UPDATE chart_of_accounts SET gifi_note = ?, lead_group = COALESCE(lead_group, ?) WHERE id = ? AND gifi_note IS NULL")
                    ->execute([$s['note'], $s['lead'], (int)$r['id']]);
                continue;
            }
            $upd->execute([$s['code'], '[' . $s['confidence'] . '] ' . $s['note'], $s['lead'], (int)$r['id']]);
            $n += $upd->rowCount();
        }
        return $n;
    }

    /** Whole-dollar GIFI lines as CSV rows (code, amount) — only when the validator passes. */
    public static function csvRows(array $report): array
    {
        $rows = [['GIFI code', 'Amount']];
        $all = $report['lines'];
        foreach (['2599', '3499', '3600', '3620', '3640', '3680', '3849', '8089', '8299', '8518', '8519', '9367', '9368', '9369', '9970', '9999'] as $t) {
            if (isset($report['totals'][$t])) $all[$t] = $report['totals'][$t];
        }
        ksort($all, SORT_STRING);
        foreach ($all as $code => $amt) $rows[] = [(string)$code, (int)$amt];
        return $rows;
    }
}
