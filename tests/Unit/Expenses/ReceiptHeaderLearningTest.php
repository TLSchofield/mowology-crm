<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Header learning baseline (migration 1123).
 *
 * Header lessons used to diff saved values against a fresh re-parse on every save —
 * not what the user was shown, no category in it (every save logged null→X), and
 * re-counted on each re-save. Now the capture extraction is stored once and diffed
 * against the confirmed values once. These are the deterministic rules behind that.
 */
class ReceiptHeaderLearningTest extends TestCase
{
    // ── headerCorrections ─────────────────────────────────────────────

    public function test_unchanged_receipt_records_no_corrections(): void
    {
        $d = headerCorrections(
            ['total' => 52.5, 'gst' => 2.5, 'subtotal' => 50, 'date' => '2026-10-01', 'vendor_hint' => 'Shell', 'accounting_category' => 'Fuel'],
            ['total' => '52.50', 'gst_amount' => '2.50', 'amount' => '50.00', 'expense_date' => '2026-10-01', 'accounting_category' => 'Fuel'],
            'Shell'
        );
        foreach ($d['fields'] as $field => $f) {
            $this->assertFalse($f['corrected'], "{$field}: DB strings vs JSON floats must compare equal");
        }
        $this->assertNull($d['category']);
    }

    public function test_corrected_total_is_flagged(): void
    {
        $d = headerCorrections(['total' => '45.00'], ['total' => '54.00'], null);
        $this->assertTrue($d['fields']['total']['corrected']);
        $this->assertSame('45.00', $d['fields']['total']['ocr']);
        $this->assertSame('54.00', $d['fields']['total']['user']);
    }

    public function test_category_change_from_a_suggestion_is_a_lesson(): void
    {
        $d = headerCorrections(['accounting_category' => 'Materials'], ['accounting_category' => 'Fuel'], null);
        $this->assertSame(['ocr' => 'Materials', 'user' => 'Fuel'], $d['category']);
    }

    public function test_category_with_nothing_suggested_is_not_a_lesson(): void
    {
        // The old re-parse baseline never had a category, so every save logged null→X.
        $d = headerCorrections(['total' => '10.00'], ['accounting_category' => 'Fuel'], null);
        $this->assertNull($d['category']);
    }

    public function test_separately_sent_suggestion_counts_as_the_category_baseline(): void
    {
        $d = headerCorrections(['suggested_accounting_category' => 'Materials'], ['accounting_category' => 'Fuel'], null);
        $this->assertSame('Materials', $d['category']['ocr']);
    }

    public function test_fields_empty_on_both_sides_are_skipped(): void
    {
        $d = headerCorrections(['total' => '10.00'], ['total' => '10.00'], null);
        $this->assertArrayNotHasKey('gst', $d['fields']);
        $this->assertArrayNotHasKey('vendor', $d['fields']);
    }

    // ── captureBaseline ───────────────────────────────────────────────

    public function test_baseline_decodes_json_and_folds_in_the_suggested_category(): void
    {
        $b = captureBaseline(json_encode(['total' => 12.5, 'suggested_accounting_category' => 'Fuel']));
        $this->assertSame('Fuel', $b['accounting_category']);
        $this->assertSame(12.5, $b['total']);
    }

    public function test_baseline_keeps_a_learned_category_over_the_suggestion(): void
    {
        $b = captureBaseline(['accounting_category' => 'Vehicle', 'suggested_accounting_category' => 'Fuel']);
        $this->assertSame('Vehicle', $b['accounting_category']);
    }

    public function test_baseline_is_null_for_missing_or_garbage_input(): void
    {
        $this->assertNull(captureBaseline(null));
        $this->assertNull(captureBaseline(''));
        $this->assertNull(captureBaseline('not json'));
        $this->assertNull(captureBaseline([]));
    }

    // ── baselineFromExpenseRow (emailed receipts) ─────────────────────

    public function test_row_baseline_maps_expense_columns_onto_parsed_keys(): void
    {
        $b = baselineFromExpenseRow([
            'total' => '30.00', 'gst_amount' => '1.43', 'amount' => '28.57',
            'expense_date' => '2026-09-30', 'vendor_name_raw' => 'Anthropic', 'accounting_category' => 'Software',
        ]);
        $d = headerCorrections($b, ['total' => '30.00', 'gst_amount' => '1.43', 'amount' => '28.57',
            'expense_date' => '2026-09-30', 'accounting_category' => 'Subscriptions'], 'Anthropic');

        foreach (['total', 'gst', 'subtotal', 'date', 'vendor'] as $field) {
            $this->assertFalse($d['fields'][$field]['corrected'], $field);
        }
        $this->assertSame(['ocr' => 'Software', 'user' => 'Subscriptions'], $d['category']);
    }

    // ── withSuggestedCategory (intake response) ───────────────────────

    public function test_intake_parsed_carries_the_suggested_category_for_mobile_clients(): void
    {
        $p = withSuggestedCategory(['total' => '10.00'], ['accounting_category' => 'Fuel']);
        $this->assertSame('Fuel', $p['suggested_accounting_category']);
        $this->assertSame('Fuel', captureBaseline($p)['accounting_category']);
    }

    public function test_intake_parsed_unchanged_without_a_suggestion(): void
    {
        $this->assertSame(['total' => '10.00'], withSuggestedCategory(['total' => '10.00'], []));
    }
}
