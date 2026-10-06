<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * sendEmail()'s optional attachment list (added for Ask first, 2026-10-06).
 * Existing callers pass nothing and keep the original single-attachment path; the list
 * only decides which files go and what the customer sees them called.
 */
class SendEmailAttachmentsTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Services/Messaging/MessagingService.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mw-attach-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/a.jpg', 'a');
        file_put_contents($this->dir . '/b.jpg', 'b');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_send_email_keeps_its_old_signature_and_gains_an_optional_list(): void
    {
        $params = (new ReflectionFunction('sendEmail'))->getParameters();
        $this->assertSame(['to', 'subject', 'htmlBody', 'attachmentPath', 'fromName', 'attachments'], array_map(fn($p) => $p->getName(), $params));
        $this->assertTrue($params[5]->isOptional());
        $this->assertSame([], $params[5]->getDefaultValue());
    }

    public function test_list_takes_paths_or_path_to_name_pairs_and_drops_missing_files(): void
    {
        $out = _normaliseAttachmentList(null, [
            $this->dir . '/a.jpg' => 'Cambridge Apartments 1.jpg',
            $this->dir . '/missing.jpg' => 'x.jpg',
            0 => $this->dir . '/b.jpg',
        ]);
        $this->assertSame([
            $this->dir . '/a.jpg' => 'Cambridge-Apartments-1.jpg',
            $this->dir . '/b.jpg' => 'b.jpg',
        ], $out);
    }

    public function test_single_attachment_path_comes_first(): void
    {
        $out = _normaliseAttachmentList($this->dir . '/b.jpg', [$this->dir . '/a.jpg' => 'one.jpg']);
        $this->assertSame([$this->dir . '/b.jpg', $this->dir . '/a.jpg'], array_keys($out));
    }
}
