<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EtransferInboxService::parseInteracEmail() and extractInvoiceNumber().
 *
 * Exercised against a real Interac notification email captured from the
 * office@mowology.ca inbox (manual-claim format) plus a synthetic auto-deposit
 * variant. Pure static methods — no database required.
 */
class EtransferInboxServiceTest extends TestCase
{
    /** Real "claim your deposit" email body (name/amount are real but public-facing on the invoice). */
    private const CLAIM_SUBJECT = 'Interac e-Transfer: Claim your $396.17 from ALEX SHI KE WANG by July 3, 2026';
    private const CLAIM_BODY = "Hi mowology office,\r\n\r\nYour funds expire soon!\r\n\$396.17\r\n\r\n"
        . "Select your financial institution to deposit funds.\r\n\r\nVancity: https://etransfer.interac.ca/x\r\n\r\nOR\r\n\r\n"
        . "Select a different Institution: https://etransfer.interac.ca/y\r\n\r\nExpiry: July 3, 2026\r\n\r\n"
        . "Message:\r\nHere is the payment for June for Alex Wang(INV-2026-0096). I've added \$18.01 because I forgot to pay the GST last month\r\n\r\n"
        . "Transfer Details\r\n\r\nDate: June 3, 2026\r\nReference Number: CAkvXmaZ\r\nSent From: ALEX SHI KE WANG\r\nAmount: \$396.17 (CAD)\n\nFAQ: https://www.interac.ca";

    public function test_parses_amount_from_structured_field(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::CLAIM_SUBJECT, self::CLAIM_BODY);
        $this->assertSame(396.17, $p['amount']);
    }

    public function test_parses_sender_name(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::CLAIM_SUBJECT, self::CLAIM_BODY);
        $this->assertSame('ALEX SHI KE WANG', $p['sender_name']);
    }

    public function test_parses_reference_number(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::CLAIM_SUBJECT, self::CLAIM_BODY);
        $this->assertSame('CAkvXmaZ', $p['reference_number']);
    }

    public function test_extracts_invoice_number_from_memo(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::CLAIM_SUBJECT, self::CLAIM_BODY);
        $this->assertSame('INV-2026-0096', $p['invoice_hint']);
    }

    public function test_memo_captured_without_transfer_details(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::CLAIM_SUBJECT, self::CLAIM_BODY);
        $this->assertStringContainsString('payment for June', $p['memo']);
        $this->assertStringNotContainsString('Reference Number', $p['memo']);
    }

    public function test_detects_claim_type(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::CLAIM_SUBJECT, self::CLAIM_BODY);
        $this->assertSame('claim', $p['transfer_type']);
    }

    public function test_amount_falls_back_to_subject_when_body_lacks_field(): void
    {
        $subject = 'Interac e-Transfer: KAMALJEET SINGH sent you $66.15. Claim your deposit!';
        $p = EtransferInboxService::parseInteracEmail($subject, 'no structured amount here');
        $this->assertSame(66.15, $p['amount']);
        $this->assertSame('KAMALJEET SINGH', $p['sender_name']);
    }

    public function test_detects_autodeposit_type(): void
    {
        $body = "Hi,\nReference Number: ABC123\nSent From: JANE DOE\nAmount: \$120.00 (CAD)\n"
              . "Your money has been automatically deposited into your account.";
        $p = EtransferInboxService::parseInteracEmail('Interac e-Transfer: JANE DOE sent you money', $body);
        $this->assertSame('autodeposit', $p['transfer_type']);
        $this->assertSame(120.00, $p['amount']);
    }

    public function test_extract_invoice_number_normalises_variants(): void
    {
        $this->assertSame('INV-2026-0096', EtransferInboxService::extractInvoiceNumber('paid inv 2026 96'));
        $this->assertSame('INV-2026-0096', EtransferInboxService::extractInvoiceNumber('INV-2026-0096'));
        $this->assertSame('INV-2026-0308', EtransferInboxService::extractInvoiceNumber('invoice 2026-0308'));
        $this->assertSame('INV-2026-0224', EtransferInboxService::extractInvoiceNumber('Invoice 2026 0224'));
        $this->assertSame('INV-2026-0226', EtransferInboxService::extractInvoiceNumber('invoice 2026-0226'));
        $this->assertNull(EtransferInboxService::extractInvoiceNumber('no invoice here'));
    }

    public function test_handles_email_with_no_parseable_fields(): void
    {
        $p = EtransferInboxService::parseInteracEmail('Random subject', 'nothing useful');
        $this->assertNull($p['amount']);
        $this->assertNull($p['sender_name']);
        $this->assertNull($p['reference_number']);
        $this->assertNull($p['invoice_hint']);
    }

    // ── paymentBlockReason() ──────────────────────────────────────────────────

    public function test_payable_statuses_are_not_blocked(): void
    {
        foreach (['sent', 'viewed', 'partial', 'overdue', 'draft'] as $status) {
            $this->assertNull(
                EtransferInboxService::paymentBlockReason($status, 'INV-2026-0001'),
                "status {$status} should be payable"
            );
        }
    }

    public function test_paid_invoice_blocked_with_duplicate_warning(): void
    {
        $msg = EtransferInboxService::paymentBlockReason('paid', 'INV-2026-0096');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('already fully paid', $msg);
        $this->assertStringContainsString('duplicate', $msg);
        $this->assertStringContainsString('INV-2026-0096', $msg);
    }

    public function test_cancelled_invoice_blocked_with_status(): void
    {
        $msg = EtransferInboxService::paymentBlockReason('cancelled', 'INV-2026-0100');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('cancelled', $msg);
        $this->assertStringContainsString("can't take a payment", $msg);
    }

    /**
     * A manually forwarded Interac notification (staff hit Forward instead of
     * it arriving directly from notify@payments.interac.ca) — Apple Mail
     * prefixes every original line with "> ", which used to leak into
     * sender_name/reference_number/amount extraction. Captured 2026-08-07.
     */
    private const FORWARDED_SUBJECT = 'Fwd: Interac e-Transfer: 1355183 B.C. LTD. sent you $1,050.95. Claim your deposit!';
    private const FORWARDED_BODY = "\r\n\r\n> Begin forwarded message:\r\n>\r\n"
        . "> From: \"1355183 B.C. LTD.\" <notify@payments.interac.ca>\r\n"
        . "> Subject: Interac e-Transfer: 1355183 B.C. LTD. sent you \$1,050.95. Claim your deposit!\r\n"
        . "> Date: August 7, 2026 at 10:06:22 AM PDT\r\n"
        . "> To: Mowology <mowology@icloud.com>\r\n\r\n"
        . "> Hi Mowology,\r\n> Your funds await!\r\n> \$1,050.95\r\n"
        . "> Select your financial institution to deposit funds.\r\n"
        . "> Expiry: Sept 5, 2026\r\n"
        . "> Transfer Details\r\n"
        . "> Date:\r\n> Aug 7, 2026\r\n"
        . "> Reference Number:\r\n> CAQFjfaF\r\n"
        . "> Sent From:\r\n> 1355183 B.C. LTD.\r\n"
        . "> Amount:\r\n> \$1,050.95 (CAD)\r\n";

    public function test_forwarded_email_strips_quote_markers_from_sender(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::FORWARDED_SUBJECT, self::FORWARDED_BODY);
        $this->assertSame('1355183 B.C. LTD.', $p['sender_name']);
    }

    public function test_forwarded_email_parses_reference_number(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::FORWARDED_SUBJECT, self::FORWARDED_BODY);
        $this->assertSame('CAQFjfaF', $p['reference_number']);
    }

    public function test_forwarded_email_parses_amount(): void
    {
        $p = EtransferInboxService::parseInteracEmail(self::FORWARDED_SUBJECT, self::FORWARDED_BODY);
        $this->assertSame(1050.95, $p['amount']);
    }
}
