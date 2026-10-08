<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mailbox config + the read-only promise: no reader may mark, move, flag or delete mail.
 */
class MailboxReadOnlyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    /** Every file that opens or reads a mailbox. */
    private const READERS = [
        'app/Services/Mail/ImapReader.php',
        'app/Modules/Comms/Services/IcloudInboxRouter.php',
        'app/Modules/Comms/Cron/icloud_inbox_poll.php',
        'app/Modules/Sales/Cron/sales_inbox_poll.php',
        'app/Modules/Expenses/Cron/receipt_inbox_poll.php',
        'app/Modules/Accounting/Cron/etransfer_inbox_poll.php',
        'app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php',
    ];

    private function src(string $f): string
    {
        $s = (string)file_get_contents(self::ROOT . $f);
        // Comments don't count.
        $s = (string)preg_replace('#/\*.*?\*/#s', '', $s);
        return (string)preg_replace('#^\s*//.*$#m', '', $s);
    }

    public function test_no_reader_writes_to_a_mailbox(): void
    {
        foreach (self::READERS as $f) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bimap_(delete|expunge|setflag_full|clearflag_full|mail_move|mail_copy|append|undelete|renamemailbox|deletemailbox|createmailbox|gc)\s*\(/i',
                $this->src($f), "{$f} writes to a mailbox");
        }
    }

    public function test_mailboxes_are_only_opened_read_only_through_imap_reader(): void
    {
        foreach (self::READERS as $f) {
            $s = $this->src($f);
            preg_match_all('/\bimap_open\s*\(([^;]*);/', $s, $m);
            foreach ($m[1] as $call) {
                $this->assertSame('app/Services/Mail/ImapReader.php', $f, "{$f} opens a mailbox itself — use ImapReader::open()");
                $this->assertStringContainsString('$flags', $call);
            }
        }
        $reader = $this->src('app/Services/Mail/ImapReader.php');
        $this->assertMatchesRegularExpression('/\$flags = self::readOnlyFlag\(\);/', $reader);
        $this->assertSame(defined('OP_READONLY') ? OP_READONLY : 2, ImapReader::readOnlyFlag());
        $this->assertSame(defined('FT_PEEK') ? FT_PEEK : 2, ImapReader::peekFlag());
    }

    public function test_every_body_fetch_peeks(): void
    {
        foreach (self::READERS as $f) {
            $s = $this->src($f);
            preg_match_all('/\bimap_(fetchbody|body)\s*\((.*?)\)\s*[,;)]/', $s, $m, PREG_SET_ORDER);
            foreach ($m as $call) {
                $this->assertMatchesRegularExpression('/FT_PEEK|peekFlag\(/', $call[2], "{$f}: imap_{$call[1]}() without FT_PEEK would mark mail read");
            }
        }
    }

    public function test_mailbox_config_purposes_and_inert_icloud(): void
    {
        $c = ['SMTP_USER' => 'office@mowology.ca', 'SMTP_PASS' => 'x', 'RECEIPTS_IMAP_PASS' => 'y'];
        $this->assertSame(['office'], array_column(MailboxConfig::forPurpose('sales', $c), 'key'));
        $this->assertSame(['office', 'receipts'], array_column(MailboxConfig::forPurpose('receipts', $c), 'key'));
        $this->assertSame(['office'], array_column(MailboxConfig::forPurpose('etransfer', $c), 'key'));   // info@ not configured
        $this->assertNull(MailboxConfig::icloud($c));
        $this->assertSame([], MailboxConfig::forPurpose('router', $c));

        $c += ['ICLOUD_IMAP_USER' => 'Mowology@iCloud.com', 'ICLOUD_IMAP_PASS' => 'abcd-efgh-ijkl-mnop'];
        $ic = MailboxConfig::icloud($c);
        $this->assertSame('imap.mail.me.com', $ic['host']);
        $this->assertSame(993, $ic['port']);
        $this->assertSame('mowology@icloud.com', $ic['user']);
        $this->assertSame('{imap.mail.me.com:993/imap/ssl}', ImapReader::serverRef($ic));
        // iCloud is read once, by the router — not by each reader.
        $this->assertNotContains('icloud', array_column(MailboxConfig::forPurpose('receipts', $c), 'key'));
        foreach (MailboxConfig::status($c) as $m) $this->assertArrayNotHasKey('pass', $m);
    }

    public function test_icloud_sent_folder_is_found(): void
    {
        $this->assertSame('Sent Messages', ImapReader::pickSentFolder(['INBOX', 'Drafts', 'Sent Messages', 'Junk', 'Deleted Messages', 'Archive']));
        $this->assertSame('INBOX.Sent', ImapReader::pickSentFolder(['INBOX', 'INBOX.Drafts', 'INBOX.Sent', 'INBOX.Trash']));
        $this->assertNull(ImapReader::pickSentFolder(['INBOX', 'Notes']));
    }

    public function test_attachment_walk_and_headers(): void
    {
        $param = fn($a, $v) => (object)['attribute' => $a, 'value' => $v];
        $struct = (object)['type' => 1, 'subtype' => 'MIXED', 'parts' => [
            (object)['type' => 1, 'subtype' => 'ALTERNATIVE', 'parts' => [
                (object)['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 4, 'ifparameters' => 1, 'parameters' => [$param('charset', 'utf-8')]],
                (object)['type' => 0, 'subtype' => 'HTML', 'encoding' => 4, 'ifparameters' => 1, 'parameters' => [$param('charset', 'utf-8')]],
            ]],
            (object)['type' => 3, 'subtype' => 'PDF', 'encoding' => 3, 'disposition' => 'attachment',
                     'ifdparameters' => 1, 'dparameters' => [$param('filename', 'Invoice-48213.pdf')]],
            (object)['type' => 5, 'subtype' => 'PNG', 'encoding' => 3, 'disposition' => 'inline', 'ifparameters' => 1, 'parameters' => [$param('name', 'logo.png')]],
        ]];
        $atts = ImapReader::attachments($struct);
        $this->assertSame(['2', '3'], array_column($atts, 'pn'));
        $this->assertSame('application/pdf', $atts[0]['mime']);
        $this->assertSame('Invoice-48213.pdf', $atts[0]['filename']);
        $this->assertSame(['pn' => '1.2', 'encoding' => 4, 'charset' => 'UTF-8', 'html' => true], ImapReader::bodyPart($struct));

        $raw = "From: a@b.ca\r\nList-Unsubscribe:\r\n <mailto:x@y.ca>\r\nPrecedence: bulk\r\n";
        $this->assertSame('<mailto:x@y.ca>', ImapReader::header($raw, 'List-Unsubscribe'));
        $this->assertSame('', ImapReader::header($raw, 'Auto-Submitted'));
    }
}
