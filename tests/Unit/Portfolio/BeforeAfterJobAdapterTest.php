<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 1) . '/../Support/TestIdentity.php';
require_once dirname(__DIR__, 3) . '/app/Modules/Portfolio/Services/BeforeAfterJobAdapter.php';

/** Mia's Before & After decisions: privacy, agreement (strata only), then her offered text. */
final class BeforeAfterJobAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestIdentity::mowology();
    }

    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        parent::tearDown();
    }

    private function pair(array $over = []): array
    {
        return array_merge([
            'id' => 7, 'label' => 'Lawn Care — Kitsilano', 'service' => 'Lawn Care', 'area' => 'Kitsilano', 'category' => 'lawn',
            'consent_state' => 'not_needed', 'privacy_checked_at' => null, 'property_name' => 'John Ellen Hughes Property',
            'address' => '3496 West 3rd Avenue', 'before_url' => 'uploads/b.jpg', 'after_url' => 'uploads/a.jpg', 'completed_at' => '2026-10-22 10:00:00',
        ], $over);
    }

    public function test_the_privacy_check_always_comes_first(): void
    {
        $this->assertSame('privacy', BeforeAfterJobAdapter::step($this->pair()));
        $this->assertSame('privacy', BeforeAfterJobAdapter::step($this->pair(['consent_state' => 'needed'])));
    }

    public function test_strata_needs_the_agreement_before_the_text_and_residential_goes_straight_to_it(): void
    {
        $checked = '2026-11-10 09:00:00';
        $this->assertSame('consent', BeforeAfterJobAdapter::step($this->pair(['privacy_checked_at' => $checked, 'consent_state' => 'needed'])));
        $this->assertSame('offer', BeforeAfterJobAdapter::step($this->pair(['privacy_checked_at' => $checked, 'consent_state' => 'recorded'])));
        $this->assertSame('offer', BeforeAfterJobAdapter::step($this->pair(['privacy_checked_at' => $checked])));
    }

    public function test_the_offer_names_the_service_and_neighbourhood_only(): void
    {
        $o = BeforeAfterJobAdapter::offer($this->pair());
        $this->assertSame('Lawn Care — Kitsilano', $o['label']);
        $this->assertSame('Kitsilano', $o['area']);
        $this->assertStringContainsString('Kitsilano', $o['alt_before']);
        $this->assertStringNotContainsString('Hughes', $o['label'] . $o['alt_before'] . $o['alt_after']);
        $this->assertStringNotContainsString('3496', $o['label'] . $o['alt_before'] . $o['alt_after']);
        $this->assertSame([], $o['notes']);
    }

    public function test_text_that_could_identify_the_client_is_left_blank(): void
    {
        $o = BeforeAfterJobAdapter::offer($this->pair(['area' => '3496 West 3rd']));
        $this->assertSame('', $o['label']);
        $this->assertSame('', $o['alt_before']);
        $this->assertNotEmpty($o['notes']);
        $this->assertTrue(BeforeAfterJobAdapter::identifies('Pruning at V6K 1A1', $this->pair()));
        $this->assertTrue(BeforeAfterJobAdapter::identifies('Work at John Ellen Hughes Property', $this->pair()));
        $this->assertFalse(BeforeAfterJobAdapter::identifies('Lawn care in Kitsilano before Mowology', $this->pair()));
    }

    public function test_no_neighbourhood_is_flagged_not_invented(): void
    {
        $o = BeforeAfterJobAdapter::offer($this->pair(['area' => '']));
        $this->assertSame('', $o['area']);
        $this->assertContains('no neighbourhood on file: add one', $o['notes']);
    }

    public function test_editing_anything_counts_as_edited(): void
    {
        $o = BeforeAfterJobAdapter::offer($this->pair());
        $this->assertFalse(BeforeAfterJobAdapter::edited($this->pair(), $o));
        $this->assertFalse(BeforeAfterJobAdapter::edited($this->pair(), ['label' => ' ' . $o['label'] . ' ', 'area' => $o['area'], 'alt_before' => $o['alt_before'], 'alt_after' => $o['alt_after']]));
        $this->assertTrue(BeforeAfterJobAdapter::edited($this->pair(), ['label' => 'Spring lawn — Kits'] + $o));
    }

    public function test_the_triangle_is_earned_by_record_and_gold_needs_five(): void
    {
        $this->assertSame('forming', BeforeAfterJobAdapter::sure([0, 0]));
        $this->assertSame('held', BeforeAfterJobAdapter::sure([1, 1]));
        $this->assertSame('held', BeforeAfterJobAdapter::sure([6, 5]));
        $this->assertSame('known', BeforeAfterJobAdapter::sure([4, 4]));
        $this->assertSame('mastered', BeforeAfterJobAdapter::sure([5, 5]));
    }

    public function test_each_card_posts_through_the_pages_own_actions(): void
    {
        $c = BeforeAfterJobAdapter::cardFor($this->pair(), [0, 0]);
        $this->assertSame('ba_privacy', $c['kind']);
        $this->assertSame('queue_privacy', $c['primary']['request']['body']['action']);
        $this->assertSame('queue_reject', $c['secondary']['request']['body']['action']);
        $this->assertSame(BeforeAfterJobAdapter::PAGE, $c['primary']['request']['url']);

        $c = BeforeAfterJobAdapter::cardFor($this->pair(['privacy_checked_at' => 'x', 'consent_state' => 'needed']), [0, 0]);
        $this->assertSame('queue_consent', $c['primary']['request']['body']['action']);
        $this->assertSame(['note'], $c['required']);

        $c = BeforeAfterJobAdapter::cardFor($this->pair(['privacy_checked_at' => 'x']), [3, 3]);
        $this->assertSame('ba_offer', $c['kind']);
        $this->assertSame('queue_approve', $c['primary']['request']['body']['action']);
        $this->assertSame('1', $c['primary']['request']['body']['offered']);
        $this->assertSame('known', $c['sure']);
        $this->assertSame('Not for the website', $c['secondary']['label']);
        $this->assertSame(['/uploads/b.jpg', '/uploads/a.jpg'], $c['post']['thumbs']);
    }

    public function test_every_waiting_pair_gets_one_card_up_to_the_cap(): void
    {
        $pairs = [];
        for ($i = 1; $i <= 25; $i++) $pairs[] = $this->pair(['id' => $i]);
        $this->assertCount(BeforeAfterJobAdapter::MAX_JOBS, BeforeAfterJobAdapter::jobs($pairs, [0, 0]));
    }

    public function test_a_street_name_or_unit_number_is_caught_even_when_it_is_only_part_of_the_address(): void
    {
        $pair = $this->pair(['address' => '12 Oak Street, Vancouver']);
        $this->assertTrue(BeforeAfterJobAdapter::identifies('Lawn care on Oak Street', $pair));
        $this->assertTrue(BeforeAfterJobAdapter::identifies('Garden care, Unit 4', $pair));
        $this->assertFalse(BeforeAfterJobAdapter::identifies('Lawn care in Kitsilano', $pair));
    }

    public function test_changing_the_service_or_filter_counts_as_an_edit(): void
    {
        $o = BeforeAfterJobAdapter::offer($this->pair());
        $this->assertTrue(BeforeAfterJobAdapter::edited($this->pair(), $o + ['category' => 'garden']));
        $this->assertFalse(BeforeAfterJobAdapter::edited($this->pair(), $o + ['category' => 'lawn', 'service' => 'Lawn Care']));
    }

    public function test_the_publish_gate_stays_on_when_the_switch_cannot_be_read(): void
    {
        $bad = new class extends PDO { public function __construct() {} #[\ReturnTypeWillChange] public function prepare($q, $o = []) { throw new PDOException('gone'); } };
        $this->assertTrue(BeforeAfterJobAdapter::gateOn($bad));
        $this->assertFalse(BeforeAfterJobAdapter::enabled($bad));
    }
}
