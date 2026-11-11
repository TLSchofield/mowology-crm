<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/AdminActionGuard.php';

/**
 * Fable review 2026-10-12 (LOW): admin "run now" endpoints ran on a plain GET. Now every web
 * request needs POST + CSRF; a GET only shows a confirm button.
 */
class AdminActionGuardTest extends TestCase
{
    private static function root(): string { return dirname(__DIR__, 3); }

    public function test_get_never_runs(): void
    {
        $ok = static fn(string $t): bool => $t === 'good';
        $this->assertSame('confirm', AdminActionGuard::decide('GET', 'good', $ok));
        $this->assertSame('confirm', AdminActionGuard::decide('HEAD', '', $ok));
        $this->assertSame('refuse', AdminActionGuard::decide('POST', '', $ok));
        $this->assertSame('refuse', AdminActionGuard::decide('POST', 'bad', $ok));
        $this->assertSame('run', AdminActionGuard::decide('POST', 'good', $ok));
    }

    public function test_token_comes_from_form_header_or_json(): void
    {
        $this->assertSame('a', AdminActionGuard::tokenFromRequest(['csrf_token' => 'a'], [], null));
        $this->assertSame('b', AdminActionGuard::tokenFromRequest([], ['HTTP_X_CSRF_TOKEN' => 'b'], null));
        $this->assertSame('c', AdminActionGuard::tokenFromRequest([], [], '{"csrf_token":"c"}'));
        $this->assertSame('', AdminActionGuard::tokenFromRequest(['csrf_token' => ['x']], [], 'not json'));
    }

    public function test_confirm_page_posts_back_with_the_token(): void
    {
        $html = AdminActionGuard::confirmPage('autopay_charge', 'tok"en');
        $this->assertStringContainsString('<form method="post">', $html);
        $this->assertStringContainsString('value="tok&quot;en"', $html);
    }

    public function test_every_cron_shim_goes_through_the_guard(): void
    {
        $guard = (string)file_get_contents(self::root() . '/public/crm/cron/_guard.php');
        $this->assertStringContainsString('adminActionGuard(', $guard);
        foreach (glob(self::root() . '/public/crm/cron/*.php') as $f) {
            if (basename($f) === '_guard.php') continue;
            $this->assertMatchesRegularExpression("#require_once __DIR__ \\. '/_guard\\.php'#", (string)file_get_contents($f), basename($f));
        }
    }

    public function test_admin_action_endpoints_need_post_and_csrf(): void
    {
        foreach (['public/crm/api/etransfer-poll-run.php', 'public/crm/api/etransfer-rematch.php',
                  'public/crm/run-backfill-snapshots.php', 'public/crm/api/run-backfill-snapshots.php',
                  'public/crm/api/backfill-gps-dwell.php', 'public/crm/api/backfill-margin-snapshots.php',
                  'public/crm/api/system-log-clear.php'] as $f) {
            $this->assertStringContainsString('adminActionGuard(', (string)file_get_contents(self::root() . '/' . $f), $f);
        }
        $m = (string)file_get_contents(self::root() . '/public/crm/api/backfill-margin-snapshots.php');
        $this->assertStringNotContainsString('getTraceAsString', $m);
        $this->assertStringContainsString("logActivity(", (string)file_get_contents(self::root() . '/public/crm/api/system-log-clear.php'));
    }

    public function test_run_now_buttons_send_the_token(): void
    {
        $db = (string)file_get_contents(self::root() . '/public/crm/database_appstack.php');
        $this->assertStringContainsString('window.MW_CSRF_TOKEN', $db);
        $this->assertStringContainsString("'X-CSRF-Token':  csrfToken", $db);
        $this->assertStringContainsString('MW_CSRF_TOKEN', (string)file_get_contents(self::root() . '/public/crm/system-log_appstack.php'));
        $this->assertStringContainsString('MW_CSRF_TOKEN', (string)file_get_contents(self::root() . '/public/crm/admin/ops_weather.php'));
    }
}
