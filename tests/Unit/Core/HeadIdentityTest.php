<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/HeadIdentity.php';

/**
 * HeadIdentity — the one place the chief of staff's shown name, pronouns and photo come from.
 */
final class HeadIdentityTest extends TestCase
{
    protected function setUp(): void { HeadIdentity::reset(); }
    protected function tearDown(): void { HeadIdentity::reset(); }

    public function testDefaults(): void
    {
        $c = HeadIdentity::chiefWith([]);
        $this->assertSame('Kaname', $c['name']);
        $this->assertSame('he', $c['they']);
        $this->assertSame('him', $c['them']);
        $this->assertSame('his', $c['their']);
        $this->assertSame("he's", $c['theyre']);
        $this->assertSame('charlie', HeadIdentity::CHIEF_KEY);
    }

    public function testChiefNameWithoutDatabaseIsKaname(): void
    {
        $this->assertSame('Kaname', HeadIdentity::chiefName(null));
    }

    public function testOverridesAndTheyPronoun(): void
    {
        $c = HeadIdentity::chiefWith(['name' => 'Ren', 'pronoun' => 'they']);
        $this->assertSame('Ren', $c['name']);
        $this->assertSame('they', $c['they']);
        $this->assertSame('them', $c['them']);
        $this->assertSame('their', $c['their']);
        $this->assertSame("they're", $c['theyre']);
    }

    public function testSheForms(): void
    {
        $c = HeadIdentity::chiefWith(['pronoun' => 'she']);
        $this->assertSame(['she', 'her', 'her', "she's"], [$c['they'], $c['them'], $c['their'], $c['theyre']]);
    }

    public function testInvalidPronounFallsBackToHe(): void
    {
        $c = HeadIdentity::chiefWith(['pronoun' => 'xyz']);
        $this->assertSame('he', $c['pronoun']);
        $this->assertSame('he', $c['they']);
    }

    public function testResetClearsTheCache(): void
    {
        $this->assertSame('Kaname', HeadIdentity::chiefName(null));
        HeadIdentity::reset();
        $this->assertSame('Kaname', HeadIdentity::chief(null)['name']);
    }
}
