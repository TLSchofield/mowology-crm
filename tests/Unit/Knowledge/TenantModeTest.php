<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Knowledge/Services/VendorKnowledgeService.php';

final class TenantModeTest extends TestCase
{
    public function testDefaultTenantIsHubEvenWithRemoteConfiguration(): void
    {
        $this->assertSame('hub', VendorKnowledgeService::resolveMode(null, true, false, true, true));
    }

    public function testNonDefaultTenantWithUrlAndSecretIsClient(): void
    {
        $this->assertSame('client', VendorKnowledgeService::resolveMode(null, false, false, true, true));
    }

    public function testNonDefaultTenantMissingEitherSettingRemainsHub(): void
    {
        $this->assertSame('hub', VendorKnowledgeService::resolveMode(null, false, false, false, true));
        $this->assertSame('hub', VendorKnowledgeService::resolveMode(null, false, false, true, false));
        $this->assertSame('hub', VendorKnowledgeService::resolveMode(null, false, false, false, false));
    }

    public function testExplicitModeWinsForOtherBusinesses(): void
    {
        foreach (['hub', 'client', 'off'] as $mode) {
            $this->assertSame($mode, VendorKnowledgeService::resolveMode($mode, false, true, false, false));
        }
    }

    public function testDefaultBusinessIsNeverClientEvenWhenExplicit(): void
    {
        $this->assertSame('hub', VendorKnowledgeService::resolveMode('client', true, false, true, true));
        $this->assertSame('off', VendorKnowledgeService::resolveMode('off', true, false, true, true));
    }

    public function testClientKeyIsStableDistinctAndUnpadded(): void
    {
        $first = VendorKnowledgeService::clientKey('tenant-a', 'shared-secret');
        $this->assertSame($first, VendorKnowledgeService::clientKey('tenant-a', 'shared-secret'));
        $this->assertNotSame($first, VendorKnowledgeService::clientKey('tenant-b', 'shared-secret'));
        $this->assertNotSame($first, VendorKnowledgeService::clientKey('tenant-a', 'different-secret'));
        $this->assertGreaterThanOrEqual(20, strlen($first));
        $this->assertStringNotContainsString('=', $first);
    }
}
