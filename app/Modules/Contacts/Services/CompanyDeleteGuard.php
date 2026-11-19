<?php
/**
 * CompanyDeleteGuard — decides whether companies may be hard-deleted (Fable review 2026-10-12).
 *
 * Deleting a company CASCADEs to its invoices (FK invoices.company_id ON DELETE CASCADE) and
 * strands its job plans and quotes. A company with any quote, job plan or invoice is archived,
 * never deleted. Every delete path (clients list bulk delete, companies list bulk delete,
 * companies/api.php delete) asks this one place first.
 */
class CompanyDeleteGuard
{
    /** table => label, each with a company_id column */
    private const RELATED = [
        'quotes'    => 'quote(s)',
        'job_plans' => 'job plan(s)',
        'invoices'  => 'invoice(s)',
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Counts of related records per table for the given companies; only non-zero entries.
     * A count that cannot be read (missing table) blocks the delete — never fail open.
     *
     * @param int[] $companyIds
     * @return array<string,int> e.g. ['invoices' => 3]
     */
    public function blockers(array $companyIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $companyIds), function ($id) { return $id > 0; }));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (array_keys(self::RELATED) as $table) {
            try {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE company_id IN ({$ph})");
                $stmt->execute($ids);
                $n = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                $n = 1;
            }
            if ($n > 0) $out[$table] = $n;
        }
        return $out;
    }

    /**
     * Ids from the list that have no related quotes, job plans or invoices.
     *
     * @param int[] $companyIds
     * @return int[]
     */
    public function deletable(array $companyIds): array
    {
        $ok = [];
        foreach ($companyIds as $id) {
            $id = (int)$id;
            if ($id > 0 && !$this->blockers([$id])) $ok[] = $id;
        }
        return $ok;
    }

    /** "3 invoice(s), 1 job plan(s)" */
    public function describe(array $blockers): string
    {
        $parts = [];
        foreach ($blockers as $table => $n) {
            $parts[] = $n . ' ' . (self::RELATED[$table] ?? $table);
        }
        return implode(', ', $parts);
    }
}
