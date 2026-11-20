<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The commons' promotion rule: when does one tenant's lesson become everyone's? */
final class PromotionTest extends TestCase
{
    public function testPaperFactsShareAtOneTenant(): void
    {
        foreach (['receipt_layout', 'sku', 'kind'] as $fact) {
            $this->assertSame('shared', LocalVendorKnowledgeStore::promotedStatus($fact, 1, 'candidate'), $fact);
        }
    }

    public function testBusinessFactsNeedTwoTenants(): void
    {
        foreach (['category_default', 'tax_treatment'] as $fact) {
            $this->assertSame('candidate', LocalVendorKnowledgeStore::promotedStatus($fact, 1, 'candidate'), $fact);
            $this->assertSame('shared', LocalVendorKnowledgeStore::promotedStatus($fact, 2, 'candidate'), $fact);
        }
    }

    public function testBlockedStaysBlockedAndSharedStaysShared(): void
    {
        $this->assertSame('blocked', LocalVendorKnowledgeStore::promotedStatus('sku', 5, 'blocked'));
        $this->assertSame('shared', LocalVendorKnowledgeStore::promotedStatus('category_default', 0, 'shared'));
    }

    public function testRepeatConfirmationsFromOneTenantDoNotPromote(): void
    {
        // Ten confirmations by the same tenant are still one distinct tenant.
        $this->assertSame('candidate', LocalVendorKnowledgeStore::promotedStatus('category_default', 1, 'candidate'));
    }
}
