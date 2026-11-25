<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContributeGateTest extends TestCase
{
    public function testGstAloneMayCreate(): void
    {
        $this->assertTrue(LocalVendorKnowledgeStore::mayCreateEntity(['gst_number' => '123456789'], ''));
    }

    public function testNameAloneMayNotCreate(): void
    {
        $this->assertFalse(LocalVendorKnowledgeStore::mayCreateEntity(['name_key' => 'real vendor'], 'Real Vendor'));
    }

    /** @dataProvider junkNames */
    public function testPhoneWithJunkNameMayNotCreate(string $name): void
    {
        $this->assertFalse(LocalVendorKnowledgeStore::mayCreateEntity(['phone_digits' => '6045551212'], $name));
    }

    public static function junkNames(): array
    {
        return [['AMOUNT'], ['DATE:']];
    }

    public function testPhoneWithRealNameMayCreate(): void
    {
        $this->assertTrue(LocalVendorKnowledgeStore::mayCreateEntity(['phone_digits' => '6045551212'], 'Lawnboy Equipment'));
    }

    public function testDomainWithRealNameMayCreate(): void
    {
        $this->assertTrue(LocalVendorKnowledgeStore::mayCreateEntity(['domain' => 'lawnboy.ca'], 'Lawnboy Equipment'));
    }
}
