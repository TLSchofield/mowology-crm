<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KbVendorNameTest extends TestCase
{
    public function testCommonsNameWins(): void
    {
        $this->assertSame('TRUSTED VENDOR', LocalVendorKnowledgeStore::creationName('garbled header', ['name' => ' Trusted Vendor ']));
    }

    public function testHintIsFallbackAndNormalised(): void
    {
        $this->assertSame('OCR HEADER', LocalVendorKnowledgeStore::creationName(' ocr header ', null));
    }

    public function testNullInputsReturnEmptyName(): void
    {
        $this->assertSame('', LocalVendorKnowledgeStore::creationName(null, null));
    }
}
