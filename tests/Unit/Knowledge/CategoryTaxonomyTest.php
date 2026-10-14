<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CategoryTaxonomyTest extends TestCase
{
    public function testEveryLocalCategoryHasANeutralCode(): void
    {
        $t = new CategoryTaxonomy(null);
        foreach (EXPENSE_ACCOUNTING_CATEGORIES as $local) {
            $this->assertNotNull($t->fromLocal($local), "no neutral code for {$local}");
        }
    }

    public function testBridgeRoundTripsForDistinctCategories(): void
    {
        $t = new CategoryTaxonomy(null);
        foreach (['Fuel', 'Materials', 'Disposal/Dump', 'Meals', 'Vehicle', 'Safety', 'Subcontractors'] as $local) {
            $this->assertSame($local, $t->toLocal($t->fromLocal($local)));
        }
    }

    public function testSharedLocalNamesPreferTheGeneralCode(): void
    {
        $t = new CategoryTaxonomy(null);
        // Office/Admin covers both office_admin and software; the bridge returns the general one.
        $this->assertSame('office_admin', $t->fromLocal('Office/Admin'));
        $this->assertSame('Office/Admin', $t->toLocal('software'));
    }

    public function testUnknownsFallBack(): void
    {
        $t = new CategoryTaxonomy(null);
        $this->assertNull($t->fromLocal('Lawn Stuff'));
        $this->assertNull($t->toLocal('not_a_code'));
        $this->assertSame('Other', $t->toLocal('other'));
        $this->assertFalse(CategoryTaxonomy::isCode('lawn_care'), 'no trade words in the taxonomy');
        $this->assertTrue(CategoryTaxonomy::isCode('fuel'));
    }

    public function testCodesAreTradeNeutral(): void
    {
        foreach (array_keys(CategoryTaxonomy::CODES) as $code) {
            foreach (['lawn', 'mow', 'snow', 'garden', 'plumb', 'electr', 'landscap'] as $trade) {
                $this->assertStringNotContainsString($trade, $code);
            }
        }
    }

    public function testPersonalHasACodeButIsNeverShared(): void
    {
        $t = new CategoryTaxonomy(null);
        $this->assertSame('personal', $t->fromLocal('Personal'));
        $this->assertContains('personal', CategoryTaxonomy::PRIVATE_CODES);
    }
}
