<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** "Recent conversation" on the contact page: merging, the 90-day window, de-duplication, the cut. */
class ContactTimelineServiceTest extends TestCase
{
    private const SINCE = '2026-07-09 00:00:00';

    private static function e(string $at, string $source = 'message', string $dir = 'in', string $channel = 'email', string $sum = ''): array
    {
        return ContactTimelineService::entry($at, $dir, $channel, $sum ?: $at, null, $source);
    }

    public function testMergeIsNewestFirstAcrossSources(): void
    {
        $r = ContactTimelineService::merge([
            [self::e('2026-10-05 09:00:00'), self::e('2026-09-01 10:00:00')],
            [self::e('2026-10-01 10:00:00', 'quote', 'out', 'quote')],
            [self::e('2026-10-03 12:00:00', 'payment', 'in', 'payment')],
        ], self::SINCE, 90);
        $this->assertSame(['2026-10-05 09:00:00', '2026-10-03 12:00:00', '2026-10-01 10:00:00', '2026-09-01 10:00:00'], array_column($r['entries'], 'at'));
        $this->assertSame(4, $r['total']);
    }

    public function testMergeDropsEntriesOlderThanTheWindow(): void
    {
        $r = ContactTimelineService::merge([[self::e('2026-07-08 23:59:59'), self::e('2026-07-09 00:00:00')]], self::SINCE, 90);
        $this->assertSame(['2026-07-09 00:00:00'], array_column($r['entries'], 'at'));
    }

    public function testMergeCutsToMaxButCountsAll(): void
    {
        $list = [];
        for ($d = 1; $d <= 20; $d++) $list[] = self::e(sprintf('2026-09-%02d 10:00:00', $d));
        $r = ContactTimelineService::merge([$list], self::SINCE, 15);
        $this->assertCount(15, $r['entries']);
        $this->assertSame(20, $r['total']);
        $this->assertSame('2026-09-20 10:00:00', $r['entries'][0]['at']);
    }

    public function testSamSendAlreadyLoggedAsAMessageIsShownOnce(): void
    {
        $r = ContactTimelineService::merge([
            [self::e('2026-10-01 10:02:00', 'message', 'out', 'email')],
            [self::e('2026-10-01 10:00:00', 'sam', 'out', 'email'), self::e('2026-10-02 10:00:00', 'yui', 'out', 'email')],
        ], self::SINCE, 90);
        $this->assertSame(['yui', 'message'], array_column($r['entries'], 'source'));
    }

    public function testSendOnAnotherChannelIsKept(): void
    {
        $r = ContactTimelineService::merge([
            [self::e('2026-10-01 10:02:00', 'message', 'out', 'email')],
            [self::e('2026-10-01 10:00:00', 'sam', 'out', 'sms')],
        ], self::SINCE, 90);
        $this->assertCount(2, $r['entries']);
    }

    public function testSameTimeKeepsSourceOrder(): void
    {
        $r = ContactTimelineService::merge([[self::e('2026-10-01 10:00:00', 'quote', 'out', 'quote', 'sent')], [self::e('2026-10-01 10:00:00', 'message', 'out', 'email', 'mail')]], self::SINCE, 90);
        $this->assertSame(['sent', 'mail'], array_column($r['entries'], 'summary'));
    }

    public function testSnippetKeepsOurOwnNumber(): void
    {
        $this->assertSame('Nov 16 for the hedge. Call (778) 846-9273.', ContactTimelineService::snippet('Nov 16 for the hedge. Call (778) 846-9273.'));
    }

    public function testSnippetIsOneScrubbedLineOf160(): void
    {
        $this->assertSame('Call me at … or …', ContactTimelineService::snippet("Call me at 604-555-1212\nor a@b.com"));
        $long = str_repeat('word ', 60);
        $s = ContactTimelineService::snippet($long);
        $this->assertLessThanOrEqual(160, mb_strlen($s));
        $this->assertStringEndsWith('…', $s);
    }
}
