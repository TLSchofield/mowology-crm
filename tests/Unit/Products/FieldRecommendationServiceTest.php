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

    // ── Product-manager flags ────────────────────────────────────────────────
    // Regression: these lived in a closure defined inside the delete-category
    // branch of api-products.php, so every save-product call wrote the product
    // and then fataled on an undefined variable — the UI reported failure for
    // saves that had succeeded (products 39–41 on production, 2026-10-04).

    public function test_product_flag_values_from_form_payload(): void
    {
        $this->assertSame([1, 1, 'Half Day Cleanup', 3], FieldRecommendationService::productFlagValues([
            'field_recommendable' => 'on',
            'field_auto_send'     => '1',
            'field_label'         => '  Half Day Cleanup ',
            'field_sort_order'    => '3',
        ]));
    }

    public function test_product_flag_values_default_off_with_null_label(): void
    {
        // A blank label must be NULL, not '', so resolveLabel() falls back to the name.
        $this->assertSame([0, 0, null, 0], FieldRecommendationService::productFlagValues([
            'field_label' => '   ',
        ]));
    }

    public function test_apply_product_flags_updates_the_saved_product(): void
    {
        $columns = $this->createMock(PDOStatement::class);
        $columns->method('rowCount')->willReturn(1);

        $update = $this->createMock(PDOStatement::class);
        $update->expects($this->once())
            ->method('execute')
            ->with([1, 0, 'Aeration', 2, 41])
            ->willReturn(true);

        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturn($columns);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE products'))
            ->willReturn($update);

        (new FieldRecommendationService($db))->applyProductFlags(41, [
            'field_recommendable' => 1,
            'field_label'         => 'Aeration',
            'field_sort_order'    => 2,
        ]);
    }

    public function test_save_product_uses_the_service_not_a_branch_local_closure(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Modules/Products/Api/api-products.php');
        $this->assertStringNotContainsString('$applyFieldFlags', $src);
        $this->assertSame(2, substr_count($src, '->applyProductFlags('), 'create and update paths');
    }

    // ── Catalogue guard ──────────────────────────────────────────────────────

    public function test_service_requires_a_product(): void
    {
        $db  = $this->createMock(PDO::class);
        $svc = new FieldRecommendationService($db);

        $this->expectException(InvalidArgumentException::class);
        $svc->create(7, ['visit_id' => 42, 'product_id' => 0]);
    }

    // ── Ask first / Send quote (migration 1180) ──────────────────────────────

    public function test_old_app_builds_without_an_intent_get_a_quote(): void
    {
        $this->assertSame('quote', FieldRecommendationService::normaliseIntent(null));
        $this->assertSame('quote', FieldRecommendationService::normaliseIntent(''));
        $this->assertSame('quote', FieldRecommendationService::normaliseIntent('nonsense'));
        $this->assertSame('ask', FieldRecommendationService::normaliseIntent(' Ask '));
    }

    public function test_open_asks_block_a_second_ask_for_the_same_service(): void
    {
        foreach (['ask_draft', 'asked', 'ask_yes'] as $s) {
            $this->assertContains($s, FieldRecommendationService::OPEN_STATUSES);
        }
    }

    public function test_price_label_never_shows_zero_dollars(): void
    {
        $this->assertSame('Price TBC', FieldRecommendationService::priceLabel(['base_price' => 0], null));
        $this->assertSame('Price TBC', FieldRecommendationService::priceLabel(['base_price' => '0.00'], 'flat'));
        $this->assertSame('$175.00', FieldRecommendationService::priceLabel(['base_price' => 175], 'flat'));
        $this->assertSame('Priced by size', FieldRecommendationService::priceLabel(['base_price' => 0], 'per_sqft'));
    }

    public function test_has_price_for_flat_and_measured_services(): void
    {
        $this->assertFalse(FieldRecommendationService::hasPrice(['base_price' => 0], null), 'Fall Clean Up with no price');
        $this->assertTrue(FieldRecommendationService::hasPrice(['base_price' => 95], 'flat'));
        $this->assertTrue(FieldRecommendationService::hasPrice(['base_price' => 0], 'per_sqft'), 'Aeration prices by size');
    }

    public function test_a_zero_dollar_quote_is_never_sendable(): void
    {
        $this->assertFalse(FieldRecommendationService::sendableAmount(['amount' => 0, 'total_amount' => 0]));
        $this->assertFalse(FieldRecommendationService::sendableAmount([]));
        $this->assertTrue(FieldRecommendationService::sendableAmount(['amount' => 0, 'total_amount' => 99.75]));
        $this->assertTrue(FieldRecommendationService::sendableAmount(['amount' => '95.00']));
    }

    public function test_an_ask_never_auto_sends(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Modules/Products/Services/FieldRecommendationService.php');
        $this->assertStringContainsString('$autoSend = $intent === self::INTENT_QUOTE', $src);
        $ask  = strpos($src, "\$result['status']  = 'ask_draft';");
        $auto = strpos($src, 'if (!$autoSend) {');
        $this->assertNotFalse($ask);
        $this->assertNotFalse($auto);
        $this->assertLessThan($auto, $ask, 'an ask returns before the auto-send block');
    }
}
