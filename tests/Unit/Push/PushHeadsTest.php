<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Push/PushHeads.php';

final class PushHeadsTest extends TestCase
{
    public function test_explicit_head_wins(): void
    {
        $this->assertSame(['Sam [Sales]', 'Linda just opened QUO-2026-0073'], PushHeads::brand('Sam', 'Linda just opened QUO-2026-0073', ['head' => 'sam']));
    }

    public function test_head_prefix_in_title_moves_into_body(): void
    {
        $this->assertSame(['Penny [Books]', 'got the receipt? — $53.21 at Midland'], PushHeads::brand('Penny: got the receipt?', '$53.21 at Midland', ['type' => 'penny_missing']));
    }

    public function test_job_pushes_are_otto(): void
    {
        $this->assertSame(['Otto [Ops]', 'Job Assigned — Thu, Oct 8 — 2205 W 45th'], PushHeads::brand('Job Assigned', 'Thu, Oct 8 — 2205 W 45th', ['screen' => 'schedule', 'stop_id' => 5]));
        $this->assertSame('otto', PushHeads::headFor(['type' => 'trip_report_unsafe']));
        $this->assertSame('otto', PushHeads::headFor(['type' => 'tracking_silent', 'screen' => 'schedule']));
    }

    public function test_unknown_push_is_unchanged(): void
    {
        $this->assertSame(['Hello', 'World'], PushHeads::brand('Hello', 'World', []));
    }

    public function test_already_branded_is_left_alone(): void
    {
        $this->assertSame(['Yui [Comms]', 'x'], PushHeads::brand('Yui [Comms]', 'x', ['head' => 'yui']));
    }
}
