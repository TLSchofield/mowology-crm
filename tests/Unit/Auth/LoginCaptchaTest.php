<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/Auth/LoginCaptcha.php';

final class LoginCaptchaTest extends TestCase
{
    public function test_watch_mode_never_refuses(): void
    {
        $this->assertTrue(LoginCaptcha::allows('watch', null), 'no token');
        $this->assertTrue(LoginCaptcha::allows('watch', ['passed' => false, 'score' => 0.1, 'error' => null]), 'low score');
    }

    public function test_enforce_refuses_missing_token_and_low_score(): void
    {
        $this->assertFalse(LoginCaptcha::allows('enforce', null));
        $this->assertFalse(LoginCaptcha::allows('enforce', ['passed' => false, 'score' => 0.2, 'error' => null]));
        $this->assertFalse(LoginCaptcha::allows('enforce', ['passed' => false, 'score' => null, 'error' => 'action_mismatch']));
        $this->assertTrue(LoginCaptcha::allows('enforce', ['passed' => true, 'score' => 0.9, 'error' => null]));
    }

    public function test_enforce_lets_people_in_when_keys_are_missing(): void
    {
        $this->assertTrue(LoginCaptcha::allows('enforce', ['passed' => false, 'error' => 'v3_not_configured']));
    }

    public function test_mode_defaults_to_watch(): void
    {
        $db = new PDO('sqlite::memory:');
        $this->assertSame('watch', LoginCaptcha::mode($db), 'no table');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)');
        $this->assertSame('watch', LoginCaptcha::mode($db));
        $db->exec("INSERT INTO ops_settings VALUES ('login_captcha_mode', 'enforce')");
        $this->assertSame('enforce', LoginCaptcha::mode($db));
    }

    public function test_the_form_hook_steps_aside_for_a_cancelled_submit(): void
    {
        if (!defined('RECAPTCHA_V3_SITE_KEY')) define('RECAPTCHA_V3_SITE_KEY', '6Ltestkey');
        if (!defined('RECAPTCHA_V3_SECRET_KEY')) define('RECAPTCHA_V3_SECRET_KEY', 'secret');
        $html = LoginCaptcha::formTags('signinForm');
        if ($html === '') $this->markTestSkipped('reCAPTCHA helper not loadable here');
        $this->assertStringContainsString('form="signinForm"', $html);
        $this->assertStringContainsString('e.defaultPrevented', $html);
        $this->assertStringContainsString('"login"', $html);
    }
}
