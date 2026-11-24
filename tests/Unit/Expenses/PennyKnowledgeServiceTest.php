<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PennyKnowledgeServiceTest extends TestCase
{
    private function mastered(array $change = []): array
    {
        return array_replace([
            'receipts_approved' => 5, 'transactions_matched' => 3,
            'unchanged_streak' => 3, 'items_unconfirmed' => 0,
            'exceptions' => [], 'last_verified' => date('Y-m-d'),
        ], $change);
    }

    public function testEveryStageRule(): void
    {
        self::assertSame('recognized', PennyKnowledgeService::stageFor($this->mastered(['receipts_approved' => 0, 'transactions_matched' => 0]))['stage']);
        self::assertSame('identified', PennyKnowledgeService::stageFor($this->mastered(['receipts_approved' => 1, 'transactions_matched' => 0]))['stage']);
        self::assertSame('understood', PennyKnowledgeService::stageFor($this->mastered(['receipts_approved' => 1, 'transactions_matched' => 1, 'unchanged_streak' => 0]))['stage']);
        self::assertSame('mastered', PennyKnowledgeService::stageFor($this->mastered())['stage']);

        self::assertSame(['Approve 1 receipt from this vendor'], PennyKnowledgeService::stageFor(['receipts_approved' => 0])['next']);
        self::assertSame(['Match 1 approved receipt from this vendor to its bank line'], PennyKnowledgeService::stageFor(['receipts_approved' => 1])['next']);
    }

    /** @dataProvider failingMasteredCondition */
    public function testEachMasteredConditionFailsAlone(array $change): void
    {
        self::assertSame('understood', PennyKnowledgeService::stageFor($this->mastered($change))['stage']);
    }

    public static function failingMasteredCondition(): array
    {
        return [
            'receipts' => [['receipts_approved' => 4]],
            'matches' => [['transactions_matched' => 2]],
            'streak/correction demotion' => [['unchanged_streak' => 0]],
            'repeated item' => [['items_unconfirmed' => 1]],
            'exception' => [['exceptions' => [['kind' => 'question', 'text' => 'Which account?']]]],
            'verification' => [['last_verified' => date('Y-m-d', strtotime('-366 days'))]],
        ];
    }

    public function testNewItemDoesNotDemoteVendor(): void
    {
        self::assertSame('mastered', PennyKnowledgeService::stageFor($this->mastered(['items_new' => 20]))['stage']);
    }

    public function testPlainNextSentencesDescribeTheGap(): void
    {
        $r = PennyKnowledgeService::stageFor($this->mastered(['receipts_approved' => 3, 'transactions_matched' => 2]));
        self::assertContains('2 more approved receipts', $r['next']);
        self::assertContains('Match 1 more receipt to its bank line', $r['next']);
    }

    public function testVendorStagesUsePersonApprovalAndAnApprovedMatch(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is not installed');
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INTEGER, created_at TEXT, approved_by INTEGER, status TEXT, anomaly_flags TEXT)');
        $db->exec('CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, matched_expense_id INTEGER)');
        $db->exec("INSERT INTO vendors VALUES (10,'Recognized'),(20,'Identified'),(30,'Understood')");
        $db->exec("INSERT INTO expenses VALUES
            (1,10,'2026-01-01',NULL,'forwarded',NULL),
            (2,20,'2026-01-02',7,'approved',NULL),
            (3,30,'2026-01-03',7,'approved',NULL)");
        $db->exec('INSERT INTO accounting_transactions VALUES (1,3)');

        $stages = array_column((new PennyKnowledgeService($db))->vendors(), 'stage', 'label');
        self::assertSame('recognized', $stages['Recognized'], 'A status alone is not person approval');
        self::assertSame('identified', $stages['Identified']);
        self::assertSame('understood', $stages['Understood']);
    }

    public function testItemStagesAndSlotsStayStableWhenHigherVendorIsAdded(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is not installed');
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INTEGER, created_at TEXT, approved_by INTEGER, status TEXT, anomaly_flags TEXT)');
        $db->exec('CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, matched_expense_id INTEGER)');
        $db->exec('CREATE TABLE vendor_product_skus (vendor_id INTEGER, sku_raw TEXT, item_name TEXT, times_seen INTEGER, times_confirmed INTEGER, last_seen_at TEXT)');
        $db->exec("INSERT INTO vendors VALUES (10,'A'),(20,'B')");
        $db->exec("INSERT INTO expenses VALUES (1,10,'2025-01-01',NULL,'draft',NULL),(2,20,'2025-01-02',NULL,'draft',NULL)");
        $db->exec("INSERT INTO vendor_product_skus VALUES (10,'one','Once',1,0,'2025-01-01'),(10,'two','Known',2,1,'2025-01-01'),(10,'three','Mastered',4,3,'2025-01-01')");
        $svc = new PennyKnowledgeService($db);
        self::assertSame([0, 1], array_column($svc->vendors(), 'slot'));
        $itemStages = array_column($svc->items(10), 'stage', 'label');
        self::assertSame('recognized', $itemStages['Once']);
        self::assertSame('understood', $itemStages['Known']);
        self::assertSame('mastered', $itemStages['Mastered']);
        $db->exec("INSERT INTO vendors VALUES (30,'C')");
        $db->exec("INSERT INTO expenses VALUES (3,30,'2025-01-03',NULL,'draft',NULL)");
        self::assertSame([0, 1], array_column(array_slice($svc->vendors(), 0, 2), 'slot'));
    }

    public function testItemMasteryDemotesOnlyForCurrentTenantCorrection(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is not installed');
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INTEGER)');
        $db->exec('CREATE TABLE vendor_product_skus (vendor_id INTEGER, sku_raw TEXT, item_name TEXT, times_seen INTEGER, times_confirmed INTEGER, last_seen_at TEXT)');
        $db->exec('CREATE TABLE head_lessons (site_id INTEGER, head TEXT, subject_kind TEXT, subject_key TEXT, field TEXT, created_at TEXT)');
        $db->exec("INSERT INTO vendors VALUES (10,'A')");
        $db->exec('INSERT INTO expenses VALUES (1,10)');
        $db->exec("INSERT INTO vendor_product_skus VALUES (10,'one','Item',4,3,'2026-01-01')");
        $db->exec("INSERT INTO head_lessons VALUES (2,'penny','vendor','a','item_name','2026-02-01')");

        self::assertSame('mastered', (new PennyKnowledgeService($db, 1))->items(10)[0]['stage']);
        self::assertSame('understood', (new PennyKnowledgeService($db, 2))->items(10)[0]['stage']);
    }
}
