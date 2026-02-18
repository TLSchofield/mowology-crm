<?php
/**
 * /app/Services/Receipts/PriceIntelligence.php
 * Cross-Receipt Price Trending & Anomaly Detection
 *
 * Tracks line item prices over time per vendor, detects price anomalies,
 * and provides cross-vendor price comparison.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/PriceIntelligence.php';
 *   recordLineItemPrices($expenseId, $vendorId, $lineItems, $expenseDate);
 *   $trend = getPriceTrend($vendorId, 'Black Mulch');
 *   $anomaly = checkPriceAnomaly($vendorId, 'Black Mulch', 7.99);
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Record price observations from an expense's line items.
 * Called on expense create/update when line items exist.
 *
 * @param int    $expenseId   Expense ID
 * @param int    $vendorId    Vendor ID
 * @param array  $lineItems   Array of line item arrays with name, unit_price, quantity
 * @param string $expenseDate Date string YYYY-MM-DD
 */
function recordLineItemPrices(int $expenseId, int $vendorId, array $lineItems, string $expenseDate): void
{
    $db = getDB();

    // Delete existing price records for this expense (in case of update)
    try {
        $stmt = $db->prepare("DELETE FROM expense_line_item_prices WHERE expense_id = ?");
        $stmt->execute([$expenseId]);
    } catch (\Throwable $e) {
        // Table may not exist yet — silently skip
        return;
    }

    foreach ($lineItems as $item) {
        $name = trim($item['name'] ?? '');
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $qty = (float)($item['quantity'] ?? 1);

        // Skip items without meaningful data
        if (empty($name) || $unitPrice <= 0) continue;

        // Normalize product name: uppercase, collapse whitespace
        $normalizedName = strtoupper(preg_replace('/\s+/', ' ', $name));

        try {
            $stmt = $db->prepare("
                INSERT INTO expense_line_item_prices
                    (vendor_id, product_name, unit_price, quantity, expense_date, expense_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$vendorId, $normalizedName, $unitPrice, $qty, $expenseDate, $expenseId]);
        } catch (\Throwable $e) {
            error_log('recordLineItemPrices: ' . $e->getMessage());
        }
    }
}


/**
 * Get price trend for a product at a specific vendor over 12 months.
 *
 * @param int    $vendorId    Vendor ID
 * @param string $productName Product name (case-insensitive)
 * @return array Array of [{date, unit_price, quantity}] ordered by date
 */
function getPriceTrend(int $vendorId, string $productName): array
{
    $db = getDB();
    $normalizedName = strtoupper(trim($productName));

    try {
        $stmt = $db->prepare("
            SELECT expense_date, unit_price, quantity
            FROM expense_line_item_prices
            WHERE vendor_id = ?
              AND product_name = ?
              AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            ORDER BY expense_date
        ");
        $stmt->execute([$vendorId, $normalizedName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('getPriceTrend: ' . $e->getMessage());
        return [];
    }
}


/**
 * Check if a line item's current price is anomalous vs historical average.
 *
 * @param int    $vendorId     Vendor ID
 * @param string $productName  Product name
 * @param float  $currentPrice Current unit price
 * @return array|null Anomaly info or null if normal
 */
function checkPriceAnomaly(int $vendorId, string $productName, float $currentPrice): ?array
{
    $db = getDB();
    $normalizedName = strtoupper(trim($productName));

    try {
        $stmt = $db->prepare("
            SELECT AVG(unit_price) as avg_price,
                   MIN(unit_price) as min_price,
                   MAX(unit_price) as max_price,
                   COUNT(*) as sample_count
            FROM expense_line_item_prices
            WHERE vendor_id = ?
              AND product_name = ?
              AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        ");
        $stmt->execute([$vendorId, $normalizedName]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stats || (int)$stats['sample_count'] < 2) return null;

        $avg = (float)$stats['avg_price'];
        if ($avg <= 0) return null;

        $deviation = abs($currentPrice - $avg) / $avg;

        // Flag if >20% deviation from average
        if ($deviation > 0.20) {
            $direction = $currentPrice > $avg ? 'above' : 'below';
            return [
                'product'    => $productName,
                'current'    => $currentPrice,
                'avg'        => round($avg, 2),
                'min'        => round((float)$stats['min_price'], 2),
                'max'        => round((float)$stats['max_price'], 2),
                'deviation'  => round($deviation * 100, 1),
                'direction'  => $direction,
                'samples'    => (int)$stats['sample_count'],
                'message'    => sprintf(
                    '%s is $%.2f (%.0f%% %s average of $%.2f)',
                    $productName, $currentPrice, $deviation * 100, $direction, $avg
                ),
            ];
        }
    } catch (\Throwable $e) {
        error_log('checkPriceAnomaly: ' . $e->getMessage());
    }

    return null;
}


/**
 * Compare prices for a product across all vendors.
 *
 * @param string $productName Product name (fuzzy match)
 * @return array Array of [{vendor_id, vendor_name, avg_price, latest_price, count}]
 */
function comparePricesAcrossVendors(string $productName): array
{
    $db = getDB();
    $normalizedName = strtoupper(trim($productName));

    try {
        $stmt = $db->prepare("
            SELECT
                elip.vendor_id,
                v.name AS vendor_name,
                AVG(elip.unit_price) AS avg_price,
                COUNT(*) AS sample_count
            FROM expense_line_item_prices elip
            JOIN vendors v ON elip.vendor_id = v.id
            WHERE elip.product_name = ?
              AND elip.expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY elip.vendor_id, v.name
            HAVING sample_count >= 1
            ORDER BY avg_price ASC
        ");
        $stmt->execute([$normalizedName]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add latest price for each vendor
        foreach ($results as &$row) {
            $stmt2 = $db->prepare("
                SELECT unit_price FROM expense_line_item_prices
                WHERE vendor_id = ? AND product_name = ?
                ORDER BY expense_date DESC LIMIT 1
            ");
            $stmt2->execute([$row['vendor_id'], $normalizedName]);
            $row['latest_price'] = (float)($stmt2->fetchColumn() ?: 0);
            $row['avg_price'] = round((float)$row['avg_price'], 2);
        }

        return $results;
    } catch (\Throwable $e) {
        error_log('comparePricesAcrossVendors: ' . $e->getMessage());
        return [];
    }
}
