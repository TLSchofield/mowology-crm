<?php
declare(strict_types=1);

/**
 * SamInvoiceHistory — "we did this here before": what an earlier invoice at this property billed for the same kind of work,
 * offered as lines the owner can add to a new invoice with one click (invoices/create.php → Sam's card).
 *
 * Sources, both read-only:
 *   - invoices + invoice_line_items for the property (not draft / cancelled / void);
 *   - jobber_invoices for the property (the Jobber import keeps its lines as ONE text: "Half Day (1, $650.00), 1/2 Load Green Waste (1, $240.00)").
 *     Most work before the CRM went live (2020–2025) is ONLY here, so skipping it leaves Sam blind on the oldest clients.
 *
 * Which earlier job: the newest one whose lines match the kind of work (service type words, "clean up" ↔ "green waste" …),
 * with a nudge for the same season (a fall clean-up looks for last fall's). When nothing matches the work, nothing is suggested —
 * Sam does not offer an unrelated invoice just because it exists. Prices are the old prices, never uplifted: the card says how old
 * they are and the owner decides. GST is never in these figures (it is added on top at invoicing).
 *
 * A suggestion only: nothing is added, saved or sent here. No namespace / no autoloader in production: require_once and `new`.
 * SQL is MySQL 5.7-safe and also runs on SQLite (the unit tests); today is passed in.
 */
class SamInvoiceHistory
{
    public const MAX_EARLIER = 3;
    /** Prices this old (months) carry a "check they still hold" caveat. */
    public const STALE_MONTHS = 10;

    /** Words that make a line "the same kind of work", by the words in the visit's service type. */
    public const FAMILIES = [
        'clean'   => ['clean up', 'cleanup', 'clean-up', 'green waste', 'half day', 'full day', 'haul', 'debris', 'leaf', 'leaves', 'gutter'],
        'mow'     => ['mow', 'lawn', 'cut', 'trim', 'edge'],
        'prune'   => ['prun', 'hedge', 'shrub', 'tree'],
        'mulch'   => ['mulch', 'bark', 'soil', 'compost'],
        'weed'    => ['weed', 'bed care', 'maintenance'],
        'snow'    => ['snow', 'salt', 'ice'],
        'planting' => ['plant', 'bulb', 'flower', 'basket'],
    ];

    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * @param string $service the visit/plan service type or title ("Cleanup"); '' = any earlier invoice, newest first
     * @return array{has:bool, headline:string, primary:?array, earlier:list<array>, caveat:?string, season:string, searched:int}
     */
    public function forProperty(int $propertyId, string $service = ''): array
    {
        $none = ['has' => false, 'headline' => '', 'primary' => null, 'earlier' => [], 'caveat' => null, 'season' => $this->seasonOf($this->today), 'searched' => 0];
        if ($propertyId < 1) return $none;

        $jobs = array_merge($this->crmJobs($propertyId), $this->jobberJobs($propertyId));
        $none['searched'] = count($jobs);
        if (!$jobs) return $none;

        $words = $this->wordsFor($service);
        $season = $this->seasonOf($this->today);
        foreach ($jobs as &$j) {
            $j['match'] = $this->matches($j['lines'], $words);
            $j['same_season'] = $this->seasonOf($j['date']) === $season;
        }
        unset($j);
        // No filter by work → every job counts as a match; otherwise only the ones that match the kind of work.
        $pool = $words ? array_values(array_filter($jobs, static fn($j) => $j['match'])) : $jobs;
        if (!$pool) return $none;

        // Same season first, then newest. The 2020 fall job still beats nothing, but last spring does not beat last fall for a fall visit.
        usort($pool, static fn($a, $b) => ((int)$b['same_season'] <=> (int)$a['same_season']) ?: strcmp($b['date'], $a['date']));
        $primary = array_shift($pool);
        $earlier = [];
        usort($pool, static fn($a, $b) => strcmp($b['date'], $a['date']));
        foreach (array_slice($pool, 0, self::MAX_EARLIER) as $e) {
            $earlier[] = ['date' => $e['date'], 'ref' => $e['ref'], 'subtotal' => $e['subtotal'], 'summary' => $this->summary($e['lines']), 'source' => $e['source']];
        }

        // The form takes prices of 0 or more, so discounts / credits are not offered as lines; the subtotal shown is the sum of the lines offered.
        $primary['lines'] = array_values(array_filter($primary['lines'], static fn($l) => $l['unit_price'] > 0 && $l['quantity'] > 0));
        if (!$primary['lines']) return $none;
        $primary['subtotal'] = round(array_sum(array_column($primary['lines'], 'line_total')), 2);
        $months = $this->monthsBetween($primary['date'], $this->today);
        $primary['months_ago'] = $months;
        unset($primary['match'], $primary['same_season']);
        $label = $this->label($service, $primary['date']);
        $caveat = $months >= self::STALE_MONTHS
            ? 'These are the ' . date('Y', strtotime($primary['date'])) . ' prices, ' . ($months >= 24 ? 'over two years' : $months . ' months') . ' old. Check they still hold; GST is added on top.'
            : 'GST is added on top of these.';
        return ['has' => true, 'headline' => $label, 'primary' => $primary, 'earlier' => $earlier, 'caveat' => $caveat, 'season' => $season, 'searched' => count($jobs)];
    }

    /**
     * "Half Day (1, $650.00), 1/2 Load Green Waste (1, $240.00)" → lines. Descriptions may hold commas and brackets, so the
     * tail "(qty, $price)" is what delimits a line. Anything that does not parse is skipped, never guessed.
     * @return list<array{description:string, quantity:float, unit_price:float, line_total:float}>
     */
    public static function parseJobberLines(?string $text): array
    {
        $text = trim((string)$text);
        if ($text === '') return [];
        $out = [];
        // One piece per ") , " boundary; each piece must parse ALONE or it is skipped, so a malformed item never leaks into the next.
        foreach (preg_split('/(?<=\)),\s+/u', $text) ?: [] as $piece) {
            if (!preg_match('/^(.+?)\s*\((-?[\d.]+),\s*(-?)\$([\d,]+(?:\.\d{1,2})?)\)$/u', trim($piece), $row)) continue;
            $desc = trim($row[1]);
            $qty = (float)$row[2];
            $price = (float)str_replace(',', '', $row[4]) * ($row[3] === '-' ? -1 : 1);
            if ($desc === '' || $qty == 0.0) continue;
            $out[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => round($price, 2), 'line_total' => round($qty * $price, 2)];
        }
        return $out;
    }

    /** @return list<array> */
    private function crmJobs(int $propertyId): array
    {
        try {
            $s = $this->db->prepare("SELECT id, invoice_number, issue_date, subtotal FROM invoices
                WHERE property_id = ? AND status NOT IN ('draft', 'cancelled', 'void') AND issue_date IS NOT NULL
                ORDER BY issue_date DESC, id DESC LIMIT 40");
            $s->execute([$propertyId]);
            $inv = $s->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            $li = $this->db->prepare("SELECT description, quantity, unit_price, line_total FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order, id");
            foreach ($inv as $i) {
                $li->execute([(int)$i['id']]);
                $lines = [];
                foreach ($li->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $desc = trim((string)$l['description']);
                    if ($desc === '') continue;
                    $lines[] = ['description' => $desc, 'quantity' => (float)$l['quantity'], 'unit_price' => round((float)$l['unit_price'], 2), 'line_total' => round((float)$l['line_total'], 2)];
                }
                if ($lines) $out[] = ['source' => 'crm', 'ref' => (string)$i['invoice_number'], 'date' => substr((string)$i['issue_date'], 0, 10), 'subtotal' => round((float)$i['subtotal'], 2), 'lines' => $lines];
            }
            return $out;
        } catch (Throwable $e) {
            return [];   // table or column missing on this environment
        }
    }

    /** @return list<array> */
    private function jobberJobs(int $propertyId): array
    {
        try {
            $s = $this->db->prepare("SELECT jobber_number, issued_date, subtotal, line_items, status FROM jobber_invoices
                WHERE property_id = ? AND issued_date IS NOT NULL AND crm_invoice_id IS NULL ORDER BY issued_date DESC LIMIT 40");
            $s->execute([$propertyId]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $st = strtolower((string)($r['status'] ?? ''));
                foreach (['void', 'bad', 'cancel', 'draft'] as $no) if (strpos($st, $no) !== false) continue 2;
                $lines = self::parseJobberLines($r['line_items'] ?? '');
                if ($lines) $out[] = ['source' => 'jobber', 'ref' => 'Jobber #' . $r['jobber_number'], 'date' => substr((string)$r['issued_date'], 0, 10), 'subtotal' => round((float)$r['subtotal'], 2), 'lines' => $lines];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<string> lower-case words that mean "the same kind of work"; [] when no service is given */
    private function wordsFor(string $service): array
    {
        $s = strtolower(trim($service));
        if ($s === '') return [];
        $words = [];
        foreach (self::FAMILIES as $fam => $list) {
            // "Cleanup", "Fall Clean Up", "clean-up" all mean the clean family; the family's own name is the trigger.
            $norm = str_replace(['-', ' '], '', $s);
            if (strpos($norm, $fam) !== false || array_filter($list, static fn($w) => strpos($s, $w) !== false)) $words = array_merge($words, $list);
        }
        if (!$words) {
            foreach (preg_split('/[^a-z]+/', $s) ?: [] as $w) if (strlen($w) >= 4) $words[] = substr($w, 0, max(4, strlen($w) - 2));
        }
        return array_values(array_unique($words));
    }

    private function matches(array $lines, array $words): bool
    {
        if (!$words) return true;
        foreach ($lines as $l) {
            $d = strtolower(str_replace('-', ' ', $l['description']));
            foreach ($words as $w) if (strpos($d, str_replace('-', ' ', $w)) !== false) return true;
        }
        return false;
    }

    private function seasonOf(string $date): string
    {
        $m = (int)date('n', strtotime($date) ?: time());
        return $m >= 9 ? ($m === 12 ? 'winter' : 'fall') : ($m >= 6 ? 'summer' : ($m >= 3 ? 'spring' : 'winter'));
    }

    private function monthsBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        return max(0, ((int)$b->format('Y') - (int)$a->format('Y')) * 12 + ((int)$b->format('n') - (int)$a->format('n')));
    }

    private function label(string $service, string $date): string
    {
        $what = trim($service) !== '' ? strtolower(trim($service)) : 'work';
        if ($what === 'cleanup') $what = 'clean-up';
        return ucfirst($this->seasonOf($date)) . ' ' . $what . ' last done here ' . date('M Y', strtotime($date));
    }

    private function summary(array $lines): string
    {
        return implode(', ', array_map(static fn($l) => $l['description'] . ' $' . number_format($l['line_total'], 0), array_slice($lines, 0, 3)));
    }
}
