<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests saved calculator-number lifecycle rules. */
final class SavedNumbersServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE saved_numbers ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, email_hash TEXT, tool TEXT, inputs_json TEXT, '
            . 'version INTEGER, status TEXT, confirm_token_hash TEXT, delete_token_hash TEXT, consent_save_text TEXT, '
            . 'consent_news_text TEXT, consent_benchmark_text TEXT, consent_save_at TEXT, consent_news_at TEXT, '
            . 'consent_benchmark_at TEXT, source_page TEXT, created_at TEXT, confirmed_at TEXT, last_used_at TEXT, '
            . 'reminder_sent_at TEXT)'
        );
        return $db;
    }

    /** Verifies confirmation preserves the original deletion token. */
    public function testConfirmDoesNotReplaceDeleteToken(): void
    {
        $db = $this->db();
        $service = new SavedNumbersService($db);
        $tokens = $service->createPending(
            'A@example.ca',
            'mow-price',
            ['onsite' => 30],
            true,
            false,
            '/tools/mow-price/'
        );
        $before = $db->query('SELECT delete_token_hash FROM saved_numbers')->fetchColumn();
        $this->assertNull($service->confirm('wrong'));
        $done = $service->confirm($tokens['confirm_token']);
        $after = $db->query('SELECT delete_token_hash FROM saved_numbers')->fetchColumn();

        $this->assertSame('confirmed', $done['status']);
        $this->assertSame($before, $after);
        $this->assertTrue($service->delete($tokens['delete_token']));
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM saved_numbers')->fetchColumn());
    }

    /** Verifies purge timing and one-time reminder behavior. */
    public function testPurgeRulesAndReminderOnce(): void
    {
        $db = $this->db();
        $service = new SavedNumbersService($db);
        $db->exec(
            "INSERT INTO saved_numbers "
            . "(email,email_hash,tool,inputs_json,version,status,confirm_token_hash,delete_token_hash,consent_save_text,source_page,created_at,last_used_at) "
            . "VALUES ('a@b.ca','x','mow-price','{}',1,'pending','a','b','save','/','2024-01-01','2024-01-01'),"
            . "('c@d.ca','y','mow-price','{}',1,'confirmed','c','d','save','/','2024-01-01','2025-01-10')"
        );
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $result = $service->purge($at, function (): bool {
            return true;
        });
        $this->assertSame(1, $result['pending_deleted']);
        $this->assertSame(0, $result['confirmed_deleted']);
        $this->assertSame(1, $result['reminders_sent']);

        $result = $service->purge($at->modify('+1 day'), function (): bool {
            return true;
        });
        $this->assertSame(0, $result['reminders_sent']);
    }
}
