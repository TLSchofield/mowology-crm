<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GoogleContactsStageServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE onboarding_google_contacts_stage (nonce TEXT PRIMARY KEY, user_id INTEGER NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL)');
        return $db;
    }

    public function test_stage_take_is_one_time(): void
    {
        $service = new GoogleContactsStageService($this->db());
        $contacts = [['ref' => 'people/1', 'first' => 'Ada']];
        $service->stage('0123456789abcdef0123456789abcdef', 7, $contacts);
        $this->assertSame($contacts, $service->take('0123456789abcdef0123456789abcdef', 7));
        $this->assertNull($service->take('0123456789abcdef0123456789abcdef', 7));
    }

    public function test_wrong_user_cannot_use_or_consume_the_row(): void
    {
        $service = new GoogleContactsStageService($this->db());
        $service->stage('0123456789abcdef0123456789abcdef', 7, [['ref' => 'people/1']]);
        $this->assertNull($service->take('0123456789abcdef0123456789abcdef', 8));
        $this->assertSame([['ref' => 'people/1']], $service->take('0123456789abcdef0123456789abcdef', 7));
    }

    public function test_expired_rows_are_deleted(): void
    {
        $db = $this->db();
        $db->prepare('INSERT INTO onboarding_google_contacts_stage (nonce, user_id, payload, created_at) VALUES (?, ?, ?, ?)')
            ->execute(['0123456789abcdef0123456789abcdef', 7, '[]', gmdate('Y-m-d H:i:s', time() - 1801)]);
        $service = new GoogleContactsStageService($db);
        $this->assertNull($service->take('0123456789abcdef0123456789abcdef', 7));
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM onboarding_google_contacts_stage')->fetchColumn());
    }

    public function test_an_oversized_list_is_refused_not_half_saved(): void
    {
        $db = $this->db();
        $service = new GoogleContactsStageService($db);
        $this->expectException(LengthException::class);
        try {
            $service->stage('0123456789abcdef0123456789abcdef', 7, [['ref' => str_repeat('x', 8100000)]]);
        } finally {
            $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM onboarding_google_contacts_stage')->fetchColumn());
        }
    }
}
