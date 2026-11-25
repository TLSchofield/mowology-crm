<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QuoteIntakeDetails — the strata / commercial questions on the public quote form are cleaned and folded
 * into the request notes as one line (no schema change), and taken back out for the form on return.
 */
class QuoteIntakeDetailsTest extends TestCase
{
    public function testResidentialGetsNoBlock(): void
    {
        $this->assertSame([], QuoteIntakeDetails::clean(['org_name' => 'X'], 'residential'));
        $this->assertFalse(QuoteIntakeDetails::applies('residential'));
        $this->assertTrue(QuoteIntakeDetails::applies('strata'));
        $this->assertTrue(QuoteIntakeDetails::applies('commercial'));
    }

    public function testCleansAndLimitsAnswers(): void
    {
        $clean = QuoteIntakeDetails::clean([
            'org_name'           => '<b>Vancouver  Mgmt</b> [x]; drop',
            'site_count'         => str_repeat('9', 100),
            'decision_maker'     => 'hacker',
            'current_contractor' => '  Acme  ',
            'timing'             => 'Next AGM in March',
        ], 'strata');
        $this->assertSame('Vancouver Mgmt x drop', $clean['org_name']);
        $this->assertSame(40, strlen($clean['site_count']));
        $this->assertSame('', $clean['decision_maker']);
        $this->assertSame('Acme', $clean['current_contractor']);
    }

    public function testAppendLeavesOutEmptyAnswersAndAddsOneLine(): void
    {
        $intake = QuoteIntakeDetails::clean(['org_name' => 'VML', 'decision_maker' => 'council', 'timing' => 'April'], 'strata');
        $out = QuoteIntakeDetails::appendTo('Lawns and hedges.', $intake);
        $this->assertSame(
            "Lawns and hedges.\n\n[Property details] Management company or business: VML; Decided by: Strata council; Next AGM, budget meeting or start date: April",
            $out
        );
    }

    public function testAppendIsIdempotentAndSplitRoundTrips(): void
    {
        $intake = QuoteIntakeDetails::clean(['site_count' => '4 buildings'], 'commercial');
        $once  = QuoteIntakeDetails::appendTo('Notes.', $intake);
        $twice = QuoteIntakeDetails::appendTo($once, $intake);
        $this->assertSame($once, $twice);
        $parts = QuoteIntakeDetails::split($twice);
        $this->assertSame('Notes.', $parts['notes']);
        $this->assertStringContainsString('Buildings or sites: 4 buildings', $parts['line']);
    }

    public function testNoAnswersReturnsNotesUntouchedAndLimitHolds(): void
    {
        $this->assertSame('Just notes.', QuoteIntakeDetails::appendTo('Just notes.', []));
        $intake = QuoteIntakeDetails::clean(['org_name' => 'A company'], 'strata');
        $long = QuoteIntakeDetails::appendTo(str_repeat('x', 2000), $intake, 2000);
        $this->assertLessThanOrEqual(2000, strlen($long));
        $this->assertStringContainsString('[Property details]', $long);
    }

    public function testLongMultibyteNotesAreNotCutMidCharacter(): void
    {
        $intake = QuoteIntakeDetails::clean(['org_name' => 'Société'], 'strata');
        $out = QuoteIntakeDetails::appendTo(str_repeat('é', 2000), $intake, 2000);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertLessThanOrEqual(2000, mb_strlen($out));
    }
}
