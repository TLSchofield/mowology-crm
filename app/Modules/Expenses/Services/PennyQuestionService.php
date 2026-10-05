<?php
/**
 * PennyQuestionService — questions Penny asks the owner, starting with unbilled materials.
 *
 * "Did you forget to raise an invoice for X, or is the product from Lawnboy included in
 * the contract?" (Tim, 2026-10-05). An approved Materials / Disposal / Subcontractor
 * receipt assigned to a job, 3–60 days old, with no invoice for that job or property
 * showing it, becomes a question. The answers teach her:
 *   invoice      → counts toward "found to bill"; opens a pre-filled invoice
 *   contract     → never asks again for this job (or contract) + vendor
 *   not_billable → never asks again for this job + vendor (shop stock, warranty, own use)
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PennyQuestionService
{
    public const BILLABLE_CATEGORIES = ['Materials', 'Disposal/Dump', 'Subcontractors'];
    public const ANSWERS = ['invoice', 'contract', 'not_billable'];
    /** Give the owner time to invoice before asking; stop asking about old receipts. */
    public const MIN_AGE_DAYS = 3;
    public const MAX_AGE_DAYS = 60;
    /** An invoice dated this close to the purchase can be the one that billed it. */
    public const INVOICE_BEFORE_DAYS = 7;
    public const INVOICE_AFTER_DAYS = 45;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'penny_questions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Create questions for newly-unbilled receipts. Cheap SQL — safe to run on card load. */
    public function scan(int $limit = 10): int
    {
        if (!$this->ready()) return 0;
        $in = implode(',', array_fill(0, count(self::BILLABLE_CATEGORIES), '?'));
        $stmt = $this->db->prepare("
            SELECT e.id, e.job_id, e.property_id, e.vendor_id, e.expense_date, e.amount, e.total, e.accounting_category,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor_name,
                   jp.property_id AS plan_property_id, jp.contract_id,
                   CONCAT(COALESCE(jp.title, jp.service_type, 'the job'), ' — ', COALESCE(p.address, '')) AS job
            FROM expenses e
            JOIN job_plans jp ON jp.id = e.job_id
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.status IN ('approved', 'forwarded')
              AND e.accounting_category IN ({$in})
              AND e.expense_date BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM penny_questions q WHERE q.kind = 'unbilled_materials' AND q.expense_id = e.id)
            ORDER BY e.expense_date DESC
            LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute(array_merge(self::BILLABLE_CATEGORIES, [
            date('Y-m-d', strtotime('-' . self::MAX_AGE_DAYS . ' days')),
            date('Y-m-d', strtotime('-' . self::MIN_AGE_DAYS . ' days')),
        ]));

        $made = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $propertyId = $e['property_id'] ?: $e['plan_property_id'];
            if ($this->alreadySettled((int)$e['job_id'], $e['contract_id'] ? (int)$e['contract_id'] : null, $e['vendor_id'] ? (int)$e['vendor_id'] : null, (string)$e['vendor_name'])) {
                continue;
            }
            $items = $this->itemNames((int)$e['id']);
            if ($this->looksBilled((int)$e['job_id'], $propertyId ? (int)$propertyId : null, (string)$e['expense_date'], $items, (string)$e['accounting_category'])) {
                continue;
            }
            $this->db->prepare("
                INSERT IGNORE INTO penny_questions
                    (kind, expense_id, plan_id, property_id, contract_id, vendor_id, vendor_name, category, amount, question)
                VALUES ('unbilled_materials', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                (int)$e['id'], (int)$e['job_id'], $propertyId ?: null, $e['contract_id'] ?: null,
                $e['vendor_id'] ?: null, $e['vendor_name'], $e['accounting_category'], (float)($e['amount'] ?: $e['total']),
                self::wording((float)($e['amount'] ?: $e['total']), $items, (string)$e['vendor_name'], (string)$e['job'], (string)$e['expense_date'], (bool)$e['contract_id']),
            ]);
            $made++;
        }
        return $made;
    }

    /** The owner already said this job/contract + vendor is covered or not billable. */
    private function alreadySettled(int $planId, ?int $contractId, ?int $vendorId, string $vendorName): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM penny_questions
            WHERE status = 'answered' AND answer IN ('contract', 'not_billable')
              AND (plan_id = ? OR (? IS NOT NULL AND contract_id = ?))
              AND (vendor_id = ? OR (? IS NULL AND vendor_name = ?))
            LIMIT 1
        ");
        $stmt->execute([$planId, $contractId, $contractId, $vendorId, $vendorId, $vendorName]);
        return (bool)$stmt->fetchColumn();
    }

    private function itemNames(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT name FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
        $s->execute([$expenseId]);
        return array_values(array_filter(array_map('trim', $s->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** An invoice for this job or property, near the purchase date, has a line that mentions it. */
    private function looksBilled(int $planId, ?int $propertyId, string $date, array $items, string $category): bool
    {
        $s = $this->db->prepare("
            SELECT li.description
            FROM invoices i JOIN invoice_line_items li ON li.invoice_id = i.id
            WHERE (i.plan_id = ? OR (? IS NOT NULL AND i.property_id = ?))
              AND COALESCE(i.invoice_date, i.issue_date) BETWEEN ? AND ?
              AND COALESCE(i.status, '') NOT IN ('void', 'cancelled')
        ");
        $s->execute([
            $planId, $propertyId, $propertyId,
            date('Y-m-d', strtotime($date . ' -' . self::INVOICE_BEFORE_DAYS . ' days')),
            date('Y-m-d', strtotime($date . ' +' . self::INVOICE_AFTER_DAYS . ' days')),
        ]);
        return self::lineMentions($s->fetchAll(PDO::FETCH_COLUMN), $items, $category);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure helpers (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Words worth matching on an invoice line: 4+ letters, not packaging/noise. */
    public static function keywords(array $items, string $category): array
    {
        static $noise = ['each', 'bags', 'pack', 'with', 'from', 'item', 'total', 'regular', 'large', 'small', 'black', 'brown', 'green'];
        $words = [];
        foreach ($items as $name) {
            foreach (preg_split('/[^a-z]+/', strtolower($name)) as $w) {
                if (strlen($w) >= 4 && !in_array($w, $noise, true)) $words[$w] = true;
            }
        }
        $generic = ['Materials' => ['material', 'materials', 'supplies'], 'Disposal/Dump' => ['dump', 'disposal', 'haul', 'green waste'], 'Subcontractors' => ['subcontract']];
        foreach ($generic[$category] ?? [] as $w) $words[$w] = true;
        return array_keys($words);
    }

    /** Does any invoice line mention the purchase? */
    public static function lineMentions(array $lineDescriptions, array $items, string $category): bool
    {
        $kw = self::keywords($items, $category);
        foreach ($lineDescriptions as $d) {
            $d = strtolower((string)$d);
            foreach ($kw as $w) {
                if (str_contains($d, $w)) return true;
            }
        }
        return false;
    }

    public static function wording(float $amount, array $items, string $vendor, string $job, string $date, bool $hasContract): string
    {
        $what = $items ? implode(', ', array_slice($items, 0, 2)) . (count($items) > 2 ? ' and more' : '') : 'materials';
        $vendor = $vendor !== '' ? $vendor : 'a supplier';
        return sprintf('You bought $%s of %s from %s for %s on %s. I can\'t find it on an invoice. Did you forget to invoice it%s?',
            number_format($amount, 2), $what, $vendor, $job, date('M j', strtotime($date)),
            $hasContract ? ', or is it included in their contract' : ', or isn\'t it billable');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Card
    // ─────────────────────────────────────────────────────────────────────────

    public function open(int $limit = 5): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->query("SELECT id, expense_id, plan_id, contract_id, amount, question FROM penny_questions
                               WHERE status = 'open' ORDER BY amount DESC, id LIMIT " . max(1, min(20, $limit)));
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok: bool, message: string, invoice_url?: string} */
    public function answer(int $id, string $answer, int $userId): array
    {
        if (!in_array($answer, self::ANSWERS, true)) {
            return ['ok' => false, 'message' => 'Unknown answer'];
        }
        $s = $this->db->prepare("SELECT plan_id, status FROM penny_questions WHERE id = ?");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q || $q['status'] !== 'open') {
            return ['ok' => false, 'message' => 'Already answered'];
        }
        $this->db->prepare("UPDATE penny_questions SET status = 'answered', answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ?")
           ->execute([$answer, $userId, $id]);
        $msg = ['invoice' => 'Noted — opening a new invoice for that job', 'contract' => "Got it — I won't ask about this supplier on this job again", 'not_billable' => "Got it — not billable; I won't ask about this supplier on this job again"][$answer];
        $out = ['ok' => true, 'message' => $msg];
        if ($answer === 'invoice' && $q['plan_id']) {
            $out['invoice_url'] = '/crm/invoices/create.php?plan_id=' . (int)$q['plan_id'];
        }
        return $out;
    }

    /** Owner said "forgot to invoice" this month — Penny's "found to bill". */
    public function foundToBillMonth(): float
    {
        if (!$this->ready()) return 0.0;
        $s = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM penny_questions WHERE answer = 'invoice' AND answered_at >= ?");
        $s->execute([date('Y-m-01')]);
        return round((float)$s->fetchColumn(), 2);
    }
}
