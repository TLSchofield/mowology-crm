<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientVisibilityServiceTest extends TestCase
{
    public function testNonContractClientsSeeEverythingWhateverTheSwitchesSay(): void
    {
        $this->assertSame(['report' => true, 'length' => true], ClientVisibilityService::decide(false, false, false));
        $this->assertSame(['report' => true, 'length' => true], ClientVisibilityService::decide(false, true, false));
    }

    public function testContractClientsFollowTheSwitches(): void
    {
        $this->assertSame(['report' => false, 'length' => false], ClientVisibilityService::decide(true, false, false));
        $this->assertSame(['report' => true,  'length' => false], ClientVisibilityService::decide(true, true, false));
        $this->assertSame(['report' => false, 'length' => true],  ClientVisibilityService::decide(true, false, true));
        $this->assertSame(['report' => true,  'length' => true],  ClientVisibilityService::decide(true, true, true));
    }

    public function testAMissingOrOddSettingIsOff(): void
    {
        foreach (['', null, '0', 'false', 'no', 'off', 'maybe', ' '] as $v) {
            $this->assertFalse(ClientVisibilityService::isOn($v), var_export($v, true));
        }
        foreach (['1', 'true', 'TRUE', ' yes ', 'on', 1, true] as $v) {
            $this->assertTrue(ClientVisibilityService::isOn($v), var_export($v, true));
        }
    }
}
