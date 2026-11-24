<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** An approved debit/card receipt must have its statement line (Tim, 2026-10-15). */
class ReceiptBankCheckTest extends TestCase
{
    private static function line(int $id, bool $free = true, bool $vendor = true, ?int $other = null, bool $samePhoto = false,
                                 bool $own = true, bool $purchase = true): array
    {
        return ['id' => $id, 'free' => $free, 'vendor' => $vendor, 'other_receipt' => $other, 'same_photo' => $samePhoto,
                'own' => $own, 'purchase' => $purchase];
    }

    public function test_which_statement_a_method_spends_from(): void
    {
        $this->assertSame('1010', ReceiptBankCheck::statementCodeFor('debit'));
        $this->assertSame('1010', ReceiptBankCheck::statementCodeFor(''), 'no method = the debit card');
        $this->assertSame('2400', ReceiptBankCheck::statementCodeFor('credit_card'));
        $this->assertNull(ReceiptBankCheck::statementCodeFor('cash'));
        $this->assertNull(ReceiptBankCheck::statementCodeFor('cheque'));
        $this->assertNull(ReceiptBankCheck::statementCodeFor('etransfer'));
        $this->assertNull(ReceiptBankCheck::statementCodeFor('paid_personally'));
    }

    public function test_it_waits_until_the_statement_is_in(): void
    {
        $this->assertSame('wait', ReceiptBankCheck::decide(false, [], 0, null)['outcome']);
        $this->assertSame('wait', ReceiptBankCheck::decide(false, [self::line(1)], 0, null)['outcome'], 'even with a line: the rest of the statement is not in');
    }

    public function test_one_clear_line_is_linked(): void
    {
        $this->assertSame(['outcome' => 'link', 'line' => 7], ReceiptBankCheck::decide(true, [self::line(7)], 0, null));
    }

    public function test_anything_unclear_is_asked_not_moved(): void
    {
        $this->assertSame('ask', ReceiptBankCheck::decide(true, [self::line(7, true, false)], 0, null)['outcome'], 'name differs (Mangia\'s vs Jo\'s)');
        $this->assertSame('ask', ReceiptBankCheck::decide(true, [self::line(7), self::line(8)], 0, null)['outcome'], 'two lines');
        $this->assertSame('ask', ReceiptBankCheck::decide(true, [self::line(7)], 1, null)['outcome'], 'another receipt could be it');
        $r = ReceiptBankCheck::decide(true, [self::line(7, false, true, 371)], 0, null);
        $this->assertSame(['outcome' => 'ask', 'line' => 7, 'receipt' => 371], $r, 'two receipts, one line');
    }

    public function test_the_same_photo_twice_is_a_duplicate_even_before_the_statement(): void
    {
        $this->assertSame(['outcome' => 'duplicate', 'receipt' => 371], ReceiptBankCheck::decide(false, [], 0, 371));
        $this->assertSame(['outcome' => 'duplicate', 'receipt' => 371], ReceiptBankCheck::decide(true, [self::line(7, false, true, 371, true)], 0, null));
    }

    public function test_nothing_anywhere_goes_back_to_draft(): void
    {
        $this->assertSame(['outcome' => 'not_found'], ReceiptBankCheck::decide(true, [], 0, null));
    }

    public function test_a_line_on_another_account_or_a_transfer_is_asked_never_linked(): void
    {
        $r = ReceiptBankCheck::decide(true, [self::line(7, true, true, null, false, false)], 0, null);
        $this->assertSame(['outcome' => 'ask', 'line' => 7, 'elsewhere' => true], $r, 'debit receipt, line on the card');
        $this->assertSame('ask', ReceiptBankCheck::decide(true, [self::line(7, true, true, null, false, true, false)], 0, null)['outcome'], 'a transfer line');
    }

    public function test_coverage_joins_consecutive_statements(): void
    {
        $jan = ['2026-01-01', '2026-01-31'];
        $feb = ['2026-02-01', '2026-02-28'];
        $this->assertTrue(ReceiptBankCheck::spans([$feb, $jan], '2026-01-29', '2026-02-03'), 'month-end receipt: January + February');
        $this->assertFalse(ReceiptBankCheck::spans([$jan], '2026-01-29', '2026-02-03'), 'February not in yet: wait');
        $this->assertFalse(ReceiptBankCheck::spans([$jan, ['2026-02-05', '2026-02-28']], '2026-01-29', '2026-02-06'), 'a gap');
        $this->assertTrue(ReceiptBankCheck::spans([['2025-12-29', '2026-01-26'], ['2026-01-27', '2026-02-25']], '2026-01-24', '2026-01-29'), 'card periods');
        $this->assertFalse(ReceiptBankCheck::spans([], '2026-01-24', '2026-01-29'));
    }
}
