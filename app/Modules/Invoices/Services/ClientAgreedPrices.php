<?php
declare(strict_types=1);

/**
 * ClientAgreedPrices — what this client has already agreed to pay, for the invoice
 * "Add from services" picker (invoices/create.php → /crm/api/agreed-prices.php).
 *
 * Source: ACTIVE job_plans at the property (the service agreement; `contract_id` links a plan
 * to a CTR- contract). Price per plan:
 *   per_visit / custom  plan_line_items when the plan has them (one row per line), else price_per_visit
 *   monthly_flat        monthly_flat_price   (unit "month")
 *   seasonal            seasonal_price       (unit "season")
 * A plan is offered only while status = 'active' and today is inside plan_start_date..plan_end_date.
 *
 * Several plans for the same service (seasons, a second plan on the property) are NOT merged and
 * never averaged: each is its own row labelled with its plan number, title and season, so the
 * person picks. Rows are ordered by service name then plan number.
 *
 * Read-only. No namespace / no autoloader in production: require_once and `new`.
 * SQL is MySQL 5.7-safe and also runs on SQLite (the unit tests); today is passed in.
 */
class ClientAgreedPrices
{
    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * @return list<array{plan_id:int, plan_number:string, contract_id:?int, contract_number:?string,
     *                    name:string, description:string, unit_price:float, quantity:float,
     *                    unit_type:string, season:?string, label:string}>
     */
    public function forProperty(int $propertyId, ?int $companyId = null): array
    {
        if ($propertyId < 1) return [];

        $sql = "SELECT jp.id, jp.plan_number, jp.title, jp.service_type, jp.pricing_model, jp.price_per_visit,
                       jp.monthly_flat_price, jp.seasonal_price, jp.contract_id, jp.season_start, jp.season_end
                FROM job_plans jp
                WHERE jp.status = 'active' AND jp.property_id = ?
                  AND (jp.plan_start_date IS NULL OR jp.plan_start_date <= ?)
                  AND (jp.plan_end_date IS NULL OR jp.plan_end_date >= ?)";
        $args = [$propertyId, $this->today, $this->today];
        // The price is the PAYER's: a company invoice sees that company's plans, an individual
        // invoice (no company) sees only plans with no company. A shared address never leaks another
        // payer's negotiated price.
        if ($companyId) {
            $sql .= " AND jp.company_id = ?";
            $args[] = $companyId;
        } else {
            $sql .= " AND jp.company_id IS NULL";
        }
        $sql .= " ORDER BY jp.service_type, jp.plan_number";
        $st = $this->db->prepare($sql);
        $st->execute($args);
        $plans = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$plans) return [];

        $lines = $this->linesByPlan(array_map('intval', array_column($plans, 'id')));
        $contracts = $this->contractNumbers(array_map('intval', array_filter(array_column($plans, 'contract_id'))));

        $out = [];
        foreach ($plans as $p) {
            $planId = (int)$p['id'];
            $model = (string)($p['pricing_model'] ?? 'per_visit');
            $rows = [];
            if ($model === 'monthly_flat') {
                $rows[] = ['name' => (string)$p['service_type'], 'description' => '', 'unit_price' => (float)$p['monthly_flat_price'], 'quantity' => 1.0, 'unit_type' => 'month'];
            } elseif ($model === 'seasonal') {
                $rows[] = ['name' => (string)$p['service_type'], 'description' => '', 'unit_price' => (float)$p['seasonal_price'], 'quantity' => 1.0, 'unit_type' => 'season'];
            } elseif (!empty($lines[$planId])) {
                foreach ($lines[$planId] as $l) {
                    $rows[] = ['name' => $l['service_type'] !== '' ? $l['service_type'] : (string)$p['service_type'],
                               'description' => $l['description'], 'unit_price' => $l['unit_price'],
                               'quantity' => $l['quantity'], 'unit_type' => $l['unit_type']];
                }
            } else {
                $rows[] = ['name' => (string)$p['service_type'], 'description' => '', 'unit_price' => (float)$p['price_per_visit'], 'quantity' => 1.0, 'unit_type' => 'visit'];
            }

            $season = ($p['season_start'] && $p['season_end']) ? $p['season_start'] . ' to ' . $p['season_end'] : null;
            $contractId = $p['contract_id'] ? (int)$p['contract_id'] : null;
            foreach ($rows as $r) {
                if ($r['unit_price'] <= 0 || trim($r['name']) === '') continue;   // nothing agreed to prefill
                $out[] = [
                    'plan_id'         => $planId,
                    'plan_number'     => (string)$p['plan_number'],
                    'contract_id'     => $contractId,
                    'contract_number' => $contractId ? ($contracts[$contractId] ?? null) : null,
                    'name'            => trim($r['name']),
                    'description'     => (string)($r['description'] ?: ''),
                    'unit_price'      => round($r['unit_price'], 2),
                    'quantity'        => $r['quantity'] > 0 ? $r['quantity'] : 1.0,
                    'unit_type'       => $r['unit_type'],
                    'season'          => $season,
                    'label'           => self::label((string)$p['title'], (string)$p['plan_number'], $contractId ? ($contracts[$contractId] ?? null) : null, $season),
                ];
            }
        }
        return $out;
    }

    /** "BI-WEEKLY MOWING · PLN-2026-0081 · CTR-2026-0003 · 04-01 to 10-31" */
    public static function label(string $title, string $planNumber, ?string $contractNumber, ?string $season): string
    {
        return implode(' · ', array_filter([trim($title), $planNumber, $contractNumber, $season]));
    }

    /** @return array<int, list<array{service_type:string, description:string, quantity:float, unit_type:string, unit_price:float}>> */
    private function linesByPlan(array $planIds): array
    {
        $in = implode(',', array_fill(0, count($planIds), '?'));
        try {
            $st = $this->db->prepare("SELECT plan_id, service_type, description, quantity, unit_type, unit_price
                                      FROM plan_line_items WHERE plan_id IN ($in) ORDER BY plan_id, sort_order, id");
            $st->execute($planIds);
        } catch (Throwable $e) {
            return [];   // no plan_line_items table here
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['plan_id']][] = [
                'service_type' => (string)($r['service_type'] ?? ''),
                'description'  => (string)($r['description'] ?? ''),
                'quantity'     => (float)$r['quantity'],
                'unit_type'    => (string)($r['unit_type'] ?? 'visit'),
                'unit_price'   => (float)$r['unit_price'],
            ];
        }
        return $out;
    }

    /** @return array<int,string> contract id => CTR-… number */
    private function contractNumbers(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $this->db->prepare("SELECT id, contract_number FROM contracts WHERE id IN ($in) AND status = 'active'");
            $st->execute($ids);
            return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'contract_number', 'id');
        } catch (Throwable $e) {
            return [];
        }
    }
}
