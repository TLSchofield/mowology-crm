<?php
/**
 * Invoice PDF Template
 * Rendered by PdfGenerator::generateInvoicePdf()
 * Variables: $invoice (array), $lineItems (array), $business (array)
 *
 * This outputs HTML for mPDF rendering — NOT an AppStack CRM page.
 */

$fmt = function($amount) { return '$' . number_format(floatval($amount), 2); };
$esc = function($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); };

// Business (our own company) info — loaded from business_settings by PdfGenerator
$business = isset($business) && is_array($business) ? $business : [];
$bizName    = trim((string)($business['company_name']    ?? 'Mowology'));
$bizPhone   = trim((string)($business['company_phone']   ?? '(778) 846-9273'));
$bizEmail   = trim((string)($business['company_email']   ?? 'office@mowology.ca'));
$bizWebsite = trim((string)($business['company_website'] ?? 'mowology.ca'));
$bizAddress = trim((string)($business['company_address'] ?? 'Vancouver, BC'));
$bizGst     = trim((string)($business['gst_registration'] ?? ''));
$bizTagline = trim((string)($business['company_tagline']  ?? ''));

// Invoice messaging (configured in Settings → Invoices)
$invoiceHeaderMsg    = trim((string)($business['invoice_message_header']      ?? ''));
$invoiceTerms        = trim((string)($business['invoice_terms_text']          ?? ''));
$invoicePaymentInstr = trim((string)($business['invoice_payment_instructions'] ?? ''));
$invoiceFooterText   = trim((string)($business['invoice_footer_text']         ?? ''));

$contactName = trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? ''));
$companyName = $invoice['company_name'] ?? '';
$propertyName = trim((string)($invoice['property_name'] ?? ''));

// Bill-to priority (the heading names the PAYER, not the site):
//   1. invoices.bill_to_name   — per-invoice manual override
//   2. companies.company_name  — the linked paying company
//   3. contact first + last    — private client / rep name
//   4. properties.property_name — last-resort fallback when nothing else resolves
// Previously property_name came before company/contact, which rendered
// "OAKRIDGE GARDENS" in Bill To when the real payer was VML.
$billToHeading = trim((string)($invoice['bill_to_name'] ?? ''));
if ($billToHeading === '') {
    $billToHeading = $companyName ?: $contactName ?: $propertyName;
}
if ($billToHeading === '') {
    $billToHeading = 'Customer';
}
$billToIsProperty = ($billToHeading === $propertyName && $companyName === '' && $contactName === '');

$billingLine = $esc($invoice['billing_address'] ?? '');
if (!empty($invoice['billing_city'])) $billingLine .= ', ' . $esc($invoice['billing_city']);
if (!empty($invoice['billing_province'])) $billingLine .= ' ' . $esc($invoice['billing_province']);
if (!empty($invoice['billing_postal_code'])) $billingLine .= ' ' . $esc($invoice['billing_postal_code']);

$propertyLine = '';
if (!empty($invoice['property_address'])) {
    $propertyLine = $esc($invoice['property_address']);
    if (!empty($invoice['property_city'])) $propertyLine .= ', ' . $esc($invoice['property_city']);
}

$issueDate = !empty($invoice['issue_date']) ? date('F j, Y', strtotime($invoice['issue_date'])) : date('F j, Y');
$dueDate = !empty($invoice['due_date']) ? date('F j, Y', strtotime($invoice['due_date'])) : '';

$subtotal = floatval($invoice['subtotal'] ?? 0);
$taxAmount = floatval($invoice['tax_amount'] ?? 0);
$total = floatval($invoice['total'] ?? ($subtotal + $taxAmount));
$taxRate = floatval($invoice['tax_rate'] ?? 0.05);
$amountPaid = floatval($invoice['amount_paid'] ?? 0);
$balanceDue = floatval($invoice['balance_due'] ?? ($total - $amountPaid));

$isPaid = ($invoice['status'] === 'paid');
$isOverdue = ($invoice['status'] === 'overdue');
?>
<style>
    body {
        font-family: Helvetica, Arial, sans-serif;
        color: #1a1a1a;
        font-size: 10pt;
        line-height: 1.5;
    }

    .header-table { width: 100%; margin-bottom: 18px; }

    .brand-name {
        font-size: 17pt;
        font-weight: bold;
        color: #2D8659;
        letter-spacing: -0.3px;
        white-space: nowrap;
    }

    .brand-tagline {
        font-size: 9pt;
        font-style: italic;
        color: #7FD858;
        margin-top: 2px;
        margin-bottom: 6px;
        letter-spacing: 0.2px;
    }

    .brand-info { font-size: 8.5pt; color: #64748b; line-height: 1.6; }

    /* Invoice messaging blocks */
    .invoice-header-msg {
        background: #E8F3F0;
        border-left: 3px solid #2D8659;
        padding: 8px 12px;
        margin: 0 0 12px 0;
        font-size: 9pt;
        color: #0D3B2E;
        line-height: 1.45;
        page-break-inside: avoid;
    }

    .invoice-messaging {
        margin-top: 12px;
        page-break-inside: avoid;
    }

    .invoice-messaging .block {
        margin-top: 6px;
        padding: 7px 12px;
        background: #f8fafc;
        border-left: 3px solid #E8F3F0;
        font-size: 8pt;
        color: #475569;
        line-height: 1.45;
    }

    .invoice-messaging .block .label {
        display: inline;
        font-size: 7.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #2D8659;
        margin-right: 6px;
    }

    .invoice-messaging .block.payment {
        background: #ecfdf5;
        border-left-color: #2D8659;
    }

    .doc-title {
        font-size: 10pt;
        font-weight: bold;
        color: #64748b;
        text-align: right;
        text-transform: uppercase;
        letter-spacing: 2px;
        margin-bottom: 2px;
    }

    .doc-number {
        font-size: 22pt;
        font-weight: bold;
        color: #0D3B2E;
        text-align: right;
        letter-spacing: -0.5px;
        line-height: 1.1;
        margin-bottom: 8px;
    }

    .doc-meta {
        font-size: 9pt;
        color: #64748b;
        text-align: right;
        line-height: 1.7;
    }

    .doc-meta strong { color: #1a1a1a; }

    .divider { border: none; border-top: 1px solid #E8F3F0; margin: 14px 0; }

    /* Primary section headings — for BILL TO, SERVICES, the ones the recipient anchors on */
    .section-title.primary {
        font-size: 10pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: #0D3B2E;
        margin-bottom: 8px;
        padding-bottom: 4px;
        border-bottom: 2px solid #2D8659;
    }

    /* Secondary section headings — for NOTES, TERMS, supporting info */
    .section-title {
        font-size: 7.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
        margin-bottom: 6px;
    }

    .client-info { font-size: 10pt; line-height: 1.7; }
    .client-name { font-size: 12pt; font-weight: bold; color: #0D3B2E; }

    .two-col { width: 100%; }
    .two-col td { width: 50%; vertical-align: top; }

    /* Line items */
    .items-table { width: 100%; border-collapse: collapse; margin-top: 10px; }

    .items-table th {
        background: #f1f5f9; color: #475569;
        font-size: 7.5pt; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.5px;
        padding: 10px 12px; text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }
    .items-table th.right { text-align: right; }

    .items-table td {
        padding: 12px 12px; border-bottom: 1px solid #f1f5f9;
        font-size: 9.5pt; vertical-align: top;
    }
    .items-table td.right { text-align: right; font-weight: 500; }
    .items-table td.desc-col { font-weight: 600; color: #0D3B2E; }
    .items-table td.subdesc { color: #64748b; font-size: 9pt; }

    /* Totals — small subtotal/tax table */
    .totals-table { width: 280px; margin-left: auto; margin-top: 12px; }
    .totals-table td { padding: 5px 0; font-size: 9.5pt; }
    .totals-table td.label { color: #64748b; }
    .totals-table td.value { text-align: right; font-weight: 500; }

    .totals-table tr.paid td {
        color: #2D8659; font-weight: 600;
        border-top: 1px solid #e2e8f0; padding-top: 8px;
    }

    /* Big filled callout for the primary number the customer needs to see */
    .total-callout {
        width: 280px; margin-left: auto; margin-top: 10px;
        background: #0D3B2E; color: #ffffff;
        padding: 14px 18px;
        page-break-inside: avoid;
    }
    .total-callout .tc-label {
        font-size: 8pt; font-weight: bold;
        text-transform: uppercase; letter-spacing: 1.2px;
        color: #7FD858;
    }
    .total-callout .tc-value {
        font-size: 20pt; font-weight: bold;
        text-align: right; line-height: 1.1;
        color: #ffffff;
    }

    /* Overdue variant — red instead of green */
    .total-callout.overdue { background: #991b1b; }
    .total-callout.overdue .tc-label { color: #fecaca; }

    /* Paid variant — muted green with checkmark vibe */
    .total-callout.paid { background: #166534; }
    .total-callout.paid .tc-label { color: #bbf7d0; }

    /* Status badges */
    .status-badge {
        display: inline-block; padding: 4px 12px; border-radius: 12px;
        font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .status-draft { background: #f1f5f9; color: #64748b; }
    .status-sent { background: #dbeafe; color: #1d4ed8; }
    .status-paid { background: #dcfce7; color: #166534; }
    .status-overdue { background: #fee2e2; color: #991b1b; }
    .status-partial { background: #fef3c7; color: #92400e; }

    .notes-section { margin-top: 16px; font-size: 9pt; color: #475569; line-height: 1.6; }

    .payment-info {
        margin-top: 20px; padding: 16px 20px;
        background: #dcfce7; border-radius: 6px;
        font-size: 9pt; color: #166534;
    }

    .footer {
        text-align: center; font-size: 8pt; color: #94a3b8;
        margin-top: 16px; padding-top: 10px; border-top: 1px solid #E8F3F0;
        page-break-inside: avoid;
    }
</style>

<!-- Header -->
<table class="header-table" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <?php
                // Build correct logo path based on project root
                $logoPath = $projectRoot ?? dirname(__DIR__, 3);
                // If projectRoot is above public, add /public
                if (!file_exists($logoPath . '/assets/img/logo/mowology-logo.jpg')) {
                    $logoPath = $logoPath . '/public';
                }
                $logoPath = $logoPath . '/assets/img/logo/mowology-logo.jpg';
            ?>
            <img src="<?php echo $logoPath; ?>" alt="<?php echo $esc($bizName); ?>" style="max-height: 50px; margin-bottom: 8px;">
            <div class="brand-name"><?php echo $esc(strtoupper($bizName)); ?></div>
            <?php if ($bizTagline !== ''): ?>
                <div class="brand-tagline"><?php echo $esc($bizTagline); ?></div>
            <?php endif; ?>
            <div class="brand-info">
                <?php if ($bizAddress !== ''): ?>
                    <?php echo nl2br($esc($bizAddress)); ?><br>
                <?php endif; ?>
                <?php if ($bizPhone !== ''): ?>
                    <?php echo $esc($bizPhone); ?><br>
                <?php endif; ?>
                <?php if ($bizEmail !== ''): ?>
                    <?php echo $esc($bizEmail); ?><br>
                <?php endif; ?>
                <?php if ($bizWebsite !== ''): ?>
                    <?php echo $esc($bizWebsite); ?><br>
                <?php endif; ?>
                <?php if ($bizGst !== ''): ?>
                    GST Business #: <?php echo $esc($bizGst); ?>
                <?php endif; ?>
            </div>
        </td>
        <td style="width: 50%; vertical-align: top;">
            <div class="doc-title">Invoice</div>
            <div class="doc-number"><?php echo $esc($invoice['invoice_number']); ?></div>
            <div class="doc-meta">
                Date: <strong><?php echo $issueDate; ?></strong><br>
                <?php if ($dueDate): ?>
                    Due: <strong><?php echo $dueDate; ?></strong><br>
                <?php endif; ?>
                <?php
                    // Only show a status badge for outcomes the recipient cares about.
                    // DRAFT / SENT / VIEWED are internal telemetry — don't surface on client-facing PDFs.
                    $showStatus = in_array($invoice['status'] ?? '', ['paid', 'overdue'], true);
                ?>
                <?php if ($showStatus): ?>
                <span class="status-badge status-<?php echo $esc($invoice['status']); ?>">
                    <?php echo strtoupper($esc($invoice['status'])); ?>
                </span>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</table>

<hr class="divider">

<?php if ($invoiceHeaderMsg !== ''): ?>
<div class="invoice-header-msg">
    <?php echo nl2br($esc($invoiceHeaderMsg)); ?>
</div>
<?php endif; ?>

<!-- Bill To / Property -->
<table class="two-col" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <div class="section-title primary">Bill To</div>
            <div class="client-info">
                <div class="client-name"><?php echo $esc($billToHeading); ?></div>
                <?php
                    // If the bill-to heading is the property name, show a company line
                    // below it so the paying company is still visible.
                    if ($billToIsProperty && $companyName !== ''):
                ?>
                    <?php echo $esc($companyName); ?><br>
                <?php endif; ?>
                <?php if ($billingLine !== ''): ?>
                    <?php echo $billingLine; ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['contact_phone'] ?: $invoice['billing_phone'])): ?>
                    <?php echo $esc($invoice['contact_phone'] ?: $invoice['billing_phone']); ?>
                <?php endif; ?>
            </div>
        </td>
        <?php if ($propertyLine && !$billToIsProperty): ?>
        <td>
            <div class="section-title primary">Service Location</div>
            <div class="client-info">
                <?php if ($propertyName !== ''): ?>
                    <div class="client-name"><?php echo $esc($propertyName); ?></div>
                <?php endif; ?>
                <?php echo $propertyLine; ?><br>
                <?php if (!empty($contactName) && trim($contactName) !== ''): ?>
                    Attn: <strong><?php echo $esc($contactName); ?></strong><br>
                <?php endif; ?>
                <?php if (!empty($invoice['plan_number'])): ?>
                    Job: <strong><?php echo $esc($invoice['plan_number']); ?></strong>
                    <?php if (!empty($invoice['job_title'])): ?>
                         — <?php echo $esc($invoice['job_title']); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </td>
        <?php endif; ?>
    </tr>
</table>

<hr class="divider">

<!-- Line Items -->
<div class="section-title primary">Services</div>

<table class="items-table">
    <thead>
        <tr>
            <th style="width: 50%;">Description</th>
            <th class="right" style="width: 15%;">Qty</th>
            <th class="right" style="width: 15%;">Price</th>
            <th class="right" style="width: 20%;">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($lineItems as $item): ?>
        <tr>
            <td class="desc-col"><?php echo $esc($item['description']); ?></td>
            <td class="right"><?php echo rtrim(rtrim(number_format(floatval($item['quantity']), 2), '0'), '.'); ?></td>
            <td class="right"><?php echo $fmt($item['unit_price']); ?></td>
            <td class="right"><?php echo $fmt($item['line_total']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Totals — Subtotal + tax in a small table -->
<table class="totals-table">
    <tr>
        <td class="label">Subtotal</td>
        <td class="value"><?php echo $fmt($subtotal); ?></td>
    </tr>
    <tr>
        <td class="label">GST (<?php echo round($taxRate * 100); ?>%)</td>
        <td class="value"><?php echo $fmt($taxAmount); ?></td>
    </tr>
    <?php if ($amountPaid > 0): ?>
    <tr>
        <td class="label">Total</td>
        <td class="value"><?php echo $fmt($total); ?></td>
    </tr>
    <tr class="paid">
        <td class="label">Paid</td>
        <td class="value">-<?php echo $fmt($amountPaid); ?></td>
    </tr>
    <?php endif; ?>
</table>

<!-- Primary amount callout — the one number the customer needs to see -->
<?php
    // Decide what to emphasize:
    //   - unpaid invoices: show Total
    //   - partial / overdue: show Balance Due (red if overdue)
    //   - paid: show Paid In Full (muted green)
    if ($isPaid) {
        $calloutClass = 'paid';
        $calloutLabel = 'Paid in Full';
        $calloutValue = $fmt($total);
    } elseif ($amountPaid > 0) {
        $calloutClass = $isOverdue ? 'overdue' : '';
        $calloutLabel = $isOverdue ? 'Balance Overdue' : 'Balance Due';
        $calloutValue = $fmt($balanceDue);
    } else {
        $calloutClass = $isOverdue ? 'overdue' : '';
        $calloutLabel = $isOverdue ? 'Amount Overdue' : 'Amount Due';
        $calloutValue = $fmt($total);
    }
?>
<table class="total-callout <?php echo $calloutClass; ?>" cellpadding="0" cellspacing="0" style="width: 280px; margin-left: auto; margin-top: 10px;">
    <tr>
        <td style="padding: 14px 18px; background: <?php
            echo $calloutClass === 'overdue' ? '#991b1b'
                : ($calloutClass === 'paid' ? '#166534' : '#0D3B2E');
        ?>; color: #ffffff;">
            <div class="tc-label"><?php echo $esc($calloutLabel); ?></div>
            <div class="tc-value"><?php echo $calloutValue; ?></div>
        </td>
    </tr>
</table>

<!-- Payment info (if paid) -->
<?php if ($isPaid && !empty($invoice['payment_date'])): ?>
<div class="payment-info">
    <strong>Payment Received</strong><br>
    Date: <?php echo date('F j, Y', strtotime($invoice['payment_date'])); ?>
    <?php if (!empty($invoice['payment_method'])): ?>
        &bull; Method: <?php echo ucfirst($esc($invoice['payment_method'])); ?>
    <?php endif; ?>
    <?php if (!empty($invoice['payment_reference'])): ?>
        &bull; Ref: <?php echo $esc($invoice['payment_reference']); ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Notes -->
<?php if (!empty($invoice['notes'])): ?>
<div class="notes-section">
    <div class="section-title">Notes</div>
    <?php echo nl2br($esc($invoice['notes'])); ?>
</div>
<?php endif; ?>

<?php if (!empty($invoice['payment_terms'])): ?>
<div class="notes-section" style="margin-top: 8px;">
    <span style="font-size: 8pt; color: #94a3b8;">Payment Terms: <?php echo $esc($invoice['payment_terms']); ?></span>
</div>
<?php endif; ?>

<?php if ($invoicePaymentInstr !== '' || $invoiceTerms !== ''): ?>
<div class="invoice-messaging">
    <?php if ($invoicePaymentInstr !== ''): ?>
    <div class="block payment">
        <span class="label">How to Pay</span>
        <?php echo nl2br($esc($invoicePaymentInstr)); ?>
    </div>
    <?php endif; ?>
    <?php if ($invoiceTerms !== ''): ?>
    <div class="block">
        <span class="label">Terms &amp; Conditions</span>
        <?php echo nl2br($esc($invoiceTerms)); ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Footer -->
<div class="footer">
    <?php if ($invoiceFooterText !== ''): ?>
        <?php echo nl2br($esc($invoiceFooterText)); ?>
    <?php else: ?>
        <?php
            $footerParts = array_filter([
                $bizName ?: 'Mowology',
                $bizAddress ?: 'Vancouver, BC',
                $bizPhone,
                $bizWebsite,
            ], fn($p) => $p !== '');
        ?>
        <?php echo $esc(implode(' · ', $footerParts)); ?>
    <?php endif; ?>
</div>
