<?php
if (!function_exists('_idFromName') && defined('APP_ROOT')) { require_once APP_ROOT . '/Services/Messaging/IdentityMail.php'; }
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
$bizName    = trim((string)($business['company_name']    ?? _idFromName()));
$bizPhone   = trim((string)($business['company_phone']   ?? _idPhone()));
$bizEmail   = trim((string)($business['company_email']   ?? _idCompanyInfo()['company_email']));
$bizWebsite = trim((string)($business['company_website'] ?? preg_replace('#^https?://#', '', _idSiteUrl())));
$bizAddress = trim((string)($business['company_address'] ?? BusinessIdentity::get()['address']));
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
$billingEntity = trim((string)($invoice['billing_entity_name'] ?? ''));

// Bill-to priority (the heading names the PAYER, not the site):
//   1. invoices.bill_to_name          — per-invoice manual override
//   2. billing_entity + company_name  — strata/property-management: "BCS4428 C/O OBSIDIAN..."
//   3. companies.company_name         — the linked paying company
//   4. contact first + last           — private client / rep name
//   5. properties.property_name       — last-resort fallback
$billToHeading = trim((string)($invoice['bill_to_name'] ?? ''));
if ($billToHeading === '') {
    if ($billingEntity !== '' && $companyName !== '') {
        $billToHeading = $billingEntity . ' C/O ' . $companyName;
    } else {
        $billToHeading = $companyName ?: $contactName ?: $propertyName;
    }
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

// Salt/snow route invoice: one-page, payment-first layout (set by PdfGenerator). Every other
// invoice renders exactly as before.
$snowLayout    = !empty($snowLayout);
// Layout (compact, payment-first, blocks kept whole) is for every invoice; $snowLayout only switches the
// route-specific content (signed terms, weather/GPS panels, work-and-day lines, no notes).
$modern        = true;
if ($snowLayout) { require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceSnowTerms.php'; }
$snowTermsHtml = (string)($snowTermsHtml ?? '');
$dueDay        = !empty($invoice['due_date']) ? date('D, M j, Y', strtotime($invoice['due_date'])) : '';
$payInstrInBox = $modern && !$isPaid && $invoicePaymentInstr !== '';
$showGenericTerms = !($snowLayout && $snowTermsHtml !== '');
// One thank-you: when the header message and the footer text both say it, the footer drops its copy.
$dropFooterText = $modern && $invoiceHeaderMsg !== '' && $invoiceFooterText !== ''
    && preg_match('/thank/i', $invoiceHeaderMsg) && preg_match('/thank/i', $invoiceFooterText);
// A footer that only repeats the company line already in the header (name, address, phone, site, email, GST)
// adds a line, not information. Anything else in it (a note, a licence number, a call-to-action) is kept.
if ($modern && $invoiceFooterText !== '' && !$dropFooterText) {
    $known = [];
    foreach ([$bizName, $bizAddress, $bizWebsite, $bizEmail, $bizGst, $bizPhone] as $src) {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$src), -1, PREG_SPLIT_NO_EMPTY) as $w) { $known[$w] = true; }
    }
    $rest = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($invoiceFooterText), -1, PREG_SPLIT_NO_EMPTY), function ($w) use ($known) {
        return !isset($known[$w]) && !in_array($w, ['and', 'gst', 'www', 'http', 'https', 'ca', 'com', 'bc', 'canada', 'tel', 'phone', 'email'], true);
    });
    if (!$rest) { $dropFooterText = true; }
}
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

    .notes-section { margin-top: 16px; font-size: 9pt; color: #475569; line-height: 1.6; page-break-inside: avoid; }

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
<?php if ($modern): ?>
<style>
    /* Compact, payment-first invoice layout (all invoices). Not limited to one page: blocks stay whole and flow to the next. */
    .divider { margin: 7px 0; }
    .header-table { margin-bottom: 4px; }
    .invoice-header-msg { margin-bottom: 6px; padding: 5px 12px; }
    .items-table { margin-top: 4px; }
    .items-table td { padding-top: 6px; padding-bottom: 6px; }
    .totals-table { margin-top: 2px; }
    .totals-table td { padding: 2px 0; }
    .two-col td { padding-right: 14px; }
    .items-table td { padding-top: 4px; padding-bottom: 4px; }
    .footer { margin-top: 6px; padding-top: 5px; }
    /* Ittai type: Inter (registered in PdfGenerator::createMpdf; falls back to the default font if absent) */
    body, td, th { font-family: inter, helvetica, sans-serif; }
    /* Ittai v1.2 brand colours (--brand #233A5E, --text #131D17, --meta #48534C, --line #D9DFDA, --sunk #EEF1EE) */
    body { color: #131D17; }
    .brand-name { color: #233A5E; }
    .brand-tagline { color: #48534C; }
    .brand-info, .doc-title, .doc-meta { color: #48534C; }
    .doc-meta strong { color: #131D17; }
    .doc-number { color: #233A5E; }
    .section-title.primary { color: #233A5E; border-bottom-color: #233A5E; }
    .items-table th { background: #EEF1EE; color: #48534C; }
    .items-table td.desc-col { color: #131D17; }
    .divider { border-top-color: #D9DFDA; }
    .client-name { color: #131D17; }
    .section-title.primary { font-size: 8pt; letter-spacing: 1px; }
    .items-table th { font-size: 8pt; letter-spacing: 1px; text-transform: uppercase; }
    .items-table { margin-top: 8px; }
    .two-col td.sl { text-align: right; padding-right: 0; }
    .client-name { font-size: 11pt; }
    .invoice-header-msg { padding: 5px 12px; margin-bottom: 12px; background: #EEF1EE; border-left: 3px solid #233A5E; color: #131D17; }
    /* Terms / payment blocks in the same indigo family (no green labels) */
    .invoice-messaging .block { border-left-color: #233A5E; color: #48534C; }
    .invoice-messaging .block .label { color: #233A5E; }
    .invoice-messaging .block.payment { background: #EEF1EE; border-left-color: #233A5E; }
</style>
<?php endif; ?>

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
            <img src="<?php echo $logoPath; ?>" alt="<?php echo $esc($bizName); ?>" style="max-height: 30px; margin-bottom: 3px;">
            <div class="brand-name"><?php echo $esc(strtoupper($bizName)); ?></div>
            <?php if ($bizTagline !== ''): ?>
                <div class="brand-tagline"><?php echo $esc($bizTagline); ?></div>
            <?php endif; ?>
            <?php if ($modern): ?>
            <div class="brand-info" style="line-height:1.4;">
                <?php
                    $contactBits = array_filter([$bizPhone, $bizEmail, $bizWebsite], fn($p) => $p !== '');
                    echo $esc(trim(preg_replace('/\s*[\r\n]+\s*/', ', ', $bizAddress)));
                ?><br>
                <?php echo $esc(implode(' · ', $contactBits)); ?><?php if ($bizGst !== ''): ?><br>GST&nbsp;#:&nbsp;<?php echo $esc($bizGst); ?><?php endif; ?>
            </div>
            <?php else: ?>
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
            <?php endif; ?>
        </td>
        <td style="width: 50%; vertical-align: top; text-align: right; padding-top: 3mm;" align="right">
            <?php $ra = ' style="text-align: right;"'; ?>
            <div class="doc-title"<?php echo $ra; ?>>Invoice</div>
            <div class="doc-number"<?php echo $ra; ?>><?php echo $esc($invoice['invoice_number']); ?></div>
            <div class="doc-meta"<?php echo $ra; ?>>
                Date: <strong><?php echo $issueDate; ?></strong><br>
                <?php if ($dueDate && ($isPaid || !$modern)): /* unpaid: the due date is in the amount panel, once */ ?>
                    Due: <strong><?php echo $dueDate; ?></strong><br>
                <?php endif; ?>
                <?php if ($modern && !empty($invoice['payment_terms'])): ?>
                    Payment Terms: <strong><?php echo $esc($invoice['payment_terms']); ?></strong><br>
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

<?php if ($invoiceHeaderMsg !== '' && !$snowLayout): /* salt/snow invoices carry no thank-you box (Tim, 2026-11-19) */ ?>
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
                <?php $billPhone = ($invoice['contact_phone'] ?? '') ?: ($invoice['billing_phone'] ?? ''); if (!empty($billPhone)): ?>
                    <?php echo $esc($billPhone); ?>
                <?php endif; ?>
            </div>
        </td>
        <?php if ($propertyLine && !$billToIsProperty): ?>
        <td<?php echo $snowLayout ? ' class="sl" style="text-align:right; padding-right:0;" align="right"' : ' style="padding-left:14px;"'; ?>>
            <div class="section-title primary"<?php echo $snowLayout ? ' style="text-align:right;"' : ''; ?>>Service Location</div>
            <div class="client-info">
                <?php if ($propertyName !== ''): ?>
                    <div class="client-name"><?php echo $esc($propertyName); ?></div>
                <?php endif; ?>
                <?php echo $propertyLine; ?><br>
                <?php if (!empty($contactName) && trim($contactName) !== ''): ?>
                    Attn: <strong><?php echo $esc($contactName); ?></strong><br>
                <?php endif; ?>
                <?php if (!empty($invoice['plan_number']) && !$snowLayout): /* route invoices carry the job as a small line item reference */ ?>
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

<!-- Line Items (the table header names the columns; no separate heading) -->

<table class="items-table">
    <thead>
        <tr>
            <th style="width: 62%;">Description</th>
            <th class="right" style="width: 8%;">Qty</th>
            <th class="right" style="width: 15%;">Price</th>
            <th class="right" style="width: 15%;">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($lineItems as $item): ?>
        <tr>
            <?php if ($snowLayout):
                // Name the work, show the date once; the route/job stays as a small reference.
                require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceSnowTerms.php';
                [$snowDesc, $snowDay] = InvoiceSnowTerms::workAndDay(
                    (string)$item['description'],
                    !empty($item['service_date']) ? (string)$item['service_date'] : null
                );
            ?>
            <td class="desc-col"><strong><?php echo $esc($snowDesc); ?></strong><?php if ($snowDay !== ''): ?><br><span style="color:#475569; font-size:9pt;"><?php echo $esc($snowDay); ?></span><?php endif; ?><?php if (!empty($item['plan_title'])): ?><br><span style="color:#94a3b8; font-size:8pt;">Job: <?php echo $esc($item['plan_title']); ?></span><?php endif; ?></td>
            <?php else: ?>
                        <td class="desc-col"><?php if (!empty($item['plan_title'])): ?><strong><?php echo $esc($item['plan_title']); ?></strong><br><?php endif; ?><?php echo $esc($item['description']); ?><?php if (!empty($item['service_date'])): ?><br><span style="color:#64748b; font-size:9pt; font-weight:normal;">Service date: <?php echo $esc(date('M j, Y', strtotime((string)$item['service_date']))); ?></span><?php endif; ?></td>
            <?php endif; ?>
            <td class="right"><?php echo rtrim(rtrim(number_format(floatval($item['quantity']), 2), '0'), '.'); ?></td>
            <td class="right"><?php echo $fmt($item['unit_price']); ?></td>
            <td class="right"><?php echo $fmt($item['line_total']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Totals — Subtotal + tax in a small table -->
<?php if (!$snowLayout): ?><div style="page-break-inside: avoid;"><?php endif; /* totals, amount due and how to pay stay together */ ?>
<table class="totals-table" style="padding-right:3.5mm;">
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
<?php $snowRow = false; if ($modern && !$isPaid):
    $snowBg = $calloutClass === 'overdue' ? '#982A1D' : '#233A5E';
    ob_start();
?>
<!-- Payment decision in one place: amount, due date, how to pay -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px; page-break-inside:avoid;">
    <tr><td style="background:<?php echo $snowBg; ?>; color:#ffffff; padding:12px 16px 10px 16px; text-align:right;">
        <div style="font-size:8pt; letter-spacing:1px; color:#D9DFDA;"><?php echo strtoupper($esc($calloutLabel)); ?></div>
        <div style="font-size:30pt; font-weight:bold; line-height:1.1;"><?php echo $calloutValue; ?></div>
        <?php if ($dueDay !== ''): ?><div style="font-size:11pt; font-weight:bold;">Due <?php echo $esc($dueDay); ?></div><?php endif; ?>
    </td></tr>
    <?php if ($invoicePaymentInstr !== ''): ?>
    <tr><td style="background:#E8F3F0; padding:7px 16px; font-size:8.5pt; color:#0D3B2E; line-height:1.4;">
        <strong>How to pay</strong><br><?php echo nl2br($esc($invoicePaymentInstr)); ?>
    </td></tr>
    <?php endif; ?>
</table>
<?php
    $payHtml = ob_get_clean();
    $wPanel  = (string)($weatherPanelHtml ?? '');
    $gWide   = (string)($presenceWideHtml ?? '');
    if ($wPanel !== '') {
        // Row 1: weather record and the amount due, equal halves. Row 2: the GPS map (left) with its text (right).
        $snowRow = true;
        // Same height: the frame (weather's border, the amount panel's tint) is carried by the table CELLS,
        // which stretch to the taller of the two; mPDF ignores a height set on the inner tables.
        $wInner  = InvoiceSnowTerms::ittai(InvoiceSnowTerms::quiet(str_replace(['border:1.2pt solid #1e3a5f;', 'margin-top:8px;'], '', $wPanel)));
        $wInner  = str_replace('Yes — service authorized', '<span style="background:#DCEBE2; color:#1A5A39; font-weight:bold;">&nbsp;Yes — service authorized&nbsp;</span>', $wInner);
        $pInner  = InvoiceSnowTerms::ittai(str_replace('margin-top:6px;', 'margin-top:0;', $payHtml));
        echo '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;"><tr>'
           . '<td width="49%" valign="top" style="border:0.6pt solid #D9DFDA; padding:0;">' . $wInner . '</td>'
           . '<td width="2%"></td>'
           . '<td width="49%" valign="top" style="background:#EEF1EE; padding:0;">' . $pInner . '</td>'
           . '</tr></table>';
        echo InvoiceSnowTerms::ittai(InvoiceSnowTerms::quiet($gWide));
    } else {
        echo $payHtml;
    }
endif; ?>
<?php if ($isPaid): ?>
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
<?php endif; ?>

<?php if (!$snowLayout): ?></div><?php endif; ?>
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

<!-- Weather authorization (salt/snow route invoices with a stored forecast record) -->
<?php if (empty($snowRow)) { echo $weatherEvidenceHtml ?? ''; } ?>

<!-- Signed snow & ice terms (salt/snow route invoices) -->
<?php if (empty($snowTermsInFooter)) { echo $snowTermsHtml; } /* PdfGenerator pins it to the page footer */ ?>

<!-- Notes -->
<?php if (!empty($invoice['notes']) && !$snowLayout): ?>
<div class="notes-section">
    <div class="section-title">Notes</div>
    <?php require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceNoteFormatter.php'; echo InvoiceNoteFormatter::html(preg_replace('/\\R\\s*\\R(?=(?:thanks|thank you|cheers|regards|best|sincerely)\\b[^\\r\\n]{0,40}\\s*$)/i', "\n", trim((string)$invoice['notes']))); /* the sign-off sits right under the note */ ?>
</div>
<?php endif; ?>

<?php if (!empty($invoice['payment_terms']) && !$modern): /* modern: shown once, in the header */ ?>
<div class="notes-section" style="margin-top: 8px;">
    <span style="font-size: 8pt; color: #94a3b8;">Payment Terms: <?php echo $esc($invoice['payment_terms']); ?></span>
</div>
<?php endif; ?>

<div style="page-break-inside: avoid;"><!-- terms and footer travel together, so the footer never sits alone on a page -->
<?php if (($invoicePaymentInstr !== '' && !$payInstrInBox && !$isPaid) || ($invoiceTerms !== '' && $showGenericTerms)): ?>
<div class="invoice-messaging">
    <?php if ($invoicePaymentInstr !== '' && !$payInstrInBox && !$isPaid): ?>
    <div class="block payment">
        <span class="label">How to Pay</span>
        <?php echo nl2br($esc($invoicePaymentInstr)); ?>
    </div>
    <?php endif; ?>
    <?php if ($invoiceTerms !== '' && $showGenericTerms): ?>
    <div class="block">
        <span class="label">Terms &amp; Conditions</span>
        <?php echo nl2br($esc($invoiceTerms)); ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Footer (only a custom footer text; the header already carries the company details) -->
<?php if (!$modern || ($invoiceFooterText !== '' && !$dropFooterText)): ?>
<div class="footer">
    <?php if ($invoiceFooterText !== '' && !$dropFooterText): ?>
        <?php echo nl2br($esc($invoiceFooterText)); ?>
    <?php else: ?>
        <?php
            $footerParts = array_filter([
                $bizName ?: _idFromName(),
                $bizAddress,
                $bizPhone,
                $bizWebsite,
            ], fn($p) => $p !== '');
        ?>
        <?php echo $esc(implode(' · ', $footerParts)); ?>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
