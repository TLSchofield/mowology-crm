<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The Listings page's decisions as Mia's cards: what is asked, what is read, what each button sends. */
class MiaListingJobsTest extends TestCase
{
    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-10 09:00:00'); }

    private function master(): array
    {
        return ['name' => 'Mowology Landscaping', 'address' => '2845 West 15th Ave Vancouver V6K 3A1', 'phone' => '778 846 9273',
                'website' => 'mowology.ca', 'email' => '', 'hours' => 'Mon–Fri 08:00–17:00', 'categories' => 'Landscaper; Lawn care service; Snow removal service'];
    }

    private function row(array $o = []): array
    {
        return $o + ['id' => 1, 'site_key' => 'bing', 'site_name' => 'Bing Places', 'claim_url' => 'https://www.bingplaces.com/', 'status' => 'unknown',
            'profile_url' => null, 'review_count' => null, 'rating' => null, 'listed_name' => null, 'listed_address' => null, 'listed_phone' => null,
            'listed_website' => null, 'notes' => null, 'is_optional' => 0, 'last_checked' => null, 'stale' => true,
            'nap' => ['status' => 'unchecked', 'fields' => [], 'issues' => []]];
    }

    private function ok(): array
    {
        $s = ['status' => 'connected', 'reason' => '', 'label' => '', 'account' => ''];
        return ['facebook' => $s, 'instagram' => $s, 'gbp' => $s, 'needs_reconnect' => false];
    }

    private function q(array $rows, ?array $google = null, array $records = [], ?array $state = null, bool $approved = true, bool $connected = true): array
    {
        return MiaListingJobs::queue($this->master(), $rows, $google, $records, $state ?? $this->ok(), $approved, $connected, $this->now());
    }

    public function test_an_unclaimed_site_asks_to_claim_and_marks_it_only_when_told(): void
    {
        $jobs = $this->q([$this->row()]);
        $this->assertCount(1, $jobs);
        $this->assertSame('claim:1', $jobs[0]['key']);
        $p = $jobs[0]['primary'];
        $this->assertSame('https://www.bingplaces.com/', $p['open']);
        $this->assertArrayNotHasKey('request', $p, 'opening the page changes nothing');
        $alt = $p['alt'];
        $this->assertSame('listing', $alt['kind']);
        $this->assertSame('claimed', $alt['primary']['request']['body']['status']);
        $this->assertSame('claimed', $alt['primary']['request']['body']['job_result']);
        $this->assertSame('listing', $alt['primary']['request']['body']['form']);
        $this->assertSame('/crm/marketing/listings.php', $alt['primary']['request']['url']);
    }

    public function test_optional_unclaimed_sites_are_not_asked(): void
    {
        $this->assertSame([], $this->q([$this->row(['is_optional' => 1])]));
    }

    public function test_an_unchecked_listing_asks_does_it_match_and_matches_posts_the_master_values(): void
    {
        $r = $this->row(['status' => 'claimed', 'profile_url' => 'https://example.test/p', 'review_count' => 4, 'rating' => '4.5', 'notes' => 'n']);
        $j = $this->q([$r])[0];
        $this->assertSame('compare:1', $j['key']);
        $b = $j['primary']['request']['body'];
        $this->assertSame('Mowology Landscaping', $b['listed_name']);
        $this->assertSame('confirmed', $b['job_result']);
        // the page's save overwrites every field, so the ones we do not change must go back unchanged
        $this->assertSame('https://example.test/p', $b['profile_url']);
        $this->assertSame('4', $b['review_count']);
        $this->assertSame('4.5', $b['rating']);
        $this->assertSame('n', $b['notes']);
        $this->assertSame('claimed', $b['status']);
        $enter = $j['secondary']['alt'];
        $this->assertSame('listing_enter', $enter['kind']);
        $this->assertSame(['listed_name', 'listed_address', 'listed_phone', 'listed_website'], $enter['fields']);
        $this->assertSame('changed', $enter['primary']['request']['body']['job_result']);
    }

    public function test_a_mismatch_names_only_what_differs_and_asks_for_the_fix(): void
    {
        $r = $this->row(['status' => 'claimed', 'profile_url' => 'https://example.test/p', 'listed_name' => 'Mowology Landscaping', 'listed_address' => '1 Other St',
                         'listed_phone' => '778-846-9273', 'listed_website' => 'https://www.mowology.ca/', 'last_checked' => '2026-11-01',
                         'nap' => ['status' => 'mismatch', 'fields' => [], 'issues' => ['x']]]);
        $j = $this->q([$r])[0];
        $this->assertSame('fix:1', $j['key']);
        $this->assertStringContainsString('address', $j['title']);
        $this->assertStringNotContainsString('phone', $j['title']);
        $states = array_column($j['compare'], 'state', 'k');
        $this->assertSame(['Name' => 'ok', 'Address' => 'diff', 'Phone' => 'ok', 'Website' => 'ok'], $states);
        $f = $j['primary']['alt']['primary']['request']['body'];
        $this->assertSame('fixed', $f['job_result']);
        $this->assertSame('Mowology Landscaping', $f['listed_name']);
        $this->assertSame('778 846 9273', $f['listed_phone'], 'Fixed saves the master values, so the mismatch clears');
    }

    public function test_a_listing_not_checked_in_ninety_days_asks_still_the_same(): void
    {
        $r = $this->row(['status' => 'claimed', 'profile_url' => 'https://example.test/p', 'listed_name' => 'Mowology Landscaping', 'listed_address' => '2845 W 15th Avenue, Vancouver, BC V6K3A1',
                         'listed_phone' => '7788469273', 'listed_website' => 'mowology.ca', 'last_checked' => '2026-06-01',
                         'nap' => ['status' => 'match', 'fields' => [], 'issues' => []]]);
        $j = $this->q([$r])[0];
        $this->assertSame('recheck:1', $j['key']);
        $this->assertSame('confirmed', $j['primary']['request']['body']['job_result']);
        $this->assertSame('7788469273', $j['primary']['request']['body']['listed_phone'], 'what the site showed is kept');
    }

    public function test_a_listing_that_matches_and_is_fresh_asks_nothing(): void
    {
        $r = $this->row(['status' => 'claimed', 'profile_url' => 'https://example.test/p', 'stale' => false, 'last_checked' => '2026-11-01',
                         'nap' => ['status' => 'match', 'fields' => [], 'issues' => []]]);
        $this->assertSame([], $this->q([$r]));
    }

    public function test_google_is_read_not_asked_and_only_a_difference_raises_a_card(): void
    {
        $gbp = $this->row(['id' => 9, 'site_key' => 'gbp', 'site_name' => 'Google Business Profile', 'claim_url' => 'https://business.google.com/', 'status' => 'verified', 'last_checked' => '2026-09-28']);
        $same = $this->master() + ['at' => '2026-11-09 09:00:00'];
        $this->assertSame([], $this->q([$gbp], $same));
        $diff = ['phone' => '604 555 0100', 'at' => '2026-11-09 09:00:00'] + $this->master();
        $j = $this->q([$gbp], $diff)[0];
        $this->assertSame('fix:9', $j['key']);
        $this->assertContains('Hours', array_column($j['compare'], 'k'), 'Google tells us hours and categories');
        $this->assertSame(['Phone'], array_values(array_column(array_filter($j['compare'], fn($x) => $x['state'] === 'diff'), 'k')));
    }

    public function test_a_google_read_older_than_the_owners_last_check_is_ignored(): void
    {
        $row = ['last_checked' => '2026-11-10'];
        $this->assertFalse(MiaListingJobs::googleFresh(['at' => '2026-11-09 09:00:00'], $row, $this->now()));
        $this->assertTrue(MiaListingJobs::googleFresh(['at' => '2026-11-09 09:00:00'], ['last_checked' => '2026-11-01'], $this->now()));
        $this->assertFalse(MiaListingJobs::googleFresh(['at' => '2026-10-01 09:00:00'], ['last_checked' => null], $this->now()), 'three days is the limit');
    }

    public function test_reconnect_and_approval_come_first(): void
    {
        $state = $this->ok();
        $state['facebook'] = ['status' => 'reconnect', 'reason' => 'Facebook turned the saved login down.', 'label' => 'Facebook', 'account' => ''];
        $state['gbp'] = ['status' => 'reconnect', 'reason' => 'the saved login can\'t be read', 'label' => 'Google', 'account' => ''];
        $jobs = $this->q([$this->row()], null, [], $state, false, true);
        $this->assertSame(['reconnect:facebook', 'reconnect:gbp', 'gbp-approval', 'claim:1'], array_column($jobs, 'key'));
        $this->assertSame(SocialConnectionState::RECONNECT_URL, $jobs[0]['primary']['href']);
        $this->assertSame(SocialConnectionState::GBP_CONNECT_URL, $jobs[1]['primary']['href']);
        $this->assertSame('gbp_approved', $jobs[2]['primary']['request']['body']['form']);
    }

    public function test_the_approval_question_is_not_asked_once_approved_or_when_not_connected(): void
    {
        $this->assertSame([], $this->q([], null, [], null, true, true));
        $this->assertSame([], $this->q([], null, [], null, false, false));
    }

    public function test_the_queue_is_capped(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) $rows[] = $this->row(['id' => $i, 'site_name' => 'S' . $i]);
        $this->assertLessThanOrEqual(MiaListingJobs::MAX_CARDS, count($this->q($rows)));
    }

    public function test_her_triangle_follows_her_record_with_the_site(): void
    {
        $r = $this->row(['status' => 'claimed', 'profile_url' => 'https://x.test']);
        $this->assertSame('forming', $this->q([$r])[0]['sure']);
        $this->assertSame('known', $this->q([$r], null, [1 => ['called' => 3, 'agreed' => 3]])[0]['sure']);
        $this->assertSame('mastered', $this->q([$r], null, [1 => ['called' => 5, 'agreed' => 5]])[0]['sure']);
        $this->assertSame('held', $this->q([$r], null, [1 => ['called' => 4, 'agreed' => 3]])[0]['sure']);
    }

    public function test_fixing_and_claiming_do_not_count_toward_her_record(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE marketing_listing_checks (id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INT, result TEXT)");
        foreach (['fixed', 'fixed', 'claimed', 'confirmed', 'changed'] as $r) $db->exec("INSERT INTO marketing_listing_checks (listing_id, result) VALUES (1, '$r')");
        $this->assertSame([1 => ['called' => 2, 'agreed' => 1]], MiaListingJobs::records($db));
    }

    public function test_google_location_becomes_the_masters_own_fields(): void
    {
        $g = MiaListingJobs::shapeGoogle([
            'title' => 'Mowology Landscaping', 'websiteUri' => 'https://mowology.ca/',
            'phoneNumbers' => ['primaryPhone' => '(778) 846-9273'],
            'storefrontAddress' => ['addressLines' => ['2845 West 15th Ave'], 'locality' => 'Vancouver', 'administrativeArea' => 'BC', 'postalCode' => 'V6K 3A1'],
            'categories' => ['primaryCategory' => ['displayName' => 'Landscaper'], 'additionalCategories' => [['displayName' => 'Snow removal service'], ['displayName' => 'Lawn care service']]],
            'regularHours' => ['periods' => array_map(fn($d) => ['openDay' => $d, 'openTime' => ['hours' => 8], 'closeTime' => ['hours' => 17]], ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'])],
        ]);
        $rows = MiaListingJobs::compareRows($this->master(), $g, true);
        $this->assertSame(array_fill(0, 6, 'ok'), array_column($rows, 'state'));
        $this->assertSame('Mon–Fri 08:00–17:00', $g['hours']);
    }

    public function test_blank_is_not_a_difference(): void
    {
        $rows = MiaListingJobs::compareRows($this->master(), ['name' => '', 'address' => '', 'phone' => '', 'website' => ''], false);
        $this->assertFalse(MiaListingJobs::anyDiffers($rows));
    }
}
