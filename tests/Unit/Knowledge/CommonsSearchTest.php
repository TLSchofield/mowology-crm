<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Knowledge/Services/VendorIdentity.php';
require_once dirname(__DIR__, 3) . '/app/Modules/Knowledge/Services/LocalVendorKnowledgeStore.php';
require_once dirname(__DIR__, 3) . '/app/Modules/Knowledge/Services/VendorKnowledgeService.php';
require_once dirname(__DIR__, 3) . '/app/Modules/Expenses/Services/ExpenseLookupService.php';

/** A business with no vendors of its own types "city of van" and still finds the commons vendor (samtest, 2026-11-27). */
final class CommonsSearchTest extends TestCase
{
    private function store(): LocalVendorKnowledgeStore
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE kb_vendors (id INTEGER PRIMARY KEY, name_key TEXT, display_name TEXT, gst_number TEXT, phone_digits TEXT, domain TEXT, kind TEXT, status TEXT, tenant_count INTEGER)");
        $ins = $db->prepare("INSERT INTO kb_vendors (id, name_key, display_name, phone_digits, kind, status, tenant_count) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([2, 'city vancouver vancouver landfill', 'City of Vancouver Vancouver Landfill', '6048737000', null, 'curated', 1]);
        $ins->execute([3, 'city vancouver secret', 'City of Vancouver Candidate Co', '6045550000', null, 'candidate', 1]);
        $ins->execute([4, 'city vancouver blocked', 'City of Vancouver Blocked Co', null, null, 'blocked', 1]);
        return new LocalVendorKnowledgeStore($db, false);
    }

    public function testFindsTheCuratedLandfillByWhatTheOwnerTypes(): void
    {
        $rows = $this->store()->search('city of van');
        $this->assertSame([2], array_column($rows, 'id'));
        $this->assertSame(['id', 'display_name', 'kind', 'status'], array_keys($rows[0]));   // identity only, no phone, no facts
    }

    public function testCandidateAndBlockedVendorsNeverAppear(): void
    {
        $this->assertSame([2], array_column($this->store()->search('city of'), 'id'));
    }

    public function testShortQueriesReturnNothing(): void
    {
        $this->assertSame([], $this->store()->search('ci'));
    }

    public function testWildcardsAreEscaped(): void
    {
        $this->assertSame('%a\\%b\\_c%', LocalVendorKnowledgeStore::likePattern('a%b_c'));
    }

    public function testMergeAppendsOnlyVendorsTheBusinessLacks(): void
    {
        $local = [['id' => 7, 'name' => 'Lordco', 'kb_vendor_id' => 9], ['id' => 8, 'name' => 'City of Vancouver Vancouver Landfill', 'kb_vendor_id' => null]];
        $commons = [
            ['id' => 9, 'display_name' => 'Lordco Auto Parts'],                       // already linked
            ['id' => 2, 'display_name' => 'City of Vancouver Vancouver Landfill'],    // same name locally
            ['id' => 5, 'display_name' => 'Acme Supply'],
        ];
        $out = ExpenseLookupService::mergeCommonsVendors($local, $commons);
        $this->assertCount(3, $out);
        $this->assertNull($out[2]['id']);
        $this->assertSame('commons', $out[2]['source']);
        $this->assertSame(5, $out[2]['kb_vendor_id']);
    }

    public function testMergeNeverExceedsTheLimit(): void
    {
        $local = array_map(static fn($i) => ['id' => $i, 'name' => "V$i"], range(1, 20));
        $this->assertCount(20, ExpenseLookupService::mergeCommonsVendors($local, [['id' => 5, 'display_name' => 'Acme']]));
    }

    public function testAStoreHandsOutOnlySharedOrCuratedVendorsById(): void
    {
        $store = $this->store();
        $this->assertSame(2, $store->vendor(2)['id'] ?? null);
        $this->assertNull($store->vendor(3));   // candidate
        $this->assertNull($store->vendor(4));   // blocked
        $this->assertNull($store->vendor(99));
    }

    public function testAdoptionRowCarriesIdentityAndNeutralDefaultsOnly(): void
    {
        $kb = ['id' => 2, 'display_name' => 'City of Vancouver Vancouver Landfill', 'phone_digits' => '6048737000', 'gst_number' => null, 'domain' => null,
               'facts' => ['tax_treatment' => [['value' => ['gst_exempt' => true]]], 'sku' => [['value' => ['sku' => '10', 'name' => '10 - Green Waste (0.34 tonne @ $124.00/tonne)']]]]];
        $row = VendorKnowledgeService::adoptionRow($kb, 'Disposal/Dump');
        $this->assertSame(['name', 'aliases', 'phone', 'website', 'gst_number', 'gst_exempt', 'category_local'], array_keys($row));
        $this->assertSame('(604) 873-7000', $row['phone']);
        $this->assertSame(1, $row['gst_exempt']);
        $this->assertSame('Disposal/Dump', $row['category_local']);
        $this->assertSame('city of vancouver vancouver landfill', $row['aliases']);
    }
}
