<?php
declare(strict_types=1);

use App\Core\OutboundGuard;
use PHPUnit\Framework\TestCase;

/**
 * Test tenant (2026-10-21): OutboundGuard is the one gate every email / SMS / push / cron /
 * Stripe / webhook exit passes through. Live mode must be a byte-identical pass-through; test
 * mode must make it impossible to reach a real client, charge a real card or touch Mowology,
 * and must FAIL CLOSED whenever it cannot be sure (unknown database, unwritable log).
 */
final class OutboundGuardTest extends TestCase
{
    private string $logDir;
    /** @var string|false */
    private $prevErrorLog = false;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/mw-outbound-guard-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->prevErrorLog = ini_set('error_log', '/dev/null'); // the guard also error_log()s each decision
        OutboundGuard::reset();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : $this->prevErrorLog);
        OutboundGuard::reset();
        @chmod($this->logDir, 0750);
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logDir);
    }

    private function live(): void
    {
        OutboundGuard::configure(['SANDBOX_LOG_DIR' => $this->logDir]);
    }

    private function test(array $extra = []): void
    {
        OutboundGuard::configure(array_merge([
            'TENANT_MODE'              => 'test',
            'SANDBOX_MAIL_SINK'        => 'sink@example.test',
            'SANDBOX_ALLOW_RECIPIENTS' => ['owner@example.test', '6045550100@msg.telus.com'],
            'SANDBOX_ALLOW_CRONS'      => ['forgot_clockout_sms'],
            'SANDBOX_LOG_DIR'          => $this->logDir,
            'DB_NAME'                  => 'tenant_test_crm',
            'HTTP_HOST'                => 'test.ittai.ca',
        ], $extra));
    }

    /** @return array<int, array> */
    private function logRows(): array
    {
        $rows = [];
        foreach (glob($this->logDir . '/outbound-*.jsonl') ?: [] as $f) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rows[] = json_decode($line, true);
            }
        }
        return $rows;
    }

    private function assertEverythingBlocked(string $expectedReasonPart): void
    {
        $this->assertNotNull(OutboundGuard::blocked());
        $this->assertFalse(OutboundGuard::isAllowedRecipient('owner@example.test'));
        $this->assertFalse(OutboundGuard::isAllowedRecipient('sink@example.test'));
        $out = OutboundGuard::email('owner@example.test', 'Hello', 'Body');
        $this->assertFalse($out['send']);
        $this->assertSame('', $out['to']);
        $this->assertStringContainsString($expectedReasonPart, (string)$out['reason']);
        $this->assertFalse(OutboundGuard::sms('6045550100@msg.telus.com', 'allowed number, still blocked'));
        $this->assertFalse(OutboundGuard::push('ios', 'tok', 'T'));
        $this->assertFalse(OutboundGuard::cronMayRun('forgot_clockout_sms', 'sender'));
        $this->assertNotNull(OutboundGuard::webhookRefusal(true, false));
        try {
            OutboundGuard::stripeKey('sk_test_abc');
            $this->fail('even a test key is refused when blocked');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedReasonPart, $e->getMessage());
        }
    }

    // ── Live mode: exact pass-through ──────────────────────────────────────────

    public function test_undefined_tenant_mode_is_live(): void
    {
        // No override and no constant defined in the test process → live.
        $this->assertFalse(defined('TENANT_MODE'));
        $this->assertFalse(getenv('TENANT_MODE'));
        $this->assertSame('live', OutboundGuard::mode());
        $this->assertTrue(OutboundGuard::isLive());
        $this->assertNull(OutboundGuard::tripped());
        $this->assertNull(OutboundGuard::blocked());
    }

    public function test_live_email_comes_out_unchanged(): void
    {
        $this->live();
        $in = ['client@example.com', 'Your invoice INV-2026-0001', "<p>Hi</p>\r\n<p>Body</p>", ['pm@example.com', 'acct@example.com']];
        $out = OutboundGuard::email(...$in);
        $this->assertSame([
            'send' => true, 'to' => $in[0], 'cc' => $in[3], 'subject' => $in[1], 'body' => $in[2], 'redirected' => false, 'reason' => null,
        ], $out);
    }

    public function test_live_sms_push_cron_stripe_and_webhook_pass_through(): void
    {
        $this->live();
        $this->assertTrue(OutboundGuard::sms('6045550199@txt.bellmobility.ca', 'Your crew is on the way'));
        $this->assertTrue(OutboundGuard::push('ios', str_repeat('ab', 32), 'Quote approved'));
        $this->assertTrue(OutboundGuard::cronMayRun('autopay_charge', 'charge'));
        $this->assertTrue(OutboundGuard::cronMayRun('campaign_sender', 'campaign'));
        $this->assertSame('sk_live_abc123', OutboundGuard::stripeKey('sk_live_abc123'));
        $this->assertSame('sk_test_abc123', OutboundGuard::stripeKey('sk_test_abc123'));
        $this->assertTrue(OutboundGuard::isAllowedRecipient('anyone@example.com'));
        $this->assertNull(OutboundGuard::webhookRefusal(false, true));
        $this->assertNull(OutboundGuard::logNotReady());
    }

    public function test_live_mode_writes_no_log_even_with_an_unwritable_dir_or_bad_config(): void
    {
        OutboundGuard::configure(['SANDBOX_LOG_DIR' => '/nonexistent/' . $this->logDir, 'SANDBOX_ALLOW_RECIPIENTS' => 'a-string']);
        OutboundGuard::email('client@example.com', 'S', 'B');
        OutboundGuard::sms('6045550199@txt.bellmobility.ca', 'M');
        OutboundGuard::push('android', 'tok', 'T');
        OutboundGuard::cronMayRun('campaign_sender', 'campaign');
        OutboundGuard::stripeKey('sk_live_x');
        OutboundGuard::webhookRefusal(false, true);
        $this->assertDirectoryDoesNotExist($this->logDir);
        $this->assertSame([], OutboundGuard::summary()['recent']);
    }

    public function test_any_mode_other_than_live_is_test(): void
    {
        OutboundGuard::configure(['TENANT_MODE' => 'tset', 'SANDBOX_LOG_DIR' => $this->logDir]);
        $this->assertSame('test', OutboundGuard::mode());
        OutboundGuard::configure(['TENANT_MODE' => 'live', 'SANDBOX_LOG_DIR' => $this->logDir]);
        $this->assertSame('live', OutboundGuard::mode());
    }

    public function test_the_environment_variable_sets_test_mode_when_the_constant_is_missing(): void
    {
        putenv('TENANT_MODE=test');
        try {
            OutboundGuard::reset();
            $this->assertSame('test', OutboundGuard::mode());
            // and with no DB_NAME known it fails closed
            $this->assertNotNull(OutboundGuard::blocked());
        } finally {
            putenv('TENANT_MODE');
            OutboundGuard::reset();
        }
        $this->assertSame('live', OutboundGuard::mode());
    }

    // ── Test mode: email ───────────────────────────────────────────────────────

    public function test_email_is_redirected_to_the_sink_with_marked_subject_and_banner(): void
    {
        $this->test();
        $out = OutboundGuard::email('client@example.com', 'Your invoice', '<p>Body</p>', ['pm@example.com']);
        $this->assertTrue($out['send']);
        $this->assertTrue($out['redirected']);
        $this->assertSame('sink@example.test', $out['to']);
        $this->assertSame([], $out['cc'], 'the original Cc list must never be kept');
        $this->assertSame('[TEST -> client@example.com, pm@example.com] Your invoice', $out['subject']);
        $this->assertStringStartsWith('<div', $out['body']);
        $this->assertStringContainsString('addressed to client@example.com', $out['body']);
        $this->assertStringContainsString('cc: pm@example.com', $out['body']);
        $this->assertStringContainsString('NOT delivered', $out['body']);
        $this->assertStringEndsWith('<p>Body</p>', $out['body']);

        $rows = $this->logRows();
        $this->assertCount(1, $rows);
        $this->assertSame('email', $rows[0]['kind']);
        $this->assertSame('redirected', $rows[0]['action']);
        $this->assertSame('client@example.com, pm@example.com', $rows[0]['target']);
        $this->assertSame('sink@example.test', $rows[0]['sink']);
    }

    public function test_redirected_email_passes_a_second_guard_unchanged(): void
    {
        // A PHPMailer failure falls back to mail(), which runs the guard again: the sink is an
        // allowed recipient, so the already-marked message is not marked twice.
        $this->test();
        $first = OutboundGuard::email('client@example.com', 'Your invoice', '<p>Body</p>');
        $second = OutboundGuard::email($first['to'], $first['subject'], $first['body'], $first['cc']);
        $this->assertFalse($second['redirected']);
        $this->assertSame($first['to'], $second['to']);
        $this->assertSame($first['subject'], $second['subject']);
        $this->assertSame($first['body'], $second['body']);
        $this->assertSame('[TEST -> x@y] S', OutboundGuard::testSubject('[TEST -> x@y] S', ['z@w']));
    }

    public function test_email_with_no_sink_is_not_sent_but_logged(): void
    {
        $this->test(['SANDBOX_MAIL_SINK' => null]);
        $out = OutboundGuard::email('client@example.com', 'Your invoice', '<p>Body</p>');
        $this->assertFalse($out['send']);
        $this->assertSame('', $out['to']);
        $this->assertSame([], $out['cc']);
        $rows = $this->logRows();
        $this->assertCount(1, $rows);
        $this->assertSame(['email', 'blocked', 'no SANDBOX_MAIL_SINK'], [$rows[0]['kind'], $rows[0]['action'], $rows[0]['reason']]);
    }

    public function test_allow_listed_recipients_pass_unchanged(): void
    {
        $this->test();
        $out = OutboundGuard::email('Owner@Example.test', 'Hello', '<p>Body</p>');
        $this->assertSame(['send' => true, 'to' => 'Owner@Example.test', 'cc' => [], 'subject' => 'Hello', 'body' => '<p>Body</p>', 'redirected' => false, 'reason' => null], $out);
        $this->assertSame([], $this->logRows(), 'a pass-through is not logged');

        // One non-allowed Cc is enough to redirect the whole message.
        $out = OutboundGuard::email('owner@example.test', 'Hello', '<p>Body</p>', ['client@example.com']);
        $this->assertTrue($out['redirected']);
        $this->assertSame('sink@example.test', $out['to']);
        $this->assertSame([], $out['cc']);
    }

    public function test_list_settings_must_be_arrays(): void
    {
        $this->test(['SANDBOX_ALLOW_RECIPIENTS' => 'owner@example.test', 'SANDBOX_ALLOW_CRONS' => 'forgot_clockout_sms']);
        $this->assertFalse(OutboundGuard::isAllowedRecipient('owner@example.test'), 'a string allow-list allows nobody');
        $this->assertFalse(OutboundGuard::isAllowedRecipient('owner@example.test'));
        $this->assertFalse(OutboundGuard::cronMayRun('forgot_clockout_sms'));
        $rows = array_values(array_filter($this->logRows(), static fn($r) => $r['kind'] === 'config'));
        $this->assertCount(2, $rows, 'each mis-typed list is reported once');
        $this->assertSame(['SANDBOX_ALLOW_RECIPIENTS', 'SANDBOX_ALLOW_CRONS'], array_column($rows, 'target'));
        $this->assertSame('error', $rows[0]['action']);
        $this->assertStringContainsString('must be an array, got string', $rows[0]['reason']);

        $this->test(['SANDBOX_ALLOW_RECIPIENTS' => 42]);
        $this->assertFalse(OutboundGuard::isAllowedRecipient('owner@example.test'));
        $this->test(['SANDBOX_ALLOW_RECIPIENTS' => ['owner@example.test', ['nested']]]);
        $this->assertTrue(OutboundGuard::isAllowedRecipient('owner@example.test'), 'non-scalar entries are skipped, the rest still count');
    }

    // ── Test mode: SMS ─────────────────────────────────────────────────────────

    public function test_sms_is_blocked_unless_the_gateway_is_allow_listed(): void
    {
        $this->test();
        $this->assertFalse(OutboundGuard::sms('6045550199@txt.bellmobility.ca', 'Your crew is on the way'));
        $this->assertTrue(OutboundGuard::sms('6045550100@msg.telus.com', 'Your crew is on the way'));
        $this->assertFalse(OutboundGuard::sms('6045550100@txt.bellmobility.ca', 'same number, other carrier'));
        $rows = $this->logRows();
        $this->assertCount(2, $rows);
        $this->assertSame('sms', $rows[0]['kind']);
        $this->assertSame('blocked', $rows[0]['action']);
        $this->assertSame('6045550199@txt.bellmobility.ca', $rows[0]['target']);
        $this->assertStringContainsString('would have texted', $rows[0]['reason']);
        $this->assertSame('Your crew is on the way', $rows[0]['preview']);
    }

    // ── Test mode: push, cron, webhook ─────────────────────────────────────────

    public function test_push_is_always_blocked_and_logged(): void
    {
        $this->test();
        $this->assertFalse(OutboundGuard::push('ios', 'abcdef0123456789abcdef', 'Quote approved'));
        $this->assertFalse(OutboundGuard::push('android', 'tok', 'Quote approved'));
        $rows = $this->logRows();
        $this->assertCount(2, $rows);
        $this->assertSame('push', $rows[0]['kind']);
        $this->assertSame('ios:abcdef012345…', $rows[0]['target']);
        $this->assertStringNotContainsString('abcdef0123456789abcdef', json_encode($rows), 'the full device token is never logged');
    }

    public function test_crons_refuse_to_run_unless_allow_listed(): void
    {
        $this->test();
        $this->assertFalse(OutboundGuard::cronMayRun('autopay_charge', 'charge'));
        $this->assertFalse(OutboundGuard::cronMayRun('campaign_sender', 'campaign'));
        $this->assertTrue(OutboundGuard::cronMayRun('forgot_clockout_sms', 'sender'));
        $rows = $this->logRows();
        $this->assertCount(2, $rows);
        $this->assertSame(['cron', 'autopay_charge', 'refused', 'charge', 'not in SANDBOX_ALLOW_CRONS'],
            [$rows[0]['kind'], $rows[0]['target'], $rows[0]['action'], $rows[0]['cron_kind'], $rows[0]['reason']]);
        $this->assertSame('campaign', $rows[1]['cron_kind']);
    }

    public function test_webhook_processes_only_test_secret_and_test_mode_events(): void
    {
        $this->test();
        $this->assertNull(OutboundGuard::webhookRefusal(true, null), 'before verification: a declared test secret may proceed to verify');
        $this->assertNull(OutboundGuard::webhookRefusal(true, false), 'a verified test-mode event is processed');
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET_IS_TEST', (string)OutboundGuard::webhookRefusal(false, null));
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET_IS_TEST', (string)OutboundGuard::webhookRefusal(false, false));
        $this->assertStringContainsString('live-mode', (string)OutboundGuard::webhookRefusal(true, true, 'payment_intent.succeeded evt_1'));
        $rows = $this->logRows();
        $this->assertCount(3, $rows);
        $this->assertSame(['webhook', 'ignored'], [$rows[2]['kind'], $rows[2]['action']]);
        $this->assertSame('payment_intent.succeeded evt_1', $rows[2]['target']);
    }

    // ── Test mode: Stripe ──────────────────────────────────────────────────────

    public function test_stripe_refuses_anything_but_a_test_key(): void
    {
        $this->test();
        $this->assertSame('sk_test_abc', OutboundGuard::stripeKey('sk_test_abc'));
        $this->assertSame('rk_test_abc', OutboundGuard::stripeKey('rk_test_abc'));
        try {
            OutboundGuard::stripeKey('sk_live_abc123456');
            $this->fail('a live key must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sk_test_', $e->getMessage());
            $this->assertStringNotContainsString('abc123456', $e->getMessage(), 'the key is never echoed');
        }
        $this->expectException(RuntimeException::class);
        OutboundGuard::stripeKey('');
    }

    // ── Fail closed ────────────────────────────────────────────────────────────

    public function test_unknown_database_name_blocks_everything(): void
    {
        $this->test(['DB_NAME' => null]);
        $this->assertFalse(defined('DB_NAME'));
        $this->assertEverythingBlocked('DB_NAME');
        $this->assertSame('tripwire', $this->logRows()[0]['kind']);

        $this->test(['DB_NAME' => '   ']);
        $this->assertNotNull(OutboundGuard::tripped());
    }

    public function test_production_database_name_blocks_everything(): void
    {
        $this->test(['DB_NAME' => 'mowology_landscape_crm']);
        $this->assertEverythingBlocked('production database');
        $kinds = array_column($this->logRows(), 'kind');
        $this->assertSame('tripwire', $kinds[0]);
        $this->assertSame(1, count(array_keys($kinds, 'tripwire', true)), 'the tripwire is reported once per process');
        $this->assertContains('email', $kinds);
        $this->assertContains('stripe', $kinds);

        $this->test(['DB_NAME' => 'Mowology_Landscape_CRM ']);
        $this->assertNotNull(OutboundGuard::tripped(), 'case and whitespace do not hide the production name');
    }

    public function test_another_database_name_is_allowed(): void
    {
        $this->test(['DB_NAME' => 'mowology_test_crm']);
        $this->assertNull(OutboundGuard::tripped());
        $this->assertNull(OutboundGuard::blocked());
        $this->assertTrue(OutboundGuard::email('owner@example.test', 'S', 'B')['send']);
    }

    public function test_tripwire_on_the_production_host(): void
    {
        foreach (['mowology.ca', 'www.mowology.ca', 'WWW.MOWOLOGY.CA:443', 'mowology.ca.', 'MOWOLOGY.CA.:443', 'www.mowology.ca.', ' Mowology.CA:8443 '] as $host) {
            $this->test(['HTTP_HOST' => $host]);
            $this->assertNotNull(OutboundGuard::tripped(), $host);
            $this->assertFalse(OutboundGuard::email('owner@example.test', 'S', 'B')['send'], $host);
        }
        foreach (['test.mowology.ca', 'mowology.ca.example.com', '[::1]:8080', ''] as $host) {
            $this->test(['HTTP_HOST' => $host]);
            $this->assertNull(OutboundGuard::tripped(), "'{$host}' is not the production host");
        }
    }

    public function test_host_normalisation(): void
    {
        $this->assertSame('mowology.ca', OutboundGuard::normaliseHost('MOWOLOGY.CA.:443'));
        $this->assertSame('mowology.ca', OutboundGuard::normaliseHost('mowology.ca..'));
        $this->assertSame('::1', OutboundGuard::normaliseHost('[::1]:8080'));
        $this->assertSame('::1', OutboundGuard::normaliseHost('[::1]'));
        $this->assertSame('2001:db8::1', OutboundGuard::normaliseHost('[2001:DB8::1]:443'));
        $this->assertSame('', OutboundGuard::normaliseHost('  '));
    }

    public function test_live_mode_never_trips(): void
    {
        OutboundGuard::configure(['DB_NAME' => 'mowology_landscape_crm', 'HTTP_HOST' => 'mowology.ca', 'SANDBOX_LOG_DIR' => $this->logDir]);
        $this->assertNull(OutboundGuard::tripped());
        $this->assertTrue(OutboundGuard::email('client@example.com', 'S', 'B')['send']);
    }

    public function test_an_unwritable_log_directory_blocks_everything(): void
    {
        // The "directory" is a regular file: it can neither be used nor created.
        $parent = $this->logDir;
        mkdir($parent, 0750, true);
        file_put_contents($parent . '/not-a-dir', 'x');
        $this->test(['SANDBOX_LOG_DIR' => $parent . '/not-a-dir']);
        $why = OutboundGuard::logNotReady();
        $this->assertNotNull($why);
        $this->assertStringContainsString('sandbox log directory', $why);
        $this->assertEverythingBlocked('sandbox log');
        $this->assertSame($why, OutboundGuard::summary()['tripped']);

        // A directory that exists but cannot be written (skipped when running as root).
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return;
        }
        $ro = $parent . '/ro';
        mkdir($ro, 0500);
        $this->test(['SANDBOX_LOG_DIR' => $ro]);
        $this->assertNotNull(OutboundGuard::logNotReady());
        $this->assertFalse(OutboundGuard::email('owner@example.test', 'S', 'B')['send']);
        chmod($ro, 0750);
        rmdir($ro);
    }

    public function test_a_writable_log_directory_is_ready_and_checked_once(): void
    {
        $this->test();
        $this->assertNull(OutboundGuard::logNotReady());
        $this->assertDirectoryExists($this->logDir);
        $this->assertFileExists(OutboundGuard::logPath());
        $this->assertSame([], $this->logRows(), 'the readiness probe writes no entry');
    }

    // ── Log hygiene ────────────────────────────────────────────────────────────

    public function test_log_fields_are_control_stripped_and_capped(): void
    {
        $this->test();
        $huge = str_repeat('x', 5000) . '@example.com';
        OutboundGuard::email("victim@example.com\r\n{\"kind\":\"forged\"}", "Subject\nwith\x01newline <b>html</b>", '<p>Body</p>', [$huge]);
        OutboundGuard::sms("gw@x\n", str_repeat('<i>m</i> ', 500));
        OutboundGuard::cronMayRun("evil\ncron" . str_repeat('z', 400), "kind\n" . str_repeat('k', 100));
        $rows = $this->logRows();
        $this->assertCount(3, $rows, 'exactly one JSON line per decision — no injected lines');
        foreach ($rows as $row) {
            foreach ($row as $k => $v) {
                $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $k), "control character in key {$k}");
                $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', (string)$v), "control character in {$k}");
                $this->assertLessThanOrEqual(OutboundGuard::FIELD_CHARS, mb_strlen((string)$v), "{$k} not capped");
            }
            $this->assertLessThanOrEqual(OutboundGuard::LABEL_CHARS, strlen($row['kind']));
            $this->assertLessThanOrEqual(OutboundGuard::LABEL_CHARS, strlen($row['action']));
            $this->assertLessThanOrEqual(OutboundGuard::PREVIEW_CHARS, mb_strlen($row['preview']));
        }
        $this->assertSame('email', $rows[0]['kind'], 'a newline in the target cannot forge a second record');
        $this->assertStringStartsWith('victim@example.com {"kind":"forged"}, xxx', $rows[0]['target']);
        $this->assertStringNotContainsString('<b>', $rows[0]['preview']);
        $this->assertSame('Subject with newline html', $rows[0]['preview']);
        $this->assertSame('cron', $rows[2]['kind']);
        $this->assertLessThanOrEqual(OutboundGuard::FIELD_CHARS, strlen($rows[2]['cron_kind']));
        $this->assertSame('kind ' . str_repeat('k', 100), $rows[2]['cron_kind']);
        $this->assertSame('a b c', OutboundGuard::cleanField("a\r\nb\tc\x7F", 10));
        $this->assertSame('abc', OutboundGuard::cleanField('abcdef', 3));
    }

    public function test_log_preview_has_no_html_and_no_body_or_token(): void
    {
        $this->test();
        OutboundGuard::email('client@example.com', str_repeat('<b>long subject</b> ', 40), '<p>Body</p>');
        $row = $this->logRows()[0];
        $this->assertLessThanOrEqual(OutboundGuard::PREVIEW_CHARS, mb_strlen($row['preview']));
        $this->assertStringNotContainsString('<b>', $row['preview']);
        $this->assertArrayNotHasKey('body', $row);
        $this->assertArrayHasKey('time', $row);
    }

    // ── Summary ────────────────────────────────────────────────────────────────

    public function test_summary_counts_and_lists_recent_entries(): void
    {
        $this->test();
        OutboundGuard::email('a@example.com', 'One', 'B');
        OutboundGuard::sms('6045550199@txt.bellmobility.ca', 'Two');
        OutboundGuard::push('ios', 'tok', 'Three');
        $s = OutboundGuard::summary(2);
        $this->assertSame('test', $s['mode']);
        $this->assertNull($s['tripped']);
        $this->assertSame($this->logDir, $s['log_dir']);
        $this->assertSame(['email:redirected' => 1, 'sms:blocked' => 1, 'push:blocked' => 1], $s['counts']);
        $this->assertCount(2, $s['recent']);
    }

    public function test_default_log_dir_is_app_storage_sandbox(): void
    {
        OutboundGuard::configure(['TENANT_MODE' => 'test']);
        $expected = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3) . '/app') . '/Storage/sandbox';
        $this->assertSame($expected, OutboundGuard::logDir());
        $this->assertFileExists(dirname(__DIR__, 3) . '/app/Storage/sandbox/.gitignore');
    }
}
