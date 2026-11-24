<?php
/**
 * InvoiceFromPhoneService — what Add → Invoice shows (2026-11-08): finished visits really waiting on an invoice
 * (UnbilledVisitService's own definition, so contracts and pre-sold work never appear) and the draft invoices ready to
 * send. Creating and sending stay in InvoiceFromVisitService.
 */
declare(strict_types=1);

class InvoiceFromPhoneService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Newest first; only the 'unbilled' bucket. Pure.
     * @return array<int, array{visit_id:int, plan_id:int, date:string, title:string, amount:float}>
     */
    public static function waiting(array $visits, string $today, int $limit = 100): array
    {
        $out = [];
        foreach ($visits as $v) {
            if (UnbilledVisitService::classify($v, $today) !== 'unbilled') continue;
            $out[] = ['visit_id' => (int)$v['id'], 'plan_id' => (int)$v['plan_id'], 'date' => (string)$v['scheduled_date'],
                      'title' => (string)($v['plan_title'] ?? 'Visit'), 'amount' => UnbilledVisitService::value($v)];
        }
        usort($out, static function ($a, $b) { return strcmp($b['date'], $a['date']) ?: $b['visit_id'] <=> $a['visit_id']; });
        return array_slice($out, 0, $limit);
    }

    /** @return array<int, array{visit_id:int, date:string, title:string, amount:float, client:string, address:string}> */
    public function unbilled(): array
    {
        $rows = self::waiting((new UnbilledVisitService($this->db))->visits(), date('Y-m-d'));
        if (!$rows) return [];
        $plans = implode(',', array_unique(array_map(static function ($r) { return (int)$r['plan_id']; }, $rows)));   // ints only
        $where = [];
        foreach ($this->db->query("
            SELECT jp.id, p.address, p.city, c.first_name, c.last_name
            FROM job_plans jp LEFT JOIN properties p ON p.id = jp.property_id LEFT JOIN contacts c ON c.id = p.site_contact_id
            WHERE jp.id IN ({$plans})
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $where[(int)$r['id']] = [
                'client'  => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'address' => trim(($r['address'] ?? '') . (!empty($r['city']) ? ', ' . $r['city'] : '')),
            ];
        }
        return array_map(static function ($r) use ($where) {
            $w = $where[$r['plan_id']] ?? ['client' => '', 'address' => ''];
            unset($r['plan_id']);
            return $r + $w;
        }, $rows);
    }

    /**
     * Only drafts this person made from a visit: contract auto-drafts and invoices someone is holding on purpose must
     * not be one tap from going out.
     * @return array<int, array{invoice_id:int, number:string, client:string, total:float}>
     */
    public function drafts(int $userId): array
    {
        $s = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.total_amount, TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) AS client
            FROM invoices i LEFT JOIN contacts c ON c.id = i.contact_id
            WHERE i.status = 'draft' AND i.visit_id IS NOT NULL AND i.created_by = ?
            ORDER BY i.created_at DESC
            LIMIT 50
        ");
        $s->execute([$userId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function ($r) {
            return ['invoice_id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'client' => (string)$r['client'], 'total' => (float)$r['total_amount']];
        }, $rows);
    }
}
