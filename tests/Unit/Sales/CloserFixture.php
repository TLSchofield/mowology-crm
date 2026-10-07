<?php
declare(strict_types=1);

/**
 * A made-up quote for the Closer: no database, canned rows. Used by CloserForQuoteTest and by
 * the stub render (docs/crm/closer-render.jpg). Customers, addresses and numbers are invented.
 */
class FakeCloserService extends CloserService
{
    public array $card = [];
    public array $siteRows = [];
    public array $days = [];

    public function __construct()
    {
        parent::__construct(new PDO('sqlite::memory:'));
        $this->card = CloserRateCard::resolve(
            [
                'closer_hourly_cost' => ['value' => '52', 'description' => 'Closer: seeded from cost factor "Owner/Manager" — labour only, no equipment or overhead. Tim to confirm.'],
                'closer_min_visit'   => ['value' => '45'],
                'closer_depot_lat'   => ['value' => '49.7016', 'description' => 'Depot geocoded from business_settings.company_address'],
                'closer_depot_lng'   => ['value' => '-123.1558'],
            ],
            ['profit_margin' => '35', 'estimated_billable_hours' => '140'],
            [
                ['factor_name' => 'Owner/Manager', 'factor_type' => 'labor', 'rate' => 45, 'rate_with_burden' => 52, 'unit' => 'per hour'],
                ['factor_name' => 'Walk-Behind Mower', 'factor_type' => 'equipment', 'rate' => 8, 'unit' => 'per hour'],
                ['factor_name' => 'String Trimmer', 'factor_type' => 'equipment', 'rate' => 3, 'unit' => 'per hour'],
                ['factor_name' => 'Leaf Blower', 'factor_type' => 'equipment', 'rate' => 2.5, 'unit' => 'per hour'],
                ['factor_name' => 'Pickup Truck', 'factor_type' => 'equipment', 'rate' => 12, 'unit' => 'per hour'],
            ],
            [['amount' => 1400, 'frequency' => 'monthly']]
        );
        $this->siteRows = [
            ['service_key' => 'mow',   'source' => 'fit',    'fixed_minutes' => 6, 'per_unit_minutes' => 5.2, 'per_obstacle_minutes' => 1.5, 'n' => 22],
            ['service_key' => 'edge',  'source' => 'manual', 'fixed_minutes' => 2, 'per_unit_minutes' => 6, 'per_obstacle_minutes' => 0, 'n' => 0],
            ['service_key' => 'hedge', 'source' => 'manual', 'fixed_minutes' => 10, 'per_unit_minutes' => 40, 'per_obstacle_minutes' => 0, 'n' => 0],
            ['service_key' => 'aeration', 'source' => 'fit', 'fixed_minutes' => 5, 'per_unit_minutes' => 4, 'per_obstacle_minutes' => 0, 'n' => 6],
        ];
        // Wednesday route: three clients on the same street, ~30–60 m apart.
        $this->days = [
            ['date' => '2026-10-07', 'crew_id' => 3, 'stops' => [
                ['lat' => 49.73000, 'lng' => -123.15000, 'property_id' => 11],
                ['lat' => 49.73030, 'lng' => -123.15000, 'property_id' => 12],
                ['lat' => 49.73080, 'lng' => -123.15000, 'property_id' => 13],
            ]],
            ['date' => '2026-10-09', 'crew_id' => 3, 'stops' => [
                ['lat' => 49.76000, 'lng' => -123.12000, 'property_id' => 21],
            ]],
        ];
    }

    public function quoteRow(int $quoteId): ?array
    {
        return $quoteId === 101 ? ['id' => 101, 'quote_number' => 'QUO-2026-0101', 'status' => 'sent', 'property_id' => 41] : null;
    }

    public function rateCard(): array { return $this->card; }
    public function depot(array $card): ?array { return $card['depot']; }

    public function lot(int $propertyId): array
    {
        return self::lotFromRow(['id' => 41, 'total_lawn_sqft' => 5200, 'edge_linear_ft' => 160, 'total_hedge_linear_ft' => 90,
                                 'obstacle_count' => 3, 'latitude' => 49.73055, 'longitude' => -123.15000]);
    }

    public function routeDays(int $excludePropertyId): array { return $this->days; }
    public function disposalCost(): ?float { return 18.0; }
    public function siteModelRows(): array { return $this->siteRows; }

    public function products(): array
    {
        $p = [
            1 => ['id' => 1, 'name' => 'Weekly Lawn Mowing', 'service_type' => 'Lawn Maintenance', 'base_cost' => 1.5, 'base_price' => 48, 'min_price' => 45],
            2 => ['id' => 2, 'name' => 'Edging & Trimming', 'service_type' => null, 'base_cost' => 0, 'base_price' => 12, 'min_price' => null],
            3 => ['id' => 3, 'name' => 'Fall Clean-up', 'service_type' => 'Cleanup', 'base_cost' => 4, 'base_price' => 180, 'min_price' => 150],
            4 => ['id' => 4, 'name' => 'Hedge Trimming', 'service_type' => 'Hedge', 'base_cost' => 0, 'base_price' => 95, 'min_price' => 80],
            5 => ['id' => 5, 'name' => 'Black Composted Bark Mulch', 'service_type' => 'Garden', 'base_cost' => 60, 'base_price' => 175, 'min_price' => null],
        ];
        foreach ($p as &$r) {
            $r['service_key'] = CloserPricing::serviceKey((string)$r['service_type']) ?? CloserPricing::serviceKey($r['name']);
        }
        return $p;
    }

    public function pricingRules(): array
    {
        // The current rule for Fall Clean-up: $0.03 / sq ft, $150 minimum.
        return [['id' => 7, 'product_id' => 3, 'measurement_group_id' => 1, 'pricing_model' => 'per_sqft', 'price_per_unit' => 0.03,
                 'minimum_price' => 150, 'included_units' => 0, 'default_frequency' => 'seasonal', 'group_key' => 'lawn_area',
                 'group_label' => 'Lawn & Garden Area', 'unit' => 'sqft'],
                // Mulch by the yard (per_yard_area): must imply no bed-work minutes.
                ['id' => 8, 'product_id' => 5, 'measurement_group_id' => 9, 'pricing_model' => 'per_yard_area', 'price_per_unit' => 175,
                 'minimum_price' => 0, 'included_units' => 0, 'depth_inches' => 3, 'min_units' => 2, 'default_frequency' => 'one_off',
                 'group_key' => 'garden_bed', 'group_label' => 'Garden beds', 'unit' => 'sqft']];
    }

    public function lineItems(int $quoteId): array
    {
        return [
            ['id' => 1, 'product_id' => 1, 'service_type' => 'Weekly Lawn Mowing', 'description' => '', 'quantity' => 26, 'unit_price' => 48],
            ['id' => 2, 'product_id' => 2, 'service_type' => 'Edging & Trimming', 'description' => '', 'quantity' => 26, 'unit_price' => 12],
            ['id' => 3, 'product_id' => 3, 'service_type' => 'Fall Clean-up', 'description' => '', 'quantity' => 1, 'unit_price' => 180],
            ['id' => 4, 'product_id' => 4, 'service_type' => 'Hedge Trimming', 'description' => '', 'quantity' => 2, 'unit_price' => 95],
            ['id' => 5, 'product_id' => null, 'service_type' => 'Gutter cleaning', 'description' => 'Front only', 'quantity' => 1, 'unit_price' => 60],
            ['id' => 6, 'product_id' => 5, 'service_type' => 'Black Composted Bark Mulch', 'description' => '4 yd · 432 sq ft at 3 in', 'quantity' => 4, 'unit_type' => 'yd', 'unit_price' => 175],
        ];
    }

    public function tierServices(): array { return []; }
}
