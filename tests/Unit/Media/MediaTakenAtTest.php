<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Media/MediaUploadService.php';

/** A photo the phone shrank keeps the time it was taken (crew page preparePhoto(), 2026-10-14). */
class MediaTakenAtTest extends TestCase
{
    public function test_capture_time_from_the_phone(): void
    {
        $now = strtotime('2026-10-14 12:00:00');
        $this->assertSame(date('Y-m-d H:i:s', strtotime('2026-10-14T18:30:00Z')), mediaTakenAt('2026-10-14T18:30:00Z', $now + 86400));
        $this->assertNull(mediaTakenAt(null, $now));
        $this->assertNull(mediaTakenAt('', $now));
        $this->assertNull(mediaTakenAt('not a date', $now));
        $this->assertNull(mediaTakenAt('2026-01-01T00:00:00Z', $now), 'older than 60 days');
        $this->assertNull(mediaTakenAt('2026-10-14T23:00:00', $now), 'more than 10 minutes ahead');
    }
}
