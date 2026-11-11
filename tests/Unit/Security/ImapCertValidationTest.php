<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Mail/ImapReader.php';

/**
 * Security audit 2026-10-12 (low): ImapReader retried every failed open with /novalidate-cert, so
 * a man in the middle could read the mailbox password and inject forged payment notices. The
 * certificate is always validated now; a failure is logged, never retried without validation.
 */
final class ImapCertValidationTest extends TestCase
{
    public function testNoMailCodeDisablesCertificateValidation(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        foreach (['app'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')
                    && stripos((string) file_get_contents($f->getPathname()), 'novalidate-cert') !== false) {
                    $hits[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $hits, '/novalidate-cert found in: ' . implode(', ', $hits));
    }

    public function testServerRefHasNoWayToSkipValidation(): void
    {
        $ref = new ReflectionMethod(ImapReader::class, 'serverRef');
        $this->assertSame(1, $ref->getNumberOfParameters());
        $this->assertSame('{mail.mowology.ca:993/imap/ssl}', ImapReader::serverRef(['host' => 'mail.mowology.ca', 'port' => 993]));
    }
}
