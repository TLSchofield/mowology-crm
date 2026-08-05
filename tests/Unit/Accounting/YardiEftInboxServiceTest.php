<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for YardiEftInboxService::parseRemittanceEmail().
 *
 * REAL_HTML_BODY is the exact HTML (base64 round-tripped, tag-for-tag) of a
 * real Yardi/Tribe EFT remittance email captured from the office@mowology.ca
 * inbox on 2026-08-05 — the actual wire format is HTML-only (nested
 * MS-Office-style tables, label text wrapped in <span>), not the flattened
 * plain-text layout a mail client's "export as text" would produce. The
 * plain-text tests below cover the fallback text parser for a manually
 * forwarded/exported copy. Pure static method — no database required.
 */
class YardiEftInboxServiceTest extends TestCase
{
    private const REAL_SUBJECT = 'Remittance to MOWOLOGY for $57.89 on 2026-07-29 06:31:53.0';

    private const REAL_HTML_BODY = <<<'HTML'
<style type="text/css">.customcolorline {    border-bottom:4px solid #0969a6;}.customcolor {    color:#0969a6;    font-weight: bold;}.customcolorbackground {    background-color:#0969a6;    background:#0969a6;     color:#ffffff; }
</style>
<div style="text-align: left;">
<div align="center">
<table border="1" cellpadding="0" cellspacing="0" class="x_MsoNormalTable" style="background:#e5e5e5; border-collapse:collapse; width:auto">
	<tbody>
		<tr>
			<td style="padding:0in" valign="top">
			<table border="0" cellpadding="0" cellspacing="0" class="x_MsoNormalTable" style="background:white; border-collapse:collapse; width:491.25pt">
				<tbody>
					<tr>
						<td style="padding:0in" valign="top">
						<table border="0" cellpadding="0" cellspacing="0" class="x_MsoNormalTable" style="border-collapse:collapse; font-family:Arial,sans-serif,serif,EmojiFont; width:100%">
							<tbody>
								<tr>
									<td style="padding:3pt; width:86.25pt" valign="top" width="115">
									<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
										<tbody>
											<tr>
												<td>&nbsp;</td>
											</tr>
										</tbody>
									</table>

									<p class="x_MsoNormal" style="line-height: 13.5pt;"><img src="cid:image_1558.png" /></p>

									<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
										<tbody>
											<tr>
												<td>&nbsp;</td>
											</tr>
										</tbody>
									</table>
									</td>
								</tr>
								<tr>
									<td class="customcolorline" colspan="2" style="background-color:#f1f3f6; background:#f1f3f6; font-family:Arial,sans-serif,serif,EmojiFont; padding-left:5px" valign="top">
										<div class="customcolor">
										<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
											<tbody>
												<tr>
													<td>&nbsp;</td>
												</tr>
											</tbody>
										</table>
										<span style="font-size:22px">Payment Overview</span>

										<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
											<tbody>
												<tr>
													<td>&nbsp;</td>
												</tr>
											</tbody>
										</table>
										</div>

										<div style="color: #5c6265;font-size: 8pt;font-weight:normal;"><span style="font-size:12px">An EFT Payment has been processed to MOWOLOGY</span></div>

										<div style="color: #5c6265;font-size: 8pt;font-weight:normal;"><span style="font-size:12px">Below are the details for the payment processed today:</span>

										<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
											<tbody>
												<tr>
													<td>&nbsp;</td>
												</tr>
											</tbody>
										</table>
										</div>
									</td>
								</tr>
							<tr style="border-width: 0px;">
								<td style="align-content:normal; border-width:0px">&nbsp;</td>
							</tr>
							<tr>
								<td style="padding:0in" valign="top">
								<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
									<tbody>
										<tr>
											<td>
											<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
												<tbody>
													<tr>
														<td style="width:50%">&nbsp;</td>
														<td border="1" bordercolor="#979ca3" style="width:50%">
														<table border="1" bordercolor="#979ca3" cellpadding="2" cellspacing="0" class="x_MsoNormalTable" style="border-bottom:2px!important; border-collapse:collapse; border:1px solid #979ca3; font-family:Arial,sans-serif,serif,EmojiFont; font-size:8pt; font-weight:500; width:100%">
															<tbody>
																<tr>
																	<td style="background-color:#f1f3f6; background:#f1f3f6; border-bottom-width:1px; font-weight:600; padding:3pt; width:156px" valign="top"><span style="font-size:12px">Transaction Reference No</span></td>
																	<td style="padding:3pt; text-align:right; width:145px" valign="top"><span style="font-size:12px">500122</span></td>
															</tr>
															<tr>
																<td style="background-color:#f1f3f6; background:#f1f3f6; font-weight:600; padding:3px; width:158px" valign="top"><span style="font-size:12px">Transaction Date</span></td>
															<td style="border-style:solid; padding:3px; text-align:right; width:147px" valign="top"><span style="font-size:12px">2026-07-23</span></td>
														</tr>
													</tbody>
												</table>
											</td>
										</tr>
										<tr>
											<td>&nbsp;</td>
										</tr>
										<tr>
											<td>
											<div class="customcolor" style="font-weight:bold;padding-bottom: 5px;padding-left: 5pt;"><span style="font-size:22px">Invoice Details</span></div>
										</td>
									</tr>
									<tr>
										<td><table border='1' bordercolor='#979ca3' cellpadding='0' cellspacing='0' style='border-collapse:collapse; border:1px solid #c0c2c6; font-family:Arial,sans-serif,serif,EmojiFont; font-size:8pt; font-weight:600; width:100%'><thead style='    background: #f1f3f6;    background-color: #f1f3f6;'><tr><th style='padding:3pt;width: 20%;/* background: #f1f3f6; */background: #f1f3f6;background-color: #f1f3f6;' valign='top'>Property Name</th><th style='font-weight:600;padding:3pt;width: 15%;background: #f1f3f6;background-color: #f1f3f6;' valign='top'>Invoice #</th><th style='padding:3pt;width:15%;background: #f1f3f6;background-color: #f1f3f6;' valign='top'>Invoice Date</th><th style='padding:3pt;width:15%;background: #f1f3f6;background-color: #f1f3f6;' valign='top'>Payment Amount</th></tr></thead><tbody><tr><td style='padding:3pt' valign='top'>VR1862 - Fifteen Oaks</td><td style='font-weight:600; padding:3pt' valign='top'>INV-2026-0143</td><td style='padding:3pt; text-align:right' valign='top'>12/06/2026</td><td style='padding:3pt; text-align:right' valign='top'>$57.89</td></tr></tbody></table></td>
									</tr>
								<tr>
									<td>
									<table border="0" cellspacing="0" style="border-collapse:collapse; width:100%">
										<tbody>
											<tr>
												<td style="width:268px">&nbsp;</td>
												<td border="1" bordercolor="#979ca3" style="width:372px">
												<table border="1" borderbottom="2" bordercolor="#979ca3" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #979ca3; font-family:Arial,sans-serif,serif,EmojiFont; font-size:8pt; font-weight:500; width:100%">
													<tbody>
														<tr>
															<td style="background-color:#f1f3f6; background:#f1f3f6; font-weight:600; padding:3pt; width:234px" valign="top"><span style="font-size:12px">Total</span></td>
														<td style="padding:3pt; text-align:right; width:119px" valign="top"><span style="font-size:12px">$57.89</span></td>
													</tr>
												</tbody>
											</table>
										</td>
								</tr>
							</tbody>
						</table>
					</td>
				</tr>
			</tbody>
		</table>
		</td>
	</tr>
	</tbody>
</table>
</div>
</div>

<div align="center">
<div style="text-align: left;">&nbsp;</div>
</div>
HTML;

    public function test_real_email_parses_transaction_reference(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::REAL_SUBJECT, self::REAL_HTML_BODY);
        $this->assertSame('500122', $p['transaction_reference']);
    }

    public function test_real_email_parses_transaction_date(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::REAL_SUBJECT, self::REAL_HTML_BODY);
        $this->assertSame('2026-07-23', $p['transaction_date']);
    }

    public function test_real_email_parses_total(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::REAL_SUBJECT, self::REAL_HTML_BODY);
        $this->assertSame(57.89, $p['total']);
    }

    public function test_real_email_parses_invoice_line(): void
    {
        $p = YardiEftInboxService::parseRemittanceEmail(self::REAL_SUBJECT, self::REAL_HTML_BODY);
        $this->assertCount(1, $p['lines']);
        $line = $p['lines'][0];
        $this->assertSame('VR1862 - Fifteen Oaks', $line['property']);
        $this->assertSame('INV-2026-0143', $line['invoice_number']);
        $this->assertSame('12/06/2026', $line['invoice_date']);
        $this->assertSame(57.89, $line['amount']);
    }

    // ── Plain-text fallback parser (manually forwarded/exported copy) ──────

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
