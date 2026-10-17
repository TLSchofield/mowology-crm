<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HeadStateServiceTest extends TestCase
{
    public function testEveryRuleAndCharlieRollup(): void
    {
        $states = HeadStateService::fromBrief([
            'charlie' => [],
            'yui' => [['key' => 'a', 'yes' => true], ['key' => 'b', 'yes' => false]],
            'sam' => [['key' => 'c']],
            'penny' => [],
            'otto' => [['key' => 'd', 'yes' => true]],
            'mia' => [],
        ], ['otto' => 'synthetic failure']);

        $this->assertSame(['state' => 'approval', 'reason' => '', 'open' => 2, 'needs_you' => 1], $states['yui']);
        $this->assertSame('working', $states['sam']['state']);
        $this->assertSame('idle', $states['penny']['state']);
        $this->assertSame(['state' => 'blocked', 'reason' => 'Desk did not load', 'open' => 1, 'needs_you' => 1], $states['otto']);
        $this->assertSame(['state' => 'unavailable', 'reason' => 'Not set up yet', 'open' => 0, 'needs_you' => 0], $states['ada']);
        $this->assertSame(['state' => 'approval', 'reason' => '', 'open' => 4, 'needs_you' => 2], $states['charlie']);
    }

    public function testBlockedTakesPrecedenceOverApprovalAndWorking(): void
    {
        $states = HeadStateService::fromBrief(['yui' => [['yes' => true], []]], ['yui']);
        $this->assertSame(['state' => 'blocked', 'reason' => 'Desk did not load', 'open' => 2, 'needs_you' => 1], $states['yui']);
    }

    public function testCharlieIsBlockedOnlyWhenHisBriefSourceFailed(): void
    {
        $states = HeadStateService::fromBrief(['charlie' => [['yes' => true]], 'sam' => []], ['charlie' => 'do not expose']);
        $this->assertSame('blocked', $states['charlie']['state']);
        $this->assertSame('Brief did not load', $states['charlie']['reason']);
        $this->assertStringNotContainsString('do not expose', $states['charlie']['reason']);
    }

    public function testCharlieWorkingAndIdleRules(): void
    {
        $working = HeadStateService::fromBrief(['charlie' => [[]]], []);
        $idle = HeadStateService::fromBrief(['charlie' => [], 'yui' => []], []);
        $this->assertSame('working', $working['charlie']['state']);
        $this->assertSame('idle', $idle['charlie']['state']);
    }
}
