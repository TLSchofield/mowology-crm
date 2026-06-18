<?php
/**
 * Quote PDF Template
 * Rendered by PdfGenerator::generateQuotePdf()
 * Variables: $quote (array), $lineItems (array)
 *
 * This outputs HTML for mPDF rendering — NOT an AppStack CRM page.
 */

// Helper
$fmt = function($amount) { return '$' . number_format(floatval($amount), 2); };
$esc = function($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); };

// Prefer the name resolved by QuoteService::resolveDisplayName() (person →
// strata/company "C/O" entity). Fall back to the old inline logic if a caller
// renders this template without the resolved field.
$contactName = $quote['display_name'] ?? '';
if ($contactName === '' || $contactName === 'N/A') {
    $contactName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''));
}
if (empty($contactName)) $contactName = $quote['company_name'] ?? 'Customer';

$propertyLine = $esc($quote['property_address'] ?? '');
if (!empty($quote['property_city'])) $propertyLine .= ', ' . $esc($quote['property_city']);
if (!empty($quote['property_postal'])) $propertyLine .= ' ' . $esc($quote['property_postal']);

$quoteDate = !empty($quote['created_at']) ? date('F j, Y', strtotime($quote['created_at'])) : date('F j, Y');
$validUntil = !empty($quote['valid_until']) ? date('F j, Y', strtotime($quote['valid_until'])) : '';
if (empty($validUntil) && !empty($quote['expiry_date'])) {
    $validUntil = date('F j, Y', strtotime($quote['expiry_date']));
}

$subtotal = floatval($quote['subtotal'] ?? 0);
$taxAmount = floatval($quote['tax_amount'] ?? 0);
$total = floatval($quote['amount'] ?? $quote['total_amount'] ?? ($subtotal + $taxAmount));
$taxRate = floatval($quote['tax_rate'] ?? 0.05);
?>
<style>
    body {
        font-family: Helvetica, Arial, sans-serif;
        color: #1a1a1a;
        font-size: 10pt;
        line-height: 1.5;
    }

    .header-table {
        width: 100%;
        margin-bottom: 14px;
    }

    .brand-name {
        font-size: 22pt;
        font-weight: bold;
        color: #2D8659;
        letter-spacing: -0.5px;
    }

    .brand-info {
        font-size: 8.5pt;
        color: #64748b;
        line-height: 1.6;
    }

    .divider {
        border: none;
        border-top: 1px solid #E8F3F0;
        margin: 20px 0;
    }

    .section-title {
        font-size: 8pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #2D8659;
        margin-bottom: 8px;
    }

    .client-info {
        font-size: 10pt;
        line-height: 1.7;
    }

    .client-name {
        font-size: 12pt;
        font-weight: bold;
        color: #0D3B2E;
    }

    .svc-addr-label {
        font-size: 7pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #94a3b8;
        margin-top: 8px;
    }

    /* One-line quote meta band */
    .meta-band {
        width: 100%;
        background: #E8F3F0;
        margin-bottom: 18px;
    }

    .meta-band td {
        padding: 8px 12px;
        vertical-align: middle;
    }

    .meta-doc {
        font-size: 10pt;
        font-weight: bold;
        letter-spacing: 0.5px;
        color: #0D3B2E;
    }

    .meta-num {
        font-size: 10pt;
        font-weight: bold;
        color: #2D8659;
    }

    .meta-title {
        font-size: 9.5pt;
        color: #64748b;
    }

    .meta-right {
        text-align: right;
        font-size: 8.5pt;
        color: #64748b;
    }

    /* Line items table */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .items-table th {
        background: #f1f5f9;
        color: #475569;
        font-size: 7.5pt;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 10px 12px;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }

    .items-table th.right {
        text-align: right;
    }

    .items-table td {
        padding: 12px 12px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 9.5pt;
        vertical-align: top;
    }

    .items-table td.right {
        text-align: right;
        font-weight: 500;
    }

    .items-table td.service-name {
        font-weight: 600;
        color: #0D3B2E;
    }

    .items-table td.description {
        color: #64748b;
        font-size: 9pt;
    }

    /* Totals */
    .totals-table {
        width: 250px;
        margin-left: auto;
        margin-top: 20px;
    }

    .totals-table td {
        padding: 6px 0;
        font-size: 9.5pt;
    }

    .totals-table td.label {
        color: #64748b;
    }

    .totals-table td.value {
        text-align: right;
        font-weight: 500;
    }

    .totals-table tr.grand td {
        padding-top: 12px;
        border-top: 2px solid #0D3B2E;
        font-size: 14pt;
        font-weight: 700;
        color: #0D3B2E;
    }

    /* Terms & notes */
    .terms-section {
        margin-top: 30px;
        padding: 16px 20px;
        background: #f8fafb;
        border-radius: 6px;
        font-size: 8.5pt;
        color: #64748b;
        line-height: 1.7;
    }

    .terms-title {
        font-size: 8pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #2D8659;
        margin-bottom: 6px;
    }

    .notes-section {
        margin-top: 16px;
        font-size: 9pt;
        color: #475569;
        line-height: 1.6;
    }

    /* Signature */
    .signature-section {
        margin-top: 30px;
        padding: 16px 20px;
        border: 1px solid #E8F3F0;
        border-radius: 6px;
    }

    .signature-img {
        max-width: 300px;
        max-height: 100px;
    }

    .signature-meta {
        font-size: 8pt;
        color: #64748b;
        margin-top: 8px;
    }

    /* Footer */
    .footer {
        text-align: center;
        font-size: 8pt;
        color: #94a3b8;
        margin-top: 40px;
        padding-top: 16px;
        border-top: 1px solid #E8F3F0;
    }

    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 8pt;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-draft { background: #f1f5f9; color: #64748b; }
    .status-sent { background: #dbeafe; color: #1d4ed8; }
    .status-accepted { background: #dcfce7; color: #166534; }
    .status-declined { background: #fee2e2; color: #991b1b; }
</style>

<!-- Header: Business info left, Quote info right -->
<table class="header-table" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 52%; vertical-align: top;">
            <?php
                // Build correct logo path for mPDF (requires absolute filesystem paths)
                $logoPath = $projectRoot ?? dirname(__DIR__, 3);

                // Try multiple possible locations (works with both repo structure and cPanel)
                $possiblePaths = [
                    $logoPath . '/public/assets/img/logo/mowology-logo.jpg',
                    $logoPath . '/assets/img/logo/mowology-logo.jpg',
                    dirname(__DIR__, 3) . '/public/assets/img/logo/mowology-logo.jpg',
                ];

                $logoPath = '';
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $logoPath = $path;
                        break;
                    }
                }
            ?>
            <?php if ($logoPath && file_exists($logoPath)): ?>
                <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Mowology" style="max-height: 50px; margin-bottom: 8px;">
            <?php endif; ?>
            <div class="brand-name">MOWOLOGY</div>
            <div class="brand-info">
                Vancouver, BC<br>
                (778) 846-9273<br>
                office@mowology.ca<br>
                mowology.ca
            </div>
        </td>
        <td style="width: 48%; vertical-align: top;">
            <!-- Prepared For — top right, frees the vertical space below -->
            <div class="section-title" style="text-align: right;">Prepared For</div>
            <div class="client-info" style="text-align: right;">
                <div class="client-name"><?php echo $esc($contactName); ?></div>
                <?php
                    // Show the company on its own line only when it adds info —
                    // i.e. a person at a company. For strata the name already reads
                    // "Entity C/O Company", so don't repeat it.
                    $showCompany = !empty($quote['company_name'])
                        && $quote['company_name'] !== $contactName
                        && stripos($contactName, (string)$quote['company_name']) === false;
                ?>
                <?php if ($showCompany): ?>
                    <?php echo $esc($quote['company_name']); ?><br>
                <?php endif; ?>
                <div class="svc-addr-label">Service Address</div>
                <?php echo $propertyLine; ?><br>
                <?php $pdfEmail = ($quote['display_email'] ?? null) ?: ($quote['contact_email'] ?? null) ?: ($quote['billing_email'] ?? null); ?>
                <?php $pdfPhone = ($quote['display_phone'] ?? null) ?: ($quote['contact_phone'] ?? null) ?: ($quote['billing_phone'] ?? null); ?>
                <?php if (!empty($pdfEmail)): ?>
                    <span style="color: #64748b;"><?php echo $esc($pdfEmail); ?></span><br>
                <?php endif; ?>
                <?php if (!empty($pdfPhone)): ?>
                    <span style="color: #64748b;"><?php echo $esc($pdfPhone); ?></span>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</table>

<!-- One-line quote meta band (number, title, date, status) -->
<table class="meta-band" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <span class="meta-doc">QUOTE</span>
            <span class="meta-num">&nbsp;&nbsp;<?php echo $esc($quote['quote_number']); ?></span>
            <?php if (!empty($quote['title'])): ?>
                <span class="meta-title">&nbsp;&middot;&nbsp; <?php echo $esc($quote['title']); ?></span>
            <?php endif; ?>
        </td>
        <td class="meta-right">
            <?php echo $quoteDate; ?>
            <?php if ($validUntil): ?>&nbsp;&middot;&nbsp; Valid <?php echo $validUntil; ?><?php endif; ?>
            &nbsp;
            <span class="status-badge status-<?php echo $esc($quote['status']); ?>"><?php echo strtoupper($esc($quote['status'])); ?></span>
        </td>
    </tr>
</table>

<!-- Services / Line Items -->
<div class="section-title">Services</div>

<table class="items-table">
    <thead>
        <tr>
            <th style="width: 30%;">Service</th>
            <th style="width: 35%;">Description</th>
            <th class="right" style="width: 10%;">Qty</th>
            <th class="right" style="width: 12%;">Price</th>
            <th class="right" style="width: 13%;">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($lineItems as $item): ?>
        <tr>
            <td class="service-name"><?php echo $esc($item['service_type']); ?></td>
            <td class="description"><?php echo $esc($item['description'] ?: '-'); ?></td>
            <td class="right"><?php echo rtrim(rtrim(number_format(floatval($item['quantity']), 2), '0'), '.'); ?></td>
            <td class="right"><?php echo $fmt($item['unit_price']); ?></td>
            <td class="right"><?php echo $fmt($item['line_total']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Totals -->
<table class="totals-table">
    <tr>
        <td class="label">Subtotal</td>
        <td class="value"><?php echo $fmt($subtotal); ?></td>
    </tr>
    <tr>
        <td class="label">GST (<?php echo round($taxRate * 100); ?>%)</td>
        <td class="value"><?php echo $fmt($taxAmount); ?></td>
    </tr>
    <tr class="grand">
        <td class="label">Total</td>
        <td class="value"><?php echo $fmt($total); ?></td>
    </tr>
</table>

<!-- Terms -->
<?php if (!empty($quote['terms'])): ?>
<div class="terms-section">
    <div class="terms-title">Terms &amp; Conditions</div>
    <?php echo nl2br($esc($quote['terms'])); ?>
</div>
<?php endif; ?>

<!-- Customer Notes -->
<?php if (!empty($quote['notes_customer'])): ?>
<div class="notes-section">
    <div class="section-title">Notes</div>
    <?php echo nl2br($esc($quote['notes_customer'])); ?>
</div>
<?php endif; ?>

<!-- Signature (if accepted) -->
<?php if ($quote['status'] === 'accepted' && !empty($quote['signature_data'])): ?>
<div class="signature-section">
    <div class="section-title">Accepted &amp; Signed</div>
    <img src="<?php echo $quote['signature_data']; ?>" class="signature-img" alt="Signature">
    <div class="signature-meta">
        Signed by: <?php echo $esc($quote['accepted_by_name']); ?><br>
        Date: <?php echo !empty($quote['signature_timestamp']) ? date('F j, Y g:i A', strtotime($quote['signature_timestamp'])) : 'N/A'; ?><br>
        IP: <?php echo $esc($quote['accepted_ip_address']); ?>
    </div>
</div>
<?php endif; ?>

<!-- Footer -->
<div class="footer">
    Mowology &bull; Vancouver, BC &bull; (778) 846-9273 &bull; mowology.ca
</div>
