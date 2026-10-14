<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CodeSuggestionGateTest extends TestCase
{
    private function ctx(array $cur = [], array $ruleHits = []): array
    {
        return [
            'expense' => ['vendor_id' => 7],
            'current' => $cur + ['vendor' => 'Lawnboy', 'subtotal' => 80.00, 'gst' => 4.00, 'pst' => 0.00, 'total' => 84.00, 'line_items' => ['Blade 21in']],
            'prompt'  => ['rule_hits' => $ruleHits, 'receipt_text' => "LAWNBOY\nSUBTOTAL 80.00\nGST 4.00\nTOTAL 84.00"],
        ];
    }

    private function vendor(array $o = []): array
    {
        return $o + ['id' => 7, 'name' => 'Lawnboy', 'default_accounting_category' => 'Repairs/Maintenance', 'gst_exempt' => 0, 'streak' => 6, 'asset_tag' => null];
    }

    public function testLearnedVendorWithCategoryAndCleanAmountsIsEligible(): void
    {
        $d = CodeSuggestionGate::decide($this->ctx(), $this->vendor());
        $this->assertTrue($d['ok'], implode('; ', $d['reasons']));
        $this->assertSame('Repairs/Maintenance', $d['category']);
        $this->assertSame('none', $d['asset_tag']);
    }

    public function testShortStreakIsNotEligible(): void
    {
        $d = CodeSuggestionGate::decide($this->ctx(), $this->vendor(['streak' => 4]));
        $this->assertFalse($d['ok']);
        $this->assertStringContainsString('streak 4', $d['reasons'][0]);
    }

    public function testCommonsCategoryFillsAMissingLocalDefault(): void
    {
        $d = CodeSuggestionGate::decide($this->ctx(), $this->vendor(['default_accounting_category' => null]), ['category_local' => 'Repairs/Maintenance', 'source' => 'commons']);
        $this->assertTrue($d['ok']);
        $this->assertSame('Repairs/Maintenance', $d['category']);
        $this->assertStringContainsString('commons', $d['reasons'][0]);
    }

    public function testNoCategoryAnywhereIsNotEligible(): void
    {
        $d = CodeSuggestionGate::decide($this->ctx(), $this->vendor(['default_accounting_category' => null]), []);
        $this->assertFalse($d['ok']);
    }

    public function testAmountsMustAddUp(): void
    {
        $d = CodeSuggestionGate::decide($this->ctx(['total' => 90.00]), $this->vendor());
        $this->assertFalse($d['ok']);
        $this->assertContains('amounts do not add up', $d['reasons']);
    }

    public function testFuelNeedsALearnedAssetTag(): void
    {
        $fuel = $this->vendor(['default_accounting_category' => 'Fuel', 'name' => 'Chevron']);
        $this->assertFalse(CodeSuggestionGate::decide($this->ctx(), $fuel)['ok']);
        $d = CodeSuggestionGate::decide($this->ctx(), array_merge($fuel, ['asset_tag' => 'truck']));
        $this->assertTrue($d['ok']);
        $this->assertSame('truck', $d['asset_tag']);
    }

    public function testOwnersFirmRuleOverridesLearnedCategory(): void
    {
        $rule = [['field' => 'accounting_category', 'value' => 'Tools/Equipment', 'strength' => 'firm', 'reason' => 'owner says tools']];
        $d = CodeSuggestionGate::decide($this->ctx([], $rule), $this->vendor());
        $this->assertFalse($d['ok'], 'code steps aside when the owner has a firm rule that disagrees');
    }

    public function testBuildMatchesTheModelSchemaAndPassesTheChecks(): void
    {
        $ctx = $this->ctx();
        $d = CodeSuggestionGate::decide($ctx, $this->vendor());
        $s = CodeSuggestionGate::build($ctx, $this->vendor(), $d);
        foreach (['vendor', 'accounting_category', 'asset_tag', 'job', 'subtotal', 'gst', 'pst', 'total', 'line_items', 'notes'] as $f) {
            $this->assertArrayHasKey($f, $s);
        }
        $this->assertNull($s['job']['value']);
        $this->assertSame(84.00, $s['total']['value']);
        $checked = ReceiptBookkeeperService::check($s, $ctx);
        foreach ($checked['checks'] as $c) {
            if ($c['check'] === 'items_sum') continue;   // code puts 0 against item amounts; a warning only
            $this->assertTrue($c['ok'], $c['message']);
        }
    }
}
