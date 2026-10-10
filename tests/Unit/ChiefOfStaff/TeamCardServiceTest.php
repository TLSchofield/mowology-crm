<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The phone's head cards show the same top items as the dashboard Action Board's column
 * for that head (action-board.js take() + render()), and a compact brain.
 */
class TeamCardServiceTest extends TestCase
{
    private function it(string $key, string $head, float $score, array $extra = []): array
    {
        return $extra + ['key' => $key, 'head' => $head, 'kind' => $head . ':x', 'text' => "Item {$key}", 'url' => '/crm/x.php',
                         'priority' => 2, 'value' => null, 'since' => '2026-10-01', 'score' => $score];
    }

    private function view(): array
    {
        return [
            'one'  => $this->it('otto:a', 'otto', 9.0),
            'rest' => [$this->it('mia:a', 'mia', 8.0), $this->it('otto:b', 'otto', 7.0), $this->it('house:overdue', 'house', 6.5)],
            'heads' => [
                'otto' => ['head' => 'otto', 'headline' => '3 stops today', 'waiting' => 5,
                           'items' => [$this->it('otto:a', 'otto', 9.0), $this->it('otto:b', 'otto', 7.0), $this->it('otto:c', 'otto', 2.0)]],
                'mia'  => ['head' => 'mia', 'headline' => 'Mia line', 'waiting' => 1, 'items' => [$this->it('mia:a', 'mia', 8.0)]],
                'charlie' => ['head' => 'charlie', 'headline' => 'GST due soon', 'waiting' => 1, 'items' => [$this->it('charlie:gst', 'charlie', 3.0)]],
                'house' => ['head' => 'house', 'headline' => '1 thing nobody else is watching', 'waiting' => 2,
                            'items' => [$this->it('house:overdue', 'house', 6.5), $this->it('house:stuck', 'house', 1.0)]],
            ],
        ];
    }

    public function test_a_heads_column_is_its_top_three_by_score_without_duplicates(): void
    {
        $c = TeamCardService::column($this->view(), 'otto');
        $this->assertSame(['otto:a', 'otto:b', 'otto:c'], array_column($c['items'], 'key'));
        $this->assertSame(5, $c['waiting']);
        $this->assertSame('3 stops today', $c['headline']);
    }

    public function test_charlies_column_carries_the_work_queue_like_the_board(): void
    {
        $c = TeamCardService::column($this->view(), 'charlie');
        $this->assertSame(['house:overdue', 'charlie:gst', 'house:stuck'], array_column($c['items'], 'key'));
        $this->assertSame(3, $c['waiting']);                 // charlie 1 + house 2
        $this->assertSame('GST due soon', $c['headline']);   // his own headline, not the Work Queue's
    }

    public function test_a_head_with_nothing_waiting_is_empty(): void
    {
        $c = TeamCardService::column($this->view(), 'yui');
        $this->assertSame([], $c['items']);
        $this->assertSame(0, $c['waiting']);
    }

    public function test_slim_keeps_yes_and_drops_unsafe_links(): void
    {
        $s = TeamCardService::slim($this->it('yui:inbox:1', 'yui', 5, ['yes' => true, 'url' => 'javascript:alert(1)']));
        $this->assertTrue($s['yes']);
        $this->assertNull($s['url']);
        $this->assertSame('/crm/contacts/view.php?id=4', TeamCardService::slim($this->it('k', 'yui', 1, ['url' => '/crm/contacts/view.php?id=4']))['url']);
        $this->assertNull(TeamCardService::safeUrl('//evil.example'));
        $this->assertSame('https://mowology.ca/x', TeamCardService::safeUrl('https://mowology.ca/x'));
    }

    public function test_head_keys_and_post_ids(): void
    {
        $this->assertSame('charlie', TeamCardService::headKey('house'));
        $this->assertSame('charlie', TeamCardService::headKey(null));
        $this->assertSame('mia', TeamCardService::headKey('MIA'));
        $this->assertSame(12, TeamCardService::gbpPostId('mia:gbp-post:12'));
        $this->assertNull(TeamCardService::gbpPostId('mia:social-drafts:12'));
        $this->assertTrue(TeamCardService::isHead('yui'));
        $this->assertFalse(TeamCardService::isHead('penny'));
        $this->assertSame('/crm/img/heads/otto.jpg', TeamCardService::faceUrl('otto'));
    }

    public function test_brain_summary_counts_tiers_strongest_first(): void
    {
        $b = HeadBrain::withItems(['units' => 2, 'parts' => [['key' => 'answers', 'label' => '2 questions answered', 'n' => 2]], 'since' => '2026-10-01'], [
            HeadBrain::item('weather:mow', 'Mowing in rain', 12),
            HeadBrain::item('weather:hedge', 'Hedges in rain', 3),
            HeadBrain::item('weather:aerate', 'Aeration', 1),
        ]);
        $s = TeamCardService::brainSummary($b);
        $this->assertSame(5, $s['units']);
        $this->assertSame(5, $s['shape']);   // shape = things learned (shape 1 = one triangle)
        $this->assertSame('2026-10-01', $s['since']);
        $this->assertSame(['Mowing in rain', 'Hedges in rain', 'Aeration', '2 questions answered'], array_column($s['parts'], 'label'));
        $this->assertSame(['Gold', 'Bronze', 'Obsidian', null], array_column($s['parts'], 'tier'));
        $this->assertSame(['gold', 'bronze', 'obsidian'], array_column($s['tiers'], 'slug'));
    }

    public function test_an_empty_brain_is_shape_one(): void
    {
        $s = TeamCardService::brainSummary([]);
        $this->assertSame(['units' => 0, 'shape' => 1, 'since' => null, 'parts' => [], 'tiers' => []], $s);
    }
}
