<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-10-12 #26 + dependencies: FPDI < 2.6.7 has CVE-2026-45802 (DoS via crafted
 * PDF); PHPMailer lived outside Composer, so `composer audit` could not see it.
 */
final class DependencyFloorsTest extends TestCase
{
    /** @return array<string,string> package => version from public/composer.lock */
    private static function locked(): array
    {
        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/public/composer.lock'), true);
        $out = [];
        foreach ($lock['packages'] ?? [] as $p) {
            $out[$p['name']] = ltrim((string) $p['version'], 'v');
        }
        return $out;
    }

    public function testFpdiIsPatched(): void
    {
        $v = self::locked()['setasign/fpdi'] ?? '0';
        $this->assertTrue(version_compare($v, '2.6.7', '>='), "setasign/fpdi {$v} is affected by CVE-2026-45802");
    }

    public function testPhpMailerIsManagedByComposerAndLoadedFirst(): void
    {
        $this->assertArrayHasKey('phpmailer/phpmailer', self::locked());
        $root = dirname(__DIR__, 3);
        foreach (['app/Services/Messaging/SmtpMailer.php', 'app/Services/Messaging/MessagingService.php'] as $f) {
            $src = (string) file_get_contents($root . '/' . $f);
            $composer = strpos($src, "/vendor/phpmailer/phpmailer/src'");
            $legacy = strpos($src, "/crm/vendor/phpmailer/src'");
            $this->assertNotFalse($composer, "{$f}: Composer PHPMailer path missing");
            $this->assertLessThan($legacy, $composer, "{$f}: Composer copy must be tried first");
        }
    }
}
