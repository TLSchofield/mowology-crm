<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's missing-receipt chaser: what is never chased, who is asked, when, and in what words.
 */
class MissingReceiptServiceTest extends TestCase
{
    private const RULES = [
        ['match_on' => 'account_code', 'pattern' => '6800', 'label' => 'Bank charges & fees', 'active' => 1],
        ['match_on' => 'account_code', 'pattern' => '2*', 'label' => 'Liabilities', 'active' => 1],
        ['match_on' => 'account_name', 'pattern' => 'insurance', 'label' => 'Insurance', 'active' => 1],
        ['match_on' => 'description', 'pattern' => 'CRA ', 'label' => 'CRA remittance', 'active' => 1],
        ['match_on' => 'description', 'pattern' => 'PAYROLL', 'label' => 'Payroll', 'active' => 0],
    ];

    private function tz(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('America/Vancouver'));
    }

    public function test_exempt_rules_by_code_prefix_name_and_description(): void
    {
        $this->assertSame('Bank charges & fees', MissingReceiptService::exemptReason(['account_code' => '6800', 'description' => 'MONTHLY FEE'], self::RULES));
        $this->assertSame('Liabilities', MissingReceiptService::exemptReason(['account_code' => '2610', 'description' => 'TD LOAN'], self::RULES));
        $this->assertSame('Insurance', MissingReceiptService::exemptReason(['account_code' => '6110', 'account_name' => 'Vehicle Insurance', 'description' => 'X'], self::RULES));
        $this->assertSame('CRA remittance', MissingReceiptService::exemptReason(['account_code' => '6900', 'description' => 'CRA GST REMIT'], self::RULES));
    }

    public function test_a_purchase_is_not_exempt_and_a_word_inside_another_does_not_count(): void
    {
        $this->assertNull(MissingReceiptService::exemptReason(['account_code' => '5200', 'account_name' => 'Materials & Supplies', 'description' => 'POS PURCHASE LAWN BOY'], self::RULES));
        $this->assertNull(MissingReceiptService::exemptReason(['account_code' => '5200', 'description' => 'MICHAELS CRAFTS'], self::RULES), '"CRA " must not hit CRAFTS');
        $this->assertNull(MissingReceiptService::exemptReason(['account_code' => '5100', 'description' => 'WAVE PAYROLL'], self::RULES), 'a rule turned off');
        $this->assertNull(MissingReceiptService::exemptReason(['account_code' => '1500', 'description' => 'X'], self::RULES), '"2*" is a prefix, not a contains');
    }

    public function test_vendor_label_reads_like_a_person_would_say_it(): void
    {
        $this->assertSame('Lawn Boy', MissingReceiptService::vendorLabel('POS PURCHASE LAWN BOY #123 VANCOUVER BC'));
        $this->assertSame('Home Depot', MissingReceiptService::vendorLabel('Point of sale - Interac HOME DEPOT 7042 BURNABY'));
        $this->assertSame('Shell', MissingReceiptService::vendorLabel('VISA DEBIT PURCHASE SHELL 0534703381'));
        $this->assertSame('A card charge', MissingReceiptService::vendorLabel('  '));
    }

    public function test_charge_time_and_card_last4(): void
    {
        $this->assertSame('12:34', MissingReceiptService::chargeTime('LAWN BOY 12:34 VANCOUVER'));
        $this->assertNull(MissingReceiptService::chargeTime('LAWN BOY 1234 VANCOUVER'));
        $this->assertSame('4417', MissingReceiptService::cardLast4(null, 'PURCHASE ****4417 LAWN BOY'));
        $this->assertSame('9921', MissingReceiptService::cardLast4('4500 1234 5678 9921', 'LAWN BOY'));
        $this->assertNull(MissingReceiptService::cardLast4(null, 'LAWN BOY'));
    }

    public function test_place_matches_vendor_words_or_squashed_name(): void
    {
        $this->assertTrue(MissingReceiptService::placeMatches(['name' => 'Lawn Boy', 'vendor_match' => null], 'POS LAWNBOY #12'));
        $this->assertTrue(MissingReceiptService::placeMatches(['name' => 'Transfer Station', 'vendor_match' => 'vancouver landfill|transfer stn'], 'CITY OF VAN TRANSFER STN'));
        $this->assertFalse(MissingReceiptService::placeMatches(['name' => 'Yard', 'vendor_match' => ''], 'SHELL 123'));
    }

    public function test_attribution_order_card_then_truck_then_clock_then_owner(): void
    {
        $card = ['user_id' => 7, 'name' => 'Nigel Smith', 'last4' => '4417'];
        $truck = [['user_id' => 9, 'name' => 'Sam Lee', 'place' => 'Lawn Boy']];
        $clock = [['user_id' => 1, 'name' => 'Tim S'], ['user_id' => 9, 'name' => 'Sam Lee']];

        $a = MissingReceiptService::attribute($card, $truck, $clock, 1, 'Tim S');
        $this->assertSame([7, 'card'], [$a['user_id'], $a['basis']]);
        $this->assertStringContainsString('••4417', $a['note']);

        $a = MissingReceiptService::attribute(null, $truck, $clock, 1, 'Tim S');
        $this->assertSame([9, 'truck'], [$a['user_id'], $a['basis']]);

        $a = MissingReceiptService::attribute(null, [], $clock, 1, 'Tim S', '12:34');
        $this->assertSame([9, 'clock'], [$a['user_id'], $a['basis']], 'the owner clocked in does not count — Sam is the only crew');
        $this->assertStringContainsString('at 12:34', $a['note']);

        $two = [['user_id' => 7, 'name' => 'Nigel'], ['user_id' => 9, 'name' => 'Sam']];
        $a = MissingReceiptService::attribute(null, [], $two, 1, 'Tim S');
        $this->assertSame([1, 'owner'], [$a['user_id'], $a['basis']], 'two people working: ask Tim');
        $this->assertStringContainsString('Nigel and Sam', $a['note']);

        $a = MissingReceiptService::attribute(null, [], [], 1, 'Tim S');
        $this->assertSame([1, 'owner'], [$a['user_id'], $a['basis']]);
    }

    public function test_two_drivers_at_the_place_fall_through_to_the_clock(): void
    {
        $truck = [['user_id' => 7, 'name' => 'Nigel', 'place' => 'Lawn Boy'], ['user_id' => 9, 'name' => 'Sam', 'place' => 'Lawn Boy']];
        $a = MissingReceiptService::attribute(null, $truck, [['user_id' => 9, 'name' => 'Sam']], 1);
        $this->assertSame([9, 'clock'], [$a['user_id'], $a['basis']]);
    }

    public function test_pennys_question_and_push_wording(): void
    {
        $i = ['id' => 5, 'charge_date' => '2026-10-07', 'charge_time' => '12:34', 'amount' => 84, 'vendor_label' => 'Lawn Boy'];
        $this->assertSame('Hi Nigel — Lawn Boy charged $84.00 on Oct 7 at 12:34. Do you have the receipt?', MissingReceiptService::ask($i, 'Nigel'));
        $i['charge_time'] = null;
        $this->assertSame('Hi — Lawn Boy charged $84.00 on Oct 7. Do you have the receipt?', MissingReceiptService::ask($i));

        $p = MissingReceiptService::pushFor([$i]);
        $this->assertSame('/crm/my-team.php?penny=missing&id=5', $p['data']['url']);
        $this->assertSame(5, $p['data']['missing_id']);

        $p = MissingReceiptService::pushFor([$i, ['id' => 6, 'charge_date' => '2026-10-06', 'amount' => 16, 'vendor_label' => 'Shell']]);
        $this->assertSame('Penny: 2 receipts missing', $p['title']);
        $this->assertSame('/crm/my-team.php?penny=missing', $p['data']['url']);
        $this->assertStringContainsString('$100.00', $p['body']);
    }

    public function test_weekly_summary_text(): void
    {
        $this->assertSame('Still missing: 6 receipts, $412', MissingReceiptService::summaryText(6, 412.30));
        $this->assertSame('Still missing: 1 receipt, $84.00 · 2 marked "no receipt" ($31.50, no GST claim)', MissingReceiptService::summaryText(1, 84, 2, 31.5));
        $this->assertSame('Every card charge has its receipt', MissingReceiptService::summaryText(0, 0));
    }

    public function test_quiet_hours_wrap_midnight(): void
    {
        foreach ([21, 22, 23, 0, 3, 6] as $h) $this->assertTrue(MissingReceiptService::isQuiet($h), "{$h}:00 is quiet");
        foreach ([7, 8, 12, 20] as $h) $this->assertFalse(MissingReceiptService::isQuiet($h), "{$h}:00 is not quiet");
        $this->assertTrue(MissingReceiptService::isQuiet(13, 12, 14), 'a same-day window');
        $this->assertFalse(MissingReceiptService::isQuiet(3, 5, 5), 'start = end: never quiet');
    }

    public function test_first_nudge_the_morning_after_now_if_late_never_if_old(): void
    {
        $now = $this->tz('2026-10-07 15:00');
        $this->assertSame('2026-10-08 08:00', MissingReceiptService::firstNudgeAt('2026-10-07', $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-07 15:00', MissingReceiptService::firstNudgeAt('2026-10-01', $now)->format('Y-m-d H:i'), 'imported late: nudge now');
        $this->assertNull(MissingReceiptService::firstNudgeAt('2026-08-01', $now), 'too old to push about');
    }

    public function test_second_nudge_three_days_later_then_none(): void
    {
        $now = $this->tz('2026-10-08 08:10');
        $this->assertSame('2026-10-11 08:10', MissingReceiptService::nextAfter(1, $now)->format('Y-m-d H:i'));
        $this->assertNull(MissingReceiptService::nextAfter(2, $now));
    }

    public function test_clear_match_within_three_days_or_strong(): void
    {
        $this->assertSame(11, MissingReceiptService::clearMatch([['expense_id' => 11, 'date' => '2026-10-09', 'confidence' => 60]], '2026-10-07')['expense_id']);
        $this->assertNull(MissingReceiptService::clearMatch([['expense_id' => 11, 'date' => '2026-10-15', 'confidence' => 60]], '2026-10-07'));
        $this->assertSame(12, MissingReceiptService::clearMatch([['expense_id' => 12, 'date' => '2026-10-15', 'confidence' => 85]], '2026-10-07')['expense_id']);
        $this->assertNull(MissingReceiptService::clearMatch([], '2026-10-07'));
    }

    public function test_a_new_receipt_closes_the_only_item_or_its_savers_own(): void
    {
        $this->assertSame(3, MissingReceiptService::pickForReceipt([['id' => 3, 'user_id' => 9]], 7));
        $two = [['id' => 3, 'user_id' => 9], ['id' => 4, 'user_id' => 7]];
        $this->assertSame(4, MissingReceiptService::pickForReceipt($two, 7));
        $this->assertNull(MissingReceiptService::pickForReceipt($two, 1), 'two candidates, neither the saver’s: leave it');
        $this->assertNull(MissingReceiptService::pickForReceipt([], 7));
    }

    public function test_week_key(): void
    {
        $this->assertSame('2026-W41', MissingReceiptService::weekKey($this->tz('2026-10-05 09:00')));
    }
}
