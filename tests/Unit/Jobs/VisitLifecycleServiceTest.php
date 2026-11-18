<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure decision logic extracted into VisitLifecycleService
 * (Phase 2). These guard the revenue-critical status-update SQL shape, the plan
 * propagation mapping, visit-move SET building, and invoice-eligibility checks —
 * all DB-free and deterministic.
 */
class VisitLifecycleServiceTest extends TestCase
{
    // ---- isValidVisitStatus -------------------------------------------------

    public function test_valid_statuses(): void
    {
        foreach (['scheduled', 'in_progress', 'completed', 'skipped', 'weather', 'cancelled'] as $s) {
            $this->assertTrue(VisitLifecycleService::isValidVisitStatus($s));
        }
    }

    public function test_invalid_status_rejected(): void
    {
        $this->assertFalse(VisitLifecycleService::isValidVisitStatus('done'));
        $this->assertFalse(VisitLifecycleService::isValidVisitStatus(''));
        $this->assertFalse(VisitLifecycleService::isValidVisitStatus('COMPLETED'));
    }

    // ---- buildStatusSetClauses ---------------------------------------------

    public function test_status_clause_base(): void
    {
        $b = VisitLifecycleService::buildStatusSetClauses('scheduled', null);
        $this->assertSame(['status = ?', 'status_changed_at = NOW()'], $b['set']);
        $this->assertSame(['scheduled'], $b['params']);
    }

    public function test_status_clause_in_progress_sets_started_at(): void
    {
        $b = VisitLifecycleService::buildStatusSetClauses('in_progress', null);
        $this->assertContains('started_at = NOW()', $b['set']);
        $this->assertSame(['in_progress'], $b['params']);
    }

    public function test_status_clause_completed_with_notes(): void
    {
        $b = VisitLifecycleService::buildStatusSetClauses('completed', 'all good');
        $this->assertSame(
            ['status = ?', 'status_changed_at = NOW()', 'completed_at = NOW()', 'completion_notes = ?'],
            $b['set']
        );
        $this->assertSame(['completed', 'all good'], $b['params']);
    }

    public function test_status_clause_completed_without_notes_omits_notes(): void
    {
        $b = VisitLifecycleService::buildStatusSetClauses('completed', null);
        $this->assertSame(['status = ?', 'status_changed_at = NOW()', 'completed_at = NOW()'], $b['set']);
        $this->assertSame(['completed'], $b['params']);
    }

    public function test_status_clause_skipped_stores_reason(): void
    {
        $b = VisitLifecycleService::buildStatusSetClauses('skipped', 'gate locked');
        $this->assertContains('completion_notes = ?', $b['set']);
        $this->assertNotContains('completed_at = NOW()', $b['set']);
        $this->assertSame(['skipped', 'gate locked'], $b['params']);
    }

    public function test_status_clause_skipped_without_reason_omits_notes(): void
    {
        $b = VisitLifecycleService::buildStatusSetClauses('skipped', null);
        $this->assertNotContains('completion_notes = ?', $b['set']);
        $this->assertSame(['skipped'], $b['params']);
    }

    public function test_only_scheduled_visits_can_be_skipped(): void
    {
        $this->assertTrue(VisitLifecycleService::canSkipFromStatus('scheduled'));
        foreach (['in_progress', 'completed', 'skipped', 'weather', 'cancelled', ''] as $s) {
            $this->assertFalse(VisitLifecycleService::canSkipFromStatus($s));
        }
    }

    public function test_status_clause_completed_empty_notes_treated_as_none(): void
    {
        // empty string is falsy → notes clause omitted (matches original `if ($notes)`)
        $b = VisitLifecycleService::buildStatusSetClauses('completed', '');
        $this->assertNotContains('completion_notes = ?', $b['set']);
        $this->assertSame(['completed'], $b['params']);
    }

    // ---- computeStopStatusFromCounts ---------------------------------------

    public function test_stop_status_all_completed(): void
    {
        // every visit serviced → stop is completed
        $this->assertSame('completed', VisitLifecycleService::computeStopStatusFromCounts(0, 3));
    }

    public function test_stop_status_all_skipped(): void
    {
        // nothing pending, nothing completed (all skipped/cancelled) → stop skipped.
        // This is the bug fix: a crew-skipped visit now syncs to the desktop schedule.
        $this->assertSame('skipped', VisitLifecycleService::computeStopStatusFromCounts(0, 0));
    }

    public function test_stop_status_partial_progress(): void
    {
        // some done, some still pending → in_progress
        $this->assertSame('in_progress', VisitLifecycleService::computeStopStatusFromCounts(2, 1));
    }

    public function test_stop_status_untouched_when_only_pending(): void
    {
        // nothing serviced yet → leave the stop as-is (null = no change)
        $this->assertNull(VisitLifecycleService::computeStopStatusFromCounts(2, 0));
    }

    // ---- buildPropagationSet -----------------------------------------------

    public function test_propagation_maps_plan_cols_to_visit_cols(): void
    {
        $b = VisitLifecycleService::buildPropagationSet([
            'default_crew_id' => 7,
            'default_time_start' => '08:00',
            'default_time_end' => '10:00',
        ]);
        $this->assertSame(
            ['assigned_crew_id = ?', 'scheduled_time_start = ?', 'scheduled_time_end = ?'],
            $b['set']
        );
        $this->assertSame([7, '08:00', '10:00'], $b['params']);
    }

    public function test_propagation_ignores_unlisted_fields(): void
    {
        $b = VisitLifecycleService::buildPropagationSet(['title' => 'x', 'default_crew_id' => 3]);
        $this->assertSame(['assigned_crew_id = ?'], $b['set']);
        $this->assertSame([3], $b['params']);
    }

    public function test_propagation_empty_when_no_allowed_fields(): void
    {
        $b = VisitLifecycleService::buildPropagationSet(['title' => 'x']);
        $this->assertSame([], $b['set']);
        $this->assertSame([], $b['params']);
    }

    public function test_propagation_includes_null_crew_value(): void
    {
        // array_key_exists, not isset — a null crew (unassign) must still propagate
        $b = VisitLifecycleService::buildPropagationSet(['default_crew_id' => null]);
        $this->assertSame(['assigned_crew_id = ?'], $b['set']);
        $this->assertSame([null], $b['params']);
    }

    // ---- buildMoveVisitSet -------------------------------------------------

    public function test_move_set_date_only_when_no_stop(): void
    {
        $b = VisitLifecycleService::buildMoveVisitSet('2026-07-01', 0, null);
        $this->assertSame(['original_scheduled_date = COALESCE(original_scheduled_date, scheduled_date)', 'scheduled_date = ?'], $b['set']);
        $this->assertSame(['2026-07-01'], $b['params']);
    }

    public function test_move_set_includes_stop_and_time(): void
    {
        $b = VisitLifecycleService::buildMoveVisitSet('2026-07-01', 55, '09:30');
        $this->assertSame(['original_scheduled_date = COALESCE(original_scheduled_date, scheduled_date)', 'scheduled_date = ?', 'stop_id = ?', 'scheduled_time_start = ?'], $b['set']);
        $this->assertSame(['2026-07-01', 55, '09:30'], $b['params']);
    }

    // ---- invoice eligibility checks ----------------------------------------

    public function test_missing_checklist_items(): void
    {
        $template = ['Mow', 'Edge', 'Blow'];
        $completed = ['Mow' => true, 'Edge' => false];
        $this->assertSame(
            ['Checklist: Edge', 'Checklist: Blow'],
            VisitLifecycleService::computeMissingChecklist($template, $completed)
        );
    }

    public function test_missing_checklist_none_when_all_complete(): void
    {
        $this->assertSame(
            [],
            VisitLifecycleService::computeMissingChecklist(['Mow'], ['Mow' => 1])
        );
    }

    public function test_missing_photos(): void
    {
        $required = ['before', 'after'];
        $uploaded = ['before' => 2, 'after' => 0];
        $this->assertSame(
            ['Photo: after'],
            VisitLifecycleService::computeMissingPhotos($required, $uploaded)
        );
    }
    // ---- canSkipFromStatus / skippableAtStop (desktop Skip, 2026-11-03) ----

    public function test_crew_skip_only_from_scheduled(): void
    {
        $this->assertTrue(VisitLifecycleService::canSkipFromStatus('scheduled'));
        $this->assertFalse(VisitLifecycleService::canSkipFromStatus('in_progress'));
        $this->assertFalse(VisitLifecycleService::canSkipFromStatus('completed'));
    }

    public function test_desk_skip_also_from_in_progress_never_completed(): void
    {
        $this->assertTrue(VisitLifecycleService::canSkipFromStatus('scheduled', true));
        $this->assertTrue(VisitLifecycleService::canSkipFromStatus('in_progress', true));
        $this->assertFalse(VisitLifecycleService::canSkipFromStatus('completed', true));
        $this->assertFalse(VisitLifecycleService::canSkipFromStatus('cancelled', true));
    }

    public function test_skippable_at_stop_picks_open_visits_and_names_refusals(): void
    {
        $visits = [
            ['visit_id' => 1, 'visit_status' => 'scheduled'],
            ['visit_id' => 2, 'visit_status' => 'in_progress'],
            ['visit_id' => 3, 'visit_status' => 'completed'],
            ['visit_id' => 4, 'visit_status' => 'skipped'],
            ['visit_id' => 5, 'visit_status' => 'scheduled', 'locked_at' => '2026-11-03 10:00:00'],
            ['visit_id' => 6, 'visit_status' => 'scheduled', 'invoice_id' => 9],
        ];
        $crew = VisitLifecycleService::skippableAtStop($visits, false);
        $this->assertSame([1], $crew['skip']);
        $this->assertSame(['2: in_progress', '3: completed', '5: locked', '6: invoiced'], $crew['refused']);

        $desk = VisitLifecycleService::skippableAtStop($visits, true);
        $this->assertSame([1, 2], $desk['skip']);
        $this->assertSame(['3: completed', '5: locked', '6: invoiced'], $desk['refused']);
    }

    public function test_all_visits_invoiced_grays_create_invoice_only_when_nothing_is_left_to_bill(): void
    {
        $this->assertFalse(VisitLifecycleService::allVisitsInvoiced([]));
        $this->assertTrue(VisitLifecycleService::allVisitsInvoiced([['status' => 'completed', 'invoice_id' => 5]]));
        // a recurring plan with a later, un-invoiced visit still needs the button
        $this->assertFalse(VisitLifecycleService::allVisitsInvoiced([
            ['status' => 'completed', 'invoice_id' => 5], ['status' => 'scheduled', 'invoice_id' => null],
        ]));
        // skipped / cancelled visits are never billed
        $this->assertTrue(VisitLifecycleService::allVisitsInvoiced([
            ['status' => 'completed', 'invoice_id' => 5], ['status' => 'skipped'], ['status' => 'cancelled'],
        ]));
        $this->assertFalse(VisitLifecycleService::allVisitsInvoiced([['status' => 'skipped']]));
    }

}
