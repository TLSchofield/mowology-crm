<?php
/**
 * MiaSeasonalHistory — finds real, priced fall-cleanup work near today's date.
 *
 * Jobber's line-item export is parsed here rather than with SQL string matching. CRM work is
 * checked against the same conservative name rules. Missing import/CRM tables mean no history,
 * never a broken Mia run. No namespace / no autoloader.
 */
class MiaSeasonalHistory
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /** @return array<int,array{name:string,qty:float,amount:float}> */
    public static function parseJobberLines(string $lineItems): array
    {
        $out = [];
        if (preg_match_all('/(?:^|,\s*)(.+?)\s*\(\s*([0-9.,]+)\s*,\s*\$\s*([0-9,]+(?:\.\d+)?)\s*\)(?=\s*,|$)/u', trim($lineItems), $m, PREG_SET_ORDER)) {
            foreach ($m as $line) {
                $out[] = ['name' => trim($line[1]), 'qty' => (float)str_replace(',', '', $line[2]), 'amount' => (float)str_replace(',', '', $line[3])];
            }
        }
        return $out;
    }

    public static function isFallCleanupName(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        if (preg_match('/\bspring\b/u', $n)) return false;
        return (bool)(preg_match('/\b(?:fall|autumn|seasonal)\b.*\bclean[\s-]?up\b/u', $n)
            || preg_match('/\b(?:leaf|leaves)\b.*\bclean[\s-]?up\b/u', $n)
            || preg_match('/\bleaf\s+fall\s+program\b/u', $n));
    }

    public static function inWindow(string $date, DateTimeImmutable $today, int $before = 14, int $after = 30, int $years = 3): bool
    {
        $d = date_create_immutable(substr($date, 0, 10));
        if (!$d || $d >= $today) return false;
        for ($i = 1; $i <= $years; $i++) {
            $anchor = $today->modify("-{$i} years");
            if ($d->format('Y') !== $anchor->format('Y')) continue;
            $start = $anchor->modify("-{$before} days")->format('m-d');
            $end = $anchor->modify("+{$after} days")->format('m-d');
            $candidate = $d->format('m-d');
            if ($start <= $end ? ($candidate >= $start && $candidate <= $end) : ($candidate >= $start || $candidate <= $end)) return true;
        }
        return false;
    }

    public static function askSeason(DateTimeImmutable $today): bool
    {
        return $today->format('m-d') >= '10-15' && $today->format('m-d') <= '12-15';
    }

    /** @return array{year:string,month:string} */
    public static function whenPhrase(string $date, DateTimeImmutable $today): array
    {
        $d = new DateTimeImmutable(substr($date, 0, 10));
        $ago = (int)$today->format('Y') - (int)$d->format('Y');
        $year = $ago === 1 ? 'Last year' : ($ago === 2 ? 'Two years ago' : 'In ' . $d->format('Y'));
        $day = (int)$d->format('j');
        $part = $day <= 10 ? 'at the start of ' : ($day <= 20 ? 'in mid-' : 'at the end of ');
        return ['year' => $year, 'month' => $part . $d->format('F')];
    }

    public function lastFallCleanup(int $contactId, ?int $propertyId, DateTimeImmutable $today): ?array
    {
        $found = [];
        try {
            $s = $this->db->prepare("SELECT id, jobber_number, issued_date, line_items FROM jobber_invoices WHERE (contact_id = ? OR (? IS NOT NULL AND property_id = ?)) AND MONTH(issued_date) BETWEEN 10 AND 12 ORDER BY issued_date DESC");
            $s->execute([$contactId, $propertyId, $propertyId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!self::inWindow((string)$r['issued_date'], $today)) continue;
                foreach (self::parseJobberLines((string)$r['line_items']) as $line) {
                    if ($line['amount'] > 0 && self::isFallCleanupName($line['name'])) {
                        $found[] = ['date' => substr($r['issued_date'], 0, 10), 'source' => 'jobber', 'ref' => (string)($r['jobber_number'] ?: $r['id']), 'amount' => $line['amount']];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {}
        try {
            $s = $this->db->prepare("SELECT jv.id, COALESCE(jv.completed_at,jv.scheduled_date) work_date, jp.service_type, jv.actual_amount FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id WHERE jv.status = 'completed' AND (p.site_contact_id = ? OR (? IS NOT NULL AND p.id = ?)) AND MONTH(COALESCE(jv.completed_at,jv.scheduled_date)) BETWEEN 10 AND 12 ORDER BY COALESCE(jv.completed_at,jv.scheduled_date) DESC");
            $s->execute([$contactId, $propertyId, $propertyId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((self::isFallCleanupName((string)$r['service_type']) || preg_match('/clean[\s-]?up/i', (string)$r['service_type'])) && self::inWindow((string)$r['work_date'], $today))
                    $found[] = ['date' => substr($r['work_date'], 0, 10), 'source' => 'crm', 'ref' => (string)$r['id'], 'amount' => (float)$r['actual_amount']];
            }
        } catch (Throwable $e) {}
        usort($found, fn($a, $b) => strcmp($b['date'], $a['date']));
        return $found[0] ?? null;
    }

    public function alreadyThisSeason(int $contactId, ?int $propertyId, DateTimeImmutable $today): bool
    {
        $season = $today->format('Y') . '-09-01';
        try {
            $s = $this->db->prepare("SELECT jp.service_type FROM job_visits jv JOIN job_plans jp ON jp.id=jv.plan_id JOIN properties p ON p.id=jp.property_id WHERE jv.status IN ('scheduled','in_progress','completed') AND jv.scheduled_date >= ? AND (p.site_contact_id=? OR (? IS NOT NULL AND p.id=?))");
            $s->execute([$season, $contactId, $propertyId, $propertyId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $name) if (self::isFallCleanupName((string)$name) || preg_match('/clean[\s-]?up/i', (string)$name)) return true;
        } catch (Throwable $e) {}
        foreach ([['invoice_items','invoice_date'],['invoice_line_items','issue_date']] as $shape) {
            try {
                $s = $this->db->prepare("SELECT ii.description FROM {$shape[0]} ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.{$shape[1]} >= ? AND (i.contact_id=? OR (? IS NOT NULL AND i.property_id=?))");
                $s->execute([$season, $contactId, $propertyId, $propertyId]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $name) if (self::isFallCleanupName((string)$name)) return true;
                break;
            } catch (Throwable $e) {}
        }
        return false;
    }
}
