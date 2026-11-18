<?php
/**
 * InvoiceLineItems — the editable line-item rows posted by invoices/create.php and
 * invoices/edit.php, and the service catalog their "Add from services" picker offers.
 *
 * Both pages post parallel arrays: li_title[], li_description[], li_quantity[],
 * li_unit_price[], li_sort_order[], li_visit_id[], li_service_date[]. This used to be
 * parsed inline in edit.php only; create.php took a single description + amount (or
 * showed job-plan lines read-only), so a manual invoice could not have line items.
 */
class InvoiceLineItems
{
    /**
     * Clean the posted rows and total them. Completely empty rows are dropped; a row
     * with no quantity counts as 1. PURE.
     *
     * @return array{items: array<int, array{title:?string, description:string, quantity:float, unit_price:float, line_total:float, sort_order:int, visit_id:?int, service_date:?string}>, subtotal: float}
     */
    public static function fromPost(array $post): array
    {
        $descriptions = (array)($post['li_description']  ?? []);
        $quantities   = (array)($post['li_quantity']     ?? []);
        $unitPrices   = (array)($post['li_unit_price']   ?? []);
        $sortOrders   = (array)($post['li_sort_order']   ?? []);
        $visitIds     = (array)($post['li_visit_id']     ?? []);
        $serviceDates = (array)($post['li_service_date'] ?? []);
        $titles       = (array)($post['li_title']        ?? []);

        $items = [];
        $subtotal = 0.0;
        $rowCount = max(count($descriptions), count($unitPrices), count($titles));
        for ($i = 0; $i < $rowCount; $i++) {
            $desc  = trim((string)($descriptions[$i] ?? ''));
            $title = trim((string)($titles[$i] ?? ''));
            $qty   = floatval($quantities[$i] ?? 0);
            $unit  = floatval($unitPrices[$i] ?? 0);
            if ($desc === '' && $title === '' && $qty === 0.0 && $unit === 0.0) continue;
            if ($qty <= 0) $qty = 1.0;
            $lineTotal = round($qty * $unit, 2);
            $subtotal += $lineTotal;
            $visitId     = intval($visitIds[$i] ?? 0);
            $serviceDate = trim((string)($serviceDates[$i] ?? ''));
            $items[] = [
                'title'        => $title !== '' ? $title : null,
                'description'  => $desc !== '' ? $desc : ($title !== '' ? $title : 'Services rendered'),
                'quantity'     => $qty,
                'unit_price'   => $unit,
                'line_total'   => $lineTotal,
                'sort_order'   => intval($sortOrders[$i] ?? $i),
                'visit_id'     => $visitId > 0 ? $visitId : null,
                'service_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate) ? $serviceDate : null,
            ];
        }

        return ['items' => $items, 'subtotal' => round($subtotal, 2)];
    }

    /** True when the form posted line-item rows at all (even if all were empty). PURE. */
    public static function posted(array $post): bool
    {
        return isset($post['li_description']) || isset($post['li_unit_price']);
    }

    /**
     * Active products for the picker — the same catalog the quote builder offers.
     * Returns [] if the products tables are missing.
     */
    public static function catalog(PDO $db): array
    {
        try {
            $stmt = $db->query("
                SELECT p.id, p.name, p.description, p.base_price,
                       c.name AS category_name,
                       u.abbreviation AS unit_abbreviation
                FROM products p
                LEFT JOIN product_categories c ON p.category_id = c.id
                LEFT JOIN unit_types u ON p.unit_type_id = u.id
                WHERE p.is_archived = 0
                ORDER BY p.display_order, p.name
            ");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log('InvoiceLineItems::catalog failed: ' . $e->getMessage());
            return [];
        }
    }
}
