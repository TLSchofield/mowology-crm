<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * When the business's own deadlines fall due, and how loudly Charlie reminds.
 */
class DeadlineRulesTest extends TestCase
{
    public function test_annual_dates_roll_into_next_year_once_passed(): void
    {
        $this->assertSame('2026-12-31', DeadlineRules::nextDue('annual:12-31', '2026-10-06'));
        $this->assertSame('2027-06-30', DeadlineRules::nextDue('annual:06-30', '2026-10-06'));
        $this->assertSame('2026-10-06', DeadlineRules::nextDue('annual:10-06', '2026-10-06'), 'due today is still due');
    }

    public function test_last_day_of_february_follows_leap_years(): void
    {
        $this->assertSame('2027-02-28', DeadlineRules::nextDue('annual:02-last', '2026-10-06'));
        $this->assertSame('2028-02-29', DeadlineRules::nextDue('annual:02-last', '2027-03-01'));
        $this->assertSame('2027-02-28', DeadlineRules::nextDue('annual:02-29', '2026-10-06'), 'a day past the month end lands on its last day');
    }

    public function test_several_dates_a_year_pick_the_next_one(): void
    {
        $q = 'dates:01-31,04-30,07-31,10-31';
        $this->assertSame('2026-10-31', DeadlineRules::nextDue($q, '2026-10-06'));
        $this->assertSame('2027-01-31', DeadlineRules::nextDue($q, '2026-11-01'));
    }

    public function test_monthly_and_every_n_months(): void
    {
        $this->assertSame('2026-10-15', DeadlineRules::nextDue('monthly:15', '2026-10-06'));
        $this->assertSame('2026-11-15', DeadlineRules::nextDue('monthly:15', '2026-10-16'));
        $this->assertSame('2027-01-15', DeadlineRules::nextDue('monthly:15', '2026-12-16'));
        $this->assertSame('2026-10-31', DeadlineRules::nextDue('monthly:last', '2026-10-06'));
        $this->assertSame('2026-11-30', DeadlineRules::nextDue('monthly:31', '2026-11-01'));
        $this->assertSame('2027-03-10', DeadlineRules::nextDue('every:6m', '2026-10-06', '2025-03-10'));
        $this->assertNull(DeadlineRules::nextDue('every:6m', '2026-10-06'), 'every N months needs a start date');
    }

    public function test_once_and_bad_rules(): void
    {
        $this->assertSame('2027-01-01', DeadlineRules::nextDue('once:2027-01-01', '2026-10-06'));
        $this->assertNull(DeadlineRules::nextDue('once:2026-01-01', '2026-10-06'));
        $this->assertNull(DeadlineRules::nextDue('', '2026-10-06'));
        foreach (['annual:13-01', 'annual:02-32', 'dates:01-31,xx', 'monthly:0', 'weekly:mon', 'once:2026-02-30'] as $bad) {
            $this->assertFalse(DeadlineRules::valid($bad), $bad);
        }
        $this->assertTrue(DeadlineRules::valid(''), 'not set up yet is allowed');
    }

    public function test_priority_bands_and_words(): void
    {
        $this->assertSame(1, DeadlineRules::priority('2026-10-06', '2026-10-01', 14), 'overdue');
        $this->assertSame(1, DeadlineRules::priority('2026-10-06', '2026-10-09', 14));
        $this->assertSame(2, DeadlineRules::priority('2026-10-06', '2026-10-20', 14));
        $this->assertSame(3, DeadlineRules::priority('2026-10-06', '2026-10-21', 14));
        $this->assertSame('overdue since Oct 1', DeadlineRules::when('2026-10-06', '2026-10-01'));
        $this->assertSame('due today', DeadlineRules::when('2026-10-06', '2026-10-06'));
        $this->assertSame('due in 9 days (Thu Oct 15)', DeadlineRules::when('2026-10-06', '2026-10-15'));
    }

    public function test_snooze_never_passes_the_due_date(): void
    {
        $this->assertSame('2026-10-09', DeadlineRules::snoozeUntil('2026-10-06', '2026-10-31', 3));
        $this->assertSame('2026-10-07', DeadlineRules::snoozeUntil('2026-10-06', '2026-10-08', 7), 'back the day before it is due');
        $this->assertSame('2026-10-13', DeadlineRules::snoozeUntil('2026-10-06', '2026-12-31', 30), 'a week at most');
    }

    public function test_the_page_form_builds_rules_and_reads_them_back(): void
    {
        $this->assertSame('annual:12-31', DeadlineRules::fromForm(['repeat' => 'annual', 'date' => '2026-12-31']));
        $this->assertSame('dates:01-31,04-30', DeadlineRules::fromForm(['repeat' => 'dates', 'dates' => '01-31, 04-30']));
        $this->assertSame('monthly:last', DeadlineRules::fromForm(['repeat' => 'monthly', 'day' => 'last']));
        $this->assertSame('every:6m', DeadlineRules::fromForm(['repeat' => 'every', 'months' => '6']));
        $this->assertSame('', DeadlineRules::fromForm(['repeat' => '']));
        $this->assertSame('Every year, Dec 31', DeadlineRules::describe('annual:12-31'));
        $this->assertSame('Every month on the 15th', DeadlineRules::describe('monthly:15'));
        $this->assertSame('Every year, last day of February', DeadlineRules::describe('annual:02-last'));
        $this->assertSame('Date not set yet', DeadlineRules::describe(''));
    }

    public function test_seeded_rules_all_parse(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../../database/migrations/1171_charlie_deadlines.sql');
        $sql = substr($sql, strpos($sql, 'INSERT IGNORE INTO charlie_deadlines'));
        preg_match_all("/'((?:annual|dates|monthly|every|once):[^']+)'/", $sql, $m);
        $this->assertGreaterThanOrEqual(10, count($m[1]));
        foreach ($m[1] as $rule) $this->assertNotNull(DeadlineRules::nextDue($rule, '2026-10-06'), $rule);
        $this->assertStringNotContainsString('T1', $sql, 'a corporation: no sole-proprietor T1 items');
        $this->assertMatchesRegularExpression("/'corp_instalments'[^;]*, 0\\),/s", $sql, 'instalments start switched off');
    }

    public function test_seeded_on_oct_6_the_first_occurrence_is_never_in_the_past(): void
    {
        $this->assertSame('2026-10-15', DeadlineService::nextOccurrence('monthly:15', null, '2026-10-06'), 'monthly-15th: Oct 15, not Sep 15');
        $this->assertSame('2027-01-31', DeadlineService::nextOccurrence('annual:01-31', null, '2026-10-06'));
        $this->assertSame('2026-11-15', DeadlineService::nextOccurrence('monthly:15', '2026-10-15', '2026-10-06'), 'after one is done: the next');
        $this->assertSame('2026-10-15', DeadlineService::nextOccurrence('monthly:15', '2026-09-15', '2026-11-20'), 'a real miss stays a miss');
    }

    public function test_a_deadline_becomes_a_brief_item_with_a_stable_key(): void
    {
        $it = DeadlineService::item(['slug' => 'van_licence', 'due_date' => '2026-12-31', 'category' => 'licence', 'title' => 'City of Vancouver business licence renewal',
            'when' => 'due Dec 31', 'priority' => 2, 'amount_hint' => null, 'lead_days' => 45, 'overdue' => false]);
        $this->assertSame('charlie:deadline:van_licence:2026-12-31', $it['key']);
        $this->assertSame('charlie:deadline_licence', $it['kind']);
        $this->assertSame('2026-11-16', $it['since']);
        $this->assertSame(['van_licence', '2026-12-31'], DeadlineService::parseKey($it['key']));
        $this->assertNull(DeadlineService::parseKey('sam:lead:1'));
    }

    public function test_year_end_pack_puts_unfinished_work_first(): void
    {
        $lines = AccountantPackService::compose(2025, ['receipts' => 0, 'bank' => 7, 'closing_used' => 0, 'closed' => 0]);
        $this->assertSame('7 bank lines from 2025 not categorised', $lines[0]['text']);
        $this->assertTrue($lines[0]['bad']);
        $this->assertSame('All 2025 receipts approved', $lines[1]['text']);
        $this->assertCount(5, $lines, 'month closing is left out until it has ever been used');
        $with = AccountantPackService::compose(2025, ['receipts' => 1, 'bank' => null, 'closing_used' => 3, 'closed' => 11]);
        $this->assertSame(['1 receipt from 2025 still not approved', '1 month of 2025 not closed'], array_column(array_slice($with, 0, 2), 'text'));
    }
}
