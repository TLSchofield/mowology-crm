<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for YardiEftInboxService::parseRemittanceEmail().
 *
 * Exercised against a real Yardi/Tribe EFT remittance email captured from the
 * office@mowology.ca inbox (2026-07-29, plain-text export). Pure static
 * method — no database required.
 */
class YardiEftInboxServiceTest extends TestCase
{
    private const SUBJECT = 'Remittance to MOWOLOGY for $57.89 on 2026-07-29 06:31:53.0';
    private const BODY = "Payment Overview\n\n"
        . "An EFT Payment has been processed to MOWOLOGY\n"
        . "Below are the details for the payment processed today:\n\n\n\n"
        . "Transaction Reference No\n500122\n"
        . "Transaction Date\n2026-07-23\n\n"
        . "Invoice Details\n"
        . "Property Name\nInvoice #\nInvoice Date\nPayment Amount\n"
        . "VR1862 - Fifteen Oaks\nINV-2026-0143\n12/06/2026\n\$57.89\n\n"
        . "Total\n\$57.89\n\n"
        . "Amount Deposited to your Bank Account\n\$57.89\n\n"
        . "Please find attached a breakdown of this EFT payment by invoice number. "
        . "Please allow up to 4 business days for the funds to be deposited to your bank account. "
        . "Any inquiries can be directed to apqueries@tribemgmt.com";

    public function test_parses_transaction_reference(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::SUBJECT, self::BODY);
        $this->assertSame('500122', $p['transaction_reference']);
    }

    public function test_parses_transaction_date(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::SUBJECT, self::BODY);
        $this->assertSame('2026-07-23', $p['transaction_date']);
    }

    public function test_parses_total(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::SUBJECT, self::BODY);
        $this->assertSame(57.89, $p['total']);
    }

    public function test_parses_single_invoice_line(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::SUBJECT, self::BODY);
        $this->assertCount(1, $p['lines']);
        $line = $p['lines'][0];
        $this->assertSame('VR1862 - Fifteen Oaks', $line['property']);
        $this->assertSame('INV-2026-0143', $line['invoice_number']);
        $this->assertSame('12/06/2026', $line['invoice_date']);
        $this->assertSame(57.89, $line['amount']);
    }

    public function test_parses_multiple_invoice_lines(): void
    {
        $body = "Payment Overview\n\nAn EFT Payment has been processed to MOWOLOGY\n\n"
            . "Transaction Reference No\n500999\n"
            . "Transaction Date\n2026-07-23\n\n"
            . "Invoice Details\n"
            . "Property Name\nInvoice #\nInvoice Date\nPayment Amount\n"
            . "VR1862 - Fifteen Oaks\nINV-2026-0143\n12/06/2026\n\$57.89\n"
            . "VR2201 - Cedar Grove\nINV-2026-0150\n15/06/2026\n\$120.00\n\n"
            . "Total\n\$177.89\n\n"
            . "Amount Deposited to your Bank Account\n\$177.89\n";

        $p = YardiEftInboxService::parseRemittanceEmail('Remittance to MOWOLOGY for $177.89 on 2026-07-29', $body);

        $this->assertSame(177.89, $p['total']);
        $this->assertCount(2, $p['lines']);
        $this->assertSame('INV-2026-0143', $p['lines'][0]['invoice_number']);
        $this->assertSame(57.89, $p['lines'][0]['amount']);
        $this->assertSame('INV-2026-0150', $p['lines'][1]['invoice_number']);
        $this->assertSame(120.00, $p['lines'][1]['amount']);
    }
}
