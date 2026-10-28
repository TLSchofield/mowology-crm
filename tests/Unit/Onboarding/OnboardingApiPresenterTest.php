<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OnboardingApiPresenterTest extends TestCase
{
    public function test_map_includes_step_data_and_drops_web_fields(): void
    {
        $body = OnboardingApiPresenter::present([['key'=>'name','title'=>'About you','state'=>'next','owner'=>'Penny','skippable'=>false,'summary'=>'hidden']], 'name', false, ['name'=>['first'=>'Ada']]);
        $this->assertSame(['ok'=>true,'steps'=>[['key'=>'name','title'=>'About you','state'=>'next','head'=>'Penny','skippable'=>false,'data'=>['first'=>'Ada']]],'next'=>'name','ready_to_finish'=>false], $body);
    }

    public function test_all_pure_data_shapes(): void
    {
        $name = OnboardingApiPresenter::nameData(['first_name'=>'Ada','last_name'=>'Lovelace','preferred_name'=>'Ada','phone'=>'(604) 555-0101','contact_channel'=>'text','quiet_start'=>'20:00:00','quiet_end'=>'07:00:00','quiet_weekends'=>1], [true,true,true], null);
        $this->assertSame(['name'=>true,'reach'=>true,'quiet'=>true], $name['parts']);
        $this->assertSame(['from'=>'20:00','to'=>'07:00','weekends'=>true], $name['quiet']);
        $company = OnboardingApiPresenter::companyData(['identity'=>['entered_name'=>'Acme','legal_name'=>'Acme Ltd','operating_name'=>null,'registration_number'=>'BC1','registration_status'=>'active','owner_confirmed_at'=>'now']]);
        $this->assertTrue($company['confirmed']); $this->assertSame('active', $company['status']);
        $qbo = OnboardingApiPresenter::quickBooksData('yes', true, true, ['clients'=>'2 added'], false);
        $this->assertFalse($qbo['can_write']); $this->assertTrue($qbo['parts'][1]['done']); $this->assertSame('2 added', $qbo['parts'][1]['summary']);
    }
}
