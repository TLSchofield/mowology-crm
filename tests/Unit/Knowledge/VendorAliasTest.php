<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Aliases that can't name a vendor, and whole-word matching (2026-10-15: "date:" and "the" filed receipts wrongly). */
class VendorAliasTest extends TestCase
{
    public function test_junk_aliases_are_not_usable(): void
    {
        foreach (['date:', 'amount', 'push', 'the', 'HD', 'transaction red', 'city of', 'w. broadway', '320-507 WEST BROADWAY', '(b)',
                  'une', 'jut', 'rev-', 'ow me now', 'delta bc r'] as $junk) {
            $this->assertFalse(VendorIdentity::usableAlias($junk), $junk);
        }
    }

    public function test_real_aliases_are_usable(): void
    {
        foreach (['rona', 'Canadian Tire', 'CTC TIRE', 'pnty', '16&macdonld twn pnty', 'marine dr town pntry', 'IMPERIAL OIL', 'store #: 43016',
                  'tel#: 604-718-1017', 'Lawnboy Enterprises Ltd', 'Costco'] as $ok) {
            $this->assertTrue(VendorIdentity::usableAlias($ok), $ok);
        }
    }

    public function test_whole_words_only(): void
    {
        $this->assertTrue(VendorIdentity::textHas('rona+ vancouver - grandview', 'rona'));
        $this->assertFalse(VendorIdentity::textHas('verona cafe', 'rona'));
        $this->assertTrue(VendorIdentity::textHas('chv43016 16th macdon … pnty', 'pnty'));
        $this->assertFalse(VendorIdentity::textHas('the home depot', 'home dep'));
        $this->assertTrue(VendorIdentity::textHas('marine dr town pntry 196', 'Marine Dr Town Pntry'));
    }
}
