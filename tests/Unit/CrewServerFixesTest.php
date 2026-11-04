<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CrewServerFixesTest extends TestCase
{
    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($source, $relative . ' must be readable');
        return $source;
    }

    public function testClusterListRequiresEditAccess(): void
    {
        $source = $this->source('app/Modules/Jobs/Api/clusters.php');
        self::assertMatchesRegularExpression('/case \'list\':\s+if \(!\$isAdmin\)/', $source);
    }

    public function testStopDetailRedactsBillingAndTeammateClockState(): void
    {
        $source = $this->source('app/Modules/Jobs/Api/stop-detail.php');
        self::assertStringContainsString("userHasPermission('billing.view') || userHasPermission('jobs.edit')", $source);
        self::assertStringContainsString('$canSeeBilling || (int)$cr[\'user_id\'] === $me', $source);
        self::assertStringContainsString("if (\$canSeeBilling) {\n            \$visitItem['price_per_visit']", $source);
    }

    public function testProfitRiskFactorsRequiresBillingAccess(): void
    {
        $source = $this->source('app/Modules/Jobs/Api/profit-risk-factors.php');
        self::assertStringContainsString("userHasPermission('billing.view')", $source);
        self::assertStringContainsString("['admin', 'manager']", $source);
    }

    public function testVisitTransitionUsesCompareAndSwap(): void
    {
        $source = $this->source('app/Modules/Jobs/Services/VisitLifecycleService.php');
        self::assertStringContainsString('WHERE id = ? AND status = ?', $source);
        self::assertStringContainsString('$stmt->rowCount() !== 1', $source);
    }

    public function testClockStopsAreConditionalAndOfflinePingUsesPunchTime(): void
    {
        $source = $this->source('app/Modules/Team/Services/TimeclockFunctions.php');
        self::assertStringContainsString("WHERE id = ? AND status = 'active' AND clock_out IS NULL", $source);
        self::assertStringContainsString("WHERE id = ? AND status = 'active' AND end_time IS NULL", $source);
        self::assertStringContainsString('VALUES (?, ?, ?, NULL, NULL, COALESCE(?, NOW()))', $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, '->rowCount() !== 1'));
    }

    public function testClockApiDropsOutOfRangeCoordinatesWithoutRejectingPunch(): void
    {
        $source = $this->source('app/Modules/Schedule/Api/clock.php');
        self::assertGreaterThanOrEqual(2, substr_count($source, '$lat < -90 || $lat > 90'));
        self::assertGreaterThanOrEqual(2, substr_count($source, '$lng < -180 || $lng > 180'));
    }

    public function testActualDurationIsSetBeforeLaborSnapshot(): void
    {
        $source = $this->source('app/Modules/Jobs/Services/VisitCompletionService.php');
        self::assertLessThan(
            strpos($source, '// ── 4. Compute labor cost'),
            strpos($source, 'SET actual_duration_minutes = (')
        );
    }

    public function testTrackingAcknowledgesThinnedAndIgnoredPoints(): void
    {
        $source = $this->source('app/Modules/Team/Services/TrackingIngestService.php');
        self::assertStringContainsString("'thinned' => true", $source);
        self::assertStringContainsString('$insertStmt->rowCount() !== 1', $source);
    }

    public function testFutureCrewPropagationIsTransactionalAndUpdatesVisits(): void
    {
        $source = $this->source('app/Modules/Jobs/Services/CrewAssignmentService.php');
        self::assertLessThan(strpos($source, '$this->db->commit();'), strpos($source, 'propagateToFuture($planId'));
        self::assertStringContainsString('UPDATE job_visits SET assigned_crew_id = ?, updated_at = NOW()', $source);
    }

    public function testScheduleFailuresAndSecondaryCrewAreHandled(): void
    {
        $offline = $this->source('app/Modules/Jobs/Api/offline-schedule.php');
        self::assertStringContainsString("['ok' => false, 'error' => 'Could not load appointments']", $offline);
        $jobs = $this->source('app/Modules/Schedule/Api/jobs.php');
        self::assertStringContainsString('FROM calendar_stop_crew csc', $jobs);
        self::assertStringContainsString('csc.user_id = ?', $jobs);
    }

    public function testJobViewGuardsActionsTimesAndDynamicHtml(): void
    {
        $source = $this->source('public/crm/jobs/view.php');
        self::assertGreaterThanOrEqual(3, substr_count($source, 'userMayActOnVisit($visitId'));
        self::assertStringContainsString('timer_stop_warning=1', $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'strtotime($endTime) >= strtotime($startTime)'));
        self::assertStringContainsString("esc(data.error || 'Error')", $source);
        self::assertStringContainsString('esc(name) + \' left <strong>\' + esc(label)', $source);
    }

    public function testConflictAndClusterNamesAreEscaped(): void
    {
        $conflicts = $this->source('public/crm/jobs/conflict-resolution.php');
        self::assertGreaterThanOrEqual(3, substr_count($conflicts, 'escHtml('));
        $clusters = $this->source('public/crm/jobs/clusters_appstack.php');
        self::assertStringContainsString('function escAttr(s)', $clusters);
        self::assertStringContainsString('JSON.stringify(String(s))', $clusters);
        self::assertStringContainsString(".replace(/&/g,'&amp;')", $clusters);
    }

    public function testAllFutureAssignmentChecksThePlanBelongsToTheStop(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Jobs/Services/CrewAssignmentService.php');
        $this->assertStringContainsString('That plan does not match this stop', $src);
        $this->assertLessThan(strpos($src, '$this->db->beginTransaction();'), strpos($src, 'That plan does not match this stop'));
    }
}
