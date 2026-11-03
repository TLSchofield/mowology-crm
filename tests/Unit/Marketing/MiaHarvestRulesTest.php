<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Mia's harvest 2026-11-03: voice + slop test, content mix, evidence minimums, ad bands, the weekly page. */
final class MiaHarvestRulesTest extends TestCase
{
    private const TODAY = '2026-11-03';

    public function testVoiceIsLearnedFromSentMailAndNothingIsGuessedFromTooFew(): void
    {
        $s = ["Hi Ann,\n\nThe hedge is done and the clippings are gone. Let me know if anything looks off.\n\nThanks,\nTim",
              "Hi Bo,\n\nWe'll be by Tuesday for the clean-up. Let me know if the gate's locked.\n\nThanks,\nTim",
              "Hi Cy,\n\nQuote's attached. It's for the front beds only, as we discussed. Let me know what you think.\n\nThanks,\nTim"];
        $p = VoiceProfile::learn($s, ['cheers mate']);
        $this->assertTrue($p['enough']);
        $this->assertSame('Hi <name>,', $p['greeting']);
        $this->assertSame('Thanks', $p['signoff']);
        $this->assertTrue($p['contractions']);
        $this->assertSame('never', $p['exclamations']);
        $this->assertLessThan(14, $p['avg_sentence_words']);
        $this->assertContains('let me know', $p['phrases']);
        $this->assertContains('cheers mate', $p['never_uses']);
        $this->assertContains('reach out', $p['never_uses']);
        $few = VoiceProfile::learn([$s[0]]);
        $this->assertFalse($few['enough']);
        $this->assertStringContainsString('nothing is guessed', $few['note']);
        $p2 = VoiceProfile::amend($p, 'signoff', 'Cheers', 'Tim prefers it for crews', self::TODAY);
        $this->assertSame('Cheers', $p2['signoff']);
        $this->assertSame('Tim prefers it for crews', $p2['updates'][0]['reason']);
    }

    public function testSlopTestCatchesFillersSuperlativesLongSentencesAndUnconfirmedPrices(): void
    {
        $bad = "Hi Ann! I wanted to reach out and touch base about our exceptional solutions, which are the best in Vancouver and 100% satisfaction guaranteed, so we can leverage this opportunity to elevate your garden to the next level this season from \$95, and while I have you I also wanted to mention that our crews are available most weekdays and some Saturdays if that helps.";
        $w = VoiceProfile::check($bad, ['greeting' => 'Hi <name>,']);
        $joined = implode("\n", $w);
        foreach (['Not his words', 'exclamation mark', 'claim nobody can back', 'over 40 words', 'price or percentage'] as $n) $this->assertStringContainsString($n, $joined);
        $this->assertSame([], VoiceProfile::check("Hi Ann,\n\nThe hedge is done. Let me know if anything looks off.\n\nThanks,\nTim", ['greeting' => 'Hi <name>,']));
        $this->assertStringContainsString('Greeting is not his', implode("\n", VoiceProfile::check("Hey there,\n\nAll done.", ['greeting' => 'Hi <name>,'])));
    }

    public function testContentMixReportsGapBalanceAndConflictsAndKeepsHouseCaptionRules(): void
    {
        $posts = [
            ['date' => '2026-10-28', 'kind' => 'proof', 'status' => 'published'],
            ['date' => '2026-11-04', 'kind' => 'useful', 'status' => 'scheduled', 'service' => 'hedges'],
            ['date' => '2026-11-04', 'kind' => 'useful', 'status' => 'scheduled', 'service' => 'hedges'],
            ['date' => '2026-11-06', 'kind' => 'useful', 'status' => 'scheduled'],
            ['date' => '2026-11-12', 'kind' => 'offer', 'status' => 'scheduled', 'offer_ends' => '2026-11-10'],
            ['date' => '2026-10-20 09:00:00', 'kind' => 'people', 'status' => 'scheduled'],
        ];
        $r = ContentMix::report($posts, self::TODAY, 'solo', '2026-10-27');
        $this->assertSame(1, $r['published_since']);
        $this->assertSame(4, $r['scheduled']);
        $this->assertSame('2026-11-10', $r['first_gap']);          // week 2 has one post, under 2 a week
        $this->assertSame(['proof', 'people'], $r['short']);         // 3 useful + 1 offer of 4 → proof 0%, people 0%
        $this->assertStringContainsString('ask the crew for finished-job photos', $r['say'][1]);
        $joined = implode("\n", $r['conflicts']);
        $this->assertStringContainsString('2 posts on 2026-11-04 about hedges', $joined);
        $this->assertStringContainsString('dated in the past', $joined);
        $this->assertStringContainsString('after its offer ends', $joined);
        $this->assertSame([], ContentMix::captionProblems('Before and after: hedges in Kitsilano this week. #vancouver #hedges'));
        $c = ContentMix::captionProblems('Exciting news! We are thrilled to announce our best in Vancouver service. Book now. Call today. #a #b #c #d');
        $this->assertStringContainsString('4 hashtags', implode("\n", $c));
        $this->assertStringContainsString('sentences', implode("\n", $c));
        $e = ContentMix::emailProblems('A subject line that is far too long to read on a phone screen today', str_repeat('word ', 40), 'short');
        $this->assertCount(3, $e);
        $this->assertSame([], ContentMix::emailProblems('Fall clean-ups', str_repeat('word ', 150) . ' Reply if you want one.'));
    }

    public function testEvidenceMinimumsAndAdBands(): void
    {
        $this->assertFalse(EvidenceMinimums::newCampaign(5)['ok']);
        $this->assertTrue(EvidenceMinimums::pause(12, 50)['ok']);
        $this->assertFalse(EvidenceMinimums::winner(999, 20)['ok']);
        $t = EvidenceMinimums::trend([1, 2, 3, 4, 5, 6, 7, 8, 9]);
        $this->assertSame(['up', true, 'thin'], [$t['direction'], $t['real'], $t['confidence']]);
        $this->assertSame('none', EvidenceMinimums::trend([3, 4])['direction']);
        $base = ['tracking' => true, 'days' => 45, 'results' => 60, 'spend' => 900, 'cost_per_result' => 30, 'trailing_cost_per_result' => 30, 'frequency' => 2.1, 'volume_trend' => 'flat', 'days_without_results' => 0];
        $this->assertSame('working', AdDiagnostics::band($base)['band']);
        $this->assertSame('watch', AdDiagnostics::band(['cost_per_result' => 38] + $base)['band']);        // +27%
        $this->assertSame('problem', AdDiagnostics::band(['cost_per_result' => 45] + $base)['band']);      // +50%
        $this->assertSame('watch', AdDiagnostics::band(['frequency' => 3.4] + $base)['band']);
        $this->assertSame('problem', AdDiagnostics::band(['days_without_results' => 14] + $base)['band']);
        $this->assertSame('no_verdict', AdDiagnostics::band(['tracking' => false] + $base)['band']);
        $this->assertSame('no_verdict', AdDiagnostics::band(['days' => 4, 'results' => 2] + $base)['band']);
        $this->assertStringContainsString('at most 20–30%', AdDiagnostics::budgetAdvice($base));
        $this->assertStringContainsString('new creative, not more money', AdDiagnostics::budgetAdvice(['frequency' => 4.5, 'ctr_trend' => 'down'] + $base));
        $b = AdDiagnostics::approvalBlock('Raise the lead campaign', 500, 625, true);
        $this->assertSame('+$125 a month (+25%)', $b['net']);
        $this->assertSame('$500 a month', $b['costs_today']);
    }

    public function testWeeklyPageHasThreeRankedActionsAndAQuietWeekIsThreeLines(): void
    {
        $this->assertSame('yellow', MarketingWeek::band('leads', 9, 10)['band']);
        $this->assertSame('red', MarketingWeek::band('leads', 7, 10)['band']);
        $this->assertSame('red', MarketingWeek::band('reach', 8, 10)['band']);
        $this->assertSame('green', MarketingWeek::band('review_score', 4.5, 4.6)['band']);
        $this->assertSame('red', MarketingWeek::band('concentration', 0.85, null)['band']);
        $this->assertSame('na', MarketingWeek::band('leads', null, 10)['band']);
        $p = MarketingWeek::page(['leads' => ['band' => 'green'], 'reach' => ['band' => 'green']], [], [], 'Week of Nov 3');
        $this->assertTrue($p['quiet']);
        $this->assertStringStartsWith('A quiet week', $p['headline']);
        $p = MarketingWeek::page(['leads' => ['band' => 'red'], 'reach' => ['band' => 'yellow']],
            [['text' => 'Answer the 3 waiting reviews', 'number' => '3 waiting, oldest 9 days', 'worth' => 300], ['text' => 'Ask the crew for photos', 'number' => 'proof at 0% of 30%', 'worth' => 100], ['text' => 'Approve the hedge post', 'number' => '1 draft', 'worth' => 150], ['text' => 'Fourth thing', 'number' => '', 'worth' => 50]], ['Search Console'], 'Week of Nov 3');
        $this->assertCount(3, $p['actions']);
        $this->assertSame('Answer the 3 waiting reviews', $p['actions'][0]['text']);
        $this->assertStringContainsString('1 thing red (leads), 1 to watch — 3 actions worth taking.', $p['headline']);
        $this->assertStringContainsString('Search Console — n/a, not clean', $p['sources_line']);
    }
}
