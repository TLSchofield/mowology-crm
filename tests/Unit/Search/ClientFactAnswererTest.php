<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientFactAnswererTest extends TestCase
{
    private function db(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT,last_name TEXT,email TEXT,phone TEXT,is_active INT)');
        $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY,company_name TEXT,billing_email TEXT,account_status TEXT)');
        $db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY,quote_number TEXT,title TEXT,status TEXT,sent_at TEXT,accepted_at TEXT,email_opened_at TEXT,viewed_at TEXT,view_count INT,contact_id INT,company_id INT,created_at TEXT)');
        $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY,invoice_number TEXT,total REAL,balance_due REAL,due_date TEXT,paid_at TEXT,contact_id INT,company_id INT,status TEXT DEFAULT \'sent\')');
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY,address TEXT,site_contact_id INT,client_id INT,company_id INT)');
        $db->exec('CREATE TABLE property_contacts (property_id INT,contact_id INT)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY,property_id INT,title TEXT)');
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY,plan_id INT,scheduled_date TEXT,status TEXT)');
        $db->exec('CREATE TABLE sales_messages (id INTEGER PRIMARY KEY,contact_id INT,direction TEXT,sent_at TEXT,snippet TEXT)');
        return$db;
    }

    public function test_quote_answer_has_open_call_and_text_actions(): void
    {
        $db=$this->db();
        $db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones','linda@example.test','604-555-1234',1)");
        $db->exec("INSERT INTO quotes VALUES(5,'QUO-2026-005','Entry','sent','2026-11-01',NULL,'2026-11-02',NULL,3,1,NULL,'2026-11-01')");
        $answer=(new ClientFactAnswerer($db,'2026-11-06'))->answer("Has Linda's quote been sent?",static function(string $p):bool{return true;});
        self::assertNotNull($answer);self::assertSame('quote_sent',$answer['intent']);
        self::assertStringContainsString('opened 3×',$answer['answer']);
        self::assertSame(['open','call','text','email','text','email'],array_column($answer['actions'],'kind'));
        self::assertSame(['Open quote','Call','Text','Email','Nudge by text','Nudge by email'],array_column($answer['actions'],'label'));
    }

    public function test_ambiguous_person_and_unknown_intent_return_null(): void
    {
        $db=$this->db();
        $db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones',NULL,NULL,1),(2,'Linda','Smith',NULL,NULL,1)");
        $answerer=new ClientFactAnswerer($db,'2026-11-06');$can=static function(string $p):bool{return true;};
        self::assertNull($answerer->answer('Was Linda quote sent?',$can));
        self::assertNull($answerer->answer('Find Linda',$can));
    }

    public function test_full_name_resolves_one_person_and_draft_quote(): void
    {
        $db=$this->db();$db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones',NULL,NULL,1),(2,'Linda','Smith',NULL,NULL,1),(3,'Bob','Jones',NULL,NULL,1)");
        $db->exec("INSERT INTO quotes VALUES(5,'QUO-5','Entry','draft',NULL,NULL,NULL,NULL,0,1,NULL,'2026-11-01')");
        $answer=(new ClientFactAnswerer($db,'2026-11-06'))->answer('Was Linda Jones quote sent?',static function(string $p):bool{return true;});
        self::assertNotNull($answer);self::assertStringContainsString('not sent yet (draft)',$answer['answer']);
        self::assertSame(['open'],array_column($answer['actions'],'kind'));
    }

    public function test_future_invoice_is_owing_without_overdue_wording_and_paid_schema_uses_total_paid_at(): void
    {
        $db=$this->db();$db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones','linda@example.test',NULL,1)");
        $db->exec("INSERT INTO invoices (id,invoice_number,total,balance_due,due_date,paid_at,contact_id,company_id) VALUES(1,'INV-1',100,25,'2026-11-20',NULL,1,NULL)");
        $answerer=new ClientFactAnswerer($db,'2026-11-06');$can=static function(string $p):bool{return true;};
        $owing=$answerer->answer('Does Linda Jones owe anything?',$can);
        self::assertNotNull($owing);self::assertStringNotContainsString('overdue',$owing['answer']);
        $db->exec("UPDATE invoices SET balance_due=0,paid_at='2026-11-03' WHERE id=1");
        $paid=$answerer->answer('Has Linda Jones paid?',$can);
        self::assertNotNull($paid);self::assertStringContainsString('last paid INV-1 ($100.00) on Nov 3',$paid['answer']);
    }

    public function test_visit_message_and_phone_less_actions(): void
    {
        $db=$this->db();$db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones','linda@example.test',NULL,1)");
        $db->exec("INSERT INTO properties VALUES(8,'10 Main St',1,1,NULL)");
        $db->exec("INSERT INTO job_plans VALUES(9,8,'Cleanup')");
        $db->exec("INSERT INTO job_visits VALUES(10,9,'2026-11-08','scheduled')");
        $db->exec("INSERT INTO sales_messages VALUES(11,1,'inbound','2026-11-05','Please use the side gate')");
        $answerer=new ClientFactAnswerer($db,'2026-11-06');$can=static function(string $p):bool{return true;};
        $visit=$answerer->answer('When is Linda Jones next visit?',$can);
        self::assertNotNull($visit);self::assertStringContainsString('Sun Nov 8',$visit['answer']);self::assertSame(['open'],array_column($visit['actions'],'kind'));
        $message=$answerer->answer('What did Linda Jones say?',$can);
        self::assertNotNull($message);self::assertStringContainsString('side gate',$message['answer']);self::assertSame(['email'],array_column($message['actions'],'kind'));
    }

    public function test_intent_words_are_not_taken_for_names(): void
    {
        $db=$this->db();$db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones','linda@example.test','604-555-1234',1)");
        $db->exec("INSERT INTO sales_messages VALUES(1,1,'inbound','2026-11-04 09:00:00','Can you come Thursday?')");
        $a=(new ClientFactAnswerer($db,'2026-11-06'))->answer('what did linda say',static function(string $p):bool{return true;});
        self::assertNotNull($a);self::assertStringContainsString('Linda Jones wrote Nov 4',$a['answer']);
    }

    public function test_cancelled_invoice_is_not_owed(): void
    {
        $db=$this->db();$db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones',NULL,NULL,1)");
        $db->exec("INSERT INTO invoices (id,invoice_number,total,balance_due,due_date,paid_at,contact_id,company_id,status) VALUES(1,'INV-9',80,80,'2026-10-01',NULL,1,NULL,'cancelled')");
        $db->exec("INSERT INTO invoices (id,invoice_number,total,balance_due,due_date,paid_at,contact_id,company_id,status) VALUES(2,'INV-8',50,0,'2026-09-01','2026-09-03',1,NULL,'paid')");
        $a=(new ClientFactAnswerer($db,'2026-11-06'))->answer('does linda owe anything',static function(string $p):bool{return true;});
        self::assertNotNull($a);self::assertStringContainsString('paid up',$a['answer']);
    }

    public function test_undated_invoice_gets_no_made_up_due_date_and_declined_quote_says_so(): void
    {
        $db=$this->db();$db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones',NULL,NULL,1)");
        $db->exec("INSERT INTO invoices (id,invoice_number,total,balance_due,due_date,paid_at,contact_id,company_id) VALUES(1,'INV-1',40,40,NULL,NULL,1,NULL)");
        $db->exec("INSERT INTO invoices (id,invoice_number,total,balance_due,due_date,paid_at,contact_id,company_id) VALUES(2,'INV-2',60,60,'2026-10-01',NULL,1,NULL)");
        $can=static function(string $p):bool{return true;};$a=(new ClientFactAnswerer($db,'2026-11-06'))->answer('does linda owe',$can);
        self::assertStringContainsString('owes $100.00 on 2 invoices, oldest INV-2 due Oct 1',$a['answer']);self::assertStringNotContainsString('Jan 1',$a['answer']);
        $db->exec("INSERT INTO quotes (id,quote_number,title,status,sent_at,contact_id,created_at) VALUES(1,'QUO-1','Cleanup','declined','2026-10-20',1,'2026-10-20')");
        $q=(new ClientFactAnswerer($db,'2026-11-06'))->answer('has linda answered the quote',$can);
        self::assertStringContainsString('declined',$q['answer']);self::assertStringNotContainsString('no reply',$q['answer']);
    }

    public function test_of_several_namesakes_the_one_with_the_record_answers_and_part_words_do_not_count(): void
    {
        $db=$this->db();
        $db->exec("INSERT INTO contacts VALUES(1,'Linda','Chou',NULL,NULL,1)");
        $db->exec("INSERT INTO contacts VALUES(2,'Linda','Tam',NULL,'604-555-0000',1)");
        $db->exec("INSERT INTO contacts VALUES(3,'Belinda','Guttman',NULL,NULL,1)");
        $db->exec("INSERT INTO quotes (id,quote_number,title,status,sent_at,contact_id,created_at) VALUES(1,'QUO-7','Hedges','sent','2026-10-15',2,'2026-10-15')");
        $db->exec("INSERT INTO quotes (id,quote_number,title,status,sent_at,contact_id,created_at) VALUES(2,'QUO-8','Beds','sent','2026-10-16',3,'2026-10-16')");
        $can=static function(string $p):bool{return true;};
        $a=(new ClientFactAnswerer($db,'2026-11-06'))->answer('has lindas quote been sent',$can);
        self::assertNotNull($a);self::assertStringContainsString('Linda Tam',$a['answer']);
        $db->exec("INSERT INTO quotes (id,quote_number,title,status,sent_at,contact_id,created_at) VALUES(3,'QUO-9','Mulch','sent','2026-10-17',1,'2026-10-17')");
        self::assertNull((new ClientFactAnswerer($db,'2026-11-06'))->answer('has lindas quote been sent',$can));
    }
}
