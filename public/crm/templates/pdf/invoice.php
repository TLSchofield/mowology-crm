<?php
/**
 * Invoice PDF Template
 * Rendered by PdfGenerator::generateInvoicePdf()
 * Variables: $invoice (array), $lineItems (array)
 *
 * This outputs HTML for mPDF rendering — NOT an AppStack CRM page.
 */

$fmt = function($amount) { return '$' . number_format(floatval($amount), 2); };
$esc = function($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); };

$contactName = trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? ''));
$companyName = $invoice['company_name'] ?? '';
// Display company name as primary if present; contact name as secondary (or primary if no company)
if (empty($contactName) && empty($companyName)) {
    $contactName = 'Customer';
}

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

    .header-table { width: 100%; margin-bottom: 30px; }

    .brand-name {
        font-size: 22pt;
        font-weight: bold;
        color: #2D8659;
        letter-spacing: -0.5px;
    }

    .brand-info { font-size: 8.5pt; color: #64748b; line-height: 1.6; }

    .doc-title {
        font-size: 18pt;
        font-weight: bold;
        color: #0D3B2E;
        text-align: right;
    }

    .doc-meta {
        font-size: 9pt;
        color: #64748b;
        text-align: right;
        line-height: 1.8;
    }

    .doc-meta strong { color: #1a1a1a; }

    .divider { border: none; border-top: 1px solid #E8F3F0; margin: 20px 0; }

    .section-title {
        font-size: 8pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #2D8659;
        margin-bottom: 8px;
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

    /* Totals */
    .totals-table { width: 280px; margin-left: auto; margin-top: 20px; }
    .totals-table td { padding: 6px 0; font-size: 9.5pt; }
    .totals-table td.label { color: #64748b; }
    .totals-table td.value { text-align: right; font-weight: 500; }

    .totals-table tr.grand td {
        padding-top: 12px; border-top: 2px solid #0D3B2E;
        font-size: 14pt; font-weight: 700; color: #0D3B2E;
    }

    .totals-table tr.paid td {
        color: #2D8659; font-weight: 600;
    }

    .totals-table tr.balance td {
        padding-top: 8px; border-top: 1px solid #e2e8f0;
        font-size: 12pt; font-weight: 700;
    }

    .balance-due { color: #dc2626; }
    .balance-paid { color: #2D8659; }

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
        margin-top: 40px; padding-top: 16px; border-top: 1px solid #E8F3F0;
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
            <img src="<?php echo $logoPath; ?>" alt="Mowology" style="max-height: 50px; margin-bottom: 8px;">
            <div class="brand-name">MOWOLOGY</div>
            <div class="brand-info">
                Vancouver, BC<br>
                (778) 846-9273<br>
                office@mowology.ca<br>
                mowology.ca
            </div>
        </td>
        <td style="width: 50%; vertical-align: top;">
            <div class="doc-title">INVOICE</div>
            <div class="doc-meta">
                <strong><?php echo $esc($invoice['invoice_number']); ?></strong><br>
                Date: <?php echo $issueDate; ?><br>
                <?php if ($dueDate): ?>
                    Due: <?php echo $dueDate; ?><br>
                <?php endif; ?>
                <span class="status-badge status-<?php echo $esc($invoice['status']); ?>">
                    <?php echo strtoupper($esc($invoice['status'])); ?>
                </span>
            </div>
        </td>
    </tr>
</table>

<hr class="divider">

<!-- Bill To / Property -->
<table class="two-col" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <div class="section-title">Bill To</div>
            <div class="client-info">
                <?php if ($companyName): ?>
                    <div class="client-name"><?php echo $esc($companyName); ?></div>
                    <?php if ($contactName): ?>
                        <?php echo $esc($contactName); ?><br>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="client-name"><?php echo $esc($contactName ?: 'Customer'); ?></div>
                <?php endif; ?>
                <?php if ($billingLine): ?>
                    <?php echo $billingLine; ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['contact_email'] ?: $invoice['billing_email'])): ?>
                    <?php echo $esc($invoice['contact_email'] ?: $invoice['billing_email']); ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['contact_phone'] ?: $invoice['billing_phone'])): ?>
                    <?php echo $esc($invoice['contact_phone'] ?: $invoice['billing_phone']); ?>
                <?php endif; ?>
            </div>
        </td>
        <?php if ($propertyLine): ?>
        <td>
            <div class="section-title">Service Location</div>
            <div class="client-info">
                <?php echo $propertyLine; ?>
                <?php if (!empty($invoice['plan_number'])): ?>
                    <br>Job: <?php echo $esc($invoice['plan_number']); ?>
                    <?php if (!empty($invoice['job_title'])): ?>
                        - <?php echo $esc($invoice['job_title']); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </td>
        <?php endif; ?>
    </tr>
</table>

<hr class="divider">

<!-- Line Items -->
<div class="section-title">Services</div>

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
    <?php if ($amountPaid > 0): ?>
    <tr class="paid">
        <td class="label">Paid</td>
        <td class="value">-<?php echo $fmt($amountPaid); ?></td>
    </tr>
    <tr class="balance">
        <td class="label">Balance Due</td>
        <td class="value <?php echo $balanceDue > 0 ? 'balance-due' : 'balance-paid'; ?>">
            <?php echo $fmt($balanceDue); ?>
        </td>
    </tr>
    <?php endif; ?>
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

<!-- Footer -->
<div class="footer">
    Mowology &bull; Vancouver, BC &bull; (778) 846-9273 &bull; mowology.ca
</div>
