<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FieldRecommendationService
 *
 * create()/buildQuote()/send() do real DB writes, quote generation and email
 * delivery, so — matching this codebase's convention for services like
 * ReceiptIntakeService and ReceiptArchiveService — only the deterministic
 * decision logic is unit tested here, not the full pipeline.
 *
 * The auto-send rules matter most: getting them wrong means an unreviewed,
 * possibly mispriced quote goes straight to a customer.
 */
class FieldRecommendationServiceTest extends TestCase
{
    // ── Chip labelling ───────────────────────────────────────────────────────

    public function test_field_label_wins_over_catalogue_name(): void
    {
        $this->assertSame('Half Day Cleanup', FieldRecommendationService::resolveLabel([
            'name'        => 'Seasonal Property Cleanup — Half Day (4hr, 2 crew)',
            'field_label' => 'Half Day Cleanup',
        ]));
    }

    public function test_falls_back_to_name_when_no_field_label(): void
    {
        $this->assertSame('Full Day Cleanup', FieldRecommendationService::resolveLabel([
            'name'        => 'Full Day Cleanup',
            'field_label' => '',
        ]));
    }

    public function test_label_falls_back_to_service_when_nothing_set(): void
    {
        $this->assertSame('Service', FieldRecommendationService::resolveLabel([]));
    }

    // ── Fixed vs measured pricing ────────────────────────────────────────────

    public function test_no_pricing_rule_means_fixed_base_price(): void
    {
        $this->assertTrue(FieldRecommendationService::isFixedPrice(null));
        $this->assertTrue(FieldRecommendationService::isFixedPrice(''));
    }

    public function test_flat_model_is_fixed(): void
    {
        $this->assertTrue(FieldRecommendationService::isFixedPrice('flat'));
    }

    public function test_measurement_driven_models_are_not_fixed(): void
    {
        foreach (['per_sqft', 'per_linear_ft', 'min_plus_sqft', 'min_plus_linear_ft'] as $model) {
            $this->assertFalse(
                FieldRecommendationService::isFixedPrice($model),
                "{$model} depends on measuring the property and must not count as fixed"
            );
        }
    }

    // ── Auto-send eligibility — the money decision ───────────────────────────

    public function test_flagged_flat_priced_product_may_auto_send(): void
    {
        $this->assertTrue(FieldRecommendationService::isAutoSendEligible(
            ['field_auto_send' => 1, 'base_price' => 450.00],
            'flat'
        ));
    }

    public function test_product_with_no_rule_may_auto_send_at_base_price(): void
    {
        $this->assertTrue(FieldRecommendationService::isAutoSendEligible(
            ['field_auto_send' => 1, 'base_price' => 450.00],
            null
        ));
    }

    public function test_unflagged_product_never_auto_sends(): void
    {
        $this->assertFalse(FieldRecommendationService::isAutoSendEligible(
            ['field_auto_send' => 0, 'base_price' => 450.00],
            'flat'
        ));
    }

    public function test_per_sqft_product_never_auto_sends_even_when_flagged(): void
    {
        // The price depends on measurements that may not exist for this property,
        // so it must reach the office before it reaches the customer.
        $this->assertFalse(FieldRecommendationService::isAutoSendEligible(
            ['field_auto_send' => 1, 'base_price' => 450.00],
            'per_sqft'
        ));
    }

    public function test_zero_priced_product_never_auto_sends(): void
    {
        $this->assertFalse(FieldRecommendationService::isAutoSendEligible(
            ['field_auto_send' => 1, 'base_price' => 0],
            'flat'
        ));
    }

    public function test_missing_fields_fail_closed(): void
    {
        $this->assertFalse(FieldRecommendationService::isAutoSendEligible([], null));
    }

    // ── Duplicate suppression window ─────────────────────────────────────────

    public function test_duplicate_window_is_thirty_days(): void
    {
        $this->assertSame(30, FieldRecommendationService::DUPLICATE_WINDOW_DAYS);
    }

    public function test_dismissed_recommendations_do_not_block_a_new_one(): void
    {
        // Otherwise a dismissed suggestion would suppress the same service for a
        // month, and the crew would have no way to re-raise it.
        $this->assertNotContains('dismissed', FieldRecommendationService::OPEN_STATUSES);
        $this->assertNotContains('converted', FieldRecommendationService::OPEN_STATUSES);
    }

    public function test_pending_and_sent_recommendations_block_duplicates(): void
    {
        foreach (['pending', 'approved', 'email_sent', 'quote_created'] as $status) {
            $this->assertContains($status, FieldRecommendationService::OPEN_STATUSES);
        }
    }

    // ── Catalogue guard ──────────────────────────────────────────────────────

    public function test_service_requires_a_product(): void
    {
        $db  = $this->createMock(PDO::class);
        $svc = new FieldRecommendationService($db);

        $this->expectException(InvalidArgumentException::class);
        $svc->create(7, ['visit_id' => 42, 'product_id' => 0]);
    }
}
