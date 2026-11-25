<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-10-12 #2: every deploy/ops script ran lftp with `ssl:verify-certificate no`,
 * so anyone on the network path could take the FTP password (and the database dump). All FTP now
 * goes through scripts/lib/ftp.sh, which verifies the certificate against the server's real name.
 */
final class DeployScriptTlsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> deploy.sh, scripts/**, .claude/commands/*.md, .claude/hooks/* */
    private static function scriptFiles(): array
    {
        $root = self::root();
        $files = [$root . '/deploy.sh'];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/scripts', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.(sh|py|php|bash)$/', $f->getFilename())) {
                $files[] = $f->getPathname();
            }
        }
        foreach (['/.claude/commands', '/.claude/hooks'] as $d) {
            foreach (glob($root . $d . '/*') ?: [] as $f) {
                if (is_file($f)) $files[] = $f;
            }
        }
        return $files;
    }

    public function testNoScriptTurnsOffCertificateVerification(): void
    {
        $bad = [];
        foreach (self::scriptFiles() as $f) {
            foreach (file($f) ?: [] as $n => $line) {
                if (preg_match('/verify-certificate\s+(no|off|false|0)\b/i', $line)
                    && !preg_match('/\bnever\b/i', $line)) {          // prose saying "never set … no" is fine
                    $bad[] = substr($f, strlen(self::root()) + 1) . ':' . ($n + 1);
                }
                if (preg_match('/check-hostname\s+(no|off|false|0)\b/i', $line)) {
                    $bad[] = substr($f, strlen(self::root()) + 1) . ':' . ($n + 1) . ' (check-hostname off)';
                }
            }
        }
        $this->assertSame([], $bad, 'TLS verification switched off in: ' . implode(', ', $bad));
    }

    public function testSharedFtpLibVerifiesAndKeepsPasswordOffTheCommandLine(): void
    {
        $lib = (string) file_get_contents(self::root() . '/scripts/lib/ftp.sh');
        $this->assertStringContainsString('set ssl:verify-certificate yes', $lib);
        $this->assertStringContainsString('set ssl:check-hostname yes', $lib);
        $this->assertStringContainsString('ftp:ssl-force true', $lib);
        $this->assertStringContainsString('--env-password', $lib);
        $this->assertStringNotContainsString('-u "$', $lib, 'password must not be passed as -u user,pass');
    }

    public function testEveryLftpCallGoesThroughTheSharedLib(): void
    {
        $direct = [];
        foreach (self::scriptFiles() as $f) {
            if (str_ends_with($f, '/scripts/lib/ftp.sh')) continue;
            foreach (file($f) ?: [] as $n => $line) {
                $code = ltrim($line);
                if ($code === '' || $code[0] === '#') continue;
                // a real lftp invocation: `lftp -u …`, `lftp --user …`, or ['lftp', …] in Python
                if (preg_match('/(^|[\s;(|&`])lftp\s+-(u|-user)\b|\[\s*[\'"]lftp[\'"]/', $line)) {
                    $direct[] = substr($f, strlen(self::root()) + 1) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $direct, 'call mw_lftp from scripts/lib/ftp.sh instead of lftp directly: ' . implode(', ', $direct));
    }
}
