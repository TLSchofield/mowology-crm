<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The customer visit report may only claim "GPS-verified" when GPS fixes were actually loaded. */
class VisitReportGpsClaimTest extends TestCase
{
    public function testGpsVerifiedClaimIsConditionalOnLoadedPings(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/public/customer/visit-report.php');
        $this->assertMatchesRegularExpression(
            '/<\?php if \(!empty\(\$pings\)\): \?>&mdash; This is a GPS-verified service record\.<\?php endif; \?>/',
            $src
        );
    }
}
