<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

class SendTimeThanksSlotTest extends TestCase
{
    public function test_no_ua_needs_ten_minutes_and_send_time(): void
    {
        $this->assertTrue(SendTimeService::isProxyOpen(SendTimeService::NO_UA,'2026-11-05 10:09:59','2026-11-05 10:00:00'));
        $this->assertFalse(SendTimeService::isProxyOpen(SendTimeService::NO_UA,'2026-11-05 10:10:00','2026-11-05 10:00:00'));
        $this->assertTrue(SendTimeService::isProxyOpen(SendTimeService::NO_UA,'2026-11-05 10:20:00',null));
    }
    public function test_home_default_pm_weekday_and_cap(): void
    {
        $svc=new SendTimeService();
        $home=$svc->thanksSlot(1,false,new DateTimeImmutable('2026-11-05 18:00:00'));
        $this->assertSame('2026-11-05 19:30:00',$home['at']->format('Y-m-d H:i:s'));
        $pm=$svc->thanksSlot(1,true,new DateTimeImmutable('2026-11-06 17:00:00'));
        $this->assertSame('2026-11-09 09:30:00',$pm['at']->format('Y-m-d H:i:s'),'paid Friday evening: Monday morning, not 5:10 pm Friday');
    }

    public function test_learned_hour_is_used_before_the_default(): void
    {
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE mia_send_times (contact_id INT, best_dow INT, best_hour INT)");
        $db->exec("INSERT INTO mia_send_times VALUES (7,2,14)");
        $slot=(new SendTimeService($db))->thanksSlot(7,false,new DateTimeImmutable('2026-11-09 16:00:00'));
        $this->assertSame('2026-11-10 14:00:00',$slot['at']->format('Y-m-d H:i:s'));
        $this->assertTrue($slot['learned']);
    }
}
