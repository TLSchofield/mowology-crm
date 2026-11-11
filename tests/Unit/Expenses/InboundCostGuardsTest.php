<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/LlmDailyCap.php';
require_once __DIR__ . '/../../../app/Modules/Expenses/Services/VendorMessageService.php';

/**
 * Security review lows (2026-10-12): receipts@ reads only known senders; AI calls have a daily
 * ceiling; a portal rebook notifies the office once per visit per day.
 */
class InboundCostGuardsTest extends TestCase
{
    public function test_receipts_inbox_reads_only_known_senders(): void
    {
        $vendors = [['id' => 3, 'name' => 'Lawnboy', 'aliases' => '', 'website' => 'https://www.lawnboy.ca']];
        $allowed = ['mowology@icloud.com', 'nigel@mowology.ca'];
        $this->assertTrue(ReceiptInboxService::senderAllowed('Mowology@iCloud.com', $allowed, $vendors));
        $this->assertTrue(ReceiptInboxService::senderAllowed('orders@lawnboy.ca', $allowed, $vendors));
        $this->assertFalse(ReceiptInboxService::senderAllowed('spam@random.biz', $allowed, $vendors));
        $this->assertFalse(ReceiptInboxService::senderAllowed('', $allowed, $vendors));
        $this->assertFalse(ReceiptInboxService::senderAllowed('lawnboy@gmail.com', $allowed, $vendors), 'free mail never matches a vendor');
    }

    public function test_unknown_sender_is_held_without_reading(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE receipt_inbox_messages (id INTEGER PRIMARY KEY, dedup_key TEXT UNIQUE, sender_email TEXT, subject TEXT,
                   email_date TEXT, attachment_name TEXT, outcome TEXT, media_id INTEGER, expense_id INTEGER, match_confidence INTEGER, note TEXT)");
        $svc = new ReceiptInboxService($db);
        $msg = ['message_id' => '<x@y>', 'sender_email' => 'spam@random.biz', 'subject' => 'invoice', 'email_date' => '2026-10-12'];
        $this->assertSame('held_sender', $svc->holdUnknownSender($msg, 'a.pdf', 'bytes')['status']);
        $this->assertSame('duplicate', $svc->holdUnknownSender($msg, 'a.pdf', 'bytes')['status']);
        $row = $db->query("SELECT outcome, expense_id, media_id FROM receipt_inbox_messages")->fetch();
        $this->assertSame(['outcome' => 'held_sender', 'expense_id' => null, 'media_id' => null], $row);
        $poll = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Expenses/Cron/receipt_inbox_poll.php');
        $this->assertStringContainsString('ReceiptInboxService::senderAllowed(', $poll);
    }

    public function test_daily_cap_counter(): void
    {
        $this->assertSame(0, LlmDailyCap::usedToday(null, '2026-10-12'));
        $this->assertSame(7, LlmDailyCap::usedToday('2026-10-12:7', '2026-10-12'));
        $this->assertSame(0, LlmDailyCap::usedToday('2026-10-11:99', '2026-10-12'), 'a new day starts at zero');
        foreach (['app/Modules/Expenses/Services/ReceiptBookkeeperService.php', 'app/Services/Receipts/ReceiptLlmExtractor.php'] as $f) {
            $this->assertStringContainsString('LlmDailyCap::take(', (string)file_get_contents(__DIR__ . '/../../../' . $f), $f);
        }
    }

    public function test_daily_cap_stops_at_the_ceiling(): void
    {
        // MySQL-only SQL (CONCAT/IF/SUBSTRING_INDEX) is exercised on the local MySQL site; here a fake
        // PDO checks the decision path: at the cap the UPDATE matches no row and the row exists → false.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(0);
        $stmt->method('fetchColumn')->willReturnOnConsecutiveCalls('5', date('Y-m-d') . ':5');
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);
        $this->assertFalse(LlmDailyCap::take($db, 'receipt_llm', 60));
    }

    public function test_rebook_notifies_once_per_visit_per_day(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../public/customer/api/rebook.php');
        $this->assertMatchesRegularExpression("/source = 'portal_rebook'.*INTERVAL 1 DAY/s", $src);
        $this->assertLessThan(strpos($src, 'sendCrmEmail('), strpos($src, '$todayStmt'), 'checked before the office email');
    }
}
