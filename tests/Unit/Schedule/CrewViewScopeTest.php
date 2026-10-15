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
}
