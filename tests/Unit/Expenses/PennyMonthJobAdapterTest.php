<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** PennyMonthJobAdapter — a month's bank line becomes the strip's job with bookkeeper.php's exact bank_decide requests. */
class PennyMonthJobAdapterTest extends TestCase
{
    private function months(): array
    {
        return [
            ['month' => '2026-01', 'locked' => true,  'ready' => false, 'drift' => ['1010' => 0.0], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
            ['month' => '2026-02', 'locked' => false, 'ready' => true,  'drift' => ['1010' => 0.0], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
            ['month' => '2026-03', 'locked' => false, 'ready' => false, 'drift' => ['1010' => -15.37], 'receipts' => 2, 'bank_lines' => 31, 'grace_until' => ''],
            ['month' => '2026-04', 'locked' => false, 'ready' => false, 'drift' => ['1010' => -15.37], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
            ['month' => '2026-05', 'locked' => false, 'ready' => false, 'drift' => ['1010' => 0.0], 'receipts' => 0, 'bank_lines' => 4, 'grace_until' => ''],
            ['month' => '2026-06', 'locked' => false, 'ready' => false, 'drift' => [], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => '2026-07-16'],
        ];
    }

    private function line(array $over = []): array
    {
        return $over + [
            'id' => 4411, 'date' => '2026-03-09', 'type' => 'expense', 'amount' => -61.4, 'description' => 'PURCHASE SHELL #4410 SQUAMISH BC',
            'current' => ['id' => 9, 'code' => '6900', 'name' => 'Uncategorized Expense'],
            'suggestion' => ['account_id' => 31, 'code' => '5200', 'name' => 'Fuel', 'reason' => 'Shell is a vendor you book as fuel', 'source' => 'vendor'],
            'stripe' => false, 'client_payment' => false, 'age' => ['class' => 'timing', 'bucket' => '61-90', 'age' => '246 days', 'why' => ''],
        ];
    }

    public function testFocusIsTheOldestOpenMonthWithLinesWaiting(): void
    {
        $this->assertSame('2026-03', PennyMonthJobAdapter::focusMonth($this->months())['month']);
        $this->assertNull(PennyMonthJobAdapter::focusMonth([['month' => '2026-01', 'locked' => false, 'ready' => true, 'bank_lines' => 3]]));
    }

    public function testOnlyALineWithHerAccountIsOffered(): void
    {
        $this->assertTrue(PennyMonthJobAdapter::proposes($this->line()));
        $this->assertFalse(PennyMonthJobAdapter::proposes($this->line(['suggestion' => null])));
        $this->assertFalse(PennyMonthJobAdapter::proposes($this->line(['suggestion' => ['account_id' => null, 'code' => null, 'name' => null, 'reason' => 'x', 'source' => 'found_receipt']])));
        $this->assertFalse(PennyMonthJobAdapter::proposes($this->line(['stripe' => true])), 'a Stripe payout is split by StripePayoutService');
        $this->assertFalse(PennyMonthJobAdapter::proposes($this->line(['client_payment' => true])), 'a client payment needs the invoice');
    }

    public function testJobCarriesBankDecidesExactRequests(): void
    {
        $month = $this->months()[2];
        $j = PennyMonthJobAdapter::fromLine($this->line(), $month, PennyMonthJobAdapter::monthDiscs($this->months(), '2026-03'), 1, 3);
        $this->assertSame('month', $j['kind']);
        $this->assertSame('bank:4411', $j['key']);
        $this->assertSame(['mode' => 'bank_decide', 'transaction_id' => 4411, 'action' => 'approve', 'account_id' => 31, 'suggested_id' => 31], $j['primary']['request']);
        $this->assertSame(['mode' => 'bank_decide', 'transaction_id' => 4411, 'action' => 'keep', 'suggested_id' => 31], $j['secondary']['request']);
        $this->assertStringContainsString('March', $j['title']);
        $this->assertStringContainsString('31 bank lines', $j['title']);
        $this->assertSame('forming', $j['sure'], 'a vendor reading is not a record');
        $this->assertSame('5200 Fuel', $j['right']['label']);
        $this->assertStringContainsString('now 6900', $j['left']['when']);
        $this->assertCount(1, $j['columns'], 'the frame names both sides: no repeated text in a column');
    }

    public function testTheFrameSaysWhichAccountAndWhatTheEvidenceIs(): void
    {
        $month = $this->months()[2];
        $discs = PennyMonthJobAdapter::monthDiscs($this->months(), '2026-03');
        $j = PennyMonthJobAdapter::fromLine($this->line(), $month, $discs, 1, 1, ['account' => '1010 Chequing Account', 'earlier' => 3]);
        $this->assertSame('1010 Chequing Account', $j['left']['label']);
        $this->assertSame('No receipt · 3 earlier on 5200', $j['right']['when']);
        $this->assertSame('246 days', $j['columns'][0]['top']);
        $bare = PennyMonthJobAdapter::fromLine($this->line(), $month, $discs, 1, 1);
        $this->assertSame('Bank line', $bare['left']['label'], 'no account known: the generic name');
        $this->assertSame('No receipt', $bare['right']['when']);
        $found = PennyMonthJobAdapter::fromLine($this->line(['suggestion' => ['account_id' => 31, 'code' => '5200', 'name' => 'Fuel', 'reason' => 'x', 'source' => 'found_receipt', 'expense_id' => 902]]), $month, $discs, 1, 1);
        $this->assertSame('Receipt #902 found · Approve links it', $found['right']['when']);
        $this->assertSame('5200 + link receipt', $found['primary']['sub'], 'the button says it does more than file a category');
        $this->assertSame('5200 only', PennyMonthJobAdapter::fromLine($this->line(), $month, $discs, 1, 1)['primary']['sub']);
    }

    public function testTheMonthDiscComesAfterTheButtonsWithOneLineFewer(): void
    {
        $j = PennyMonthJobAdapter::fromLine($this->line(), $this->months()[2], PennyMonthJobAdapter::monthDiscs($this->months(), '2026-03'), 1, 1);
        $this->assertSame('March', $j['after']['label']);
        $this->assertSame('30 lines left', $j['after']['top']);
        $this->assertSame('−15.37', $j['after']['bottom']);
        $this->assertSame('differs', $j['after']['state']);
    }

    public function testAFoundReceiptIsLinkedByTheSameRequest(): void
    {
        $l = $this->line(['suggestion' => ['account_id' => 31, 'code' => '5200', 'name' => 'Fuel', 'reason' => 'Matches your Shell receipt', 'source' => 'found_receipt', 'expense_id' => 902]]);
        $j = PennyMonthJobAdapter::fromLine($l, $this->months()[2], [], 1, 1);
        $this->assertSame(902, $j['primary']['request']['expense_id']);
    }

    public function testConfidenceIsHeldOnlyBehindARuleOrALesson(): void
    {
        $this->assertSame('held', PennyMonthJobAdapter::sureOf(['source' => 'rule']));
        $this->assertSame('held', PennyMonthJobAdapter::sureOf(['source' => 'learned']));
        $this->assertSame('forming', PennyMonthJobAdapter::sureOf(['source' => 'fact']));
        $this->assertSame('forming', PennyMonthJobAdapter::sureOf([]));
    }

    public function testDiscsTellExactLockedCarriedAndOpen(): void
    {
        $d = PennyMonthJobAdapter::monthDiscs($this->months(), '2026-03');
        $by = array_column($d, null, 'month');
        $this->assertSame(['same', 'locked'], [$by['2026-01']['state'], $by['2026-01']['bottom']]);
        $this->assertSame(['same', 'exact'], [$by['2026-02']['state'], $by['2026-02']['bottom']]);
        $this->assertSame(['differs', '−15.37'], [$by['2026-03']['state'], $by['2026-03']['bottom']]);
        $this->assertTrue($by['2026-03']['focus']);
        $this->assertSame(['−15.37', true], [$by['2026-04']['bottom'], $by['2026-04']['carried']], 'the same gap the month before is carried, not new');
        $this->assertFalse($by['2026-03']['carried']);
        $this->assertSame(['missing', '4 lines'], [$by['2026-05']['state'], $by['2026-05']['bottom']]);
        $this->assertSame(['info', 'waiting'], [$by['2026-06']['state'], $by['2026-06']['bottom']]);
        $this->assertFalse($by['2026-04']['focus']);
    }

    public function testRailIsCappedAtTheNewestMonths(): void
    {
        $m = [];
        for ($i = 1; $i <= 14; $i++) $m[] = ['month' => sprintf('2027-%02d', $i > 12 ? 12 : $i), 'locked' => true, 'ready' => false, 'drift' => [], 'receipts' => 0, 'bank_lines' => 0];
        $this->assertCount(PennyMonthJobAdapter::MAX_DISCS, PennyMonthJobAdapter::monthDiscs($m, null));
    }

    public function testTheGapGoesToTheListOnlyWhenItsOriginMonthIsClean(): void
    {
        $m = [
            ['month' => '2026-01', 'locked' => false, 'ready' => false, 'drift' => ['a' => 7.01], 'receipts' => 0, 'bank_lines' => 10, 'grace_until' => ''],
            ['month' => '2026-02', 'locked' => false, 'ready' => false, 'drift' => ['a' => 7.01], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
        ];
        $this->assertNull(PennyMonthJobAdapter::askMonth($m, '2026-11-10'), 'January still has lines to sort; February only carries its gap');
        $m[0]['bank_lines'] = 0;
        $this->assertSame('2026-01', PennyMonthJobAdapter::askMonth($m, '2026-11-10'));
        $m[0]['grace_until'] = '2026-12-01';
        $this->assertNull(PennyMonthJobAdapter::askMonth($m, '2026-11-10'), 'not while late statements may still come');
    }

    public function testAMonthAlreadyOnTheListDoesNotHideTheNextNewGap(): void
    {
        $m = [
            ['month' => '2026-01', 'locked' => false, 'ready' => false, 'drift' => ['a' => 7.01], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
            ['month' => '2026-02', 'locked' => false, 'ready' => false, 'drift' => ['a' => 7.01], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
            ['month' => '2026-03', 'locked' => false, 'ready' => false, 'drift' => ['a' => -15.37], 'receipts' => 0, 'bank_lines' => 0, 'grace_until' => ''],
        ];
        $asked = static fn(string $ym): bool => $ym === '2026-01';
        $this->assertSame('2026-03', PennyMonthJobAdapter::askMonth($m, '2026-11-10', $asked), 'January is on the list; March is a new gap, not February\'s carry');
        $this->assertNull(PennyMonthJobAdapter::askMonth($m, '2026-11-10', static fn() => true));
    }

    public function testTheGapJobPostsOnlyTheMonthAndNeverEmailsAnyone(): void
    {
        $draft = ['month' => '2026-01', 'account' => '1010 Chequing Account', 'amount' => 7.01, 'question' => 'q', 'evidence' => 'Filed FY2025 cash is $11,017.00; the Dec 31 statements add up to $11,009.99 ($7.01 less).', 'evidence_short' => 'Filed $11,017.00 · statements $11,009.99'];
        $discs = PennyMonthJobAdapter::monthDiscs($this->months(), '2026-03');
        $j = PennyMonthJobAdapter::fromGap($draft, $this->months()[2], $discs, 1, 1);
        $this->assertSame('January is $7.01 off the bank — nothing Penny can match', $j['title']);
        $this->assertSame(['mode' => 'accountant_question', 'month' => '2026-01', 'action' => 'add'], $j['primary']['request'], 'the words are written by the server, not sent');
        $this->assertSame('drop', $j['secondary']['request']['action']);
        $this->assertSame("Ricole's list", $j['right']['label']);
        $this->assertSame('Filed $11,017.00 · statements $11,009.99', $j['right']['when'], 'short on the rail; the whole sentence is in the question');
        $this->assertSame('on the list', $j['after']['top']);
        $this->assertSame([], $j['columns']);
    }

    public function testAReceiptFromTheClosedYearIsNeverOfferedAsALink(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Accounting/Services/MonthCloseService.php';
        $this->assertTrue(PennyMonthJobAdapter::inClosedYear('2025-12-30'), 'University Landscapers, Dec 30 2025: its books are in the filed year');
        $this->assertTrue(PennyMonthJobAdapter::inClosedYear(''), 'an unknown date is not trusted');
        $this->assertFalse(PennyMonthJobAdapter::inClosedYear('2026-01-02'));
    }
}
