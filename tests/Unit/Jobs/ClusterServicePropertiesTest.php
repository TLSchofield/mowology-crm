<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The Route Clusters property picker list (2026-10-12: the page called an endpoint that never existed). */
final class ClusterServicePropertiesTest extends TestCase
{
    public function testListsOnlyActivePropertiesWithContactName(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('CONCAT', static fn (...$a) => implode('', array_map('strval', $a)), -1);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, site_contact_id INTEGER, is_active INTEGER)');
        $db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
        $db->exec("INSERT INTO contacts VALUES (1, 'Ann', 'Lee')");
        $db->exec("INSERT INTO properties VALUES (10, '12 Oak St', 'Vancouver', 1, 1), (11, '9 Elm Rd', 'Burnaby', NULL, 1), (12, '1 Gone Ave', 'Vancouver', 1, 0)");

        $rows = (new ClusterService($db))->listActiveProperties();

        $this->assertSame([11, 10], array_column($rows, 'id'));            // Burnaby before Vancouver; inactive left out
        $this->assertSame('Ann Lee', $rows[1]['contact_name']);
        $this->assertSame('', $rows[0]['contact_name']);
    }
}
