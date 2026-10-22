<?php
declare(strict_types=1);

use App\Core\TenantIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Test tenant (2026-10-21): the handful of identity values a second copy of the app can change.
 * With no constants defined, every fallback must equal today's hard-coded production value.
 */
final class TenantIdentityTest extends TestCase
{
    public function test_fallbacks_equal_todays_production_values(): void
    {
        foreach (array_keys(TenantIdentity::DEFAULTS) as $name) {
            $this->assertFalse(defined($name), "{$name} must not be defined in the unit-test process");
        }
        $this->assertSame('no-reply@mowology.ca', TenantIdentity::mailFrom());
        $this->assertSame('Mowology', TenantIdentity::mailFromName());
        $this->assertSame('office@mowology.ca', TenantIdentity::officeEmail());
        $this->assertSame('mowology@icloud.com', TenantIdentity::notifyEmail());
        $this->assertSame('7788469273@msg.telus.com', TenantIdentity::notifySms());
        $this->assertSame('mowology_cron_', TenantIdentity::cronLockPrefix());
        $this->assertSame('/home/mowology/tmp', TenantIdentity::sessionSavePath());
    }

    public function test_a_defined_value_wins_and_an_empty_one_falls_back(): void
    {
        $this->assertSame('no-reply@test.example', TenantIdentity::resolve('TENANT_MAIL_FROM', 'no-reply@test.example'));
        $this->assertSame('no-reply@test.example', TenantIdentity::resolve('TENANT_MAIL_FROM', '  no-reply@test.example '));
        $this->assertSame('no-reply@mowology.ca', TenantIdentity::resolve('TENANT_MAIL_FROM', ''));
        $this->assertSame('no-reply@mowology.ca', TenantIdentity::resolve('TENANT_MAIL_FROM', null));
        $this->assertSame('no-reply@mowology.ca', TenantIdentity::resolve('TENANT_MAIL_FROM', false));
        $this->assertSame('no-reply@mowology.ca', TenantIdentity::resolve('TENANT_MAIL_FROM', ['array']));
    }

    public function test_values_cannot_inject_a_mail_header(): void
    {
        $this->assertSame('a@test.example Bcc: x@y', TenantIdentity::resolve('TENANT_MAIL_FROM', "a@test.example\r\nBcc: x@y"));
        $this->assertSame('Ittai Test', TenantIdentity::resolve('TENANT_MAIL_FROM_NAME', "Ittai\nTest"));
    }

    public function test_cron_lock_prefix_is_a_safe_file_name(): void
    {
        $this->assertSame('tenant_a_cron_', TenantIdentity::resolve('CRON_LOCK_PREFIX', 'tenant-a cron/'));
        $this->assertSame('mowology_cron_', TenantIdentity::resolve('CRON_LOCK_PREFIX', ''));
    }

    public function test_unknown_setting_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantIdentity::resolve('TENANT_PHONE', '604');
    }

    public function test_session_path_reads_only_the_uniquely_named_tenant_variable(): void
    {
        $this->assertFalse(defined('TENANT_SESSION_SAVE_PATH'));
        putenv('SESSION_SAVE_PATH=/tmp/generic-must-be-ignored');
        putenv('TENANT_MODE=test');
        try {
            $this->assertSame('/home/mowology/tmp', TenantIdentity::sessionSavePath(), 'the generic name and the mode change nothing');
            putenv('TENANT_SESSION_SAVE_PATH=/tmp/tenant-sessions');
            $this->assertSame('/tmp/tenant-sessions', TenantIdentity::sessionSavePath(), 'the tenant variable is honoured on its own, no mode involved');
        } finally {
            putenv('TENANT_SESSION_SAVE_PATH');
            putenv('SESSION_SAVE_PATH');
            putenv('TENANT_MODE');
        }
        $this->assertSame('/home/mowology/tmp', TenantIdentity::sessionSavePath());
    }

    public function test_the_wired_files_read_the_identity_not_a_literal(): void
    {
        $root = dirname(__DIR__, 3);
        $ms = (string)file_get_contents($root . '/app/Services/Messaging/MessagingService.php');
        // Every From / Reply-To / Return-Path header line in MessagingService goes through TenantIdentity.
        preg_match_all('/^\s*(?:\$headers\s*\.?=\s*"(?:From|Reply-To|Return-Path):|\$mail->(?:setFrom|addReplyTo)\()[^\n]*$/m', $ms, $m);
        $this->assertGreaterThanOrEqual(10, count($m[0]));
        foreach ($m[0] as $line) {
            $this->assertStringNotContainsString('mowology.ca', $line, 'hard-coded sender address in: ' . trim($line));
            $this->assertSame(1, preg_match('/TenantIdentity::|\{\$fromEmail\}/', $line), 'sender line not wired: ' . trim($line));
        }
        // The two header lines that interpolate {$fromEmail} get it from TenantIdentity, nowhere else.
        $this->assertSame(2, substr_count($ms, '$fromEmail = TenantIdentity::mailFrom();'));
        $this->assertSame(0, preg_match('/\$fromEmail\s*=\s*[\'"]/', $ms), '$fromEmail assigned a literal');
        foreach (['app/Services/Notifications.php', 'public/includes/notifications.php'] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $this->assertStringContainsString("define('NOTIFICATION_EMAIL', \\App\\Core\\TenantIdentity::notifyEmail())", $src, $f);
            $this->assertStringContainsString("define('NOTIFICATION_SMS_GATEWAY', \\App\\Core\\TenantIdentity::notifySms())", $src, $f);
        }
        $this->assertStringContainsString('TenantIdentity::cronLockPrefix()', (string)file_get_contents($root . '/app/Core/CronLock.php'));
        $this->assertStringContainsString('TenantIdentity::cronLockPrefix()', (string)file_get_contents($root . '/app/Modules/Expenses/Cron/process_ocr_queue.php'));
        foreach (['app/Core/session_config.php', 'public/includes/bootstrap.php'] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $this->assertStringContainsString("'/home/mowology/tmp'", $src, $f . ' must keep the production default');
            // Only the uniquely named tenant constant / env var; nothing mode-related, nothing generic.
            $this->assertStringContainsString("defined('TENANT_SESSION_SAVE_PATH')", $src, $f);
            $this->assertStringContainsString("getenv('TENANT_SESSION_SAVE_PATH')", $src, $f);
            $this->assertSame(0, preg_match("/[^_]SESSION_SAVE_PATH/", $src), $f . ' must not consult the generic SESSION_SAVE_PATH');
            $this->assertSame(0, preg_match("/getenv\(\s*['\"](?!TENANT_SESSION_SAVE_PATH['\"])/", $src), $f . ' may read no other environment variable');
            $this->assertStringNotContainsString('TENANT_MODE', $src, $f . ' must not consult the mode');
        }
    }
}
