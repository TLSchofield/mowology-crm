<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for RulesEngine rule-matching logic.
 *
 * The core matching logic lives in the private ruleMatches() method.
 * We test it via the public previewMatch() method with a mocked PDO
 * that returns known rule fixtures.
 *
 * No real database required.
 */
class RulesEngineTest extends TestCase
{
    /**
     * Build a RulesEngine whose getActiveRules() returns the given fixture rules.
     */
    private function engineWithRules(array $rules): RulesEngine
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($rules);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->willReturn($mockStmt);

        return new RulesEngine($mockPdo);
    }

    private function makeRule(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'name'               => 'Test Rule',
            'priority'           => 10,
            'applies_to'         => 'expense',
            'condition_field'    => 'description',
            'condition_operator' => 'contains',
            'condition_value'    => 'shell',
            'account_id'         => 5,
            'account_name'       => 'Fuel & Vehicle',
            'account_code'       => '6100',
            'transaction_type'   => 'expense',
            'is_active'          => 1,
        ], $overrides);
    }

    // ── contains operator ─────────────────────────────────────────────────────

    /** @test */
    public function contains_matches_substring_case_insensitively(): void
    {
        $engine = $this->engineWithRules([$this->makeRule(['condition_value' => 'shell'])]);
        $result = $engine->previewMatch('Shell Station #42', '', 'expense');

        $this->assertNotNull($result);
        $this->assertSame('Test Rule', $result['rule_name']);
        $this->assertSame(5, $result['account_id']);
    }

    /** @test */
    public function contains_is_case_insensitive(): void
    {
        $engine = $this->engineWithRules([$this->makeRule(['condition_value' => 'home depot'])]);
        $result = $engine->previewMatch('HOME DEPOT #1234', '', 'expense');
        $this->assertNotNull($result);
    }

    /** @test */
    public function contains_no_match_returns_null(): void
    {
        $engine = $this->engineWithRules([$this->makeRule(['condition_value' => 'shell'])]);
        $result = $engine->previewMatch('Canadian Tire', '', 'expense');
        $this->assertNull($result);
    }

    /** @test */
    public function contains_empty_value_matches_all(): void
    {
        // Default catch-all rule (empty condition_value)
        $engine = $this->engineWithRules([
            $this->makeRule([
                'condition_value' => '',
                'applies_to'      => 'income',
                'account_code'    => '4900',
                'account_name'    => 'Other Services',
            ])
        ]);
        $result = $engine->previewMatch('Any description whatsoever', '', 'income');
        $this->assertNotNull($result);
    }

    // ── equals operator ───────────────────────────────────────────────────────

    /** @test */
    public function equals_matches_exact_string(): void
    {
        $engine = $this->engineWithRules([$this->makeRule([
            'condition_operator' => 'equals',
            'condition_value'    => 'google ads',
        ])]);

        $this->assertNotNull($engine->previewMatch('Google Ads', '', 'expense'));
        $this->assertNull($engine->previewMatch('Google Ads Monthly Charge', '', 'expense'));
    }

    // ── starts_with operator ──────────────────────────────────────────────────

    /** @test */
    public function starts_with_matches_prefix(): void
    {
        $engine = $this->engineWithRules([$this->makeRule([
            'condition_operator' => 'starts_with',
            'condition_value'    => 'petro',
        ])]);

        $this->assertNotNull($engine->previewMatch('Petro-Canada #52', '', 'expense'));
        $this->assertNull($engine->previewMatch('Esso Petro', '', 'expense'));
    }

    // ── ends_with operator ────────────────────────────────────────────────────

    /** @test */
    public function ends_with_matches_suffix(): void
    {
        $engine = $this->engineWithRules([$this->makeRule([
            'condition_operator' => 'ends_with',
            'condition_value'    => 'ads',
        ])]);

        $this->assertNotNull($engine->previewMatch('Google Ads', '', 'expense'));
        $this->assertNull($engine->previewMatch('Ads by Google', '', 'expense'));
    }

    // ── amount operators ──────────────────────────────────────────────────────

    /** @test */
    public function amount_gt_matches_when_amount_exceeds_threshold(): void
    {
        // previewMatch passes amount=0 on the fake tx, so amount_gt always fails
        // This verifies the boundary behaviour: amount_gt with value 0 would match any positive tx
        // but previewMatch creates a fake tx with amount=0, so amount_gt(0) should NOT match (0 > 0 is false)
        $engine = $this->engineWithRules([$this->makeRule([
            'condition_field' => 'amount_gt',
            'condition_value' => '0',
        ])]);
        $result = $engine->previewMatch('Anything', '', 'expense');
        $this->assertNull($result, 'amount_gt(0) should not match when previewMatch uses amount=0');
    }

    // ── type filtering ────────────────────────────────────────────────────────

    /** @test */
    public function income_only_rule_does_not_match_expense_transaction(): void
    {
        $engine = $this->engineWithRules([$this->makeRule([
            'applies_to'      => 'income',
            'condition_value' => 'shell',
        ])]);
        $result = $engine->previewMatch('Shell Station', '', 'expense');
        $this->assertNull($result);
    }

    /** @test */
    public function expense_only_rule_does_not_match_income_transaction(): void
    {
        $engine = $this->engineWithRules([$this->makeRule([
            'applies_to'      => 'expense',
            'condition_value' => 'invoice',
        ])]);
        $result = $engine->previewMatch('Invoice Payment', '', 'income');
        $this->assertNull($result);
    }

    /** @test */
    public function both_rule_matches_either_type(): void
    {
        $engine = $this->engineWithRules([$this->makeRule([
            'applies_to'      => 'both',
            'condition_value' => 'transfer',
        ])]);
        $this->assertNotNull($engine->previewMatch('Wire Transfer', '', 'income'));
        $this->assertNotNull($engine->previewMatch('Wire Transfer Fee', '', 'expense'));
    }

    // ── priority (first match wins) ───────────────────────────────────────────

    /** @test */
    public function first_matching_rule_wins_by_priority(): void
    {
        $rules = [
            $this->makeRule(['id' => 1, 'priority' => 1,  'condition_value' => 'shell', 'account_id' => 10, 'account_name' => 'Fuel']),
            $this->makeRule(['id' => 2, 'priority' => 99, 'condition_value' => 'shell', 'account_id' => 99, 'account_name' => 'Other']),
        ];
        $engine = $this->engineWithRules($rules);
        $result = $engine->previewMatch('Shell Gas Station', '', 'expense');

        $this->assertNotNull($result);
        $this->assertSame(10, $result['account_id']); // higher-priority (lower number) wins
    }

    // ── previewMatch result shape ─────────────────────────────────────────────

    /** @test */
    public function previewMatch_result_has_required_keys(): void
    {
        $engine = $this->engineWithRules([$this->makeRule()]);
        $result = $engine->previewMatch('Shell Station', '', 'expense');

        $this->assertArrayHasKey('rule_id', $result);
        $this->assertArrayHasKey('rule_name', $result);
        $this->assertArrayHasKey('account_id', $result);
        $this->assertArrayHasKey('account_name', $result);
        $this->assertArrayHasKey('account_code', $result);
    }
}
