<?php
declare(strict_types=1);

/**
 * JobberPriceHistory — "what did we bill this client for X, this year and last?"
 *
 * Reads the Jobber invoice history kept in jobber_invoices (migration 1239) and breaks each invoice's
 * `line_items` text ("Hedges Trim (4, $361.25), Green Waste Tarp (1, $35.00)") into per-service price
 * history. READ ONLY: nothing here writes to the ledger or to `invoices`.
 *
 * Jobber's "(qty, $amount)": the amount is the LINE TOTAL, not the unit price (checked against the
 * invoice subtotals — "Hedges Trim (4, $361.25)" is $90.31 a unit). Quantity can be 0 (a flat charge),
 * so a unit price exists only when qty >= 1.
 *
 * Draft, void and bad-debt invoices are left out of the figures: they are not proof the client
 * accepted the price.
 *
 * No namespace — loaded via bootstrap.php require_once like the other Accounting services.
 */
class JobberPriceHistory
{
    /** Statuses that count as "billed at this price". */
    private const COUNTED = ['paid', 'open', 'past_due'];

    public function __construct(private PDO $db) {}

    /**
     * Break a Jobber line-items cell into lines. Pure.
     * @return list<array{name:string, qty:float, total:float}>
     */
    public static function parseLineItems(?string $s): array
    {
        $s = trim((string)$s);
        if ($s === '') return [];
        $out = [];
        // Name may itself contain commas/parentheses, so anchor on the "(qty, $amount)" tail.
        if (!preg_match_all('/(.+?) \((-?[\d.]+), (-?)\$([\d,]+(?:\.\d+)?)\)(?:, |$)/u', $s, $m, PREG_SET_ORDER)) return [];
        foreach ($m as $x) {
            $total = (float)str_replace(',', '', $x[4]);
            if ($x[3] === '-') $total = -$total;
            $out[] = ['name' => trim($x[1]), 'qty' => (float)$x[2], 'total' => round($total, 2)];
        }
        return $out;
    }

    /**
     * Group invoice rows into per-service history. Pure.
     * @param list<array> $rows jobber_invoices rows: jobber_number, issued_date, status, line_items
     * @return list<array{service:string, count:int, last:array, years:array<int,array>}> sorted by latest date, newest first
     */
    public static function summarize(array $rows): array
    {
        $svc = [];
        foreach ($rows as $r) {
            if (!in_array((string)($r['status'] ?? ''), self::COUNTED, true)) continue;
            $date = (string)($r['issued_date'] ?? '');
            if ($date === '') continue;
            $year = (int)substr($date, 0, 4);
            foreach (self::parseLineItems($r['line_items'] ?? null) as $l) {
                if ($l['total'] <= 0.0) continue;                 // $0 placeholders ("Areas", month headings)
                $key = mb_strtolower($l['name']);
                $unit = $l['qty'] >= 1 ? round($l['total'] / $l['qty'], 2) : null;
                $svc[$key]['service'] = $svc[$key]['service'] ?? $l['name'];
                $svc[$key]['count'] = ($svc[$key]['count'] ?? 0) + 1;
                $entry = ['date' => $date, 'invoice' => (string)($r['jobber_number'] ?? ''), 'qty' => $l['qty'],
                          'total' => $l['total'], 'unit' => $unit];
                if (!isset($svc[$key]['last']) || strcmp($date, $svc[$key]['last']['date']) > 0) $svc[$key]['last'] = $entry;
                $y =& $svc[$key]['years'][$year];
                $y['n'] = ($y['n'] ?? 0) + 1;
                $y['sum'] = round(($y['sum'] ?? 0) + $l['total'], 2);
                if ($unit !== null) {
                    $y['unit_min'] = isset($y['unit_min']) ? min($y['unit_min'], $unit) : $unit;
                    $y['unit_max'] = isset($y['unit_max']) ? max($y['unit_max'], $unit) : $unit;
                }
                if (!isset($y['last_date']) || strcmp($date, $y['last_date']) > 0) { $y['last_date'] = $date; $y['last_total'] = $l['total']; }
                unset($y);
            }
        }
        foreach ($svc as &$s) krsort($s['years']);
        unset($s);
        $list = array_values($svc);
        usort($list, fn($a, $b) => strcmp($b['last']['date'], $a['last']['date']));
        return $list;
    }

    // ── Who a Jobber record belongs to ───────────────────────────────────────────
    // scope*() return [SQL condition, params] over the columns contact_id / company_id / property_id /
    // client_name, which jobber_invoices and jobber_quotes share — so quotes and invoices always agree.

    /** @return array{0:string,1:array} */
    public function scopeForProperty(int $propertyId): array
    {
        return ['property_id = ?', [$propertyId]];
    }

    /** A contact: records matched to them or to a property they are the site contact of. @return array{0:string,1:array} */
    public function scopeForContact(int $contactId): array
    {
        $where = 'contact_id = ?';
        try {
            $s = $this->db->prepare("SELECT id FROM properties WHERE site_contact_id = ?");
            $s->execute([$contactId]);
            $props = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            if ($props) $where .= ' OR property_id IN (' . implode(',', $props) . ')';
        } catch (Throwable $e) { /* properties unreadable — contact match only */ }
        return [$where, [$contactId]];
    }

    /**
     * A company: records matched to it, at its properties, or whose Jobber client name is the company's name
     * followed by " - ..." or " c/o ..." (Jobber kept "Beedie Development - Kanco 2130 Cambridge Apartments
     * Ltd." as one client; the importer's exact-name match misses it). @return array{0:string,1:array}
     */
    public function scopeForCompany(int $companyId): array
    {
        $where = 'company_id = ?';
        $params = [$companyId];
        try {
            $s = $this->db->prepare("SELECT property_id FROM company_properties WHERE company_id = ?");
            $s->execute([$companyId]);
            $props = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            if ($props) $where .= ' OR property_id IN (' . implode(',', $props) . ')';
        } catch (Throwable $e) { /* company_properties unreadable — company match only */ }
        try {
            $s = $this->db->prepare("SELECT company_name FROM companies WHERE id = ?");
            $s->execute([$companyId]);
            $name = trim((string)$s->fetchColumn());
            if (mb_strlen($name) >= 4) {
                $esc = addcslashes($name, '%_\\');
                $where .= " OR client_name = ? OR client_name LIKE ? OR client_name LIKE ?";
                array_push($params, $name, $esc . ' - %', $esc . ' c/o %');
            }
        } catch (Throwable $e) { /* no name match */ }
        return [$where, $params];
    }

    public function forProperty(int $propertyId): array { return self::summarize($this->rows(...$this->scopeForProperty($propertyId))); }
    public function forContact(int $contactId): array   { return self::summarize($this->rows(...$this->scopeForContact($contactId))); }
    public function forCompany(int $companyId): array   { return self::summarize($this->rows(...$this->scopeForCompany($companyId))); }

    /**
     * Fill in line items that the first Jobber import left empty. ONLY writes jobber_invoices.line_items,
     * and only where it is still empty — status, balance, tax and every other column (which the 2026
     * ledger reads) are never touched, so this is safe to run on a newer export than the original import.
     * @return array{rows:int, matched:int, filled:int, already_had:int, not_found:int, no_lines:int}
     */
    public function backfillLineItems(string $csv): array
    {
        $out = ['rows' => 0, 'matched' => 0, 'filled' => 0, 'already_had' => 0, 'not_found' => 0, 'no_lines' => 0];
        $p = JobberCsv::parse($csv);
        if ($p['kind'] !== 'invoices') return $out + ['error' => 'That is not a Jobber Invoices export.'];
        $map = JobberCsv::guessMapping('invoices', $p['headers'], []);
        if (JobberCsv::missing('invoices', $map) || !isset($map['line_items'])) {
            return $out + ['error' => 'The export has no "Line items" column.'];
        }
        $have = [];
        foreach ($this->db->query("SELECT jobber_number, line_items FROM jobber_invoices")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $have[(string)$r['jobber_number']] = trim((string)$r['line_items']) !== '';
        }
        $upd = $this->db->prepare("UPDATE jobber_invoices SET line_items = ? WHERE jobber_number = ? AND (line_items IS NULL OR line_items = '')");
        foreach ($p['rows'] as $raw) {
            $row = JobberCsv::invoiceRow($raw, $map);
            if ($row === null) continue;
            $out['rows']++;
            $n = (string)$row['jobber_number'];
            if (!isset($have[$n])) { $out['not_found']++; continue; }
            $out['matched']++;
            if ($have[$n]) { $out['already_had']++; continue; }
            if ($row['line_items'] === null) { $out['no_lines']++; continue; }
            $upd->execute([$row['line_items'], $n]);
            $out['filled']++;
        }
        return $out;
    }

    private function rows(string $where, array $params): array
    {
        try {
            $s = $this->db->prepare("SELECT jobber_number, issued_date, status, line_items FROM jobber_invoices
                                     WHERE ($where) AND line_items IS NOT NULL ORDER BY issued_date DESC LIMIT 3000");
            $s->execute($params);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // table not migrated yet — the panel simply does not show
        }
    }
}
