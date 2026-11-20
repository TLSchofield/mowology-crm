<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SamRequestRulesTest extends TestCase
{
    public function test_invoice_reply_request_is_sure(): void
    {
        $subject = 'Re: Invoice INV-2026-0001 from Mowology';
        $body = 'Hi, can we book you guys in for a fall cleanup? Thanks';
        $request = SamRequestRules::isRequest($subject, $body);
        $services = SamRequestRules::services($subject . "\n" . $body);
        $this->assertTrue($request['yes']);
        $this->assertSame(['cleanup'], $services);
        $this->assertNull(SamRequestRules::negated($body));
        $result = SamRequestRules::level(['known_client' => true, 'properties' => 1, 'services' => $services,
            'has_history' => true, 'negation' => null, 'is_request' => $request['yes']]);
        $this->assertSame('sure', $result['level']);
    }

    public function test_negation_is_not_sure(): void
    {
        $text = "We don't need a fall cleanup this year, thanks";
        $negation = SamRequestRules::negated($text);
        $this->assertSame("don't need", $negation);
        $result = SamRequestRules::level(['known_client' => true, 'properties' => 1, 'services' => ['cleanup'],
            'has_history' => true, 'negation' => $negation, 'is_request' => true]);
        $this->assertSame('not_sure', $result['level']);
        $this->assertContains("says: don't need", $result['reasons']);
    }

    public function test_two_services_are_not_sure(): void
    {
        $services = SamRequestRules::services('Fall cleanup and hedge trim');
        $result = SamRequestRules::level(['known_client' => true, 'properties' => 1, 'services' => $services,
            'has_history' => true, 'negation' => null, 'is_request' => true]);
        $this->assertSame(['hedge', 'cleanup'], $services);
        $this->assertSame('not_sure', $result['level']);
        $this->assertContains('two services named', $result['reasons']);
    }

    public function test_two_properties_are_likely(): void
    {
        $result = SamRequestRules::level(['known_client' => true, 'properties' => 2, 'services' => ['cleanup'],
            'has_history' => true, 'negation' => null, 'is_request' => true]);
        $this->assertSame('likely', $result['level']);
        $this->assertContains('2 properties — which one?', $result['reasons']);
    }

    public function test_unknown_sender_is_not_sure(): void
    {
        $result = SamRequestRules::level(['known_client' => false, 'properties' => 1, 'services' => ['cleanup'],
            'has_history' => true, 'negation' => null, 'is_request' => true]);
        $this->assertSame('not_sure', $result['level']);
        $this->assertContains('sender not a client', $result['reasons']);
    }

    public function testThePlaceIsNotTheJob(): void
    {
        $this->assertSame(['aeration', 'overseed'], SamRequestRules::services("we need some aeration and seeding for our backyard lawn"));
        $this->assertSame(['hedge'], SamRequestRules::services("a quote for trimming my cedar hedge in my back garden"));
        $this->assertSame(['hedge'], SamRequestRules::services("We really need to have the trees trimmed"));
        $this->assertSame(['mow'], SamRequestRules::services("can you do lawn mowing every two weeks"));
        $this->assertSame(['cleanup'], SamRequestRules::services("Hey can we book you guys in for a fall cleanup in the next few weeks?"));
    }

    public function testWinterWorkIsRecognised(): void
    {
        $this->assertSame(['snow'], SamRequestRules::services("The winter season is coming up and we would like to request the quotes for our buildings"));
        $this->assertSame(['snow', 'salting'], SamRequestRules::services("signed quotes for snow removal and pre-salting"));
    }
}
