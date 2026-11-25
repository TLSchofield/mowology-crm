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
        $recurring=new class { public function pausePrompt():?string{return 'QuickBooks still makes 1 recurring invoice: Care — $100.00.';} };
        $s=new OnboardingStepService($db,'tenant',null,static fn()=>['connected'=>true,'onboarding'=>$onboarding,'sync'=>$sync,'expenses'=>$expenses,'recurring'=>$recurring]);
        $on=$s->apply(7,['key'=>'quickbooks','do'=>'send','on'=>true]);
        $this->assertTrue($on['ok']);
        $this->assertStringContainsString('QuickBooks still makes 1 recurring invoice', $on['message']);
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

    private function withTerms(PDO $db): void
    {
        $db->exec("CREATE TABLE terms_versions (id INTEGER PRIMARY KEY, doc_key TEXT DEFAULT 'platform', version TEXT, is_current INT DEFAULT 0, activated_at TEXT, created_at TEXT, UNIQUE (doc_key, version))");
        $db->exec("CREATE TABLE terms_acceptances (id INTEGER PRIMARY KEY, user_id INT, doc_key TEXT DEFAULT 'platform', version TEXT, accepted_at TEXT DEFAULT CURRENT_TIMESTAMP, ip_address TEXT, user_agent TEXT, UNIQUE (user_id, doc_key, version))");
    }

    public function test_terms_accept_saves_and_records_for_a_non_default_business_only(): void
    {
        $db=$this->db();$this->withTerms($db);
        try {
            Tenant::forTests(null);   // Mowology: only the setting, no gate switched on
            $s=new OnboardingStepService($db,'tenant');
            $this->assertTrue($s->apply(7,['key'=>'terms','do'=>'accept','_ip'=>'1.2.3.4','_ua'=>'UA'])['ok']);
            $this->assertSame(OnboardingTermsModule::TERMS_VERSION,$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='onboarding_terms_accepted'")->fetchColumn());
            $this->assertSame(0,(int)$db->query('SELECT COUNT(*) FROM terms_versions')->fetchColumn());

            Tenant::forTests(['key'=>'greenleaf','cfg'=>['db_name'=>'x']]);
            $this->assertTrue($s->apply(7,['key'=>'terms','do'=>'accept','_ip'=>'1.2.3.4','_ua'=>'UA'])['ok']);
            $this->assertTrue($s->apply(7,['key'=>'terms','do'=>'accept'])['ok'],'twice is fine');
            $row=$db->query('SELECT version, is_current FROM terms_versions')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(OnboardingTermsModule::TERMS_VERSION,$row['version']);$this->assertSame(1,(int)$row['is_current']);
            $this->assertSame('1.2.3.4',$db->query('SELECT ip_address FROM terms_acceptances WHERE user_id=7')->fetchColumn());
            $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM terms_acceptances')->fetchColumn());
        } finally { Tenant::forTests(null); }
    }

    public function test_a_terms_version_someone_set_non_current_stays_off(): void
    {
        $db=$this->db();$this->withTerms($db);
        $db->exec("INSERT INTO terms_versions (doc_key, version, is_current) VALUES ('platform', '2026-01-01', 0)");
        try {
            Tenant::forTests(['key'=>'greenleaf','cfg'=>['db_name'=>'x']]);
            $this->assertTrue((new OnboardingStepService($db,'tenant'))->apply(7,['key'=>'terms','do'=>'accept'])['ok']);
            $this->assertSame(0,(int)$db->query('SELECT COUNT(*) FROM terms_versions WHERE is_current = 1')->fetchColumn());
            $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM terms_versions')->fetchColumn());
        } finally { Tenant::forTests(null); }
    }

    public function test_terms_accept_survives_missing_tables(): void
    {
        $db=$this->db();
        try {
            Tenant::forTests(['key'=>'greenleaf','cfg'=>['db_name'=>'x']]);
            $r=(new OnboardingStepService($db,'tenant'))->apply(7,['key'=>'terms','do'=>'accept']);
            $this->assertTrue($r['ok']);
            $this->assertSame(OnboardingTermsModule::TERMS_VERSION,$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='onboarding_terms_accepted'")->fetchColumn());
        } finally { Tenant::forTests(null); }
    }

    public function test_gst_yearend_and_payroll_answers(): void
    {
        $db=$this->db();$s=new OnboardingStepService($db,'tenant');
        $this->assertFalse($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'yes','number'=>'12'])['ok']);
        $this->assertFalse($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'yes','filing'=>'weekly'])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'yes','number'=>'123456789 RT0001','filing'=>'annual'])['ok']);
        $this->assertSame('123456789RT0001',$db->query('SELECT gst_registration FROM business_settings WHERE id=1')->fetchColumn());
        $this->assertSame('annual',$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='gst_filing'")->fetchColumn());
        $this->assertTrue($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'no'])['ok']);
        $this->assertSame('no',$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='gst_registered'")->fetchColumn());
        $this->assertFalse($s->apply(7,['key'=>'yearend','do'=>'answer','who'=>'x'])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'yearend','do'=>'answer','who'=>'self'])['ok']);
        $this->assertFalse($s->apply(7,['key'=>'payroll','do'=>'answer','how'=>'app','app'=>str_repeat('a',61)])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'payroll','do'=>'answer','how'=>'app','app'=>' Wave '])['ok']);
        $this->assertSame('Wave',$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='onboarding_payroll_app'")->fetchColumn());
        $this->assertSame('Choose a valid action.',$s->apply(7,['key'=>'gst','do'=>'nope'])['message']);
    }

    public function test_an_old_app_cannot_reach_or_see_the_new_steps(): void
    {
        $db=$this->db();$s=new OnboardingStepService($db,'tenant');
        $this->assertSame('No such step.',$s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'no','knows'=>''])['message']);
        $this->assertSame('No such step.',$s->apply(7,['key'=>'terms','do'=>'accept','knows'=>'gst'])['message']);
        $this->assertTrue($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'no','knows'=>'gst,terms'])['ok']);
        $this->assertSame('name',$s->apply(7,['key'=>'books','do'=>'ack','knows'=>''])['next'],'next is a step the old app knows');
    }

    public function test_contacts_finish_replaces_total_skip_sets_answer_and_old_apps_cannot_reach_it(): void
    {
        $db=$this->db();$s=new OnboardingStepService($db,'tenant');
        $this->assertSame('No such step.',$s->apply(7,['key'=>'contacts','do'=>'finish','count'=>2,'knows'=>''])['message']);
        $this->assertFalse($s->apply(7,['key'=>'contacts','do'=>'finish','count'=>-1,'knows'=>'contacts'])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'contacts','do'=>'finish','count'=>12,'knows'=>'contacts'])['ok']);
        $this->assertTrue($s->apply(7,['key'=>'contacts','do'=>'finish','count'=>3,'knows'=>'contacts'])['ok']);
        $this->assertSame('3',$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='onboarding_contacts_count'")->fetchColumn());
        $this->assertTrue($s->apply(7,['key'=>'contacts','do'=>'skip','knows'=>'contacts'])['ok']);
        $this->assertSame('skipped',$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='onboarding_contacts_answer'")->fetchColumn());
        $tooMany=$s->apply(7,['key'=>'contacts','do'=>'import','contacts'=>array_fill(0,201,[]),'knows'=>'contacts']);
        $this->assertFalse($tooMany['ok']);$this->assertSame('too_many',$tooMany['error']);
    }

    public function test_google_contacts_start_list_and_import_source_use_injected_services(): void
    {
        $db=$this->db();
        $stage=new class {
            public ?array $answer=null;
            public function take(string $nonce,int $userId,string $grant):?array{return $this->answer;}
        };
        $google=static fn(PDO $db)=>['client_id'=>'client-id','state_secret'=>'state-secret','business_key'=>'greenleaf','stage'=>$stage];
        $importer=new class {
            public string $source='';
            public function import(array $contacts,string $source='phone'):array{$this->source=$source;return [['ref'=>'people/1','outcome'=>'created','contact_id'=>9]];}
        };
        $service=new OnboardingStepService($db,'tenant',null,null,null,$google,static fn(PDO $db)=>$importer);
        $start=$service->apply(7,['key'=>'contacts','do'=>'google_start','knows'=>'contacts']);
        $this->assertTrue($start['ok']);$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/',$start['nonce']);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?',$start['url']);
        $expired=$service->apply(7,['key'=>'contacts','do'=>'google_list','nonce'=>$start['nonce'],'knows'=>'contacts']);
        $this->assertSame('expired',$expired['error']);
        $stage->answer=[['ref'=>'people/1','first'=>'Ada']];
        // No grant (the secret that only the browser redirect carries) = no list, whatever the stage holds.
        $noGrant=$service->apply(7,['key'=>'contacts','do'=>'google_list','nonce'=>$start['nonce'],'knows'=>'contacts']);
        $this->assertSame('expired',$noGrant['error']);
        $listed=$service->apply(7,['key'=>'contacts','do'=>'google_list','nonce'=>$start['nonce'],'grant'=>str_repeat('a',32),'knows'=>'contacts']);
        $this->assertSame('Ada',$listed['contacts'][0]['first']);
        $service->apply(7,['key'=>'contacts','do'=>'import','source'=>'google','contacts'=>[['ref'=>'people/1','first'=>'Ada']],'knows'=>'contacts']);
        $this->assertSame('google',$importer->source);
    }

    public function test_google_start_reports_not_configured_without_server_constants(): void
    {
        if (defined('ITTAI_GOOGLE_WEB_CLIENT_ID') || defined('ITTAI_GOOGLE_WEB_CLIENT_SECRET')) $this->markTestSkipped('Server constants are present.');
        $result=(new OnboardingStepService($this->db(),'tenant'))->apply(7,['key'=>'contacts','do'=>'google_start','knows'=>'contacts']);
        $this->assertFalse($result['ok']);$this->assertSame('not_configured',$result['error']);
        $this->assertSame('Google is not set up on this server yet.',$result['message']);
    }

    private function snapshot(PDO $db): void
    {
        $db->exec('CREATE TABLE qbo_onboarding_snapshot (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, data TEXT, pulled_at TEXT)');
        $ins=$db->prepare('INSERT INTO qbo_onboarding_snapshot (site_id, kind, qbo_id, data) VALUES (1, ?, ?, ?)');
        $ins->execute(['agency','1',json_encode(['DisplayName'=>'No Tax Agency','TaxAgencyConfig'=>'HIDDEN_AGENCY'])]);
        $ins->execute(['agency','2',json_encode(['DisplayName'=>'Canada Revenue Agency','TaxRegistrationNumber'=>'99-1234567','TaxTrackedOnSales'=>true,'TaxTrackedOnPurchases'=>true,'LastFileDate'=>'2025-12-23','TaxAgencyConfig'=>'SYSTEM_GENERATED'])]);
        $ins->execute(['company','3',json_encode(['FiscalYearStartMonth'=>'January','Country'=>'CA','NameValue'=>[['Name'=>'PayrollFeature','Value'=>'false']]])]);
    }

    public function test_qbo_facts_from_the_sample_rows(): void
    {
        $f=OnboardingQboFacts::from(
            [['DisplayName'=>'No Tax Agency','TaxAgencyConfig'=>'HIDDEN_AGENCY'],['DisplayName'=>'Canada Revenue Agency','TaxRegistrationNumber'=>'99-1234567','TaxTrackedOnSales'=>true,'LastFileDate'=>'2025-12-23','TaxAgencyConfig'=>'SYSTEM_GENERATED']],
            ['FiscalYearStartMonth'=>'January','NameValue'=>[['Name'=>'PayrollFeature','Value'=>'false']]]);
        $this->assertTrue($f['gst_registered']);$this->assertSame('991234567',$f['gst_number'],'99-1234567 is 9 digits once the dash goes');$this->assertSame('99-1234567',$f['gst_number_raw']);
        $this->assertSame('2025-12-23',$f['gst_last_filed']);$this->assertSame('January',$f['fiscal_start']);$this->assertSame('December',$f['fiscal_end']);$this->assertFalse($f['payroll_in_qbo']);
        $g=OnboardingQboFacts::from([['DisplayName'=>'CRA','TaxRegistrationNumber'=>'123456789RT0001','TaxTrackedOnSales'=>true]],['FiscalYearStartMonth'=>'April','NameValue'=>[['Name'=>'PayrollFeature','Value'=>'true']]]);
        $this->assertSame('123456789RT0001',$g['gst_number']);$this->assertSame('March',$g['fiscal_end']);$this->assertTrue($g['payroll_in_qbo']);
        $this->assertFalse(OnboardingQboFacts::from([['DisplayName'=>'No Tax Agency','TaxAgencyConfig'=>'HIDDEN_AGENCY'],['DisplayName'=>'Other','TaxTrackedOnSales'=>false]],[])['gst_registered']);
        $n=OnboardingQboFacts::from([],[]);$this->assertNull($n['gst_registered']);$this->assertNull($n['fiscal_start']);$this->assertNull($n['payroll_in_qbo']);
    }

    public function test_no_snapshot_means_null_facts_and_todays_questions(): void
    {
        $db=$this->db();$c=new OnboardingContext($db,7);
        $this->assertNull(OnboardingQboFacts::read($c));
        $this->assertSame('Is your business registered for GST?',(new OnboardingGstModule())->question($c));
        $this->assertSame('Who does your year-end — an accountant, or you?',(new OnboardingYearEndModule())->question($c));
        $this->assertSame('How do you run payroll today?',(new OnboardingPayrollModule())->question($c));
        $this->snapshot($db);   // table created after: a read with rows now
        $c=new OnboardingContext($db,7);$this->assertTrue(OnboardingQboFacts::read($c)['gst_registered']);
    }

    public function test_questions_and_answers_use_what_quickbooks_said(): void
    {
        $db=$this->db();$this->snapshot($db);$c=new OnboardingContext($db,7);
        $this->assertSame("QuickBooks says you're registered for GST, number 991234567. Is that right — and how often do you file?",(new OnboardingGstModule())->question($c));
        $this->assertSame('Your books run January to December (from QuickBooks). Who does your year-end — an accountant, or you?',(new OnboardingYearEndModule())->question($c));
        $this->assertSame('How do you run payroll today?',(new OnboardingPayrollModule())->question($c),'QuickBooks says no payroll feature');
        $s=new OnboardingStepService($db,'tenant');
        $this->assertTrue($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'yes','filing'=>'quarterly'])['ok']);
        $this->assertSame('991234567',$db->query('SELECT gst_registration FROM business_settings WHERE id=1')->fetchColumn(),'the QuickBooks number, normalised');
        $this->assertTrue($s->apply(7,['key'=>'yearend','do'=>'answer','who'=>'accountant'])['ok']);
        $this->assertSame('January',$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='onboarding_fiscal_start'")->fetchColumn());
    }

    public function test_an_invalid_quickbooks_number_leaves_the_setting_and_asks(): void
    {
        $f=OnboardingQboFacts::from([['DisplayName'=>'Canada Revenue Agency','TaxRegistrationNumber'=>'12-345','TaxTrackedOnSales'=>true]],[]);
        $this->assertTrue($f['gst_registered']);$this->assertNull($f['gst_number']);$this->assertSame('12-345',$f['gst_number_raw']);
        $db=$this->db();
        $db->exec('CREATE TABLE qbo_onboarding_snapshot (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, data TEXT, pulled_at TEXT)');
        $db->prepare('INSERT INTO qbo_onboarding_snapshot (site_id, kind, qbo_id, data) VALUES (1, ?, ?, ?)')->execute(['agency','1',json_encode(['DisplayName'=>'CRA','TaxRegistrationNumber'=>'12-345','TaxTrackedOnSales'=>true])]);
        $this->assertTrue((new OnboardingStepService($db,'tenant'))->apply(7,['key'=>'gst','do'=>'answer','registered'=>'yes'])['ok']);
        $this->assertSame('',(string)$db->query('SELECT gst_registration FROM business_settings WHERE id=1')->fetchColumn());
    }

    public function test_a_valid_quickbooks_number_is_used_and_payroll_is_one_tap(): void
    {
        $db=$this->db();
        $db->exec('CREATE TABLE qbo_onboarding_snapshot (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, data TEXT, pulled_at TEXT)');
        $ins=$db->prepare('INSERT INTO qbo_onboarding_snapshot (site_id, kind, qbo_id, data) VALUES (1, ?, ?, ?)');
        $ins->execute(['agency','1',json_encode(['DisplayName'=>'Canada Revenue Agency','TaxRegistrationNumber'=>'123456789 RT0001','TaxTrackedOnSales'=>true])]);
        $ins->execute(['company','2',json_encode(['FiscalYearStartMonth'=>'April','NameValue'=>[['Name'=>'PayrollFeature','Value'=>'true']]])]);
        $c=new OnboardingContext($db,7);$s=new OnboardingStepService($db,'tenant');
        $this->assertSame('You run payroll in QuickBooks — is that right?',(new OnboardingPayrollModule())->question($c));
        $this->assertTrue($s->apply(7,['key'=>'gst','do'=>'answer','registered'=>'yes'])['ok']);
        $this->assertSame('123456789RT0001',$db->query('SELECT gst_registration FROM business_settings WHERE id=1')->fetchColumn());
        $this->assertTrue($s->apply(7,['key'=>'payroll','do'=>'answer','how'=>'app','app'=>OnboardingPayrollModule::QBO_APP])['ok']);
        $this->assertSame('Payroll: QuickBooks Payroll.',(new OnboardingPayrollModule())->summary(new OnboardingContext($db,7)));
    }
}
