<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/TestIdentity.php';

/**
 * Quote SMS text must survive Canadian carrier email-to-SMS gateways:
 * no URLs or domains, ≤160 chars, email pointer + phone fallback (CLAUDE.md rule 11).
 */
class SmsTemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestIdentity::mowology();
    }

    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        parent::tearDown();
    }

    private function assertGatewaySafe(string $message): void
    {
        $this->assertLessThanOrEqual(160, strlen($message), $message);
        $this->assertStringNotContainsStringIgnoringCase('http', $message);
        $this->assertStringNotContainsStringIgnoringCase('www', $message);
        $this->assertDoesNotMatchRegularExpression('/[a-z0-9-]+\.[a-z]{2,}/i', $message, 'looks like a domain: ' . $message);
        $this->assertStringContainsString('(778) 846-9273', $message);
        $this->assertStringContainsStringIgnoringCase('email', $message);
    }

    public function testStandardQuoteMessageIsGatewaySafe(): void
    {
        $message = buildQuoteNotificationSmsText('QUO-2026-0001');
        $this->assertGatewaySafe($message);
        $this->assertStringContainsString('QUO-2026-0001', $message);
    }

    public function testCompanyNameWithDotDoesNotLeakDomainPattern(): void
    {
        $this->assertGatewaySafe(buildQuoteNotificationSmsText('QUO-2026-0042', 'Mowology.ca Inc.'));
    }

    public function testLongCompanyNameFallsBackUnder160(): void
    {
        $message = buildQuoteNotificationSmsText('QUO-2026-0099', str_repeat('Very Long Landscaping Name ', 8));
        $this->assertGatewaySafe($message);
        $this->assertStringContainsString('QUO-2026-0099', $message);
    }
}
