<?php
/**
 * QboAccountMapService — CRM chart_of_accounts ↔ QBO Account, suggested by the code and
 * confirmed by Tim (2026-10-07).
 *
 * suggest() is pure: for every CRM account it scores every QBO account —
 *   100  the account number matches (Account.AcctNum = chart_of_accounts.code)      'number'
 *    90  the normalised name matches exactly and the classification agrees         'name'
 *   60–85 the names are similar (similar_text ≥ 72 %) and the classification agrees 'name'
 *    ≤40  similar name but a different classification (asset vs expense …) — shown,
 *         never auto-picked                                                          'name?'
 * Classification agreement: CRM type asset/liability/equity/revenue/expense ↔ QBO Classification
 * Asset/Liability/Equity/Revenue/Expense.
 *
 * Nothing is pushed to a QBO account that Tim has not confirmed (qbo_account_map.confirmed_at).
 * A row with qbo_account_id NULL is an explicit "no twin — never push" decision.
 */
declare(strict_types=1);

class QboAccountMapService
{
    public const AUTO_PICK_MIN = 85;   // suggestions at/above this are pre-selected on the page

    private const CLASS_OF = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expense'];
    private const NOISE = ['expense', 'expenses', 'account', 'accounts', 'and', 'the', 'of', 'other', 'misc', 'general'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** CRM accounts to map (active, leaf or not). */
    public function crmAccounts(): array
    {
        $stmt = $this->db->query("SELECT id, code, name, type, sub_type, is_active FROM chart_of_accounts WHERE is_active = 1 ORDER BY code");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Confirmed rows for the connection, keyed by crm_account_id. */
    public function confirmed(int $connectionId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM qbo_account_map WHERE connection_id = ?");
        $stmt->execute([$connectionId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['crm_account_id']] = $r;
        }
        return $out;
    }

    /** crm_account_id => qbo_account_id for confirmed, non-null rows (what the push uses). */
    public function lookup(int $connectionId): array
    {
        $out = [];
        foreach ($this->confirmed($connectionId) as $crmId => $r) {
            if (!empty($r['confirmed_at']) && !empty($r['qbo_account_id'])) $out[$crmId] = (string)$r['qbo_account_id'];
        }
        return $out;
    }

    public function confirm(int $connectionId, int $crmAccountId, ?array $qbo, int $userId, int $confidence = 0, string $matchedOn = 'manual'): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO qbo_account_map (connection_id, crm_account_id, qbo_account_id, qbo_name, qbo_acct_num, qbo_type, qbo_sub_type, confidence, matched_on, confirmed_by, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE qbo_account_id = VALUES(qbo_account_id), qbo_name = VALUES(qbo_name), qbo_acct_num = VALUES(qbo_acct_num),
                qbo_type = VALUES(qbo_type), qbo_sub_type = VALUES(qbo_sub_type), confidence = VALUES(confidence), matched_on = VALUES(matched_on),
                confirmed_by = VALUES(confirmed_by), confirmed_at = NOW()"
        );
        $stmt->execute([
            $connectionId, $crmAccountId,
            $qbo['id'] ?? null, isset($qbo['name']) ? substr((string)$qbo['name'], 0, 255) : null, $qbo['num'] ?? null,
            $qbo['type'] ?? null, $qbo['sub_type'] ?? null,
            max(0, min(100, $confidence)), substr($matchedOn, 0, 20), $userId,
        ]);
    }

    public function clear(int $connectionId, int $crmAccountId): void
    {
        $this->db->prepare("DELETE FROM qbo_account_map WHERE connection_id = ? AND crm_account_id = ?")->execute([$connectionId, $crmAccountId]);
    }

    /**
     * Pure. $crm rows: id, code, name, type. $qbo rows (discovery shape): id, num, name, classification, type, sub_type, active.
     * @return array<int, array{best:?array, candidates:array}>
     */
    public static function suggest(array $crm, array $qbo): array
    {
        $out = [];
        $qboActive = array_values(array_filter($qbo, static fn($a) => !isset($a['active']) || $a['active']));
        foreach ($crm as $c) {
            $cands = [];
            $want = self::CLASS_OF[strtolower((string)($c['type'] ?? ''))] ?? null;
            $cName = self::norm((string)($c['name'] ?? ''));
            foreach ($qboActive as $a) {
                $score = 0; $on = null;
                if (!empty($a['num']) && !empty($c['code']) && ltrim((string)$a['num'], '0') === ltrim((string)$c['code'], '0')) {
                    $score = 100; $on = 'number';
                } else {
                    $aName = self::norm((string)($a['name'] ?? ''));
                    if ($cName !== '' && $aName !== '') {
                        $same = $want === null || ($a['classification'] ?? null) === $want;
                        if ($aName === $cName) {
                            $score = $same ? 90 : 40; $on = $same ? 'name' : 'name?';
                        } else {
                            similar_text($cName, $aName, $pct);
                            if ($pct >= 72) {
                                $score = $same ? (int)round(60 + ($pct - 72) * (25 / 28)) : (int)round($pct / 2.5);
                                $on = $same ? 'name' : 'name?';
                            }
                        }
                    }
                }
                if ($score > 0) {
                    $cands[] = ['qbo_id' => (string)$a['id'], 'name' => $a['name'], 'num' => $a['num'] ?? null, 'type' => $a['type'] ?? null,
                                'sub_type' => $a['sub_type'] ?? null, 'classification' => $a['classification'] ?? null, 'score' => $score, 'matched_on' => $on];
                }
            }
            usort($cands, static fn($x, $y) => $y['score'] <=> $x['score'] ?: strcmp((string)$x['name'], (string)$y['name']));
            $cands = array_slice($cands, 0, 4);
            $best = $cands[0] ?? null;
            if ($best !== null && $best['matched_on'] === 'name?') $best = null;
            $out[(int)$c['id']] = ['best' => $best, 'candidates' => $cands];
        }
        return $out;
    }

    public static function norm(string $s): string
    {
        $s = strtolower($s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
        $words = array_filter(explode(' ', $s), static fn($w) => $w !== '' && !in_array($w, self::NOISE, true));
        return implode(' ', $words);
    }
}
