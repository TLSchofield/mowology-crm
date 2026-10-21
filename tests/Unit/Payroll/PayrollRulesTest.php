<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny Payroll step 1 (2026-10-21): the 2026 CRA rules, by pay date, with their sources. The published maxima
 * are recomputed from the rates so a typo in a rule file fails here, not on someone's pay.
 */
class PayrollRulesTest extends TestCase
{
    public function test_the_loaded_editions_have_sources_dates_and_consistent_constants(): void
    {
        $this->assertSame([], PayrollRules::problems((new PayrollRules())->editions()));
    }

    public function test_the_edition_is_chosen_by_pay_date_and_july_brings_bcs_catch_up_rate(): void
    {
        $r = new PayrollRules();
        $june = $r->forPayDate('BC', '2026-06-30');
        $july = $r->forPayDate('bc', '2026-07-01');
        $this->assertSame(0.0506, $june['provincial']['lowest_rate']);
        $this->assertSame(575, $june['provincial']['tax_reduction']['basic']);
        $this->assertSame(0.0614, $july['provincial']['lowest_rate'], 'prorated July–December (T4127 123rd)');
        $this->assertSame(805, $july['provincial']['tax_reduction']['basic']);
        $this->assertSame(44952, $july['provincial']['tax_reduction']['ceiling']);
        $this->assertSame('T4127 122nd edition', $july['federal']['edition'], 'federal and CPP/EI do not change in July');
    }

    public function test_cpp_cpp2_and_ei_maxima_follow_from_their_rates(): void
    {
        $f = (new PayrollRules())->forPayDate('BC', '2026-03-13')['federal'];
        $c = $f['cpp'];
        $this->assertSame($c['ympe'] - $c['basic_exemption'], $c['ymce']);
        $this->assertEqualsWithDelta($c['max'], round($c['ymce'] * $c['rate'], 2), 0.001, '4,230.45');
        $this->assertEqualsWithDelta($c['base_max'], round($c['ymce'] * $c['base_rate'], 2), 0.001, '3,519.45');
        $this->assertEqualsWithDelta($c['rate'], $c['base_rate'] + $c['first_additional_rate'], 1e-9);
        $this->assertEqualsWithDelta($c['cpp2_max'], ($c['yampe'] - $c['ympe']) * $c['cpp2_rate'], 0.001, '416');
        $e = $f['ei'];
        $this->assertEqualsWithDelta($e['max_premium'], round($e['max_insurable'] * $e['rate'], 2), 0.001, '1,123.07');
        $this->assertEqualsWithDelta($e['employer_rate'], round($e['rate'] * 1.4, 5), 1e-9);
        $this->assertEqualsWithDelta($e['employer_max'], round($e['max_premium'] * 1.4, 2), 0.001, '1,572.30');
    }

    public function test_a_pay_date_with_no_rules_is_refused_in_plain_words(): void
    {
        try {
            (new PayrollRules())->forPayDate('BC', '2027-01-15');
            $this->fail('2027 rates are not loaded');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('load the new CRA tables', $e->getMessage());
        }
        $this->expectExceptionMessage('Quebec is not supported');
        (new PayrollRules())->forPayDate('QC', '2026-03-13');
    }

    public function test_the_checker_catches_a_typo_and_an_overlap(): void
    {
        $e = (new PayrollRules())->editions();
        $bad = $e;
        $bad[2]['brackets'][2]['k'] = 3906;             // July BC constant typed wrong
        $bad[] = $e[1];                                  // January BC edition loaded twice
        $p = PayrollRules::problems($bad);
        $this->assertNotEmpty(array_filter($p, fn($x) => str_contains($x, 'constant K for bracket 3')));
        $this->assertNotEmpty(array_filter($p, fn($x) => str_contains($x, 'overlap')));
    }
}
