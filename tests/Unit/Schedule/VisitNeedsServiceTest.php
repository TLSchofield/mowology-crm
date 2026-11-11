<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Schedule/Services/VisitNeedsService.php';

/** What a visit needs before Finish, for a phone working offline (native Android visit screen, 2026-10-16). */
class VisitNeedsServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE snow_route_rates (id INTEGER PRIMARY KEY, plan_id INT, role TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, photo_types_required TEXT, photos_block_completion INT)");
        $db->exec("CREATE TABLE visit_service_choices (visit_id INT, choice TEXT)");
        $db->exec("INSERT INTO snow_route_rates (plan_id, role) VALUES (7, 'salt'), (7, 'snow')");
        $db->exec("INSERT INTO job_plans VALUES (7, NULL, 0), (8, '[\"Before\",\"after\"]', 1), (9, 'before', 0)");
        $db->exec("INSERT INTO visit_service_choices VALUES (101, 'salt')");
        $db->exec("CREATE TABLE visit_photos (id INTEGER PRIMARY KEY, visit_id INT, photo_type TEXT, deleted_at TEXT)");
        $db->exec("INSERT INTO visit_photos (visit_id, photo_type, deleted_at) VALUES (201, 'before', NULL), (201, 'after', '2026-10-16')");
        return $db;
    }

    public function testEachVisitGetsItsNeeds(): void
    {
        $n = (new VisitNeedsService($this->db()))->forVisits([
            ['visit_id' => 101, 'plan_id' => 7], ['visit_id' => 102, 'plan_id' => 7],
            ['visit_id' => 201, 'plan_id' => 8], ['visit_id' => 301, 'plan_id' => 9], ['visit_id' => 401, 'plan_id' => 0],
        ], 1, false);
        $this->assertSame(['is_route' => true, 'choice' => 'salt'], $n[101]['snow']);
        $this->assertSame(['is_route' => true, 'choice' => null], $n[102]['snow'], 'a route stop with nothing recorded yet');
        $this->assertFalse($n[201]['snow']['is_route']);
        $this->assertSame(['required' => ['before', 'after'], 'blocks' => true, 'taken' => ['before']], $n[201]['photos'], 'a deleted photo is not taken');
        $this->assertSame(['required' => ['before'], 'blocks' => false, 'taken' => []], $n[301]['photos'], 'wanted, not blocking');
        $this->assertSame([], $n[401]['special_requests']);
    }

    public function testPhotoTypesReadEitherForm(): void
    {
        $this->assertSame(['before', 'after'], VisitNeedsService::photoTypes('before, after'));
        $this->assertSame([], VisitNeedsService::photoTypes(null));
        $this->assertSame(['salt', 'arctic', 'snow', 'none'], array_column(VisitNeedsService::snowChoices(), 'value'));
    }
}
