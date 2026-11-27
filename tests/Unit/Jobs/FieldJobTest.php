<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Safety-net for the field "Add job" service functions.
 *
 * addAdHocVisit() and findNearbyProperties() are new global functions in the
 * Plan library, called from /crm/api/field-job.php and nearby-properties.php
 * (the crew mobile flow). Like PlanFunctionsLoadTest, this asserts they load
 * with their expected arity through the aggregator — a tripwire against an
 * accidental rename or signature change breaking the field endpoints.
 */
class FieldJobTest extends TestCase
{
    /** name => declared parameter count */
    private const EXPECTED = [
        'addAdHocVisit'       => 4, // (planId, date, crewId, userId)
        'findNearbyProperties'=> 4, // (lat, lng, radiusM, limit)
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Jobs/Services/PlanFunctions.php';
    }

    public function test_field_job_functions_are_defined(): void
    {
        foreach (self::EXPECTED as $fn => $_) {
            $this->assertTrue(function_exists($fn), "Field-job function missing: {$fn}()");
        }
    }

    public function test_field_job_function_signatures(): void
    {
        foreach (self::EXPECTED as $fn => $arity) {
            $ref = new ReflectionFunction($fn);
            $this->assertSame($arity, $ref->getNumberOfParameters(), "Arity of {$fn}() changed");
        }
    }
}
