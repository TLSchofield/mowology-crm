<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The pure part of the manager-PIN pre-trip waiver: what reason gets stored. */
class PreTripBypassServiceTest extends TestCase
{
    public function test_blank_reason_becomes_manager_override(): void
    {
        $this->assertSame('manager_override', PreTripBypassService::cleanReason(null));
        $this->assertSame('manager_override', PreTripBypassService::cleanReason("   \n"));
    }

    public function test_reason_is_trimmed_and_kept(): void
    {
        $this->assertSame('truck in the shop', PreTripBypassService::cleanReason("  truck in the shop "));
    }

    public function test_reason_is_capped(): void
    {
        $out = PreTripBypassService::cleanReason(str_repeat('é', 250));
        $this->assertSame(PreTripBypassService::MAX_REASON, mb_strlen($out));
    }
}
