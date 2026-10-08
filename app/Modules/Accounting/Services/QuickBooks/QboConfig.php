<?php
/**
 * QboConfig — the QuickBooks Online app credentials and endpoints (2026-10-07).
 *
 * Values come from secrets.php (never from this file):
 *   QBO_CLIENT_ID, QBO_CLIENT_SECRET   Intuit app keys — sandbox keys while QBO_ENV = 'sandbox',
 *                                      production keys once Intuit has unlocked them.
 *   QBO_ENV                            'sandbox' | 'production'
 *   QBO_REDIRECT_URI                   must match the app's registered redirect URI exactly
 *                                      (https only; e.g. https://mowology.ca/crm/api/quickbooks/oauth-callback.php)
 *
 * Endpoints are the ones Intuit publishes in its OpenID discovery documents
 * (https://developer.api.intuit.com/.well-known/openid_configuration and the _sandbox_ twin —
 * fetched 2026-10-07; both name the same authorization, token and revocation endpoints).
 * The API base differs per environment. minorversion 75 is the only version Intuit serves
 * since 2025-08-01 (older values are ignored and answered as 75).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

class QboConfig
{
    public const SCOPE          = 'com.intuit.quickbooks.accounting';
    public const MINOR_VERSION  = '75';
    public const AUTH_URL       = 'https://appcenter.intuit.com/connect/oauth2';
    public const TOKEN_URL      = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    public const REVOKE_URL     = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
    public const API_SANDBOX    = 'https://sandbox-quickbooks.api.intuit.com';
    public const API_PRODUCTION = 'https://quickbooks.api.intuit.com';

    private string $clientId;
    private string $clientSecret;
    private string $env;
    private string $redirectUri;

    public function __construct(string $clientId, string $clientSecret, string $env, string $redirectUri)
    {
        $this->clientId     = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->env          = $env === 'production' ? 'production' : 'sandbox';
        $this->redirectUri  = trim($redirectUri);
    }

    /** Build from the secrets.php constants; missing constants give an unconfigured config, never a fatal. */
    public static function fromConstants(): self
    {
        return new self(
            defined('QBO_CLIENT_ID') ? (string)QBO_CLIENT_ID : '',
            defined('QBO_CLIENT_SECRET') ? (string)QBO_CLIENT_SECRET : '',
            defined('QBO_ENV') ? (string)QBO_ENV : 'sandbox',
            defined('QBO_REDIRECT_URI') ? (string)QBO_REDIRECT_URI : ''
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    /** What is missing, in Tim's words, for the settings page. */
    public function missing(): array
    {
        $m = [];
        if ($this->clientId === '')     $m[] = 'QBO_CLIENT_ID';
        if ($this->clientSecret === '') $m[] = 'QBO_CLIENT_SECRET';
        if ($this->redirectUri === '')  $m[] = 'QBO_REDIRECT_URI';
        return $m;
    }

    public function env(): string { return $this->env; }
    public function isProduction(): bool { return $this->env === 'production'; }
    public function clientId(): string { return $this->clientId; }
    public function redirectUri(): string { return $this->redirectUri; }

    public function apiBase(): string
    {
        return $this->isProduction() ? self::API_PRODUCTION : self::API_SANDBOX;
    }

    /** "Basic " + base64(client_id:client_secret) — the token and revoke endpoints want this. */
    public function basicAuthHeader(): string
    {
        return 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
    }
}
