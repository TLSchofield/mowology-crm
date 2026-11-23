<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VisitEditServiceTest extends TestCase
{
    public function testAScheduledVisitCanBeCancelled(): void
    {
        $this->assertNull(VisitEditService::cancelRefusal(['status' => 'scheduled'], false));
        $this->assertNull(VisitEditService::cancelRefusal(['status' => 'skipped'], false));
    }

    /** @dataProvider refusedVisits */
    public function testCancelIsRefusedWhenItWouldLoseWork(array $visit, bool $timer, string $needle): void
    {
        $why = VisitEditService::cancelRefusal($visit, $timer);
        $this->assertNotNull($why);
        $this->assertStringContainsString($needle, $why);
    }

    public static function refusedVisits(): array
    {
        return [
            'done'      => [['status' => 'completed'], false, 'done'],
            'cancelled' => [['status' => 'cancelled'], false, 'already cancelled'],
            'locked'    => [['status' => 'scheduled', 'locked_at' => '2026-11-01 10:00:00'], false, 'locked'],
            'invoiced'  => [['status' => 'scheduled', 'invoice_id' => 9], false, 'invoice'],
            'flagged'   => [['status' => 'scheduled', 'is_invoiced' => 1], false, 'invoice'],
            'timer'     => [['status' => 'scheduled'], true, 'right now'],
            'started'   => [['status' => 'in_progress'], false, 'right now'],
        ];
    }

    public function testSystemNotesAreNeverChangeable(): void
    {
        $this->assertNotNull(VisitEditService::noteRefusal(null, 5, true));
        $this->assertNotNull(VisitEditService::noteRefusal(0, 5, true));
    }

    public function testNoteOwnership(): void
    {
        $this->assertNull(VisitEditService::noteRefusal(5, 5, false));
        $this->assertNull(VisitEditService::noteRefusal(7, 5, true));
        $this->assertNotNull(VisitEditService::noteRefusal(7, 5, false));
    }

    public function testNoteText(): void
    {
        $this->assertSame("a\nb", VisitEditService::cleanNote("  a\r\nb  "));
        $this->assertNull(VisitEditService::cleanNote('   '));
        $this->assertNull(VisitEditService::cleanNote(null));
        $this->assertNull(VisitEditService::cleanNote(str_repeat('x', VisitEditService::NOTE_MAX + 1)));
    }

    public function testPlanTitleAndDescription(): void
    {
        $r = VisitEditService::cleanPlanFields(['title' => '  Lawn care ', 'description' => ' cut it ']);
        $this->assertSame([], $r['errors']);
        $this->assertSame(['title' => 'Lawn care', 'description' => 'cut it'], $r['data']);
    }

    public function testEmptyTitleIsRefusedAndNothingToChangeIsReported(): void
    {
        $this->assertNotSame([], VisitEditService::cleanPlanFields(['title' => '  '])['errors']);
        $this->assertSame(['Nothing to change.'], VisitEditService::cleanPlanFields([])['errors']);
        $this->assertSame(['Nothing to change.'], VisitEditService::cleanPlanFields(['other' => 'x'])['errors']);
    }

    public function testPriceNeedsConfirmationAndASaneValue(): void
    {
        $unconfirmed = VisitEditService::cleanPlanFields(['price_per_visit' => '85']);
        $this->assertStringContainsString('Confirm', $unconfirmed['errors'][0]);
        $this->assertArrayNotHasKey('price_per_visit', $unconfirmed['data']);
        foreach (['0', '-5', 'abc', '', 2000000] as $bad) {
            $this->assertNotSame([], VisitEditService::cleanPlanFields(['price_per_visit' => $bad, 'confirm_price' => 1])['errors'], (string)$bad);
        }
        foreach (['false', 'no', '0', 0, false, null, ''] as $notYes) {
            $this->assertNotSame([], VisitEditService::cleanPlanFields(['price_per_visit' => '85', 'confirm_price' => $notYes])['errors'], var_export($notYes, true));
        }
        foreach ([true, 1, '1', 'true'] as $yes) {
            $this->assertSame([], VisitEditService::cleanPlanFields(['price_per_visit' => '85', 'confirm_price' => $yes])['errors'], var_export($yes, true));
        }
        $ok = VisitEditService::cleanPlanFields(['price_per_visit' => '85.456', 'confirm_price' => 1]);
        $this->assertSame([], $ok['errors']);
        $this->assertSame(85.46, $ok['data']['price_per_visit']);
    }
}
