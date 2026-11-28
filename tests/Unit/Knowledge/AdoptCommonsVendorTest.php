<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Knowledge/Services/VendorKnowledgeService.php';

/** adopt_commons: idempotent, no twins, no wrong-business link, only shared/curated, nothing from a candidate fact. */
final class AdoptCommonsVendorTest extends TestCase
{
    private function service(array $kbVendors): array
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, aliases TEXT, default_accounting_category TEXT, default_gbp_category TEXT, phone TEXT, website TEXT, gst_exempt INTEGER DEFAULT 0, gst_number TEXT, name_key TEXT, domain TEXT, kb_vendor_id INTEGER, is_active INTEGER DEFAULT 1)");
        $svc = new VendorKnowledgeService($db);
        $store = new class($kbVendors) implements VendorKnowledgeStore {
            public function __construct(private array $v) {}
            public function resolve(array $identity): ?array { return null; }
            public function search(string $q, int $limit = 8): array { return []; }
            public function vendor(int $id): ?array { $v = $this->v[$id] ?? null; return $v && in_array($v['status'], ['shared', 'curated'], true) ? $v : null; }
            public function contribute(array $lesson, string $tenantKey) { return false; }
            public function ready(): bool { return true; }
        };
        $p = new ReflectionProperty(VendorKnowledgeService::class, 'store');
        $p->setAccessible(true);
        $p->setValue($svc, $store);
        return [$svc, $db];
    }

    private static function landfill(array $over = []): array
    {
        return array_merge(['id' => 2, 'display_name' => 'City of Vancouver Vancouver Landfill', 'name_key' => 'city vancouver vancouver landfill',
            'gst_number' => null, 'phone_digits' => '6048737000', 'domain' => null, 'status' => 'curated',
            'facts' => ['tax_treatment' => [['status' => 'shared', 'value' => ['gst_exempt' => true]]]]], $over);
    }

    public function testCreatesAndLinksOnceThenIsIdempotent(): void
    {
        [$svc, $db] = $this->service([2 => self::landfill()]);
        $a = $svc->adoptCommonsVendorForClient(2);
        $this->assertTrue($a['created']);
        $this->assertSame('City of Vancouver Vancouver Landfill', $a['vendor']['name']);
        $row = $db->query("SELECT * FROM vendors WHERE id = " . $a['vendor_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$row['kb_vendor_id']);
        $this->assertSame('(604) 873-7000', $row['phone']);
        $this->assertSame(1, (int)$row['gst_exempt']);
        $b = $svc->adoptCommonsVendorForClient(2);   // the double tap
        $this->assertFalse($b['created']);
        $this->assertSame($a['vendor_id'], $b['vendor_id']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM vendors")->fetchColumn());
    }

    public function testACandidateOrUnknownCommonsVendorIsNotAvailable(): void
    {
        [$svc, $db] = $this->service([3 => self::landfill(['id' => 3, 'status' => 'candidate'])]);
        $this->assertNull($svc->adoptCommonsVendorForClient(3));
        $this->assertNull($svc->adoptCommonsVendorForClient(99));
        $this->assertNull($svc->adoptCommonsVendorForClient(0));
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM vendors")->fetchColumn());
    }

    public function testAHandTypedTwinIsLinkedNotDuplicated(): void
    {
        [$svc, $db] = $this->service([2 => self::landfill()]);
        $db->exec("INSERT INTO vendors (name, is_active) VALUES ('city of vancouver vancouver landfill', 1)");
        $r = $svc->adoptCommonsVendorForClient(2);
        $this->assertFalse($r['created']);
        $this->assertSame(1, $r['vendor_id']);
        $this->assertSame(2, (int)$db->query("SELECT kb_vendor_id FROM vendors WHERE id = 1")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM vendors")->fetchColumn());
    }

    public function testASameNamedVendorWithAnotherBusinessNumberIsNotLinked(): void
    {
        [$svc, $db] = $this->service([2 => self::landfill(['gst_number' => '111111111RT0001'])]);
        $db->exec("INSERT INTO vendors (name, gst_number, is_active) VALUES ('City of Vancouver Vancouver Landfill', '222222222RT0001', 1)");
        $r = $svc->adoptCommonsVendorForClient(2);
        $this->assertTrue($r['created']);
        $this->assertNotSame(1, $r['vendor_id']);
        $this->assertNull($db->query("SELECT kb_vendor_id FROM vendors WHERE id = 1")->fetchColumn());
    }

    public function testACandidateFactDoesNotMakeTheVendorGstExempt(): void
    {
        [$svc, $db] = $this->service([2 => self::landfill(['facts' => ['tax_treatment' => [['status' => 'candidate', 'value' => ['gst_exempt' => true]]]]])]);
        $r = $svc->adoptCommonsVendorForClient(2);
        $this->assertSame(0, (int)$db->query("SELECT gst_exempt FROM vendors WHERE id = " . $r['vendor_id'])->fetchColumn());
    }

    public function testAJunkNameIsRefused(): void
    {
        [$svc] = $this->service([2 => self::landfill(['display_name' => 'City of'])]);
        $this->assertNull($svc->adoptCommonsVendorForClient(2));
    }
}
