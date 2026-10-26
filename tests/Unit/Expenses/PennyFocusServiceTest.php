<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The three rows on Penny's card (Tim, 2026-10-26). */
class PennyFocusServiceTest extends TestCase
{
    public function testAnUnknownVendorIsWorthMoreThanATrustedOne(): void
    {
        $this->assertGreaterThan(PennyFocusService::teachScore(5, ['trusted' => true]), PennyFocusService::teachScore(5, null));
        $this->assertSame(0.0, PennyFocusService::teachScore(0, null));
    }

    public function testTheBiggestUntrustedGroupWins(): void
    {
        $groups = [
            ['vendor' => 'Shell', 'n' => 20, 'expense_id' => 1, 'suggestion_id' => 11],
            ['vendor' => 'Home Depot', 'n' => 8, 'expense_id' => 2, 'suggestion_id' => 12],
            ['vendor' => 'Southlands Nursery', 'n' => 6, 'expense_id' => 3, 'suggestion_id' => 13],
        ];
        $strength = ['shell' => ['trusted' => true, 'pct' => 100, 'seen' => 40],
                     'home depot' => ['trusted' => false, 'pct' => 60, 'seen' => 30]];
        $pick = PennyFocusService::pickTeach($groups, $strength);
        // Shell: 20 x 0.2 = 4; Home Depot: 8 x (0.6 + 0.4 x 0.6) = 6.72; Southlands (unknown): 6 x 1.25 = 7.5
        $this->assertSame('Southlands Nursery', $pick['vendor']);
        $this->assertNull(PennyFocusService::pickTeach([], []));
    }

    public function testTheTeachReasonSaysWhyInPlainWords(): void
    {
        $g = ['vendor' => 'Home Depot', 'n' => 8];
        $this->assertStringContainsString('8 Home Depot receipts are waiting', PennyFocusService::whyTeach($g, ['trusted' => false, 'pct' => 60, 'seen' => 30]));
        $this->assertStringContainsString('right 60% of the time', PennyFocusService::whyTeach($g, ['trusted' => false, 'pct' => 60, 'seen' => 30]));
        $this->assertStringContainsString('haven\'t learned Home Depot yet', PennyFocusService::whyTeach($g, null));
        $this->assertStringContainsString('only Home Depot receipt', PennyFocusService::whyTeach(['vendor' => 'Home Depot', 'n' => 1], null));
    }

    public function testAWordLikeAmountIsAReadingMistakeNotAShop(): void
    {
        $this->assertTrue(PennyFocusService::isJunkVendor('AMOUNT'));
        $this->assertTrue(PennyFocusService::isJunkVendor(' total '));
        $this->assertFalse(PennyFocusService::isJunkVendor('Shell'));
        $w = PennyFocusService::whyTeach(['vendor' => 'AMOUNT', 'n' => 10], null);
        $this->assertStringContainsString('10 receipts have "AMOUNT" read as the shop name', $w);
    }

    public function testTheMonthReasonNamesTheGapTheAmountAndTheGuess(): void
    {
        $w = PennyFocusService::whyMonth(['month' => '2026-02', 'amount_off' => -229.75, 'receipt_amount' => -150.0, 'vendor' => 'Shell', 'date' => '2026-02-17',
                                          'hint' => 'Could it be the $150.00 bank line on Feb 14 (POINTOFSALE)?']);
        $this->assertStringContainsString('February ends $229.75 below the bank', $w);
        $this->assertStringContainsString('($150.00) is booked as paid from chequing', $w);
        $this->assertStringContainsString('Feb 14', $w);
    }

    public function testAHintFromAClosedYearSaysSo(): void
    {
        $c = ['amount' => 150.0, 'date' => '2025-05-14', 'description' => 'POINT OF SALE (SHELL C01303 VANCOUVER BCCA)'];
        $this->assertStringContainsString('May 14, 2025', PennyFocusService::hintFor($c, '2026-02-17'));
        $this->assertStringContainsString('filed 2025 books', PennyFocusService::hintFor($c, '2026-02-17'));
        $this->assertStringNotContainsString('filed 2025', PennyFocusService::hintFor(['date' => '2026-02-14'] + $c, '2026-02-17'));
    }

    public function testMoneyPrefersDepositsThenGstThenNothing(): void
    {
        $pm = ['waiting' => 2, 'waiting_total' => 865.0, 'high' => 2, 'url' => '/x'];
        $this->assertSame('2 deposits matched to invoices', PennyFocusService::moneyRow($pm, 10.5)['title']);
        $this->assertStringContainsString('$10.50 of GST', PennyFocusService::moneyRow(null, 10.5)['title']);
        $this->assertNull(PennyFocusService::moneyRow(['waiting' => 0], 0.0));
    }

    public function testALinkOnTheCardStaysInsideTheCrm(): void
    {
        $this->assertSame('/crm/accounting/payment-match.php', PennyFocusService::internalUrl('/crm/accounting/payment-match.php'));
        $this->assertSame('/crm/expenses_appstack.php?edit=18', PennyFocusService::internalUrl('/crm/expenses_appstack.php?edit=18'));
        foreach (['javascript:alert(1)', '//evil.example/x', 'https://evil.example', '/\\evil.example', ''] as $bad) $this->assertSame('', PennyFocusService::internalUrl($bad), $bad);
        $pm = ['waiting' => 1, 'waiting_total' => 10.0, 'high' => 0, 'url' => 'javascript:alert(document.domain)'];
        $this->assertSame('', PennyFocusService::moneyRow($pm, 0.0)['url']);
    }

    public function testACachedRowIsUsedOnlyWhenItHasWhatTheCardPrints(): void
    {
        $this->assertTrue(PennyFocusService::validRow(['kind' => 'teach', 'title' => 'a', 'why' => 'b']));
        foreach (['bad', ['kind' => 'x', 'title' => 'a', 'why' => 'b'], ['kind' => 'teach', 'title' => 1, 'why' => 'b'], ['kind' => 'month', 'title' => 'a'], null] as $bad) {
            $this->assertFalse(PennyFocusService::validRow($bad));
        }
    }
}

