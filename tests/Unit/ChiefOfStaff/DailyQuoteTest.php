<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DailyQuoteTest extends TestCase
{
    public function testSameQuoteAllDayAndChangesNextDay(): void
    {
        $a = DailyQuote::forDate('2026-10-11');
        $this->assertSame($a, DailyQuote::forDate('2026-10-11'));
        $this->assertNotSame($a, DailyQuote::forDate('2026-10-12'));
        $this->assertNotSame('', $a['text']);
        $this->assertNotSame('', $a['by']);
    }

    public function testEveryQuoteIsShortAndCredited(): void
    {
        foreach (DailyQuote::QUOTES as [$text, $who]) {
            $this->assertLessThanOrEqual(110, mb_strlen($text), $text);
            $this->assertNotSame('', trim($who), $text);
            $this->assertStringNotContainsString('!', $text, 'no exclamation marks (brand voice)');
        }
    }
}
