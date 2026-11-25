<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CrewViewScopeTest extends TestCase
{
    public function test_crew_see_only_their_own_by_default_whatever_they_ask(): void
    {
        $this->assertSame(['crew' => 7, 'scope' => 'mine', 'can_switch' => false], CrewViewScope::decide('user', 7, 'own', 'all', 3));
        $this->assertSame(7, CrewViewScope::decide('user', 7, 'nonsense', null)['crew'], 'anything but "all" is own');
        $this->assertFalse(CrewViewScope::seesOtherCrews('user', 'own'));
    }

    public function test_crew_may_switch_when_the_company_allows_it(): void
    {
        $this->assertSame(['crew' => null, 'scope' => 'all', 'can_switch' => true], CrewViewScope::decide('user', 7, 'all', null));
        $this->assertSame(7, CrewViewScope::decide('user', 7, 'all', 'mine')['crew']);
        $this->assertTrue(CrewViewScope::seesOtherCrews('user', 'all'));
    }

    public function test_office_defaults_to_all_and_can_pick_mine_or_one_crew(): void
    {
        $this->assertNull(CrewViewScope::decide('manager', 2, 'own', null)['crew']);
        $this->assertSame(2, CrewViewScope::decide('admin', 2, 'own', 'mine')['crew']);
        $this->assertSame(9, CrewViewScope::decide('admin', 2, 'own', 'all', 9)['crew']);
        $this->assertTrue(CrewViewScope::seesOtherCrews('manager', 'own'));
    }

    public function test_a_one_person_business_has_nothing_to_switch(): void
    {
        $this->assertSame(['crew' => null, 'scope' => 'all', 'can_switch' => false], CrewViewScope::decide('admin', 1, 'own', 'mine', null, 1));
        $this->assertTrue(CrewViewScope::decide('admin', 1, 'own', null, null, 2)['can_switch'], 'two people: the switch stays');
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, is_active INT)");
        $db->exec("INSERT INTO users (role, is_active) VALUES ('admin', 1), ('accountant', 1), ('user', 0)");
        $this->assertSame(1, CrewViewScope::people($db), 'the accountant and inactive logins do not count');
    }

    public function test_the_crew_checklist_keeps_only_offered_people_in_order_without_repeats(): void
    {
        $this->assertSame([5, 3], CrewViewScope::crewList(' 5,3,5,99,,abc ', ['3', '5', '7']));
        $this->assertSame([], CrewViewScope::crewList('', ['3']), 'nobody ticked = every crew');
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, is_active INT)");
        $db->exec("INSERT INTO users (full_name, role, is_active) VALUES ('Zoe', 'user', 1), ('Amy', 'admin', 1), ('Ann', 'accountant', 1), ('Old', 'user', 0)");
        $this->assertSame([['value' => '2', 'label' => 'Amy'], ['value' => '1', 'label' => 'Zoe']], CrewViewScope::crewOptions($db));
    }

    public function test_pick_gives_null_one_id_or_a_list_and_drops_junk_and_duplicates(): void
    {
        $this->assertNull(CrewViewScope::pick(null));
        $this->assertNull(CrewViewScope::pick([]));
        $this->assertNull(CrewViewScope::pick(['', 'x', '0', -3]));
        $this->assertSame(7, CrewViewScope::pick(7));
        $this->assertSame(7, CrewViewScope::pick(['7', 7]));
        $this->assertSame([7, 12], CrewViewScope::pick(['7', '12', '7', 'abc']));
    }

    public function test_the_office_can_pick_several_crews_and_crew_who_may_not_see_others_cannot(): void
    {
        $this->assertSame([3, 9], CrewViewScope::decide('admin', 1, 'own', 'all', [3, 9])['crew']);
        $this->assertSame(3, CrewViewScope::decide('admin', 1, 'own', 'all', [3])['crew']);
        $this->assertNull(CrewViewScope::decide('admin', 1, 'own', 'all', [])['crew']);
        $this->assertSame(1, CrewViewScope::decide('admin', 1, 'own', 'mine', [3, 9])['crew'], '"Mine" wins over the picker');
        $this->assertSame(7, CrewViewScope::decide('user', 7, 'own', 'all', [3, 9])['crew'], 'own-only crew stay on themselves');
    }
}
