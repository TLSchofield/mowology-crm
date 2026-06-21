<?php
/**
 * Statement of Account PDF template — rendered by PdfGenerator::generateStatementPdf().
 * Variables: $statement (StatementService::getStatementData output), $business (array)
 * Outputs HTML for mPDF — NOT an AppStack page.
 */
$fmt = static fn($n) => '$' . number_format((float)$n, 2);
$esc = static fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

$business    = is_array($business ?? null) ? $business : [];
$bizName     = trim((string)($business['company_name']    ?? 'Mowology'));
$bizPhone    = trim((string)($business['company_phone']   ?? '(778) 846-9273'));
$bizEmail    = trim((string)($business['company_email']   ?? 'office@mowology.ca'));
$bizWebsite  = trim((string)($business['company_website'] ?? 'mowology.ca'));
$bizAddress  = trim((string)($business['company_address'] ?? 'Vancouver, BC'));
$bizGst      = trim((string)($business['gst_registration'] ?? ''));
$bizTagline  = trim((string)($business['company_tagline']  ?? ''));
$bizPayInstr = trim((string)($business['invoice_payment_instructions'] ?? ''));

$contact = $statement['contact'] ?? [];
$ledger  = $statement['ledger']  ?? ['opening'=>0,'rows'=>[],'total_charged'=>0,'total_paid'=>0,'closing'=>0];
$period  = $statement['period']  ?? ['label'=>''];

$clientName = $contact['company_name'] ?: trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
$clientName = $clientName ?: 'Customer';
$addr = trim((string)($contact['billing_address'] ?? ''));
if (!empty($contact['billing_city']))     $addr .= ($addr ? ', ' : '') . $contact['billing_city'];
if (!empty($contact['billing_province'])) $addr .= ' ' . $contact['billing_province'];
if (!empty($contact['billing_postal_code'])) $addr .= ' ' . $contact['billing_postal_code'];

$genDate = date('F j, Y', strtotime($statement['generated_at'] ?? 'now'));
$closing = (float)$ledger['closing'];
?>
<style>
    body { font-family: Helvetica, Arial, sans-serif; color:#1a1a1a; font-size:10pt; line-height:1.5; }
    .brand-name { font-size:17pt; font-weight:bold; color:#2D8659; letter-spacing:-0.3px; }
    .brand-tagline { font-size:9pt; font-style:italic; color:#7FD858; margin:2px 0 6px; }
    .brand-info { font-size:8.5pt; color:#64748b; line-height:1.6; }
    .doc-title { font-size:15pt; font-weight:bold; color:#0D3B2E; letter-spacing:0.5px; }
    .meta { font-size:9pt; color:#64748b; }
    .billto-label { font-size:7.5pt; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
    .billto-name { font-size:11pt; font-weight:bold; color:#1a1a1a; }
    table.ledger { width:100%; border-collapse:collapse; margin-top:14px; font-size:9pt; }
    table.ledger thead th { background:#2D8659; color:#fff; text-align:left; padding:6px 8px; font-size:8.5pt; }
    table.ledger thead th.num { text-align:right; }
    table.ledger tbody td { padding:5px 8px; border-bottom:1px solid #e5e7eb; }
    table.ledger tbody td.num { text-align:right; }
    table.ledger tr.opening td { background:#E8F3F0; font-weight:bold; color:#0D3B2E; }
    table.ledger tr.pay td { color:#2D8659; }
    .totals { width:46%; margin-left:54%; margin-top:14px; font-size:9.5pt; border-collapse:collapse; }
    .totals td { padding:5px 8px; }
    .totals tr.due td { background:#0D3B2E; color:#fff; font-weight:bold; font-size:11pt; }
    .totals td.num { text-align:right; }
    .payinstr { margin-top:18px; background:#E8F3F0; border-left:3px solid #2D8659; padding:8px 12px; font-size:8.5pt; color:#0D3B2E; }
    .footer { margin-top:22px; padding-top:8px; border-top:1px solid #e5e7eb; font-size:7.5pt; color:#94a3b8; text-align:center; }
</style>

<table class="header-table" width="100%"><tr>
    <td width="60%" valign="top">
        <div class="brand-name"><?= $esc($bizName) ?></div>
        <?php if ($bizTagline): ?><div class="brand-tagline"><?= $esc($bizTagline) ?></div><?php endif; ?>
        <div class="brand-info">
            <?= $esc($bizAddress) ?><br>
            <?= $esc($bizPhone) ?> &middot; <?= $esc($bizEmail) ?> &middot; <?= $esc($bizWebsite) ?>
            <?php if ($bizGst): ?><br>GST: <?= $esc($bizGst) ?><?php endif; ?>
        </div>
    </td>
    <td width="40%" valign="top" align="right">
        <div class="doc-title">STATEMENT OF ACCOUNT</div>
        <div class="meta" style="margin-top:6px;">
            Statement date: <strong><?= $esc($genDate) ?></strong><br>
            Period: <strong><?= $esc($period['label'] ?? '') ?></strong>
        </div>
    </td>
</tr></table>

<table width="100%" style="margin-top:6px;"><tr><td valign="top">
    <div class="billto-label">Statement for</div>
    <div class="billto-name"><?= $esc($clientName) ?></div>
    <?php if ($addr): ?><div class="brand-info"><?= $esc($addr) ?></div><?php endif; ?>
    <?php if (!empty($contact['email'])): ?><div class="brand-info"><?= $esc($contact['email']) ?></div><?php endif; ?>
</td></tr></table>

<table class="ledger">
    <thead><tr>
        <th width="14%">Date</th>
        <th width="46%">Description</th>
        <th width="13%" class="num">Charge</th>
        <th width="13%" class="num">Payment</th>
        <th width="14%" class="num">Balance</th>
    </tr></thead>
    <tbody>
        <tr class="opening">
            <td><?= $esc($period['from'] ? date('M j, Y', strtotime($period['from'])) : '') ?></td>
            <td>Opening balance</td>
            <td class="num"></td><td class="num"></td>
            <td class="num"><?= $fmt($ledger['opening']) ?></td>
        </tr>
        <?php if (empty($ledger['rows'])): ?>
            <tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:14px;">No activity in this period.</td></tr>
        <?php else: foreach ($ledger['rows'] as $r): ?>
            <tr class="<?= $r['type'] === 'payment' ? 'pay' : '' ?>">
                <td><?= $esc($r['date'] ? date('M j, Y', strtotime($r['date'])) : '') ?></td>
                <td><?= $esc($r['desc']) ?></td>
                <td class="num"><?= $r['charge']  > 0 ? $fmt($r['charge'])  : '' ?></td>
                <td class="num"><?= $r['payment'] > 0 ? '(' . $fmt($r['payment']) . ')' : '' ?></td>
                <td class="num"><?= $fmt($r['balance']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
</table>

<table class="totals">
    <tr><td>Opening balance</td><td class="num"><?= $fmt($ledger['opening']) ?></td></tr>
    <tr><td>Charges this period</td><td class="num"><?= $fmt($ledger['total_charged']) ?></td></tr>
    <tr><td>Payments this period</td><td class="num">(<?= $fmt($ledger['total_paid']) ?>)</td></tr>
    <tr class="due"><td><?= $closing > 0 ? 'Balance Due' : 'Balance' ?></td><td class="num"><?= $fmt($closing) ?></td></tr>
</table>

<?php if ($closing > 0 && $bizPayInstr): ?>
    <div class="payinstr"><strong>Payment:</strong> <?= $esc($bizPayInstr) ?></div>
<?php endif; ?>

<div class="footer">
    <?= $esc($bizName) ?> &middot; <?= $esc($bizPhone) ?> &middot; <?= $esc($bizEmail) ?> &middot; <?= $esc($bizWebsite) ?>
</div>
