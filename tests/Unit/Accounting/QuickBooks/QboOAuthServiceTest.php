<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The OAuth 2.0 dance with Intuit, replayed against canned responses.
 * Shapes follow developer.intuit.com "Set up OAuth 2.0" (read 2026-10-07).
 */
class QboOAuthServiceTest extends TestCase
{
    private function config(string $env = 'sandbox'): QboConfig
    {
        return new QboConfig('client-abc', 'secret-xyz', $env, 'https://mowology.ca/crm/api/quickbooks/oauth-callback.php');
    }

    private function tokenJson(): array
    {
        return ['token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'RT1', 'x_refresh_token_expires_in' => 8640000,
                'x_refresh_token_hard_expires_in' => 157680000, 'access_token' => 'AT1'];
    }

    public function test_authorize_url_carries_every_required_parameter_and_the_state(): void
    {
        $svc = new QboOAuthService($this->config(), new QboFakeTransport());
        $url = $svc->authorizeUrl('state-123');
        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('client-abc', $q['client_id']);
        $this->assertSame('com.intuit.quickbooks.accounting', $q['scope']);
        $this->assertSame('https://mowology.ca/crm/api/quickbooks/oauth-callback.php', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('state-123', $q['state']);
    }

    public function test_unconfigured_app_refuses_to_build_a_url_and_names_what_is_missing(): void
    {
        $svc = new QboOAuthService(new QboConfig('', '', 'sandbox', ''), new QboFakeTransport());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('QBO_CLIENT_ID');
        $svc->authorizeUrl('s');
    }

    public function test_code_exchange_posts_the_form_with_basic_auth_and_parses_the_tokens(): void
    {
        $t = (new QboFakeTransport())->push(200, $this->tokenJson());
        $svc = new QboOAuthService($this->config(), $t);
        $tokens = $svc->exchangeCode('CODE1');
        $req = $t->last();
        $this->assertSame('POST', $req['method']);
        $this->assertSame(QboConfig::TOKEN_URL, $req['url']);
        $this->assertContains('Authorization: Basic ' . base64_encode('client-abc:secret-xyz'), $req['headers']);
        $this->assertContains('x-include-refresh-token-hard-expires-in: true', $req['headers']);
        parse_str($req['body'], $form);
        $this->assertSame(['grant_type' => 'authorization_code', 'code' => 'CODE1', 'redirect_uri' => 'https://mowology.ca/crm/api/quickbooks/oauth-callback.php'], $form);
        $this->assertSame('AT1', $tokens['access_token']);
        $this->assertSame('RT1', $tokens['refresh_token']);
    }

    public function test_refresh_sends_the_latest_refresh_token_and_returns_the_rotated_one(): void
    {
        $json = $this->tokenJson();
        $json['refresh_token'] = 'RT2-rotated';
        $t = (new QboFakeTransport())->push(200, $json);
        $tokens = (new QboOAuthService($this->config(), $t))->refresh('RT1');
        parse_str($t->last()['body'], $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('RT1', $form['refresh_token']);
        $this->assertSame('RT2-rotated', $tokens['refresh_token']);
    }

    public function test_token_response_expiries_follow_intuit_lifetimes(): void
    {
        $now = 1_800_000_000;
        $p = QboOAuthService::parseTokenResponse($this->tokenJson(), $now);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now + 3600), $p['access_expires_at']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now + 100 * 86400), $p['refresh_expires_at']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now + 5 * 365 * 86400), $p['refresh_hard_expires_at']);
        $this->assertNull(QboOAuthService::parseTokenResponse(['access_token' => 'a', 'refresh_token' => 'r'], $now)['refresh_hard_expires_at']);
    }

    public function test_an_invalid_grant_is_reported_with_intuits_words(): void
    {
        $t = (new QboFakeTransport())->push(400, ['error' => 'invalid_grant', 'error_description' => 'Incorrect or invalid refresh token']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant Incorrect or invalid refresh token');
        (new QboOAuthService($this->config(), $t))->refresh('stale');
    }

    public function test_revoke_posts_the_refresh_token_as_json_and_reports_200(): void
    {
        $t = (new QboFakeTransport())->push(200, '');
        $ok = (new QboOAuthService($this->config(), $t))->revoke('RT1');
        $this->assertTrue($ok);
        $this->assertSame(QboConfig::REVOKE_URL, $t->last()['url']);
        $this->assertSame(['token' => 'RT1'], json_decode($t->last()['body'], true));
    }

    public function test_production_and_sandbox_differ_only_in_the_api_base(): void
    {
        $this->assertSame('https://sandbox-quickbooks.api.intuit.com', $this->config('sandbox')->apiBase());
        $this->assertSame('https://quickbooks.api.intuit.com', $this->config('production')->apiBase());
        $this->assertSame('sandbox', (new QboConfig('a', 'b', 'staging', 'c'))->env(), 'unknown env falls back to sandbox');
    }
}
