<?php
declare(strict_types=1);

namespace App\Core;

/**
 * TenantIdentity — the few identity values a second copy of this app must be able to change.
 *
 * Each value is an optional constant in secrets.php. When the constant is undefined or empty
 * the fallback is exactly today's hard-coded value, so production is unchanged.
 *
 *   TENANT_MAIL_FROM        default From address      no-reply@mowology.ca
 *   TENANT_MAIL_FROM_NAME   default From display name  Mowology
 *   TENANT_OFFICE_EMAIL     Reply-To / Return-Path     office@mowology.ca
 *   TENANT_NOTIFY_EMAIL     owner notification inbox   mowology@icloud.com
 *   TENANT_NOTIFY_SMS       owner SMS gateway address  7788469273@msg.telus.com
 *   SESSION_SAVE_PATH       PHP session directory      /home/mowology/tmp
 *   CRON_LOCK_PREFIX        cron lock-file prefix      mowology_cron_
 *
 * No side effects at load. The wider "assumes Mowology" cleanup (AI prompts, templates, phone
 * numbers in copy) is deliberately NOT here — see docs/test-tenant.md.
 */
final class TenantIdentity
{
    public const DEFAULTS = [
        'TENANT_MAIL_FROM'      => 'no-reply@mowology.ca',
        'TENANT_MAIL_FROM_NAME' => 'Mowology',
        'TENANT_OFFICE_EMAIL'   => 'office@mowology.ca',
        'TENANT_NOTIFY_EMAIL'   => 'mowology@icloud.com',
        'TENANT_NOTIFY_SMS'     => '7788469273@msg.telus.com',
        'SESSION_SAVE_PATH'     => '/home/mowology/tmp',
        'CRON_LOCK_PREFIX'      => 'mowology_cron_',
    ];

    private function __construct()
    {
    }

    /** The value for a named setting: its constant when defined and non-empty, else the default. */
    public static function get(string $name): string
    {
        return self::resolve($name, defined($name) ? constant($name) : null);
    }

    /**
     * Pure: what get() returns for a given constant value (unit-testable without defining
     * constants). Values are trimmed and stripped of CR/LF so a secrets typo can never inject a
     * mail header.
     */
    public static function resolve(string $name, $definedValue): string
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException("TenantIdentity: unknown setting {$name}");
        }
        $value = is_scalar($definedValue) ? trim((string)$definedValue) : '';
        if ($value === '') {
            $value = self::DEFAULTS[$name];
        }
        $value = preg_replace('/[\r\n\x00-\x1F]+/', ' ', $value) ?? '';
        if ($name === 'CRON_LOCK_PREFIX') {
            $value = preg_replace('/[^a-z0-9_]/i', '_', $value) ?? self::DEFAULTS[$name];
        }
        return $value;
    }

    public static function mailFrom(): string
    {
        return self::get('TENANT_MAIL_FROM');
    }

    public static function mailFromName(): string
    {
        return self::get('TENANT_MAIL_FROM_NAME');
    }

    public static function officeEmail(): string
    {
        return self::get('TENANT_OFFICE_EMAIL');
    }

    public static function notifyEmail(): string
    {
        return self::get('TENANT_NOTIFY_EMAIL');
    }

    public static function notifySms(): string
    {
        return self::get('TENANT_NOTIFY_SMS');
    }

    /**
     * The session directory: the SESSION_SAVE_PATH constant, else the SESSION_SAVE_PATH
     * environment variable (session_config.php runs before secrets.php on some paths, and
     * cPanel can SetEnv it), else today's path. Callers still check is_dir/is_writable.
     */
    public static function sessionSavePath(): string
    {
        $value = defined('SESSION_SAVE_PATH') ? constant('SESSION_SAVE_PATH') : getenv('SESSION_SAVE_PATH');
        return self::resolve('SESSION_SAVE_PATH', $value === false ? null : $value);
    }

    public static function cronLockPrefix(): string
    {
        return self::get('CRON_LOCK_PREFIX');
    }
}
