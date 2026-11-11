<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The payroll remittance reminder: ask for the Wave report first, close it when Wave's debits match the report,
 * and alarm only when they do not (Tim, 2026-11-03). Figures are September 2026's real ones.
 */
class PayrollRemitMatchTest extends TestCase
{
    private const DUE = '2026-10-15';
    private const RUN = ['id' => 9, 'remittance' => 4653.27, 'gross_wages' => 16565.26];

    private function debits(): array
    {
        return [
            ['id' => 3013, 'date' => '2026-09-02', 'amount' => 2303.38],
            ['id' => 3035, 'date' => '2026-09-15', 'amount' => 2349.89],
            ['id' => 3064, 'date' => '2026-10-01', 'amount' => 2220.73], // October's pay run: a different month
        ];
    }

    public function test_no_report_asks_for_it_and_never_says_overdue(): void
    {
        $a = PayrollRemitMatch::assess(null, $this->debits(), self::DUE, '2026-11-03', '2026-10-31', true);
        $this->assertSame(PayrollRemitMatch::NO_REPORT, $a['state']);
        $this->assertStringContainsString('Upload September', (string)$a['text']);
        $this->assertDoesNotMatchRegularExpression('/overdue|unpaid|owed/i', (string)$a['text']);
    }

    public function test_payroll_not_run_through_wave_here_is_left_as_the_plain_reminder(): void
    {
        $this->assertSame(PayrollRemitMatch::NA, PayrollRemitMatch::assess(null, [], self::DUE, '2026-11-03', '2026-10-31', false)['state']);
    }

    public function test_the_two_september_wave_debits_match_the_report(): void
    {
        $a = PayrollRemitMatch::assess(self::RUN, $this->debits(), self::DUE, '2026-11-03', '2026-10-31', true);
        $this->assertSame(PayrollRemitMatch::MATCHED, $a['state']);
        $this->assertEqualsCanonicalizing([3013, 3035], $a['ids']);
        $this->assertSame(4653.27, $a['found']);
        $this->assertSame('Paid by Wave: Sep 2 $2,303.38 + Sep 15 $2,349.89 = $4,653.27, matched to the September report', $a['note']);
    }

    public function test_a_zero_report_never_closes_the_reminder_by_itself(): void
    {
        $a = PayrollRemitMatch::assess(['remittance' => 0, 'gross_wages' => 0], [], self::DUE, '2026-11-03', '2026-10-31', true);
        $this->assertSame(PayrollRemitMatch::NO_REPORT, $a['state']);
        $this->assertStringContainsString('mark this Done if there was no payroll', (string)$a['text']);
    }

    public function test_a_dollar_short_after_the_due_date_is_the_only_real_overdue(): void
    {
        $d = $this->debits();
        $d[1]['amount'] = 2348.89;
        $a = PayrollRemitMatch::assess(self::RUN, $d, self::DUE, '2026-11-03', '2026-10-31', true);
        $this->assertSame(PayrollRemitMatch::SHORT, $a['state']);
        $this->assertStringContainsString('$4,653.27', (string)$a['text']);
    }

    public function test_nothing_to_say_before_it_is_due(): void
    {
        $a = PayrollRemitMatch::assess(self::RUN, [], self::DUE, '2026-10-10', '2026-10-09', true);
        $this->assertSame(PayrollRemitMatch::PENDING, $a['state']);
    }

    public function test_bank_lines_that_stop_before_the_due_date_ask_for_the_statement_not_alarm(): void
    {
        $a = PayrollRemitMatch::assess(self::RUN, [], self::DUE, '2026-11-03', '2026-10-09', true);
        $this->assertSame(PayrollRemitMatch::UNCHECKED, $a['state']);
        $this->assertStringContainsString('up to Oct 9', (string)$a['text']);
        $none = PayrollRemitMatch::assess(self::RUN, [], self::DUE, '2026-11-03', null, true);
        $this->assertSame(PayrollRemitMatch::UNCHECKED, $none['state'], 'absent bank lines are not "paid" and not "unpaid"');
    }

    public function test_debits_outside_the_month_window_or_after_the_due_date_do_not_count(): void
    {
        $late = [['id' => 1, 'date' => '2026-10-20', 'amount' => 4653.27]];
        $this->assertSame(PayrollRemitMatch::SHORT, PayrollRemitMatch::assess(self::RUN, $late, self::DUE, '2026-11-03', '2026-10-31', true)['state']);
        $early = [['id' => 2, 'date' => '2026-07-01', 'amount' => 4653.27]];
        $this->assertSame(PayrollRemitMatch::SHORT, PayrollRemitMatch::assess(self::RUN, $early, self::DUE, '2026-11-03', '2026-10-31', true)['state']);
    }

    public function test_which_bank_lines_are_wave_remittances(): void
    {
        $this->assertTrue(PayrollRemitMatch::isWaveDebit('Preauthorized payment Wave PYRL Wave PYRL Wave PYRL'));
        $this->assertTrue(PayrollRemitMatch::isWaveDebit('PREAUTHORIZEDPAYMENT(WAVEPYRL)'));
        $this->assertFalse(PayrollRemitMatch::isWaveDebit('SEP 02WAVE - PAYROLL FEES TORONTO ON'), 'the fee line is not a remittance');
        $this->assertFalse(PayrollRemitMatch::isWaveDebit('Bill payment-online CRA GST/HST - Interim'));
    }

    public function test_the_month_covered_is_the_month_before_the_due_date(): void
    {
        $this->assertSame('2026-09', PayrollRemitMatch::monthFor('2026-10-15'));
        $this->assertSame('2026-12', PayrollRemitMatch::monthFor('2027-01-15'));
    }

    public function test_the_request_cannot_force_the_brief_red_but_a_real_miss_still_does(): void
    {
        $row = ['slug' => 'payroll_remit', 'category' => 'payroll', 'title' => 'Payroll remittance to CRA', 'when' => 'overdue since Oct 15',
                'due_date' => '2026-10-15', 'lead_days' => 7, 'priority' => 1, 'amount_hint' => null, 'overdue' => true];
        $ask = DeadlineService::askItem($row, ['month' => '2026-09', 'state' => PayrollRemitMatch::NO_REPORT, 'text' => 'Upload September report']);
        $this->assertNull(BriefStatus::deadlineDays($ask, '2026-11-03'));
        $this->assertNull(BriefStatus::forcedRed($ask, '2026-11-03'));
        $this->assertSame(2, $ask['priority']);
        $real = DeadlineService::item(['title' => '$4,653.27 CRA payroll remittance for September: no matching Wave debit found'] + $row);
        $this->assertSame('deadline overdue', BriefStatus::forcedRed($real, '2026-11-03'));
    }

    public function test_a_request_gets_no_done_button_but_a_deadline_does(): void
    {
        $ask = ['key' => 'charlie:payroll_ask:2026-09', 'kind' => 'charlie:payroll_report_ask', 'text' => 'Upload', 'url' => '/x', 'priority' => 2];
        $dl = ['key' => 'charlie:deadline:t4_slips:2027-02-28', 'kind' => 'charlie:deadline_payroll', 'text' => 'T4', 'url' => '/x', 'priority' => 2];
        [$a, $b] = CharlieInboxService::fromDeadlines([$ask, $dl]);
        $this->assertSame([], $a['actions']);
        $this->assertSame('Done', $b['actions'][0]['label']);
    }

    public function test_a_zero_or_missing_remittance_against_paid_wages_is_not_paid(): void
    {
        foreach ([['remittance' => 0, 'gross_wages' => 16565.26], ['remittance' => null, 'gross_wages' => 100], ['remittance' => 0]] as $run) {
            $a = PayrollRemitMatch::assess($run, [], self::DUE, '2026-11-03', '2026-10-31', true);
            $this->assertSame(PayrollRemitMatch::NO_REPORT, $a['state']);
            $this->assertStringContainsString('Import it again', (string)$a['text']);
        }
    }

    public function test_another_months_debits_cannot_make_a_match(): void
    {
        // Two of last month's debits ($1,000 + $3,653.27) happen to add up to this month's remittance; they were claimed already.
        $d = [['id' => 1, 'date' => '2026-08-25', 'amount' => 1000.00], ['id' => 2, 'date' => '2026-08-28', 'amount' => 3653.27]];
        $this->assertSame(PayrollRemitMatch::MATCHED, PayrollRemitMatch::assess(self::RUN, $d, self::DUE, '2026-11-03', '2026-10-31', true)['state'], 'unclaimed, the coincidence would match');
        $this->assertSame(PayrollRemitMatch::SHORT, PayrollRemitMatch::assess(self::RUN, $d, self::DUE, '2026-11-03', '2026-10-31', true, [1, 2])['state']);
    }

    public function test_debits_inside_the_month_are_tried_before_the_wider_window(): void
    {
        // The real pair sits inside September; a stray $4,653.27 debit on Oct 3 must not be the one chosen.
        $d = $this->debits();
        $d[] = ['id' => 77, 'date' => '2026-10-03', 'amount' => 4653.27];
        $a = PayrollRemitMatch::assess(self::RUN, $d, self::DUE, '2026-11-03', '2026-10-31', true);
        $this->assertEqualsCanonicalizing([3013, 3035], $a['ids']);
    }
}
