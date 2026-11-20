<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BusinessIdentityServiceTest extends TestCase
{
    private PDO $db;
    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE business_settings (id INTEGER PRIMARY KEY, gst_registration TEXT, company_website TEXT, company_phone TEXT, company_address TEXT)');
        $this->db->exec("INSERT INTO business_settings VALUES (1, '', '', '', '')");
        $this->db->exec('CREATE TABLE business_identity (id INTEGER PRIMARY KEY, entered_name TEXT, legal_name TEXT, operating_name TEXT, registration_number TEXT, registration_type TEXT, legal_structure TEXT, jurisdiction TEXT, registration_status TEXT, source TEXT, source_record_id TEXT, registry_business_number TEXT, registry_verified_at TEXT, owner_confirmed_at TEXT, entered_name_provenance TEXT, legal_name_provenance TEXT, operating_name_provenance TEXT, registration_number_provenance TEXT, registration_type_provenance TEXT, legal_structure_provenance TEXT, jurisdiction_provenance TEXT, registration_status_provenance TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_by INTEGER)');
    }
    private function record(array $replace=[]): RegistryRecord
    {return new RegistryRecord(array_merge(['record_id'=>'BC1234567','legal_name'=>'Acme Lawn Ltd.','operating_names'=>['Green Acme'],'registration_number'=>'BC1234567','registration_type'=>'bc_registration','legal_structure'=>'BC company','status'=>'active','jurisdiction'=>'BC','registered_on'=>'2020-01-02','registry_business_number'=>'123456789'], $replace));}
    private function service(?FakeRegistryProvider $fake=null): BusinessIdentityService
    {$fake=$fake??new FakeRegistryProvider(null,['BC1234567'=>$this->record()]);return new BusinessIdentityService($this->db,$fake,7);}

    public function testExactNameIgnoresPunctuationAndLegalSuffix(): void
    {$this->assertSame(OrgBookBcProvider::normaliseName('ACME, LTD.'),OrgBookBcProvider::normaliseName('Acme'));}
    public function testSeveralSimilarTradeNameAndNoMatchResultsRemainProviderControlled(): void
    {$matches=[['record_id'=>'1','legal_name'=>'Acme One','operating_names'=>['Trade One'],'match'=>'similar'],['record_id'=>'2','legal_name'=>'Acme Two','match'=>'similar']];$s=$this->service(new FakeRegistryProvider(new RegistrySearch('matches',$matches)));$this->assertSame($matches,$s->search('Acme')['matches']);$this->assertSame('none',$this->service(new FakeRegistryProvider(new RegistrySearch('none')))->search('Missing')['status']);}
    public function testProviderUnavailableAndIncompleteRecordFailSoft(): void
    {$this->assertSame('unavailable',$this->service(new FakeRegistryProvider(new RegistrySearch('unavailable')))->search('Acme')['status']);$s=$this->service(new FakeRegistryProvider(null,['X'=>new RegistryRecord(['record_id'=>'X'])]));$this->assertTrue($s->start('Acme')['ok']);$this->assertTrue($s->confirm('X')['ok']);}
    public function testRejectingSearchResultWritesNothing(): void
    {$this->service(new FakeRegistryProvider(new RegistrySearch('matches',[['record_id'=>'X']])))->search('Acme');$this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM business_identity')->fetchColumn());}
    public function testConfirmRefetchesAndIgnoresClientFieldsWithRegistryProvenance(): void
    {$fake=new FakeRegistryProvider(null,['BC1234567'=>$this->record()]);$s=$this->service($fake);$s->start('Acme');$current=$s->confirm('BC1234567');$this->assertSame(1,$fake->fetchCount);$this->assertSame('Acme Lawn Ltd.',$current['identity']['legal_name']);$this->assertSame('registry_verified',$current['identity']['provenance']['legal_name']);$this->assertArrayNotHasKey('registry_business_number',$current['identity']);}
    public function testManualAndReturningUser(): void
    {$s=$this->service();$current=$s->manual(['legal_name'=>'Owner Supplied','operating_name'=>'Trade']);$this->assertSame('identified',$current['knowledge']['stage']);$this->assertSame('owner_provided',$s->current()['identity']['provenance']['legal_name']);}
    public function testJwtPostBodyIsParsedWithoutCsrfRequirement(): void
    {$request=BusinessIdentityRequest::post('{"name":"Phone Business"}',[],[],true);$this->assertSame(['name'=>'Phone Business'],$request['body']);$this->assertFalse($request['csrf_required']);}
    public function testSessionPostRequiresCsrfAndAcceptsFormBody(): void
    {$request=BusinessIdentityRequest::post('',['legal_name'=>'Form Business','csrf_token'=>'token'],[],false);$this->assertSame('Form Business',$request['body']['legal_name']);$this->assertTrue($request['csrf_required']);$this->assertSame('token',$request['csrf_token']);}
    public function testManualRejectsArrayValue(): void
    {$this->expectException(InvalidArgumentException::class);$this->expectExceptionMessage('Please check what you typed and try again.');$this->service()->manual(['legal_name'=>['not','text']]);}
    public function testConfirmWithoutStartBecomesIdentified(): void
    {$current=$this->service()->confirm('BC1234567');$this->assertSame('identified',$current['knowledge']['stage']);$this->assertSame('Green Acme',$current['identity']['entered_name']);$this->assertSame('registry_verified',$current['identity']['provenance']['entered_name']);}
    public function testConfirmTwiceKeepsSingleton(): void
    {$s=$this->service();$s->start('Acme');$s->confirm('BC1234567');$s->confirm('BC1234567');$this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM business_identity')->fetchColumn());}
    public function testStageProgressionCrossCheckAndNeverMastered(): void
    {$this->assertNull(BusinessIdentityService::stage(null,null));$this->assertSame('recognized',BusinessIdentityService::stage(['entered_name'=>'A'],null));$row=['entered_name'=>'A','source'=>'orgbook_bc','owner_confirmed_at'=>'now','registration_status'=>'active','registry_business_number'=>'123 456 789'];$this->assertSame('identified',BusinessIdentityService::stage($row,'999999999RT0001'));$this->assertSame('understood',BusinessIdentityService::stage($row,'123456789RT0001'));$this->assertNotSame('mastered',BusinessIdentityService::stage($row,'123456789RT0001'));}
    public function testMissingMigrationReturnsNamedError(): void
    {$this->db->exec('DROP TABLE business_identity');$this->assertSame('migration 1373 has not been run',$this->service()->current()['error']);}

    public function testContactMappingOmitsEmptyFieldsAndComposesAddressPartsInOrder(): void
    {
        $contacts=BusinessIdentityService::mapContacts([
            'company_website'=>' https://example.test ',
            'company_phone'=>'  ',
            'company_address'=>" 12 Main St \n\n Vancouver \r\n BC V5V 1A1 ",
        ]);
        $this->assertSame([
            ['field'=>'website','value'=>'https://example.test','source'=>'your_settings','status'=>'suggested'],
            ['field'=>'address','value'=>'12 Main St, Vancouver, BC V5V 1A1','source'=>'your_settings','status'=>'suggested'],
        ],$contacts);
        $this->assertSame([],BusinessIdentityService::mapContacts(['company_website'=>'','company_phone'=>null,'company_address'=>" \n "]));
    }

    public function testContactsReadsSettingsWithoutCallingExternalProviderAndFailsSoft(): void
    {
        $this->db->exec("UPDATE business_settings SET company_phone = '555-0100' WHERE id = 1");
        $fake=new FakeRegistryProvider();
        $this->assertSame('555-0100',$this->service($fake)->contacts()[0]['value']);
        $this->assertSame(0,$fake->searchCount);
        $this->assertSame(0,$fake->fetchCount);
        $this->db->exec('DROP TABLE business_settings');
        $this->assertSame([],$this->service($fake)->contacts());
    }

    public function testOrgBookOversizeResponseIsUnavailable(): void
    {
        $dir=sys_get_temp_dir().'/orgbook-test-'.bin2hex(random_bytes(8));
        $fetcher=static function(string $url, callable $writer): int {$writer(null,str_repeat('x',2097153));return 200;};
        $this->assertSame('unavailable',(new OrgBookBcProvider('tenant',$dir,$fetcher))->search('Acme')->status);
    }

    public function testOrgBookLimiterFailureIsRateLimited(): void
    {
        $notDirectory=tempnam(sys_get_temp_dir(),'orgbook-limiter-');
        $this->assertIsString($notDirectory);
        try {$this->assertSame('rate_limited',(new OrgBookBcProvider('tenant',$notDirectory))->search('Acme')->status);}
        finally {@unlink($notDirectory);}
    }

    public function testOrgBookRejectsBlankTenantKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrgBookBcProvider(" \t\n");
    }
}
