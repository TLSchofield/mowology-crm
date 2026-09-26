<?php
/**
 * Contract PDF Template
 * Rendered by PdfGenerator::generateContractPdf()
 *
 * Variables: $contract, $terms (array|null), $signature (array|null), $biz (array)
 *
 * Serves two jobs from one layout:
 *   - a record of an e-signed contract, with the captured signature rendered in
 *   - a printable copy for wet signing, with ruled lines instead
 *
 * The terms revision is printed in the footer either way. A signed page that
 * cannot be tied back to a specific revision of the disclaimers proves only
 * that something was signed — which is the gap the whole terms system exists
 * to close.
 *
 * This outputs HTML for mPDF rendering — NOT an AppStack CRM page.
 */

$esc = function ($str) { return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8'); };
$fmt = function ($amount) { return '$' . number_format((float)$amount, 2); };

$billingCycleLabels = [
    'monthly'   => 'Monthly',
    'per_visit' => 'Per Visit',
    'seasonal'  => 'Seasonal',
    'annual'    => 'Annual',
    'custom'    => 'Custom',
];
$cycle = $billingCycleLabels[$contract['billing_cycle'] ?? ''] ?? ucfirst((string)($contract['billing_cycle'] ?? ''));

$invoiceTimingLabels = [
    'after_visit'  => 'After each visit',
    'end_of_month' => 'End of month',
    'upfront'      => 'In advance',
];
$timing = $invoiceTimingLabels[$contract['invoice_timing'] ?? ''] ?? '';

$clientName = trim((string)($contract['first_name'] ?? '') . ' ' . (string)($contract['last_name'] ?? ''));
if ($clientName === '') { $clientName = (string)($contract['company_name'] ?? 'Client'); }

$propertyLine = $esc($contract['property_address'] ?? '');
if (!empty($contract['property_city'])) { $propertyLine .= ', ' . $esc($contract['property_city']); }

$companyName = trim((string)($biz['company_name'] ?? '')) ?: 'Mowology';
$companyBits = array_filter([
    trim((string)($biz['company_address'] ?? '')),
    trim((string)($biz['company_phone'] ?? '')),
    trim((string)($biz['company_email'] ?? '')),
    trim((string)($biz['company_website'] ?? '')),
]);

$dateOrDash = function ($d) { return $d ? date('F j, Y', strtotime((string)$d)) : '—'; };
?>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.5; }
    .header { border-bottom: 3px solid #2D8659; padding-bottom: 12px; margin-bottom: 20px; }
    .company-name { font-size: 17pt; font-weight: bold; color: #2D8659; }
    .company-meta { font-size: 8.5pt; color: #64748b; margin-top: 3px; }
    .doc-title { font-size: 14pt; font-weight: bold; margin-top: 14px; }
    .doc-number { font-size: 9.5pt; color: #64748b; }

    .section-title { font-size: 10.5pt; font-weight: bold; color: #2D8659;
        border-bottom: 1px solid #E8F3F0; padding-bottom: 4px; margin: 22px 0 10px; }

    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 5px 0; vertical-align: top; font-size: 9.5pt; }
    table.kv td.k { color: #64748b; width: 38%; }
    table.kv td.v { font-weight: bold; }

    .terms-body { font-size: 9pt; line-height: 1.6; white-space: pre-line; text-align: left; }

    .notes { font-size: 9pt; color: #475569; white-space: pre-line;
        background: #F8FAFC; padding: 10px 12px; border-left: 3px solid #E8F3F0; }

    .signature-section { margin-top: 26px; padding: 16px 20px;
        border: 1px solid #E8F3F0; border-radius: 6px; }
    .signature-img { max-width: 300px; max-height: 100px; }
    .signature-meta { font-size: 8pt; color: #64748b; margin-top: 8px; }

    .sign-line { border-bottom: 1px solid #94a3b8; height: 34px; }
    .sign-label { font-size: 8pt; color: #64748b; padding-top: 4px; }

    .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #E8F3F0;
        font-size: 8pt; color: #94a3b8; text-align: center; }
</style>

<div class="header">
    <div class="company-name"><?php echo $esc($companyName); ?></div>
    <?php if ($companyBits): ?>
        <div class="company-meta"><?php echo $esc(implode('  &bull;  ', $companyBits)); ?></div>
    <?php endif; ?>
    <div class="doc-title">Service Contract<?php echo !empty($contract['title']) ? ' — ' . $esc($contract['title']) : ''; ?></div>
    <div class="doc-number"><?php echo $esc($contract['contract_number'] ?? ''); ?></div>
</div>

<div class="section-title">Parties &amp; Location</div>
<table class="kv">
    <tr><td class="k">Client</td><td class="v"><?php echo $esc($clientName); ?></td></tr>
    <?php if (!empty($contract['company_name']) && $contract['company_name'] !== $clientName): ?>
        <tr><td class="k">Organisation</td><td class="v"><?php echo $esc($contract['company_name']); ?></td></tr>
    <?php endif; ?>
    <tr><td class="k">Service location</td><td class="v"><?php echo $propertyLine ?: '—'; ?></td></tr>
    <tr><td class="k">Provider</td><td class="v"><?php echo $esc($companyName); ?></td></tr>
</table>

<div class="section-title">Term &amp; Billing</div>
<table class="kv">
    <tr><td class="k">Start date</td><td class="v"><?php echo $dateOrDash($contract['start_date'] ?? null); ?></td></tr>
    <tr><td class="k">End date</td><td class="v"><?php echo $dateOrDash($contract['end_date'] ?? null); ?></td></tr>
    <?php if (!empty($contract['billing_amount'])): ?>
        <tr><td class="k">Service value</td>
            <td class="v"><?php echo $fmt($contract['billing_amount']); ?><?php echo $cycle ? ' / ' . $esc($cycle) : ''; ?></td></tr>
    <?php endif; ?>
    <?php if ($timing): ?>
        <tr><td class="k">Invoiced</td><td class="v"><?php echo $esc($timing); ?></td></tr>
    <?php endif; ?>
    <tr><td class="k">Auto-renew</td>
        <td class="v"><?php
            echo !empty($contract['auto_renew'])
                ? 'Yes' . (!empty($contract['renewal_date']) ? ' — renews ' . $dateOrDash($contract['renewal_date']) : '')
                : 'No — this agreement ends on the date above and does not renew automatically';
        ?></td></tr>
</table>

<?php if (!empty($contract['notes'])): ?>
    <div class="section-title">Notes</div>
    <div class="notes"><?php echo $esc($contract['notes']); ?></div>
<?php endif; ?>

<?php if ($terms && trim((string)$terms['body']) !== ''): ?>
    <div class="section-title">Terms &amp; Conditions</div>
    <div class="terms-body"><?php echo $esc($terms['body']); ?></div>
<?php endif; ?>

<div class="section-title">Agreement</div>

<?php if ($signature && !empty($signature['signature_data'])): ?>
    <!-- Already signed electronically — reproduce the captured signature. -->
    <div class="signature-section">
        <div style="font-weight:bold;margin-bottom:8px;">Signed electronically</div>
        <img src="<?php echo $signature['signature_data']; ?>" class="signature-img" alt="Signature">
        <div class="signature-meta">
            Signed by: <?php echo $esc($signature['signer_name'] ?? ''); ?><br>
            Date: <?php echo !empty($signature['signed_at']) ? date('F j, Y g:i A', strtotime((string)$signature['signed_at'])) : '—'; ?><br>
            IP: <?php echo $esc($signature['signed_ip'] ?? ''); ?>
            <?php if (!empty($signature['terms_acknowledged'])): ?>
                <br>Terms &amp; Conditions acknowledged at signing.
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Not yet signed — ruled lines so this can be printed, signed by hand
         and returned. Both parties sign: a wet copy has no server-side record
         of who sent it, so the provider's countersignature is what makes the
         returned page a complete document rather than a client assertion. -->
    <p style="font-size:9pt;color:#475569;">
        By signing below, the Client agrees to this service contract<?php echo $terms ? ' and to the Terms &amp; Conditions set out above' : ''; ?>.
    </p>
    <table style="width:100%;border-collapse:collapse;margin-top:14px;">
        <tr>
            <td style="width:48%;"><div class="sign-line"></div><div class="sign-label">Client signature</div></td>
            <td style="width:4%;"></td>
            <td style="width:48%;"><div class="sign-line"></div><div class="sign-label">Print name</div></td>
        </tr>
        <tr><td colspan="3" style="height:14px;"></td></tr>
        <tr>
            <td><div class="sign-line"></div><div class="sign-label">Date</div></td>
            <td></td>
            <td><div class="sign-line"></div><div class="sign-label">For <?php echo $esc($companyName); ?></div></td>
        </tr>
    </table>
<?php endif; ?>

<div class="footer">
    <?php echo $esc($contract['contract_number'] ?? ''); ?>
    &bull; version <?php echo (int)($contract['current_version'] ?? 1); ?>
    <?php if ($terms && !empty($terms['template_version'])): ?>
        &bull; Terms revision <?php echo (int)$terms['template_version']; ?><?php
            echo empty($terms['snapshot']) ? ' (current wording)' : ''; ?>
    <?php endif; ?>
    &bull; Generated <?php echo date('F j, Y'); ?>
    <br><?php echo $esc($companyName); ?><?php echo !empty($biz['company_phone']) ? ' &bull; ' . $esc($biz['company_phone']) : ''; ?>
</div>
