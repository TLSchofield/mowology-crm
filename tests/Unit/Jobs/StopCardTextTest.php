<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The stop card's top line, the same on Android/web and the iPhone (StopCardView). */
class StopCardTextTest extends TestCase
{
    public function test_property_first_then_company_then_contact_skipping_the_address(): void
    {
        $stop = ['property_name' => 'Brandon Lodge', 'company_name' => 'Vancouver Management Ltd', 'contact_name' => 'Ron Harvie', 'property_address' => '1650 West 13th Avenue'];
        $this->assertSame('Brandon Lodge', StopCardText::headline($stop));
        $this->assertSame('Vancouver Management Ltd', StopCardText::client($stop));
        $named = ['property_name' => '1650 West 13th Avenue', 'company_name' => '', 'contact_name' => 'Ron Harvie', 'property_address' => '1650 West 13th Avenue'];
        $this->assertSame('Ron Harvie', StopCardText::headline($named), 'a property named by its address is skipped');
        $this->assertSame('', StopCardText::client($named), 'the client line does not repeat the headline');
    }

    public function test_arrival_visits_and_crew(): void
    {
        $stop = ['estimated_arrival' => '09:00:00', 'visits' => [['visit_id' => 1]], 'crew_names' => ['Nigel Chan', 'Tim Schofield']];
        $this->assertSame('09:00', StopCardText::arrival($stop));
        $this->assertSame('1 visit', StopCardText::visits($stop));
        $this->assertSame('Nigel C., Tim S.', StopCardText::crew($stop));
        $this->assertSame('13:30', StopCardText::arrival(['visits' => [['scheduled_time_start' => '13:30:00']]]));
        $this->assertSame('', StopCardText::arrival([]));
        $this->assertSame('2 visits', StopCardText::visits(['visits' => [[], []]]));
    }
}
