<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A filter narrows the jobs it knows about and never hides one it doesn't. */
final class ServiceTypeFilterTest extends TestCase
{
    /** @return string[] the job types that show under $chosen */
    private function shown(array $chosen): array
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE p (t TEXT)');
        foreach (['lawn_care', 'maintenance', 'Hedge Trimming', 'Lawn Cut', '7 Day Lawn Cut Service', 'snow_removal'] as $t) {
            $db->prepare('INSERT INTO p VALUES (?)')->execute([$t]);
        }
        $db->exec('INSERT INTO p VALUES (NULL)');
        $known = ['lawn_care' => 'Lawn Care', 'maintenance' => 'Maintenance', 'hedge_trimming' => 'Hedge Trimming', 'snow_removal' => 'Snow Removal'];
        [$sql, $params] = ServiceTypeFilter::showsClause('t', $chosen, $known);
        $q = $db->prepare("SELECT COALESCE(t, 'none') FROM p WHERE $sql");
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testATypeTheFilterDoesNotKnowAlwaysShows(): void
    {
        $this->assertContains('Lawn Cut', $this->shown(['maintenance']));
        $this->assertContains('7 Day Lawn Cut Service', $this->shown(['snow_removal']));
        $this->assertContains('none', $this->shown(['maintenance']));
    }

    public function testAKnownTypeIsStillFilteredOut(): void
    {
        $shown = $this->shown(['maintenance']);
        $this->assertContains('maintenance', $shown);
        $this->assertNotContains('lawn_care', $shown);
        $this->assertNotContains('snow_removal', $shown);
        $this->assertNotContains('Hedge Trimming', $shown);
    }

    public function testCapitalsAndSpellingOfAKnownTypeMatch(): void
    {
        $this->assertContains('Hedge Trimming', $this->shown(['hedge_trimming']));
    }
}
