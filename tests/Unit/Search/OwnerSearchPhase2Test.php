<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Owner search phase 2: one-person scope, filter words, learned picks, verbs in the box, prefilled messages. */
final class OwnerSearchPhase2Test extends TestCase
{
    private function db(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY,first_name TEXT,last_name TEXT,email TEXT,phone TEXT,is_active INT,company_id INT)');
        $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY,company_name TEXT,company_type TEXT,billing_city TEXT,billing_email TEXT,account_status TEXT)');
        $db->exec('CREATE TABLE search_picks (id INTEGER PRIMARY KEY,user_id INT,prefix TEXT,kind TEXT,entity_id INT,picks INT,last_at TEXT,UNIQUE(user_id,prefix,kind,entity_id))');
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY,address TEXT,city TEXT,postal_code TEXT,status TEXT,site_contact_id INT,client_id INT,company_id INT)');
        $db->exec('CREATE TABLE property_contacts (property_id INT,contact_id INT)');
        $db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY,quote_number TEXT,title TEXT,status TEXT,amount REAL,total_amount REAL,contact_id INT,company_id INT,property_id INT,sent_at TEXT,accepted_at TEXT,email_opened_at TEXT,viewed_at TEXT,created_at TEXT)');
        $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY,invoice_number TEXT,bill_to_name TEXT,service_address TEXT,total REAL,balance_due REAL,status TEXT,contact_id INT,company_id INT,due_date TEXT,paid_at TEXT,created_at TEXT)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY,plan_number TEXT,title TEXT,status TEXT,service_type TEXT,client_id INT,property_id INT,company_id INT,created_at TEXT)');
        $db->exec('CREATE TABLE contracts (id INTEGER PRIMARY KEY,contract_number TEXT,title TEXT,status TEXT,property_id INT,contact_id INT,company_id INT)');
        $db->exec('CREATE TABLE sales_messages (id INTEGER PRIMARY KEY,contact_id INT,direction TEXT,subject TEXT,snippet TEXT,from_addr TEXT,sent_at TEXT)');
        return$db;
    }

    private static function all(): callable { return static function(string $p):bool{return true;}; }

    private function seed(PDO $db): void
    {
        $db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones','linda@example.test','604-555-1234',1,10),(2,'Linda','Smith',NULL,NULL,1,NULL),(3,'Bob','Jones',NULL,'604-555-9999',1,10)");
        $db->exec("INSERT INTO companies VALUES(10,'Acme Strata','strata','Burnaby','acme@example.test','active')");
        $db->exec("INSERT INTO properties VALUES(1,'10 Main St','Van','V1A','active',1,NULL,NULL),(2,'20 Oak St','Van','V1B','active',NULL,NULL,10),(3,'30 Elm St','Van','V1C','active',2,NULL,NULL)");
        $db->exec("INSERT INTO quotes (id,quote_number,title,status,total_amount,contact_id,company_id,sent_at,created_at) VALUES
            (1,'QUO-1','Hedges','draft',100,1,NULL,NULL,'2026-11-01'),
            (2,'QUO-2','Mulch','sent',200,1,NULL,'2026-11-02','2026-11-02'),
            (3,'QUO-3','Beds','draft',300,2,NULL,NULL,'2026-11-03'),
            (4,'QUO-4','Lawn','accepted',400,3,10,'2026-10-01','2026-10-01')");
        $db->exec("INSERT INTO invoices (id,invoice_number,total,balance_due,status,contact_id,company_id,due_date,created_at) VALUES
            (1,'INV-1',100,100,'sent',1,NULL,'2026-10-01','2026-09-20'),
            (2,'INV-2',50,50,'sent',2,NULL,'2026-09-01','2026-08-20'),
            (3,'INV-3',70,70,'draft',1,NULL,'2026-08-01','2026-07-20'),
            (4,'INV-4',90,90,'sent',1,NULL,'2026-12-01','2026-11-01'),
            (5,'INV-5',60,0,'paid',1,NULL,'2026-10-10','2026-10-01')");
        $db->exec("INSERT INTO job_plans VALUES(1,'JOB-1','Hedge trim','active','trim',1,NULL,NULL,'2026-10-01'),(2,'JOB-2','Elm care','active','care',NULL,3,NULL,'2026-10-02'),(3,'JOB-3','Strata care','active','care',NULL,NULL,10,'2026-10-03')");
        $db->exec("INSERT INTO sales_messages VALUES(1,1,'inbound','Gate','Use side gate','linda@example.test','2026-11-04 09:00:00'),(2,2,'inbound','Hello','Hi','x@example.test','2026-11-05 09:00:00')");
    }

    private function groupIds(array $result): array
    {
        $out=[];foreach($result['groups']as$g)$out[$g['key']]=array_column($g['items'],'id');return$out;
    }

    protected function tearDown(): void { BusinessIdentity::reset(); }

    public function test_scope_to_a_contact_limits_every_group_and_allows_an_empty_query(): void
    {
        $db=$this->db();$this->seed($db);
        $r=(new UniversalSearchService($db,'2026-11-06'))->search('',self::all(),7,5,['kind'=>'contact','id'=>1]);
        $ids=$this->groupIds($r);
        self::assertArrayNotHasKey('people',$ids);self::assertArrayNotHasKey('vendors',$ids);self::assertArrayNotHasKey('team',$ids);
        self::assertSame([1],$ids['properties']);
        self::assertSame([2,1],$ids['quotes']);
        self::assertSame([4,5,1],array_slice($ids['invoices'],0,3)) ;
        self::assertSame([1],$ids['jobs']);
        self::assertSame([1],$ids['messages']);
    }

    public function test_scope_still_filters_by_the_words_and_follows_property_contacts(): void
    {
        $db=$this->db();$this->seed($db);$db->exec('INSERT INTO property_contacts VALUES(2,1)');
        $service=new UniversalSearchService($db,'2026-11-06');
        $r=$service->search('mulch',self::all(),7,5,['kind'=>'contact','id'=>1]);
        self::assertSame(['quotes'=>[2]],$this->groupIds($r));
        $props=$this->groupIds($service->search('',self::all(),7,5,['kind'=>'contact','id'=>1]))['properties'];
        sort($props);self::assertSame([1,2],$props);
    }

    public function test_scope_to_a_company_uses_company_columns_and_skips_receipts(): void
    {
        $db=$this->db();$this->seed($db);
        $ids=$this->groupIds((new UniversalSearchService($db,'2026-11-06'))->search('',self::all(),7,5,['kind'=>'company','id'=>10]));
        self::assertSame([2],$ids['properties']);self::assertSame([4],$ids['quotes']);self::assertSame([3],$ids['jobs']);
        self::assertArrayNotHasKey('invoices',$ids);self::assertArrayNotHasKey('receipts',$ids);
        self::assertSame([1],$ids['messages']);   // Linda works for the company; Bob has no messages
    }

    public function test_filter_words(): void
    {
        self::assertSame(['words'=>['Linda'],'groups'=>[],'state'=>['unpaid']],UniversalSearchService::filterWords('Linda owing'));
        self::assertSame(['words'=>[],'groups'=>['quotes'],'state'=>['draft']],UniversalSearchService::filterWords('draft QUOTES'));
        self::assertSame(['words'=>['linda'],'groups'=>['messages','people'],'state'=>[]],UniversalSearchService::filterWords('linda texts clients'));
    }

    public function test_overdue_alone_lists_overdue_invoices_oldest_first(): void
    {
        $db=$this->db();$this->seed($db);
        $r=(new UniversalSearchService($db,'2026-11-06'))->search('overdue',self::all(),7);
        self::assertSame(['invoices'=>[2,1]],$this->groupIds($r));   // draft INV-3, future INV-4 and paid INV-5 are out
    }

    public function test_name_plus_unpaid_and_draft_quotes_and_scope_plus_unpaid(): void
    {
        $db=$this->db();$this->seed($db);
        $service=new UniversalSearchService($db,'2026-11-06');
        $ids=$this->groupIds($service->search('linda unpaid',self::all(),7,10))['invoices'];
        sort($ids);self::assertSame([1,2,4],$ids);   // both Lindas' unpaid, no draft, no paid
        $ids=$this->groupIds($service->search('draft quotes',self::all(),7));
        self::assertSame(['quotes'=>[3,1]],$ids);
        $ids=$this->groupIds($service->search('unpaid',self::all(),7,10,['kind'=>'contact','id'=>2]));
        self::assertSame(['invoices'=>[2]],$ids);
    }

    public function test_learned_results_come_first_skip_missing_and_unseen_and_not_under_scope(): void
    {
        $db=$this->db();$this->seed($db);
        $service=new UniversalSearchService($db,'2026-11-06');
        // "gate" finds only the message; the owner then picked Bob (never matched), a deleted quote and an invoice they cannot see.
        $service->recordPick(7,'gate','contact',3);$service->recordPick(7,'gate','quote',999);$service->recordPick(7,'gate','invoice',1);
        $limited=static function(string $p):bool{return $p!=='billing.view';};
        $r=$service->search('gate',$limited,7);
        self::assertSame(['learned'],array_column($r['groups'],'key'));   // billing.view missing: no messages group, the invoice pick is hidden
        self::assertSame(['contact:3'],array_map(static function($i){return $i['kind'].':'.$i['id'];},$r['groups'][0]['items']));
        $r=$service->search('gate',static function(string $p):bool{return $p==='clients.view';},7);
        self::assertSame('learned',$r['groups'][0]['key']);self::assertSame('You picked before',$r['groups'][0]['title']);
        self::assertSame([3],array_column($r['groups'][0]['items'],'id'));self::assertSame('contact',$r['groups'][0]['items'][0]['kind']);
        $r=$service->search('gate',self::all(),7);
        self::assertSame('learned',$r['groups'][0]['key']);
        self::assertSame(['contact:3','invoice:1'],array_map(static function($i){return $i['kind'].':'.$i['id'];},$r['groups'][0]['items']));
        self::assertSame('messages',$r['groups'][1]['key']);
        $r=$service->search('gate',self::all(),7,5,['kind'=>'contact','id'=>1]);
        self::assertNotContains('learned',array_column($r['groups'],'key'));
        self::assertSame('star',UniversalSearchService::flatForWeb([['key'=>'learned','title'=>'You picked before','items'=>[['label'=>'Bob','url'=>'/x']]]])[0]['icon']);
    }

    public function test_call_with_and_without_a_phone(): void
    {
        $db=$this->db();$this->seed($db);$a=new ClientFactAnswerer($db,'2026-11-06');
        $r=$a->command('call linda jones',self::all());
        self::assertSame('command',$r['intent']);self::assertSame('Call Linda Jones — 604-555-1234',$r['answer']);
        self::assertSame(['call','text'],array_column($r['actions'],'kind'));
        self::assertNull($a->command('call linda smith',self::all()));          // no phone
        self::assertNull($a->command('find linda',self::all()));                // not a verb
        self::assertSame(['email'],array_column($a->command('email linda jones',self::all())['actions'],'kind'));
        self::assertSame(['text'],array_column($a->command('text bob jones',self::all())['actions'],'kind'));
    }

    public function test_namesakes_resolve_to_the_one_who_can_act_and_scope_names_the_person(): void
    {
        $db=$this->db();$this->seed($db);$a=new ClientFactAnswerer($db,'2026-11-06');
        $r=$a->command('call linda',self::all());           // Smith has no phone
        self::assertSame(1,$r['about']['id']);
        self::assertNull($a->command('open linda',self::all()));   // both can be opened
        $r=$a->command('open',self::all(),['kind'=>'contact','id'=>2]);
        self::assertSame(2,$r['about']['id']);self::assertSame('open',$r['actions'][0]['kind']);
        self::assertSame('/crm/clients_appstack.php?action=view_contact&id=2',$r['actions'][0]['url']);
    }

    public function test_nudge_picks_the_sent_unanswered_quote_and_signs_with_the_business(): void
    {
        $db=$this->db();$this->seed($db);
        BusinessIdentity::use(['name'=>'Greenline','owner_name'=>'Sam Rivera']);
        $r=(new ClientFactAnswerer($db,'2026-11-06'))->command('nudge linda jones',self::all());
        self::assertSame('Nudge Linda Jones about QUO-2 (Mulch), sent Nov 2',$r['answer']);
        self::assertSame(['text','email','open'],array_column($r['actions'],'kind'));
        $text=$r['actions'][0];
        self::assertSame('Hi Linda, just checking in on the quote we sent on Nov 2 for Mulch (QUO-2). Happy to answer any questions. — Sam at Greenline',$text['body']);
        self::assertSame('Your quote QUO-2',$r['actions'][1]['subject']);
        self::assertStringNotContainsString('http',$text['body']);
        self::assertNull((new ClientFactAnswerer($db,'2026-11-06'))->command('nudge linda smith',self::all()));   // only a draft
        $db->exec("UPDATE quotes SET accepted_at='2026-11-03' WHERE id=2");
        self::assertNull((new ClientFactAnswerer($db,'2026-11-06'))->command('follow up linda jones',self::all()));
    }

    public function test_sign_off_drops_missing_pieces(): void
    {
        $db=$this->db();$this->seed($db);
        BusinessIdentity::use(['name'=>'Greenline','owner_name'=>'']);
        $b=(new ClientFactAnswerer($db,'2026-11-06'))->command('nudge linda jones',self::all())['actions'][0]['body'];
        self::assertStringEndsWith('questions. — Greenline',$b);
        BusinessIdentity::use(['name'=>'','owner_name'=>'Sam']);
        $b=(new ClientFactAnswerer($db,'2026-11-06'))->command('nudge linda jones',self::all())['actions'][0]['body'];
        self::assertStringEndsWith('questions.',$b);
        $db->exec("UPDATE quotes SET title='' WHERE id=2");
        $b=(new ClientFactAnswerer($db,'2026-11-06'))->command('nudge linda jones',self::all())['actions'][0]['body'];
        self::assertStringContainsString('on Nov 2 (QUO-2).',$b);
    }

    public function test_remind_wording_overdue_versus_due_and_answers_gain_remind_actions(): void
    {
        $db=$this->db();$this->seed($db);
        BusinessIdentity::use(['name'=>'Greenline','owner_name'=>'Sam']);
        $a=new ClientFactAnswerer($db,'2026-11-06');
        $r=$a->command('remind linda jones',self::all());
        self::assertSame('Remind Linda Jones about INV-1, $100.00 overdue 36 days',$r['answer']);
        self::assertSame(['text','email','open'],array_column($r['actions'],'kind'));
        self::assertStringContainsString('invoice INV-1 for $100.00 was due on Oct 1. Thank you! — Sam at Greenline',$r['actions'][0]['body']);
        self::assertSame('Invoice INV-1',$r['actions'][1]['subject']);
        $db->exec("UPDATE invoices SET balance_due=0,status='paid' WHERE id=1");   // oldest unpaid is now INV-4, due Dec 1
        $r=$a->command('remind linda jones',self::all());
        self::assertSame('Remind Linda Jones about INV-4, $90.00 due Dec 1',$r['answer']);
        self::assertStringContainsString('is due on Dec 1',$r['actions'][0]['body']);
        $paid=$a->answer('does linda jones owe anything',self::all());
        self::assertSame(['open','call','text','email','text','email'],array_column($paid['actions'],'kind'));
        self::assertSame('Remind by text',$paid['actions'][4]['label']);
        self::assertSame('Remind by email',$paid['actions'][5]['label']);
        self::assertNull($a->command('remind bob jones',self::all()));   // nothing owing
    }
}
