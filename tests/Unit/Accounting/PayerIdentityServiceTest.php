<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Who pays as whom — one table, owner's word first (2026-10-14). */
class PayerIdentityServiceTest extends TestCase
{
    private function db(bool $withNew = true): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE bank_memo_payers (id INTEGER PRIMARY KEY, pattern TEXT UNIQUE, payer_name TEXT, company_id INT, note TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE etransfer_sender_payers (sender_key TEXT, payer_key TEXT, sender_name TEXT, payer_name TEXT, times INT DEFAULT 1, last_seen TEXT, PRIMARY KEY (sender_key, payer_key))");
        if ($withNew) {
            $db->exec("CREATE TABLE payer_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, alias_key TEXT, alias_raw TEXT, payer_name TEXT, payer_key TEXT,
                       contact_id INT, company_id INT, source TEXT DEFAULT 'learned', times INT DEFAULT 1, last_seen TEXT, note TEXT, created_by INT, created_at TEXT, removed_at TEXT,
                       UNIQUE (kind, alias_key, payer_key))");
        }
        return $db;
    }

    public function test_keys(): void
    {
        $this->assertSame('VML EFT 2026', PayerIdentityService::key('bank_memo', ' vml-eft*2026 '));
        $this->assertSame('STRATAPLANBCS2106', PayerIdentityService::key('etransfer_sender', 'STRATA PLAN BCS-2106'));
        $this->assertSame('JOGAL', PayerIdentityService::key('sms_name', 'Jo Gal'));
        $this->assertSame('6045551234', PayerIdentityService::key('phone', '+1 (604) 555-1234'));
        $this->assertSame('a@b.ca', PayerIdentityService::key('email', ' A@B.ca '));
    }

    public function test_owner_word_beats_learned_counts_and_memo_words_are_whole(): void
    {
        $rows = [
            ['kind' => 'bank_memo', 'alias_key' => 'VML', 'payer_name' => 'Vancouver Management Ltd', 'source' => 'learned', 'times' => 9],
            ['kind' => 'bank_memo', 'alias_key' => 'KANCO', 'payer_name' => 'Beedie', 'source' => 'tim', 'times' => 1],
            ['kind' => 'bank_memo', 'alias_key' => 'MAN', 'payer_name' => 'Someone', 'source' => 'learned', 'times' => 1],
        ];
        $this->assertSame(['Beedie', 'Vancouver Management Ltd'], PayerIdentityService::pick('bank_memo', 'EFT VML-EFT KANCO HOLDINGS', $rows));
        $this->assertSame([], PayerIdentityService::pick('bank_memo', 'MANAGEMENT FEE', $rows), 'MAN is not a word of MANAGEMENT');
    }

    public function test_learn_read_remove_and_never_relearn(): void
    {
        $s = new PayerIdentityService($this->db());
        $id = $s->learn('sms_name', 'Jo Gal', 'Joseph Gallentine', 'tim');
        $this->assertSame(['Joseph Gallentine'], $s->payersFor('sms_name', 'JO GAL'));
        $this->assertTrue($s->remove((int)$id));
        $this->assertSame([], $s->payersFor('sms_name', 'Jo Gal'));
        $this->assertNull($s->learn('sms_name', 'Jo Gal', 'Joseph Gallentine', 'learned'), 'a removed pair is not learned back');
        $this->assertNotNull($s->learn('sms_name', 'Jo Gal', 'Joseph Gallentine', 'tim'), 'the owner can put it back');
        $this->assertSame(['Joseph Gallentine'], $s->payersFor('sms_name', 'Jo Gal'));
    }

    public function test_learning_keeps_the_old_tables_current(): void
    {
        $db = $this->db();
        $s = new PayerIdentityService($db);
        $s->learn('etransfer_sender', 'alan inglis', 'Maureen Kirkbride');
        $s->learn('etransfer_sender', 'ALAN INGLIS', 'Maureen Kirkbride');
        $s->learn('bank_memo', 'Coastal Health', 'VCHA : Little Mountain Residential Care', 'learned', ['company_id' => 17]);
        $this->assertSame(2, (int)$db->query("SELECT times FROM etransfer_sender_payers WHERE sender_key = 'ALANINGLIS'")->fetchColumn());
        $this->assertSame(2, (int)$db->query("SELECT times FROM payer_aliases WHERE alias_key = 'ALANINGLIS'")->fetchColumn());
        $this->assertSame('17', (string)$db->query("SELECT company_id FROM bank_memo_payers WHERE pattern = 'COASTAL HEALTH'")->fetchColumn());
        $this->assertSame(2, $s->timesFor('etransfer_sender', 'Alan Inglis'));
    }

    public function test_before_1362_it_reads_the_old_tables(): void
    {
        $db = $this->db(false);
        $db->exec("INSERT INTO bank_memo_payers (pattern, payer_name, created_at) VALUES ('VANCOUVER MGMT', 'VANCOUVER MANAGEMENT LTD', '2026-10-08')");
        $db->exec("INSERT INTO etransfer_sender_payers VALUES ('STRATAPLANBCS2106', 'ALEXANDRABEE', 'STRATA PLAN BCS-2106', 'Alexandra Bee', 1, '2026-10-07')");
        $s = new PayerIdentityService($db);
        $this->assertSame(['VANCOUVER MANAGEMENT LTD'], $s->payersFor('bank_memo', 'EFT DEPOSIT VANCOUVER MGMT'));
        $this->assertSame(['Alexandra Bee'], $s->payersFor('etransfer_sender', 'Strata Plan BCS 2106'));
    }

    public function test_short_memo_words_are_refused(): void
    {
        $this->assertNull((new PayerIdentityService($this->db()))->learn('bank_memo', 'AB', 'Anyone'));
    }
}
