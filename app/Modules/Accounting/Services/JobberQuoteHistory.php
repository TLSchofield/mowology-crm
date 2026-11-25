<?php
declare(strict_types=1);

/**
 * JobberQuoteHistory — Jobber's Quotes export kept as read-only history (jobber_quotes, migration 1392).
 *
 * What it is for: "what did we quote this client, and did they take it?" next to what was billed
 * (JobberPriceHistory). Never creates a CRM quote, job, invoice or ledger entry.
 *
 * Outcome, from Jobber's status + dates (an archived quote is NOT proof of a "no": it may have been
 * replaced by a newer quote, so it is labelled "archived", never "lost"):
 *   accepted  — Converted or Approved
 *   archived  — Archived without ever being approved
 *   open      — Awaiting response / Changes requested (still waiting, or never answered)
 *   draft     — never sent
 *
 * No namespace — loaded via bootstrap.php require_once like the other Accounting services.
 */
class JobberQuoteHistory
{
    public function __construct(private PDO $db) {}

    /** @return 'accepted'|'archived'|'open'|'draft' */
    public static function outcome(string $status, ?string $approved = null, ?string $converted = null): string
    {
        if ($status === 'converted' || $status === 'approved' || $approved || $converted) return 'accepted';
        if ($status === 'archived') return 'archived';
        if ($status === 'draft') return 'draft';
        return 'open';
    }

    public static function normStatus(string $raw): string
    {
        $s = strtolower(trim($raw));
        if (str_starts_with($s, 'convert')) return 'converted';
        if (str_starts_with($s, 'approv')) return 'approved';
        if (str_starts_with($s, 'archiv')) return 'archived';
        if (str_starts_with($s, 'await')) return 'awaiting';
        if (str_starts_with($s, 'draft')) return 'draft';
        if (str_starts_with($s, 'changes')) return 'changes';
        return 'unknown';
    }

    /**
     * Parse a Jobber Quotes export. Pure.
     * @return array{rows:list<array>, error:?string}
     */
    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $head = fgetcsv($h, 0, ',', '"', '');
        if (!$head) return ['rows' => [], 'error' => 'The file is empty.'];
        $idx = [];
        foreach ($head as $i => $name) $idx[strtolower(trim((string)$name))] = $i;
        foreach (['quote #', 'client name', 'status', 'total ($)'] as $need) {
            if (!isset($idx[$need])) return ['rows' => [], 'error' => 'That is not a Jobber Quotes export (no "' . $need . '" column).'];
        }
        $cell = fn(array $r, string $k) => isset($idx[$k], $r[$idx[$k]]) ? trim((string)$r[$idx[$k]]) : '';
        $nul = fn(string $v) => ($v === '' || $v === '-') ? null : $v;
        $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $num = ltrim($cell($r, 'quote #'), '#');
            if ($num === '') continue;
            $raw = $cell($r, 'status');
            $total = JobberCsv::money($cell($r, 'total ($)')) ?? 0.0;
            $rows[] = [
                'quote_number'   => mb_substr($num, 0, 40),
                'client_name'    => $nul(mb_substr($cell($r, 'client name'), 0, 200)),
                'client_email'   => $nul(mb_substr(strtolower($cell($r, 'client email')), 0, 255)),
                'client_phone'   => $nul(mb_substr($cell($r, 'client phone'), 0, 50)),
                'service_street' => $nul(mb_substr($cell($r, 'service street'), 0, 255)),
                'title'          => $nul(mb_substr($cell($r, 'title'), 0, 255)),
                'status'         => self::normStatus($raw),
                'status_raw'     => $nul(mb_substr($raw, 0, 60)),
                'subtotal'       => JobberCsv::money($cell($r, 'subtotal ($)')) ?? $total,
                'total'          => $total,
                'drafted_date'   => JobberCsv::date($cell($r, 'drafted date')),
                'sent_date'      => JobberCsv::date($cell($r, 'sent date')),
                'approved_date'  => JobberCsv::date($cell($r, 'approved date')),
                'converted_date' => JobberCsv::date($cell($r, 'converted date')),
                'archived_date'  => JobberCsv::date($cell($r, 'archived date')),
                'line_items'     => $nul($cell($r, 'line items')),
            ];
        }
        fclose($h);
        return ['rows' => $rows, 'error' => null];
    }

    public function ready(): bool
    {
        try { $this->db->query("SELECT 1 FROM jobber_quotes LIMIT 1"); return true; } catch (Throwable $e) { return false; }
    }

    /**
     * Import (insert new, update existing by quote_number). Links each quote to the CRM client the same way
     * the invoice import does. Writes only jobber_quotes.
     * @param array|null $ix JobberImportService::buildIndex() result (null = no linking)
     * @return array{rows:int, inserted:int, updated:int, linked:int}
     */
    public function import(string $csv, ?array $ix = null): array
    {
        $p = self::parse($csv);
        if ($p['error'] !== null) return ['rows' => 0, 'inserted' => 0, 'updated' => 0, 'linked' => 0, 'error' => $p['error']];
        $have = [];
        foreach ($this->db->query("SELECT quote_number FROM jobber_quotes")->fetchAll(PDO::FETCH_COLUMN) as $n) $have[(string)$n] = true;
        $cols = ['contact_id', 'company_id', 'property_id', 'match_how', 'client_name', 'client_email', 'service_street', 'title', 'status', 'status_raw',
                 'subtotal', 'total', 'drafted_date', 'sent_date', 'approved_date', 'converted_date', 'archived_date', 'line_items', 'imported_at'];
        $ins = $this->db->prepare("INSERT INTO jobber_quotes (quote_number, " . implode(', ', $cols) . ") VALUES (?" . str_repeat(', ?', count($cols)) . ")");
        $upd = $this->db->prepare("UPDATE jobber_quotes SET " . implode(' = ?, ', $cols) . " = ? WHERE quote_number = ?");
        $out = ['rows' => 0, 'inserted' => 0, 'updated' => 0, 'linked' => 0];
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            foreach ($p['rows'] as $r) {
                $m = $ix ? JobberImportService::matchClient($ix, $r) : ['contact_id' => null, 'company_id' => null, 'property_id' => null, 'how' => 'none'];
                if ($m['contact_id'] || $m['company_id'] || $m['property_id']) $out['linked']++;
                $vals = [$m['contact_id'], $m['company_id'], $m['property_id'], $m['how'], $r['client_name'], $r['client_email'], $r['service_street'],
                         $r['title'], $r['status'], $r['status_raw'], $r['subtotal'], $r['total'], $r['drafted_date'], $r['sent_date'],
                         $r['approved_date'], $r['converted_date'], $r['archived_date'], $r['line_items'], $now];
                $out['rows']++;
                if (isset($have[$r['quote_number']])) { $upd->execute(array_merge($vals, [$r['quote_number']])); $out['updated']++; }
                else { $ins->execute(array_merge([$r['quote_number']], $vals)); $out['inserted']++; $have[$r['quote_number']] = true; }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $out;
    }

    /**
     * Quotes for a client, newest first, each with its outcome and parsed lines.
     * @param array{0:string,1:array} $scope from JobberPriceHistory::scopeFor*()
     */
    public function forScope(array $scope, int $limit = 60): array
    {
        try {
            $s = $this->db->prepare("SELECT quote_number, title, status, total, drafted_date, sent_date, approved_date, converted_date, archived_date, line_items
                                     FROM jobber_quotes WHERE ($scope[0]) ORDER BY COALESCE(sent_date, drafted_date) DESC LIMIT " . max(1, min(300, $limit)));
            $s->execute($scope[1]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['outcome'] = self::outcome((string)$r['status'], $r['approved_date'], $r['converted_date']);
                $r['date'] = $r['sent_date'] ?: $r['drafted_date'];
                $r['lines'] = JobberPriceHistory::parseLineItems($r['line_items']);
                $out[] = $r;
            }
            return $out;
        } catch (Throwable $e) {
            return [];   // table not migrated yet — the panel simply does not show quotes
        }
    }
}
