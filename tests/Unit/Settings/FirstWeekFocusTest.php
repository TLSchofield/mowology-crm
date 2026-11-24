<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FirstWeekFocusTest extends TestCase
{
    public function testOrderOfWhatMattersMost(): void
    {
        $this->assertSame('add_client', FirstWeekFocus::pick([])['key']);
        $this->assertSame('add_client', FirstWeekFocus::pick(['clients' => 0, 'quotes' => 5, 'quotes_waiting' => 2, 'visits_today' => 3])['key']);
        $this->assertSame('first_quote', FirstWeekFocus::pick(['clients' => 2])['key']);
        $this->assertSame('follow_up', FirstWeekFocus::pick(['clients' => 2, 'quotes' => 3, 'quotes_waiting' => 1, 'visits_today' => 4])['key']);
        $this->assertSame('today', FirstWeekFocus::pick(['clients' => 2, 'quotes' => 3, 'visits_today' => 1])['key']);
        $this->assertSame('caught_up', FirstWeekFocus::pick(['clients' => 2, 'quotes' => 3])['key']);
    }

    public function testPluralsAndButtons(): void
    {
        $this->assertSame('A quote is waiting for an answer', FirstWeekFocus::pick(['clients' => 1, 'quotes' => 1, 'quotes_waiting' => 1])['headline']);
        $this->assertSame('3 quotes are waiting for an answer', FirstWeekFocus::pick(['clients' => 1, 'quotes' => 3, 'quotes_waiting' => 3])['headline']);
        $this->assertSame('You have 1 visit today', FirstWeekFocus::pick(['clients' => 1, 'quotes' => 1, 'visits_today' => 1])['headline']);
        $this->assertSame('You have 2 visits today', FirstWeekFocus::pick(['clients' => 1, 'quotes' => 1, 'visits_today' => 2])['headline']);
        foreach ([[], ['clients' => 1], ['clients' => 1, 'quotes' => 1, 'quotes_waiting' => 1], ['clients' => 1, 'quotes' => 1, 'visits_today' => 1], ['clients' => 1, 'quotes' => 1]] as $f) {
            $p = FirstWeekFocus::pick($f);
            $this->assertNotSame('', $p['button']);
            $this->assertStringStartsWith('/crm/', $p['href']);
        }
    }

    public function testGreeting(): void
    {
        $this->assertSame('Good morning, Sam.', FirstWeekFocus::greeting('Sam Lee', 8));
        $this->assertSame('Good afternoon, Sam.', FirstWeekFocus::greeting(' Sam ', 12));
        $this->assertSame('Good evening.', FirstWeekFocus::greeting('', 20));
    }

    public function testALoginSkipsStepsItCannotOpen(): void
    {
        $f = ['clients' => 2, 'quotes' => 3, 'quotes_waiting' => 1, 'visits_today' => 2];
        $this->assertSame('today', FirstWeekFocus::pick($f, ['quotes' => false])['key']);
        $p = FirstWeekFocus::pick($f, ['quotes' => false, 'schedule' => false]);
        $this->assertSame(['caught_up', '', ''], [$p['key'], $p['button'], $p['href']]);
        $this->assertSame('first_quote', FirstWeekFocus::pick(['clients' => 1], [])['key']);
        $this->assertSame('caught_up', FirstWeekFocus::pick([], ['clients' => false])['key']);
    }

    public function testGatherThrowsWhenCoreTablesAreMissingButToleratesNoVisits(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            FirstWeekFocus::gather($db);
            $this->fail('a missing contacts table must not read as zero clients');
        } catch (PDOException $e) {
            $this->assertTrue(true);
        }
        $db->exec("CREATE TABLE contacts (id INTEGER)");
        $db->exec("INSERT INTO contacts VALUES (1)");
        $db->exec("CREATE TABLE quotes (id INTEGER, status TEXT)");
        $db->exec("INSERT INTO quotes VALUES (1,'sent'),(2,'draft')");
        $g = FirstWeekFocus::gather($db);
        $this->assertSame([1, 2, 1, 0], [$g['clients'], $g['quotes'], $g['quotes_waiting'], $g['visits_today']]);
    }
}
