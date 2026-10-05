<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's badges: earned only from the owner's decisions, lost again when she slips.
 */
class PennyBadgeServiceTest extends TestCase
{
    private function d(bool $ok, string $vendor = 'Home Depot', string $cat = 'Materials', array $outcome = []): array
    {
        return ['all_ok' => $ok, 'vendor' => $vendor, 'category' => $cat, 'outcome' => $outcome];
    }

    private function keys(array $r): array
    {
        return array_column($r['earned'], 'key');
    }

    public function test_nothing_decided_means_no_badges_but_a_next_one(): void
    {
        $r = PennyBadgeService::compute([]);
        $this->assertSame([], $r['earned']);
        $this->assertNotNull($r['next']);
        $this->assertSame(0, $r['next']['have']);
    }

    public function test_five_unchanged_from_one_vendor_earns_trust_and_a_slip_loses_it(): void
    {
        $five = array_fill(0, 5, $this->d(true));
        $this->assertContains('vendor:Home Depot', $this->keys(PennyBadgeService::compute($five)));

        $slipped = array_merge([$this->d(false)], $five);   // newest first
        $r = PennyBadgeService::compute($slipped);
        $this->assertNotContains('vendor:Home Depot', $this->keys($r));
    }

    public function test_streak_and_tax_count_only_the_most_recent_run(): void
    {
        $tax = ['total' => ['accepted' => true], 'gst' => ['accepted' => true], 'pst' => ['accepted' => true]];
        $list = array_merge(array_fill(0, 10, $this->d(true, 'A', 'Materials', $tax)), [$this->d(false, 'A', 'Materials', ['gst' => ['accepted' => false]])], array_fill(0, 30, $this->d(true)));
        $r = PennyBadgeService::compute($list);
        $this->assertContains('streak', $this->keys($r));
        $this->assertNotContains('tax', $this->keys($r), 'only 10 right since the GST slip');
    }

    public function test_fuel_pro_needs_category_and_tag_kept(): void
    {
        $good = ['accounting_category' => ['accepted' => true], 'asset_tag' => ['accepted' => true]];
        $fuel = array_fill(0, 10, $this->d(false, 'Shell', 'Fuel', $good));
        $this->assertContains('fuel', $this->keys(PennyBadgeService::compute($fuel)));
        $fuel[0]['outcome']['asset_tag']['accepted'] = false;
        $this->assertNotContains('fuel', $this->keys(PennyBadgeService::compute($fuel)));
    }

    public function test_job_finder_counts_kept_picks_not_blank_ones(): void
    {
        $picked = ['job' => ['suggested' => 42, 'accepted' => true]];
        $blank = ['job' => ['suggested' => null, 'accepted' => true]];
        $list = array_merge(array_fill(0, 4, $this->d(false, 'X', 'Materials', $picked)), array_fill(0, 9, $this->d(false, 'X', 'Materials', $blank)));
        $this->assertNotContains('jobs', $this->keys(PennyBadgeService::compute($list)));
        $list[] = $this->d(false, 'X', 'Materials', $picked);
        $this->assertContains('jobs', $this->keys(PennyBadgeService::compute($list)));
    }

    public function test_found_to_bill_shows_the_dollars(): void
    {
        $r = PennyBadgeService::compute([], 86.4);
        $this->assertSame('$86 found to bill', $r['earned'][0]['label']);
    }
}
