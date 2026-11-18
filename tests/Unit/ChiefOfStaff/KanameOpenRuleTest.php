<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KanameOpenRuleTest extends TestCase
{
    private const TODAY = '2026-11-14';

    private function brief(int $lines, array $urgent = []): array
    {
        return ['items' => array_fill(0, $lines, ['key' => 'x']), 'urgent' => $urgent];
    }

    private function u(string $text): array
    {
        return ['id' => KanameOpenRule::id('bad:' . $text), 'text' => $text];
    }

    public function testFirstOpenWithLinesShows(): void
    {
        $v = KanameOpenRule::verdict($this->brief(2), self::TODAY, null, []);
        $this->assertTrue($v['open_now']);
        $this->assertSame('first_open', $v['reason']);
        $this->assertSame(self::TODAY, $v['day']);
    }

    public function testFirstOpenOfANewDayShows(): void
    {
        $v = KanameOpenRule::verdict($this->brief(1), self::TODAY, '2026-11-13', []);
        $this->assertSame('first_open', $v['reason']);
    }

    public function testEmptyBriefIsNotShown(): void
    {
        $v = KanameOpenRule::verdict($this->brief(0), self::TODAY, null, []);
        $this->assertFalse($v['open_now']);
        $this->assertSame('empty', $v['reason']);
    }

    public function testSecondOpenSameDayIsQuiet(): void
    {
        $v = KanameOpenRule::verdict($this->brief(3), self::TODAY, self::TODAY, []);
        $this->assertFalse($v['open_now']);
        $this->assertSame('already_shown', $v['reason']);
    }

    public function testNewUrgentBringsItBackSameDay(): void
    {
        $a = $this->u('Overdue: GST return');
        $v = KanameOpenRule::verdict($this->brief(0, [$a]), self::TODAY, self::TODAY, []);
        $this->assertTrue($v['open_now']);
        $this->assertSame('urgent', $v['reason']);
        $this->assertSame([$a['id']], $v['urgent_ids']);
    }

    public function testUrgentAlreadyShownStaysQuiet(): void
    {
        $a = $this->u('Overdue: GST return');
        $v = KanameOpenRule::verdict($this->brief(2, [$a]), self::TODAY, self::TODAY, [$a['id']]);
        $this->assertFalse($v['open_now']);
    }

    public function testResolvedUrgentDoesNotReshow(): void
    {
        // the phone showed A earlier; A is gone now and nothing new: no card
        $a = $this->u('A');
        $v = KanameOpenRule::verdict($this->brief(1), self::TODAY, self::TODAY, [$a['id']]);
        $this->assertFalse($v['open_now']);
    }

    public function testOnlyTheNewUrgentCounts(): void
    {
        $a = $this->u('A'); $b = $this->u('B');
        $v = KanameOpenRule::verdict($this->brief(1, [$a, $b]), self::TODAY, self::TODAY, [$a['id']]);
        $this->assertTrue($v['open_now']);
        $this->assertSame([$a['id'], $b['id']], $v['urgent_ids']);
    }

    public function testUrgentFromBadNewsSkipsUnreachableHeads(): void
    {
        $view = ['bad' => ["I couldn't reach Penny — their part of this brief is missing.", 'Payment failed: $900'], 'one' => null, 'rest' => []];
        $u = KanameOpenRule::urgent($view, self::TODAY);
        $this->assertCount(1, $u);
        $this->assertSame('Payment failed: $900', $u[0]['text']);
    }

    public function testUrgentIncludesDeadlineWithinTwoDays(): void
    {
        $soon = ['key' => 'charlie:deadline:tax:2026-11-16', 'text' => 'GST due in 2 days'];
        $later = ['key' => 'charlie:deadline:tax:2026-11-17', 'text' => 'WCB due in 3 days'];
        $u = KanameOpenRule::urgent(['bad' => [], 'one' => $soon, 'rest' => [$later]], self::TODAY);
        $this->assertSame(['GST due in 2 days'], array_column($u, 'text'));
    }

    public function testUrgentIdIsStableAcrossBuilds(): void
    {
        $view = ['bad' => ['Payment failed: $900'], 'one' => null, 'rest' => []];
        $this->assertSame(KanameOpenRule::urgent($view, self::TODAY), KanameOpenRule::urgent($view, '2026-11-15'));
    }

    public function testParseShownKeepsOnlyWellFormedIdsAndCaps(): void
    {
        $this->assertSame(['a1b2c3d4e5'], KanameOpenRule::parseShown('a1b2c3d4e5, nonsense,A1B2C3D4E5,'));
        $many = implode(',', array_map(fn($i) => KanameOpenRule::id((string)$i), range(1, 80)));
        $this->assertCount(KanameOpenRule::MAX_SHOWN, KanameOpenRule::parseShown($many));
        $this->assertSame([], KanameOpenRule::parseShown(null));
    }

    public function testUrgentListIsCappedBelowWhatAPhoneRemembers(): void
    {
        $bad = array_map(fn($i) => "Payment failed: #$i", range(1, 70));
        $u = KanameOpenRule::urgent(['bad' => $bad, 'one' => null, 'rest' => []], self::TODAY);
        $this->assertCount(KanameOpenRule::MAX_URGENT, $u);
        $this->assertLessThan(KanameOpenRule::MAX_SHOWN, KanameOpenRule::MAX_URGENT);
    }
}
