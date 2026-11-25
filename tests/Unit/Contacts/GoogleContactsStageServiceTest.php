<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GoogleContactsStageServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE onboarding_google_contacts_stage (nonce TEXT PRIMARY KEY, user_id INTEGER NOT NULL, grant_hash TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL)');
        return $db;
    }

    public function test_stage_take_is_one_time(): void
    {
        $service = new GoogleContactsStageService($this->db());
        $contacts = [['ref' => 'people/1', 'first' => 'Ada']];
        $service->stage('0123456789abcdef0123456789abcdef', 7, $contacts, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertSame($contacts, $service->take('0123456789abcdef0123456789abcdef', 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        $this->assertNull($service->take('0123456789abcdef0123456789abcdef', 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_wrong_user_cannot_use_or_consume_the_row(): void
    {
        $service = new GoogleContactsStageService($this->db());
        $service->stage('0123456789abcdef0123456789abcdef', 7, [['ref' => 'people/1']], 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertNull($service->take('0123456789abcdef0123456789abcdef', 8, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame([['ref' => 'people/1']], $service->take('0123456789abcdef0123456789abcdef', 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_expired_rows_are_deleted(): void
    {
        $db = $this->db();
        $db->prepare('INSERT INTO onboarding_google_contacts_stage (nonce, user_id, grant_hash, payload, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['0123456789abcdef0123456789abcdef', 7, hash('sha256', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), '[]', gmdate('Y-m-d H:i:s', time() - 1801)]);
        $service = new GoogleContactsStageService($db);
        $this->assertNull($service->take('0123456789abcdef0123456789abcdef', 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM onboarding_google_contacts_stage')->fetchColumn());
    }

    public function test_an_oversized_list_is_refused_not_half_saved(): void
    {
        $db = $this->db();
        $service = new GoogleContactsStageService($db);
        $this->expectException(LengthException::class);
        try {
            $service->stage('0123456789abcdef0123456789abcdef', 7, [['ref' => str_repeat('x', 8100000)]], 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        } finally {
            $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM onboarding_google_contacts_stage')->fetchColumn());
        }
    }

    public function test_the_nonce_alone_is_not_enough_without_the_grant_from_the_browser_redirect(): void
    {
        $service = new GoogleContactsStageService($this->db());
        $nonce = '0123456789abcdef0123456789abcdef';
        $service->stage($nonce, 7, [['ref' => 'people/1']], 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        // Someone who started the flow and knows the nonce (but never saw the redirect) cannot collect the list, nor destroy it.
        $this->assertNull($service->take($nonce, 7, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
        $this->assertNull($service->take($nonce, 7, ''));
        $this->assertSame([['ref' => 'people/1']], $service->take($nonce, 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_a_second_consent_on_the_same_nonce_cannot_replace_the_first_list(): void
    {
        $service = new GoogleContactsStageService($this->db());
        $nonce = '0123456789abcdef0123456789abcdef';
        $service->stage($nonce, 7, [['ref' => 'people/A']], 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        try {
            $service->stage($nonce, 7, [['ref' => 'people/B']], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
            $this->fail('A repeated nonce must be refused.');
        } catch (PDOException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame([['ref' => 'people/A']], $service->take($nonce, 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }
}
