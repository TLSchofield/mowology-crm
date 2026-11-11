<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Mia's decisions on the Social page as one queue: reconnect, failed, approval, drafts. */
class MiaJobAdapterTest extends TestCase
{
    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-10 09:00:00'); }

    private function state(bool $broken): array
    {
        return ['needs_reconnect' => $broken, 'message' => $broken ? 'Reconnect Facebook — Facebook: the saved login is gone.' : '', 'action_url' => SocialConnectionState::RECONNECT_URL];
    }

    private function failed(int $id, string $detail): array
    {
        return ['id' => $id, 'caption' => 'Crew on site', 'platforms' => 'facebook,instagram', 'detail' => $detail, 'thumb_path' => 'uploads/a.jpg'];
    }

    public function test_the_causes_are_read_from_the_platform_text(): void
    {
        $this->assertSame('ratio', MiaJobAdapter::classify('Meta API error [Instagram media container creation] (code 36003): The aspect ratio is not supported.'));
        $this->assertSame('login', MiaJobAdapter::classify('Meta account has no page token. Please reconnect the account at Social Accounts settings.'));
        $this->assertSame('login', MiaJobAdapter::classify('Meta API error [post] (code 190): Error validating access token'));
        $this->assertSame('other', MiaJobAdapter::classify('Something odd happened'));
    }

    public function test_the_triangle_follows_her_record(): void
    {
        $this->assertSame('forming', MiaJobAdapter::sure([0, 0]));
        $this->assertSame('held', MiaJobAdapter::sure([1, 1]));
        $this->assertSame('held', MiaJobAdapter::sure([6, 5]));
        $this->assertSame('known', MiaJobAdapter::sure([3, 3]));
        $this->assertSame('mastered', MiaJobAdapter::sure([5, 5]));
    }

    public function test_reconnect_comes_first_and_swallows_login_failures_and_approvals(): void
    {
        $waiting = [['id' => 9, 'caption' => 'Hello', 'platforms' => 'facebook', 'scheduled_at' => null, 'auto_generated' => 0, 'created_by_name' => 'Tim']];
        $q = MiaJobAdapter::queue($this->state(true), [$this->failed(1, 'no page token'), $this->failed(2, 'aspect ratio')], $waiting, [], [], true, $this->now());
        $this->assertSame(['reconnect', 'failed'], array_column($q, 'kind'));
        $this->assertSame('ratio', $q[1]['cause']);
        $this->assertSame(SocialConnectionState::RECONNECT_URL, $q[0]['primary']['href']);
        $this->assertSame([1, 2], array_column($q, 'index'));
        $this->assertSame(2, $q[0]['total']);
        $this->assertStringContainsString('2 posts did not go out', $q[0]['post']['caption']);
    }

    public function test_a_failed_post_gets_the_one_button_that_fixes_its_cause(): void
    {
        $q = MiaJobAdapter::queue($this->state(false), [$this->failed(1, '(code 36003) aspect ratio'), $this->failed(2, 'no page token'), $this->failed(3, 'odd')], [], [], [], true, $this->now());
        $this->assertSame('Fix the picture', $q[0]['primary']['label']);
        $this->assertSame('/crm/marketing/social-post-editor.php?id=1', $q[0]['primary']['href']);
        $this->assertSame('Reconnect', $q[1]['primary']['label']);
        $this->assertSame('Try again', $q[2]['primary']['label']);
        $this->assertSame(['url' => '/crm/api/social/posts.php?action=retry', 'body' => ['id' => 3]], $q[2]['primary']['request']);
        $this->assertSame('odd', $q[2]['detail']);
    }

    public function test_approve_says_when_it_goes_out_and_carries_her_record(): void
    {
        $waiting = [['id' => 9, 'caption' => 'Hello', 'platforms' => 'facebook,instagram', 'scheduled_at' => null, 'auto_generated' => 1, 'created_by_name' => 'Mia', 'thumb_path' => '']];
        $j = MiaJobAdapter::queue($this->state(false), [], $waiting, [], ['crew' => [5, 5], 'hand' => [0, 0]], true, $this->now())[0];
        $this->assertSame('approve', $j['kind']);
        $this->assertSame('mastered', $j['sure']);
        $this->assertSame('posts shortly', $j['primary']['sub']);
        $this->assertSame(['url' => '/crm/api/social/posts.php?action=approve', 'body' => ['id' => 9]], $j['primary']['request']);
        $this->assertSame('Approved 5 of 5 crew-photo drafts.', $j['confidence']);
        $this->assertSame('posts Wed Nov 11, 10:00 a.m.', MiaJobAdapter::goesOut('2026-11-11 10:00:00', $this->now()));
        $this->assertSame('posts shortly', MiaJobAdapter::goesOut('2026-11-01 10:00:00', $this->now()));
    }

    public function test_drafts_need_edit_permission_and_default_to_tomorrow_morning(): void
    {
        $drafts = [['id' => 4, 'caption' => 'Draft', 'platforms' => 'facebook', 'auto_generated' => 0, 'thumb_path' => 'x.jpg']];
        $this->assertSame([], MiaJobAdapter::queue($this->state(false), [], [], $drafts, [], false, $this->now()));
        $j = MiaJobAdapter::queue($this->state(false), [], [], $drafts, [], true, $this->now())[0];
        $this->assertSame('schedule', $j['kind']);
        $this->assertSame('2026-11-11T10:00', $j['right']['scheduled_at']);
        $this->assertSame(['scheduled_at'], $j['fields']);
        $this->assertSame('forming', $j['sure']);
    }

    public function test_nothing_waiting_is_an_empty_queue(): void
    {
        $this->assertSame([], MiaJobAdapter::queue($this->state(false), [], [], [], [], true, $this->now()));
    }
}
