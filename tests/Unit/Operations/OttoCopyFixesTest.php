<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 2026-11-28 tablet findings: one state for the line and the list, plain trade-neutral words, services as data. */
class OttoCopyFixesTest extends TestCase
{
    public function test_headline_does_not_say_nothing_needs_you_when_the_list_has_an_item(): void
    {
        $h = OttoRules::headline(['stops' => 1, 'crews' => 1, 'open' => 1], 'Tim');
        $this->assertStringNotContainsString('Nothing needs you', $h);
        $this->assertStringContainsString('1 thing needs you.', $h);
        $this->assertStringContainsString('3 things need you.', OttoRules::headline(['stops' => 2, 'open' => 3], ''));
    }

    public function test_headline_counts_items_the_specific_lines_do_not_cover(): void
    {
        $h = OttoRules::headline(['stops' => 1, 'weather' => 1, 'open' => 3], '');
        $this->assertStringContainsString('2 more things need you.', $h);
        $this->assertStringNotContainsString('more thing', OttoRules::headline(['stops' => 1, 'weather' => 1, 'open' => 1], ''));
    }

    public function test_headline_says_nothing_needs_you_only_when_the_list_is_empty(): void
    {
        $this->assertStringContainsString('Nothing needs you right now.', OttoRules::headline(['stops' => 1, 'crews' => 1, 'open' => 0], 'Tim'));
    }

    public function test_headline_without_open_keeps_the_old_behaviour(): void
    {
        $this->assertStringContainsString('Nothing needs you right now.', OttoRules::headline(['stops' => 1, 'crews' => 1], 'Tim'));
    }

    public function test_the_missing_pin_line_is_plain_and_trade_neutral(): void
    {
        $t = PropertyBorderRules::text('no_pin', '123 Test Street, Vancouver', ['next' => '2026-11-28']);
        $this->assertSame("123 Test Street has no pin yet, so we can't show this address on the map (next visit Sat Nov 28).", $t);
        $this->assertStringNotContainsStringIgnoringCase('crew', $t);
    }

    public function test_location_note_is_plain(): void
    {
        $this->assertSame('The area for this address is set on the map.', OttoAutoPlaceService::locationNote(['ok' => true]));
        $n = OttoAutoPlaceService::locationNote(['ok' => false, 'message' => "The address didn't match the city's lot map — draw it, or move the pin onto the building."]);
        $this->assertStringNotContainsStringIgnoringCase('zone editor', $n);
        $this->assertStringNotContainsStringIgnoringCase('otto', $n);
        $this->assertStringContainsString("couldn't match this address", $n);
        $this->assertStringContainsString('not answering', OttoAutoPlaceService::locationNote(['ok' => false, 'message' => "The province's lot map isn't answering right now — try again."]));
        $this->assertSame('This address needs a map pin first.', OttoAutoPlaceService::locationNote(['ok' => false, 'message' => 'It needs a map pin first.']));
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, service_type TEXT)');
        return $db;
    }

    public function test_a_new_business_gets_only_other(): void
    {
        $this->assertSame(['Other'], (new FieldJobService($this->db()))->serviceTypes());
    }

    public function test_services_already_used_come_first_most_used_first(): void
    {
        $db = $this->db();
        foreach (['Plumbing', 'Plumbing', 'Painting', 'Other', ''] as $s) $db->prepare('INSERT INTO job_plans (service_type) VALUES (?)')->execute([$s]);
        $this->assertSame(['Plumbing', 'Painting', 'Other'], (new FieldJobService($db))->serviceTypes());
    }

    public function test_the_owners_own_list_wins_and_is_cleaned(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO ops_settings VALUES ('field_service_types', \"Lawn Cut\n  Garden Care \n\nLawn Cut\nOther\", '')");
        $db->exec("INSERT INTO job_plans (service_type) VALUES ('Plumbing')");
        $this->assertSame(['Lawn Cut', 'Garden Care', 'Other'], (new FieldJobService($db))->serviceTypes());
    }

    public function test_no_trade_words_are_built_in(): void
    {
        $this->assertSame(['Other'], FieldJobService::SERVICE_TYPES);
    }
}
