<?php
declare(strict_types=1);

/**
 * QboJournalExport — the journal as a QuickBooks Online (Canada) journal-entry import CSV
 * (Accountant's Ledger step 2, 2026-10-13). The accountant keeps their own QBO file; this lets them
 * bring our books in without typing.
 *
 * Columns (QBO "Import journal entries"): Journal No., Journal Date, Account Name, Description,
 * Debits, Credits, Name.
 *   - Account Name: our account's name; a sub-account is written "Parent:Sub" (chart_of_accounts.parent_id).
 *     The accountant maps names that differ from their QBO chart once, in QBO's import screen.
 *   - Name: required by QBO on every Accounts Receivable / Accounts Payable line — the customer or
 *     supplier. Taken from the source document (invoice bill-to, Jobber client, receipt vendor, the
 *     line's contact or vendor); "Unassigned customer/supplier" when none can be found.
 *   - Debits / Credits: positive numbers, one of the two per line; every journal balances.
 *   - Journal Date: dd/mm/yyyy by default (QBO Canada); mm/dd/yyyy or yyyy-mm-dd on request.
 * Two shapes:
 *   detailed  one QBO journal per journal entry ("MW-<entry id>"), one line per journal line;
 *   summary   one journal per month ("MW-YYYY-MM", dated the month's last day), one line per account
 *             (per account AND name on A/R and A/P lines) with the month's net movement.
 *
 * Pure builders (tested in tests/Unit/Ledger/QboJournalExportTest.php) + lines() for the query.
 * No namespace / no autoloader in production: require_once and `new`.
 */
final class QboJournalExport
{
    public const COLUMNS = ['Journal No.', 'Journal Date', 'Account Name', 'Description', 'Debits', 'Credits', 'Name'];
    public const DATE_FORMATS = ['dmy' => 'd/m/Y', 'mdy' => 'm/d/Y', 'ymd' => 'Y-m-d'];
    public const NO_CUSTOMER = 'Unassigned customer';
    public const NO_SUPPLIER = 'Unassigned supplier';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Is an account A/R, A/P or neither? (asset receivable that isn't a tax refund → ar; liability payable → ap). Pure. */
    public static function partyKind(array $a): ?string
    {
        $sub = strtolower((string)($a['sub_type'] ?? ''));
        $name = strtolower((string)($a['name'] ?? ''));
        if (($a['type'] ?? '') === 'asset' && $sub === 'receivable' && strpos($name, 'tax') === false) return 'ar';
        if (($a['type'] ?? '') === 'liability' && $sub === 'payable') return 'ap';
        return null;
    }

    /** id => "Parent:Sub" full names. Pure. */
    public static function fullNames(array $accounts): array
    {
        $byId = [];
        foreach ($accounts as $a) $byId[(int)$a['id']] = $a;
        $out = [];
        foreach ($byId as $id => $a) {
            $parts = [trim(str_replace(':', ' ', (string)$a['name']))];
            $seen = [$id => true];
            $p = (int)($a['parent_id'] ?? 0);
            while ($p && isset($byId[$p]) && !isset($seen[$p]) && count($parts) < 5) {
                array_unshift($parts, trim(str_replace(':', ' ', (string)$byId[$p]['name'])));
                $seen[$p] = true;
                $p = (int)($byId[$p]['parent_id'] ?? 0);
            }
            $out[$id] = implode(':', $parts);
        }
        return $out;
    }

    private static function date(string $ymd, string $fmt): string
    {
        $f = self::DATE_FORMATS[$fmt] ?? self::DATE_FORMATS['dmy'];
        return (new DateTimeImmutable($ymd))->format($f);
    }

    private static function money(float $v): ?float
    {
        $v = round($v, 2);
        return abs($v) < 0.005 ? null : $v;
    }

    /**
     * Detailed: one QBO journal per entry. Pure.
     * @param array $lines rows: entry_id, entry_date, memo, account (full name), party (ar|ap|null), name, description, debit, credit
     */
    public static function detailed(array $lines, string $fmt = 'dmy'): array
    {
        $out = [];
        foreach ($lines as $l) {
            $d = round((float)$l['debit'], 2); $c = round((float)$l['credit'], 2);
            if (abs($d) < 0.005 && abs($c) < 0.005) continue;
            $desc = trim((string)($l['description'] ?? '')) !== '' ? (string)$l['description'] : (string)($l['memo'] ?? '');
            $out[] = ['MW-' . (int)$l['entry_id'], self::date((string)$l['entry_date'], $fmt), (string)$l['account'],
                      mb_substr($desc, 0, 4000), self::money($d), self::money($c), self::nameFor($l)];
        }
        return $out;
    }

    /** The Name for a line: the party name on A/R and A/P lines, blank elsewhere. Pure. */
    private static function nameFor(array $l): string
    {
        if (($l['party'] ?? null) === null) return '';
        $n = trim((string)($l['name'] ?? ''));
        return $n !== '' ? $n : (($l['party'] === 'ap') ? self::NO_SUPPLIER : self::NO_CUSTOMER);
    }

    /** Summary: one QBO journal per month, net per account (and per name on A/R / A/P). Pure. */
    public static function summary(array $lines, string $fmt = 'dmy'): array
    {
        $groups = [];   // ym => key => [account, name, net]
        foreach ($lines as $l) {
            $ym = substr((string)$l['entry_date'], 0, 7);
            $name = self::nameFor($l);
            $key = $l['account'] . "\x1F" . $name;
            if (!isset($groups[$ym][$key])) $groups[$ym][$key] = ['account' => (string)$l['account'], 'name' => $name, 'net' => 0.0];
            $groups[$ym][$key]['net'] += (float)$l['debit'] - (float)$l['credit'];
        }
        ksort($groups);
        $out = [];
        foreach ($groups as $ym => $g) {
            $last = date('Y-m-t', strtotime($ym . '-01'));
            uasort($g, static fn($a, $b) => [$a['account'], $a['name']] <=> [$b['account'], $b['name']]);
            foreach ($g as $r) {
                $net = round($r['net'], 2);
                if (abs($net) < 0.005) continue;
                $out[] = ['MW-' . $ym, self::date($last, $fmt), $r['account'], 'Monthly summary ' . $ym,
                          $net > 0 ? $net : null, $net < 0 ? -$net : null, $r['name']];
            }
        }
        return $out;
    }

    /** Pure: journal no. => [debits, credits] for a built file (each must balance). */
    public static function journalTotals(array $rows): array
    {
        $t = [];
        foreach ($rows as $r) {
            $t[$r[0]] = $t[$r[0]] ?? [0.0, 0.0];
            $t[$r[0]][0] = round($t[$r[0]][0] + (float)($r[4] ?? 0), 2);
            $t[$r[0]][1] = round($t[$r[0]][1] + (float)($r[5] ?? 0), 2);
        }
        return $t;
    }

    /** Every posted line in [from, to] ready for detailed() / summary(): account full name, party and name. */
    public function lines(string $from, string $to): array
    {
        $accounts = $this->db->query("SELECT id, code, name, type, sub_type, parent_id FROM chart_of_accounts")->fetchAll(PDO::FETCH_ASSOC);
        $full = self::fullNames($accounts);
        $party = [];
        foreach ($accounts as $a) $party[(int)$a['id']] = self::partyKind($a);
        $st = $this->db->prepare("SELECT je.id AS entry_id, je.entry_date, je.memo, je.source_type, je.source_id,
                                         jl.account_id, jl.description, jl.debit, jl.credit, jl.contact_id, jl.vendor_id
                                  FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                                  WHERE je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                                  ORDER BY je.entry_date, je.id, jl.id");
        $st->execute([$from, $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $need = [];
        foreach ($rows as $r) if ($party[(int)$r['account_id']] ?? null) $need[] = $r;
        $names = $this->names($need);
        foreach ($rows as &$r) {
            $aid = (int)$r['account_id'];
            $r['account'] = $full[$aid] ?? ('Account ' . $aid);
            $r['party'] = $party[$aid] ?? null;
            $r['name'] = $r['party'] ? ($names[$r['source_type'] . ':' . $r['source_id'] . ':' . $r['contact_id'] . ':' . $r['vendor_id']] ?? '') : '';
        }
        unset($r);
        return $rows;
    }

    /** The customer / supplier behind A/R and A/P lines, from their source documents (batched by type). */
    private function names(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) if ($r['source_id'] !== null) $ids[(string)$r['source_type']][(int)$r['source_id']] = true;
        $map = [];   // "type:id" => name
        $q = function (string $sql, array $idList): array {
            if (!$idList) return [];
            $in = implode(',', array_map('intval', array_keys($idList)));
            try { return $this->db->query(str_replace('{IN}', $in, $sql))->fetchAll(PDO::FETCH_KEY_PAIR) ?: []; }
            catch (Throwable $e) { return []; }
        };
        foreach ($q("SELECT id, bill_to_name FROM invoices WHERE id IN ({IN})", ($ids['invoice'] ?? []) + ($ids['payment'] ?? [])) as $id => $n) {
            $map["invoice:$id"] = $n; $map["payment:$id"] = $n;
        }
        foreach ($q("SELECT a.id, i.bill_to_name FROM invoice_payment_allocations a JOIN invoices i ON i.id = a.invoice_id WHERE a.id IN ({IN})", $ids['payment_allocation'] ?? []) as $id => $n) $map["payment_allocation:$id"] = $n;
        foreach ($q("SELECT s.id, i.bill_to_name FROM stripe_payments s JOIN invoices i ON i.id = s.invoice_id WHERE s.id IN ({IN})", $ids['stripe_payment'] ?? []) as $id => $n) $map["stripe_payment:$id"] = $n;
        foreach ($q("SELECT id, client_name FROM jobber_invoices WHERE id IN ({IN})", ($ids['jobber_invoice'] ?? []) + ($ids['jobber_carryover'] ?? [])) as $id => $n) {
            $map["jobber_invoice:$id"] = $n; $map["jobber_carryover:$id"] = $n;
        }
        foreach ($q("SELECT c.id, i.bill_to_name FROM client_credits c JOIN invoices i ON i.id = c.invoice_id WHERE c.id IN ({IN})", $ids['client_credit'] ?? []) as $id => $n) $map["client_credit:$id"] = $n;
        foreach ($q("SELECT e.id, COALESCE(v.name, e.vendor_name_raw) FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id IN ({IN})", $ids['expense'] ?? []) as $id => $n) $map["expense:$id"] = $n;

        $vendorIds = []; $contactIds = [];
        foreach ($rows as $r) { if ($r['vendor_id']) $vendorIds[(int)$r['vendor_id']] = true; if ($r['contact_id']) $contactIds[(int)$r['contact_id']] = true; }
        $vendors = $q("SELECT id, name FROM vendors WHERE id IN ({IN})", $vendorIds);
        $contacts = $q("SELECT id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM contacts WHERE id IN ({IN})", $contactIds);

        $out = [];
        foreach ($rows as $r) {
            $k = $r['source_type'] . ':' . $r['source_id'] . ':' . $r['contact_id'] . ':' . $r['vendor_id'];
            $n = $map[$r['source_type'] . ':' . (int)$r['source_id']] ?? null;
            if (($n === null || trim((string)$n) === '') && $r['vendor_id']) $n = $vendors[(int)$r['vendor_id']] ?? null;
            if (($n === null || trim((string)$n) === '') && $r['contact_id']) $n = $contacts[(int)$r['contact_id']] ?? null;
            $out[$k] = trim((string)$n);
        }
        return $out;
    }
}
