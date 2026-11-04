<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OnboardingStepServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT,last_name TEXT,full_name TEXT,preferred_name TEXT,role TEXT,phone TEXT,contact_channel TEXT,quiet_start TEXT,quiet_end TEXT,quiet_weekends INT DEFAULT 0)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT,description TEXT)');
        $db->exec("CREATE TABLE business_settings (id INTEGER PRIMARY KEY,company_name TEXT,gst_registration TEXT,timezone TEXT DEFAULT 'America/Vancouver')");
        $db->exec('CREATE TABLE business_identity (id INTEGER PRIMARY KEY,legal_name TEXT,operating_name TEXT,registration_number TEXT)');
        $db->exec('CREATE TABLE qbo_connections (site_id INT,status TEXT,refresh_token_enc TEXT)');
        $db->exec('CREATE TABLE qbo_onboarding_parts (site_id INT,part TEXT)');
        $db->exec("INSERT INTO users (id,role) VALUES (7,'admin')");$db->exec("INSERT INTO business_settings (id,company_name,gst_registration) VALUES (1,'Placeholder','')");
        return $db;
    }

    public function test_name_parts_books_and_unknown_actions(): void
    {
        $db=$this->db();$s=new OnboardingStepService($db,'tenant');
        $this->assertTrue($s->apply(7,['key'=>'name','do'=>'save','first'=>'Ada','last'=>'Lovelace','preferred'=>'Ada'])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'name','do'=>'reach','channel'=>'text','mobile'=>'6045550101'])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'name','do'=>'quiet','from'=>'20:00','to'=>'07:00','weekends'=>true])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'books','do'=>'ack'])['ok']);
        $this->assertSame('No such step.',$s->apply(7,['key'=>'nope','do'=>'save'])['message']);
        $this->assertSame('Choose a valid action.',$s->apply(7,['key'=>'name','do'=>'nope'])['message']);
    }

    public function test_company_search_confirm_and_manual_use_injected_registry(): void
    {
        $db=$this->db();
        $identity=new class {
            public function start(string $name): array{return ['ok'=>true];}
            public function search(string $name): array{return ['ok'=>true,'matches'=>[['legal_name'=>'Acme Ltd','registration_number'=>'BC1','status'=>'active','record_id'=>'r1']]];}
            public function confirm(string $id): array{return ['ok'=>true,'identity'=>['operating_name'=>'Acme','legal_name'=>'Acme Ltd']];}
            public function manual(array $in): array{return ['ok'=>true,'identity'=>['legal_name'=>$in['legal_name']]];}
        };
        $s=new OnboardingStepService($db,'tenant',static fn(int $uid)=>$identity);
        $r=$s->apply(7,['key'=>'company','do'=>'search','name'=>'Acme']);$this->assertSame('r1',$r['matches'][0]['record_id']);
        $this->assertSame('ok',OnboardingStepService::registryState('matches'));$this->assertSame('ok',OnboardingStepService::registryState('none'));
        $this->assertSame('unavailable',OnboardingStepService::registryState('unavailable'));$this->assertSame('rate_limited',OnboardingStepService::registryState('rate_limited'));
        $this->assertSame('unavailable',OnboardingStepService::registryState(''));
        $this->assertTrue($s->apply(7,['key'=>'company','do'=>'confirm','record_id'=>'r1'])['ok']);$this->assertSame('Acme',$db->query('SELECT company_name FROM business_settings')->fetchColumn());
        $this->assertTrue($s->apply(7,['key'=>'company','do'=>'manual','name'=>'Manual Co'])['ok']);$this->assertSame('Manual Co',$db->query('SELECT company_name FROM business_settings')->fetchColumn());
    }

    public function test_quickbooks_actions_and_refusal_message_pass_through(): void
    {
        $db=$this->db();
        $qbo=new class { public function pull():array{return ['ok'=>true,'message'=>'Read.'];} public function apply(string $part,int $uid):array{return ['ok'=>false,'message'=>'Preview only.'];} };
        $s=new OnboardingStepService($db,'tenant',null,static fn()=>['connected'=>true,'onboarding'=>$qbo]);
        $this->assertTrue($s->apply(7,['key'=>'quickbooks','do'=>'yes'])['ok']);
        $this->assertSame('Read.',$s->apply(7,['key'=>'quickbooks','do'=>'read'])['message']);
        $this->assertSame('Preview only.',$s->apply(7,['key'=>'quickbooks','do'=>'accept','part'=>'clients'])['message']);
        $disconnected=new OnboardingStepService($db,'tenant',null,static fn()=>['connected'=>false,'onboarding'=>$qbo]);
        $this->assertSame('Connect QuickBooks first.',$disconnected->apply(7,['key'=>'quickbooks','do'=>'read'])['message']);
    }

    public function test_quickbooks_send_action_sets_invoices_date_and_receipts_together(): void
    {
        $db=$this->db();
        $onboarding=new class { public function pull():array{return ['ok'=>true];} public function apply(string $part,int $uid):array{return ['ok'=>true];} };
        $sync=new class { public array $calls=[]; public function setAuto(bool $on,?string $from):bool{$this->calls[]=[$on,$from];return true;} };
        $expenses=new class { public array $calls=[]; public function setReceipts(bool $on):void{$this->calls[]=$on;} };
        $s=new OnboardingStepService($db,'tenant',null,static fn()=>['connected'=>true,'onboarding'=>$onboarding,'sync'=>$sync,'expenses'=>$expenses]);
        $this->assertTrue($s->apply(7,['key'=>'quickbooks','do'=>'send','on'=>true])['ok']);
        $this->assertSame([[true,OnboardingStepService::businessToday($db)]],$sync->calls); $this->assertSame([true],$expenses->calls);
        $this->assertTrue($s->apply(7,['key'=>'quickbooks','do'=>'send','on'=>false])['ok']);
        $this->assertSame([false,null],$sync->calls[1]); $this->assertSame([true,false],$expenses->calls);
    }

    public function test_business_today_uses_the_business_timezone_setting(): void
    {
        $db=$this->db();
        $db->exec("UPDATE business_settings SET timezone='America/Vancouver'");
        $instant=new DateTimeImmutable('2026-11-05 01:30:00',new DateTimeZone('UTC'));
        $this->assertSame('2026-11-04',OnboardingStepService::businessToday($db,$instant));

        $db->exec("UPDATE business_settings SET timezone='' ");
        $before=date_default_timezone_get();
        try {
            date_default_timezone_set('America/Vancouver');
            $this->assertSame('2026-11-04',OnboardingStepService::businessToday($db,$instant),'an empty setting uses PHP configuration');
        } finally { date_default_timezone_set($before); }
    }

    public function test_skip_and_done_gate(): void
    {
        $s=new OnboardingStepService($this->db(),'tenant');
        $this->assertFalse($s->apply(7,['key'=>'done','do'=>'finish'])['ok']);
        $this->assertSame('A step before this one still needs doing — the map shows which.',$s->apply(7,['key'=>'done','do'=>'finish'])['message']);
        $this->assertTrue($s->apply(7,['key'=>'accountant','do'=>'skip'])['ok']);
    }

    public function test_accountant_invite_returns_link_data(): void
    {
        $db=$this->db();
        $invites=new class { public function invite(string $name,string $email,int $uid,string $url):array{return ['link'=>$url.'/invite','expires_at'=>'2026-10-30 10:00:00'];} };
        $s=new OnboardingStepService($db,'tenant',null,null,static fn()=>$invites);
        $bad=$s->apply(7,['key'=>'accountant','do'=>'invite','name'=>'','email'=>'bad']);$this->assertFalse($bad['ok']);
        $r=$s->apply(7,['key'=>'accountant','do'=>'invite','name'=>'Pat Books','email'=>'pat@example.com','_site_url'=>'https://tenant.test']);
        $this->assertSame(['link'=>'https://tenant.test/invite','expires_at'=>'2026-10-30 10:00:00','name'=>'Pat Books'],$r['invite']);
    }
}
