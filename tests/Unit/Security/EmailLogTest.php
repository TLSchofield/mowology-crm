<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Messaging/EmailLog.php';

/**
 * Security audit 2026-11-08: the email log holds no email text, lives outside the web folder, is kept per business
 * and for 30 days, and the two pages that showed it are gone.
 */
class EmailLogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/emaillog_' . bin2hex(random_bytes(4));
        Tenant::forTests(null);
    }

    protected function tearDown(): void
    {
        Tenant::forTests(null);
        if (!is_dir($this->root)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function as(string $key): void
    {
        Tenant::forTests(['key' => $key, 'cfg' => []]);
    }

    public function testLineHasNoEmailTextAndNoHeaders(): void
    {
        $headers = "From: office@example.test\r\nBcc: hidden@example.test\r\nX-Secret: header-secret";
        $file = EmailLog::record('client@example.test', 'Your sign-up code', $headers, true, 1234, $this->root, strtotime('2026-11-08 09:15:00'));
        $this->assertNotNull($file);
        $text = file_get_contents($file);
        $this->assertSame("2026-11-08 09:15:00\tsent\tclient@example.test\tYour sign-up code\tmail()\t1234 bytes\n", $text);
        $this->assertStringNotContainsString('hidden@example.test', $text);
        $this->assertStringNotContainsString('header-secret', $text);
    }

    public function testLogEmailAttemptNeverWritesTheBody(): void
    {
        if (!defined('STORAGE_ROOT')) define('STORAGE_ROOT', sys_get_temp_dir() . '/emaillog_const_' . bin2hex(random_bytes(4)));
        require_once __DIR__ . '/../../../app/Services/Messaging/EmailLogger.php';
        $this->as('emaillog-body-test');
        $dir = EmailLog::dir(STORAGE_ROOT);
        $marker = 'BODY-MARKER-' . bin2hex(random_bytes(4));

        logEmailAttempt('client@example.test', 'Subject line', '<p>Your code is ' . $marker . '</p>', 'PHPMailer SMTP (mail.example.test)', false);

        $files = glob($dir . '/email-*.log') ?: [];
        $this->assertCount(1, $files);
        $text = file_get_contents($files[0]);
        $this->assertStringNotContainsString($marker, $text);
        $this->assertStringContainsString("\tfailed\tclient@example.test\tSubject line\tPHPMailer SMTP (mail.example.test)\t", $text);
        unlink($files[0]);
        rmdir($dir);
    }

    public function testEachBusinessWritesToItsOwnFolder(): void
    {
        $this->assertSame($this->root . '/logs/email', EmailLog::dir($this->root));
        $this->as('rehearsal2');
        $this->assertSame($this->root . '/logs/email/t/rehearsal2', EmailLog::dir($this->root));

        $file = EmailLog::record('a@example.test', 'Hello', 'PHPMailer SMTP (x)', true, 10, $this->root);
        $this->assertStringStartsWith($this->root . '/logs/email/t/rehearsal2/email-', (string)$file);
        $this->assertSame([], glob($this->root . '/logs/email/email-*.log') ?: []);
    }

    public function testUnsafeBusinessKeyWritesNothing(): void
    {
        $this->as('../other');
        $this->assertNull(EmailLog::dir($this->root));
        $this->assertNull(EmailLog::record('a@example.test', 'Hello', '', true, 10, $this->root));
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function testLineBreaksInRecipientOrSubjectCannotForgeALine(): void
    {
        $line = EmailLog::line("a@example.test\n2026-01-01 00:00:00\tsent", "Hi\r\n\tthere", 'PHPMailer SMTP (x)', true, 5, strtotime('2026-11-08 10:00:00'));
        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertSame(5, substr_count($line, "\t"));
        $this->assertStringContainsString("\tHi there\t", $line);
    }

    public function testLongSubjectIsCut(): void
    {
        $line = EmailLog::line('a@example.test', str_repeat('x', 400), '', true, 5, time());
        $this->assertSame(150, strlen(explode("\t", $line)[3]));
    }

    public function testFirstWriteOfTheDayPrunesFilesOlderThanThirtyDays(): void
    {
        $now = strtotime('2026-11-08 08:00:00');
        $dir = $this->root . '/logs/email';
        mkdir($dir, 0750, true);
        file_put_contents($dir . '/email-2026-10-08.log', "old\n");   // 31 days: goes
        file_put_contents($dir . '/email-2026-10-09.log', "edge\n");  // 30 days: stays
        file_put_contents($dir . '/email-2026-11-07.log', "recent\n");
        file_put_contents($dir . '/notes.txt', "not ours\n");
        mkdir($dir . '/t/other', 0750, true);
        file_put_contents($dir . '/t/other/email-2026-01-01.log', "another business\n");

        EmailLog::record('a@example.test', 'Hello', '', true, 10, $this->root, $now);

        $this->assertFileDoesNotExist($dir . '/email-2026-10-08.log');
        $this->assertFileExists($dir . '/email-2026-10-09.log');
        $this->assertFileExists($dir . '/email-2026-11-07.log');
        $this->assertFileExists($dir . '/email-2026-11-08.log');
        $this->assertFileExists($dir . '/notes.txt');
        $this->assertFileExists($dir . '/t/other/email-2026-01-01.log', 'one business never prunes another\'s folder');
    }

    public function testLaterWritesTheSameDayDoNotPrune(): void
    {
        $now = strtotime('2026-11-08 08:00:00');
        $dir = $this->root . '/logs/email';
        EmailLog::record('a@example.test', 'First', '', true, 10, $this->root, $now);
        file_put_contents($dir . '/email-2026-01-01.log', "old\n");
        EmailLog::record('a@example.test', 'Second', '', true, 10, $this->root, $now + 60);
        $this->assertFileExists($dir . '/email-2026-01-01.log');
        $this->assertSame(2, substr_count(file_get_contents($dir . '/email-2026-11-08.log'), "\n"));
    }

    public function testTheViewerPagesAndThePublicLogFolderWriterAreGone(): void
    {
        $repo = dirname(__DIR__, 3);
        $this->assertFileDoesNotExist($repo . '/public/crm/email-logs-viewer.php');
        $this->assertFileDoesNotExist($repo . '/public/crm/view-email-logs.php');
        $logger = file_get_contents($repo . '/app/Services/Messaging/EmailLogger.php');
        $this->assertStringNotContainsString('PUBLIC_ROOT', $logger);
        $this->assertStringNotContainsString('email-logs', $logger);
    }
}
