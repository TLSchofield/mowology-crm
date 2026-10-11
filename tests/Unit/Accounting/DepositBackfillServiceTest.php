<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Orphan bank deposits ↔ paid CRM invoices / Jobber payments (2026-10-11). */
final class DepositBackfillServiceTest extends TestCase
{
    private static function c(string $key, string $src, float $amt, string $method, string $date, string $name = ''): array
    {
        return ['key' => $key, 'src' => $src, 'id' => (int)preg_replace('/\D/', '', $key), 'ref' => $key, 'amount' => $amt,
                'method' => $method, 'date' => $date, 'name' => $name];
    }

    public function testKindAndMethod(): void
    {
        $this->assertSame('cheque', DepositBackfillService::kindOf('CHEQUEDEPOSIT-BRANCH(LABURNUMHEIGHTS-1901)'));
        $this->assertSame('cheque', DepositBackfillService::kindOf('Cheque deposit-branch'));
        $this->assertSame('e_transfer', DepositBackfillService::kindOf('e-Transfer credit Ref 2026032318'));
        $this->assertSame('stripe', DepositBackfillService::kindOf('Preauthorized credit Jobber - By Stripe'));
        $this->assertSame('eft', DepositBackfillService::kindOf('PREAUTHORIZED CREDIT (DORSET REALTY GROUP CAN'));
        $this->assertTrue(DepositBackfillService::methodFits('cheque', 'Cheque'));
        $this->assertFalse(DepositBackfillService::methodFits('cheque', 'e_transfer'));
        $this->assertTrue(DepositBackfillService::methodFits('e_transfer', 'E-Transfer'));
        $this->assertTrue(DepositBackfillService::methodFits('eft', 'e_transfer'));
        $this->assertSame('credit_card', DepositBackfillService::methodForKind('stripe'));
    }

    public function testExactSingleAndWindow(): void
    {
        $dep = ['transaction_date' => '2026-03-24', 'amount' => 9590.41, 'description' => 'CHEQUE DEPOSIT - BRANCH'];
        $m = DepositBackfillService::match($dep, [self::c('job1', 'jobber', 9590.41, 'Cheque', '2026-03-24'),
                                                   self::c('crm2', 'crm', 9590.41, 'cheque', '2026-01-01')]);   // outside the window
        $this->assertSame('single', $m['class']);
        $this->assertSame('job1', $m['pick'][0]['key']);
        $m = DepositBackfillService::match($dep, [self::c('crm3', 'crm', 9590.41, 'e_transfer', '2026-03-24')]);   // wrong method
        $this->assertSame('none', $m['class']);
    }

    public function testAmbiguousResolvedByMemoName(): void
    {
        $dep = ['transaction_date' => '2026-04-22', 'amount' => 378.16, 'description' => 'ETRANSFER CREDIT (ALEX SHI KEWANG)'];
        $two = [self::c('crm1', 'crm', 378.16, 'e_transfer', '2026-04-20', 'Alex Shi'), self::c('crm2', 'crm', 378.16, 'e_transfer', '2026-04-21', 'Jo Brown')];
        $m = DepositBackfillService::match($dep, $two);
        $this->assertSame('single', $m['class']);
        $this->assertSame('crm1', $m['pick'][0]['key']);
        $dep['description'] = 'CHEQUE DEPOSIT - BRANCH';
        $two = [self::c('crm1', 'crm', 262.5, 'cheque', '2026-04-20'), self::c('crm2', 'crm', 262.5, 'cheque', '2026-04-21')];
        $dep['amount'] = 262.5;
        $this->assertSame('ambiguous', DepositBackfillService::match($dep, $two)['class']);
    }

    public function testGroupAndPlanActions(): void
    {
        $deps = [
            ['id' => 1, 'transaction_date' => '2026-07-14', 'amount' => 3780.00, 'description' => 'Cheque deposit-branch'],
            ['id' => 2, 'transaction_date' => '2026-04-23', 'amount' => 1690.44, 'description' => 'CHEQUE DEPOSIT - BRANCH'],
            ['id' => 3, 'transaction_date' => '2026-03-24', 'amount' => 451.37, 'description' => 'CHEQUE DEPOSIT - BRANCH'],
            ['id' => 4, 'transaction_date' => '2026-03-06', 'amount' => 0.67, 'description' => 'Preauthorized credit STRIPE'],
        ];
        $cands = [
            self::c('crm188', 'crm', 3255.00, 'cheque', '2026-07-14'), self::c('crm194', 'crm', 525.00, 'cheque', '2026-07-14'),
            self::c('crm9', 'crm', 1195.36, 'cheque', '2026-04-23'), self::c('job59', 'jobber', 495.08, 'Cheque', '2026-04-23'),
            self::c('job58', 'jobber', 451.37, 'Cheque', '2026-03-24'),
        ];
        $plan = DepositBackfillService::plan($deps, $cands);
        $this->assertSame(['link_crm', 'review', 'source_jobber', 'none'], array_column($plan, 'action'));
        $this->assertSame('subset', $plan[0]['class']);
        $s = DepositBackfillService::summary($plan);
        $this->assertSame(3780.00, $s['income_drop']);
        $this->assertSame(1, $s['by_action']['review']['n']);
    }
}
