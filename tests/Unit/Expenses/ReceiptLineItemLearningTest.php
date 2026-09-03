<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Line-item learning — the deterministic rules behind the new signals:
 *  - assessLineItemQuality(): items-sum vs subtotal drives Vision/LLM escalation
 *  - validateLlmLineItems(): hallucination gates on the LLM tier's output
 *  - ocrTextFromStored(): raw_ocr_json may be a JSON Vision blob after a rescan
 *  - ExpenseLineItemService::lessonForRename() / resolveLineTotal()
 *  - extractLineItems() honours a vendor profile (noise skip, known-name capture)
 */
class ReceiptLineItemLearningTest extends TestCase
{
    // ── assessLineItemQuality ──────────────────────────────────────────

    public function test_quality_none_without_items(): void
    {
        $r = assessLineItemQuality(['line_items' => [], 'subtotal' => '10.00', 'total' => '10.50']);
        $this->assertSame('none', $r['line_items_quality']);
        $this->assertNull($r['items_sum']);
    }

    public function test_quality_match_within_tolerance(): void
    {
        $r = assessLineItemQuality([
            'line_items' => [['name' => 'A', 'amount' => '5.00'], ['name' => 'B', 'line_total' => '4.97']],
            'subtotal'   => '10.00',
        ]);
        $this->assertSame('9.97', $r['items_sum']);
        $this->assertSame('match', $r['line_items_quality']);
    }

    public function test_quality_mismatch_when_a_line_is_missing(): void
    {
        $r = assessLineItemQuality([
            'line_items' => [['name' => 'A', 'amount' => '5.00']],
            'subtotal'   => '25.00',
        ]);
        $this->assertSame('mismatch', $r['line_items_quality']);
    }

    public function test_quality_falls_back_to_total_when_no_subtotal(): void
    {
        $r = assessLineItemQuality([
            'line_items' => [['name' => 'A', 'amount' => '21.00']],
            'subtotal'   => null,
            'total'      => '21.00',
        ]);
        $this->assertSame('match', $r['line_items_quality']);
    }

    // ── lineItemsNeedBetterExtraction ──────────────────────────────────

    public function test_needs_better_when_mismatch_or_empty_with_subtotal(): void
    {
        $this->assertTrue(lineItemsNeedBetterExtraction(['line_items_quality' => 'mismatch', 'line_items' => [['name' => 'x']], 'subtotal' => '9']));
        $this->assertTrue(lineItemsNeedBetterExtraction(['line_items_quality' => 'none', 'line_items' => [], 'subtotal' => '40.00']));
        $this->assertFalse(lineItemsNeedBetterExtraction(['line_items_quality' => 'match', 'line_items' => [['name' => 'x']], 'subtotal' => '9']));
        $this->assertFalse(lineItemsNeedBetterExtraction(['line_items_quality' => 'none', 'line_items' => [], 'subtotal' => null, 'total' => null]));
    }

    // ── validateLlmLineItems ───────────────────────────────────────────

    public function test_llm_rows_rejected_when_sum_off(): void
    {
        $items = [['name' => 'TOPSOIL 30L', 'line_total' => '10.00'], ['name' => 'MULCH', 'line_total' => '10.00']];
        $this->assertSame('sum_mismatch', validateLlmLineItems($items, "TOPSOIL 30L 10.00\nMULCH 10.00\nSUBTOTAL 30.00", 30.0));
    }

    public function test_llm_rows_rejected_when_name_not_in_ocr(): void
    {
        $items = [['name' => 'GARDEN GNOME DELUXE', 'line_total' => '20.00']];
        $this->assertSame('name_absent', validateLlmLineItems($items, "TOPSOIL 30L 20.00\nSUBTOTAL 20.00", 20.0));
    }

    public function test_llm_rows_accepted_with_one_char_ocr_slip(): void
    {
        $items = [['name' => 'TOPSOIL 30L', 'line_total' => '20.00']];
        // OCR read "TOPS0IL" (zero for O) — one-character slip on a 7-letter word
        $this->assertNull(validateLlmLineItems($items, "TOPS0IL 30L 20.00\nSUBTOTAL 20.00", 20.0));
    }

    // ── ocrTextFromStored ──────────────────────────────────────────────

    public function test_ocr_text_from_stored_passes_plain_text_through(): void
    {
        $this->assertSame("HOME DEPOT\nTOTAL 12.00", ocrTextFromStored("HOME DEPOT\nTOTAL 12.00"));
        $this->assertSame('', ocrTextFromStored(null));
    }

    public function test_ocr_text_from_stored_unwraps_vision_json(): void
    {
        $json = json_encode(['fullTextAnnotation' => ['text' => "HOME DEPOT\nTOTAL 12.00"], 'textAnnotations' => []]);
        $this->assertSame("HOME DEPOT\nTOTAL 12.00", ocrTextFromStored($json));
        $wrapped = json_encode(['responses' => [['fullTextAnnotation' => ['text' => 'X']]]]);
        $this->assertSame('X', ocrTextFromStored($wrapped));
    }

    public function test_ocr_text_from_stored_rejects_unrelated_json(): void
    {
        $this->assertSame('', ocrTextFromStored('{"foo": "bar"}'));
    }

    // ── ExpenseLineItemService pure helpers ────────────────────────────

    public function test_lesson_for_rename_uses_ocr_name_as_source(): void
    {
        $l = ExpenseLineItemService::lessonForRename('TOPS0IL 30L', 'Topsoil (fixed once)', 'Topsoil 30L Bag');
        $this->assertSame('line_item_name', $l['type']);
        $this->assertSame('TOPS0IL 30L', $l['ocr_value']);
        $this->assertSame('Topsoil 30L Bag', $l['corrected_value']);
    }

    public function test_lesson_for_rename_null_when_unchanged_or_blank(): void
    {
        $this->assertNull(ExpenseLineItemService::lessonForRename(null, 'Mulch', 'mulch'));
        $this->assertNull(ExpenseLineItemService::lessonForRename('', '', 'Mulch'));
    }

    public function test_resolve_line_total_rules(): void
    {
        $this->assertSame(12.5, ExpenseLineItemService::resolveLineTotal('12.50', true, 3.0, 2.0, 99.0));
        $this->assertSame(6.0, ExpenseLineItemService::resolveLineTotal(null, true, 3.0, 2.0, 99.0));
        $this->assertSame(99.0, ExpenseLineItemService::resolveLineTotal(null, false, 3.0, 2.0, 99.0));
        $this->assertSame(99.0, ExpenseLineItemService::resolveLineTotal('', true, null, 2.0, 99.0));
    }

    // ── extractLineItems with a vendor profile ─────────────────────────

    public function test_profile_noise_lines_are_skipped(): void
    {
        $lines = ['THANK YOU FOR SHOPPING   5.00', 'MULCH BAG   9.99'];
        $plain = extractLineItems($lines);
        $this->assertCount(2, $plain, 'without a profile the noise line parses as an item');

        $withProfile = extractLineItems($lines, ['noise' => ['THANK YOU FOR SHOPPING   5.00']]);
        $this->assertCount(1, $withProfile);
        $this->assertSame('MULCH BAG', $withProfile[0]['name']);
    }

    public function test_profile_known_name_is_captured_with_canonical_spelling(): void
    {
        // A bare product-name line that matches the vendor's purchase history is queued
        // as a pending item (instead of relying on the price-line lookback) and takes the
        // canonical spelling from history rather than the OCR casing.
        $lines = ['RIVER ROCK 1"', 'Sz: 20kg', '45.00'];

        $plain = extractLineItems($lines);
        $this->assertCount(1, $plain);
        $this->assertSame('RIVER ROCK 1"', $plain[0]['name']);

        $items = extractLineItems($lines, ['known_names' => ['River Rock 1"']]);
        $this->assertCount(1, $items);
        $this->assertSame('River Rock 1"', $items[0]['name']);
        $this->assertSame('45.00', $items[0]['amount']);
    }
}
