<?php
/**
 * Combined Client Portal — Personal + Business accounts side by side.
 *
 * For clients like Lorne Folick who have both a personal contact record
 * and a linked company (e.g. Folick Holdings). Uses the same portal_token
 * as the regular portal — the company link is detected automatically.
 *
 * If no company link is found, redirects to the regular portal.
 *
 * Access modes:
 *   Client  — ?token=XXXX          (contacts.portal_token)
 *   Admin   — ?contact_id=N        (requires CRM session)
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db        = getDB();
$contact   = null;
$adminMode = false;
$error     = '';

if (isset($_GET['contact_id'])) {
    require_once dirname(__DIR__) . '/loginAuth/auth.php';
    requireLogin();
    $adminMode = true;
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token,
                                 stripe_card_brand, stripe_card_last4, stripe_card_exp, autopay_enabled
                          FROM contacts WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['contact_id']]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) $error = 'Contact not found.';

} elseif (!empty($_GET['token'])) {
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token,
                                 stripe_card_brand, stripe_card_last4, stripe_card_exp, autopay_enabled
                          FROM contacts WHERE portal_token = ? LIMIT 1");
    $stmt->execute([trim($_GET['token'])]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) $error = 'This portal link is invalid or has expired.';
} else {
    $error = 'No portal link provided.';
}

// ── Detect linked company ────────────────────────────────────────────────────
$company = null;
if ($contact && !$error) {
    $stmt = $db->prepare("
        SELECT id, company_name,
               stripe_card_brand, stripe_card_last4, stripe_card_exp, autopay_enabled,
               billing_address, billing_city, billing_province, billing_postal_code
        FROM companies
        WHERE primary_contact_id = ? OR billing_contact_id = ?
        LIMIT 1
    ");
    $stmt->execute([$contact['id'], $contact['id']]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // If no company link, redirect to the regular portal
    if (!$company) {
        $dest = $adminMode
            ? '/customer/portal.php?contact_id=' . $contact['id']
            : '/customer/portal.php?token=' . urlencode($contact['portal_token'] ?? '');
        header('Location: ' . $dest);
        exit;
    }
}

// ── Load PERSONAL invoices (contact_id match, not linked to the company) ────
$personalInvoices = [];
$personalBalance  = 0.0;

// ── Load BUSINESS invoices (company_id match) ────────────────────────────────
$businessInvoices = [];
$businessBalance  = 0.0;

if ($contact && $company && !$error) {
    $cid = $contact['id'];
    $oid = $company['id'];

    $stmt = $db->prepare("
        SELECT i.id, i.invoice_number, i.status, i.total AS total_amount,
               i.amount_paid, i.balance_due, i.access_token, i.created_at, i.due_date,
               i.company_id
        FROM invoices i
        JOIN invoice_contacts ic ON ic.invoice_id = i.id
        WHERE ic.contact_id = ?
          AND (i.company_id IS NULL OR i.company_id != ?)
          AND i.access_token IS NOT NULL AND i.access_token != ''
          AND i.status NOT IN ('draft', 'cancelled')
        GROUP BY i.id
        ORDER BY i.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$cid, $oid]);
    $personalInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($personalInvoices as $inv) {
        if (in_array($inv['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
            $personalBalance += floatval($inv['balance_due'] ?? 0);
        }
    }

    $stmt = $db->prepare("
        SELECT i.id, i.invoice_number, i.status, i.total AS total_amount,
               i.amount_paid, i.balance_due, i.access_token, i.created_at, i.due_date
        FROM invoices i
        WHERE i.company_id = ?
          AND i.access_token IS NOT NULL AND i.access_token != ''
          AND i.status NOT IN ('draft', 'cancelled')
        ORDER BY i.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$oid]);
    $businessInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($businessInvoices as $inv) {
        if (in_array($inv['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
            $businessBalance += floatval($inv['balance_due'] ?? 0);
        }
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function portalStatusBadge(string $status): string {
    $map = [
        'paid'    => ['Paid',     '#2D8659', '#e8f3f0'],
        'partial' => ['Partial',  '#c9780c', '#fff7ed'],
        'overdue' => ['Overdue',  '#dc2626', '#fef2f2'],
        'sent'    => ['Sent',     '#1d4ed8', '#eff6ff'],
        'viewed'  => ['Viewed',   '#1d4ed8', '#eff6ff'],
    ];
    $s = strtolower($status);
    [$label, $color, $bg] = $map[$s] ?? [ucfirst($status), '#6b7280', '#f3f4f6'];
    return "<span style=\"display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;letter-spacing:.3px;background:{$bg};color:{$color}\">{$label}</span>";
}

function portalFmtDate(string $d): string {
    $ts = strtotime($d);
    return $ts ? date('M j, Y', $ts) : '—';
}

function portalFmtMoney(float $n): string {
    return '$' . number_format($n, 2);
}

$baseUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$firstName  = $contact ? htmlspecialchars($contact['first_name']) : 'there';
$fullName   = $contact ? htmlspecialchars(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) : '';
$companyName = $company ? htmlspecialchars($company['company_name']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Accounts — Mowology</title>
<link rel="stylesheet" href="/customer/portal.css">
<style>
/* Combined portal — two-column split layout */
.cp-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    max-width: 1100px;
    margin: 0 auto;
    padding: 32px 24px 60px;
}
@media (max-width: 768px) {
    .cp-grid { grid-template-columns: 1fr; gap: 16px; padding: 20px 16px 48px; }
}

.cp-panel {
    background: var(--p-bg);
    border-radius: var(--p-radius-lg);
    box-shadow: var(--p-shadow);
    border: 1px solid var(--p-border);
    overflow: hidden;
}

.cp-panel-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--p-border);
}
.cp-panel-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.cp-panel-badge.personal {
    background: #eff6ff;
    color: #1d4ed8;
}
.cp-panel-badge.business {
    background: #e8f3f0;
    color: var(--p-dark);
}
.cp-panel-name {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--p-text);
    margin-bottom: 2px;
}
.cp-panel-sub {
    font-size: 13px;
    color: var(--p-text-light);
}

.cp-card-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 24px;
    background: var(--p-bg-subtle);
    border-bottom: 1px solid var(--p-border);
}
.cp-card-icon {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: var(--p-bg);
    border: 1px solid var(--p-border-mid);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cp-card-icon svg { width: 16px; height: 16px; stroke: var(--p-green); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.cp-card-text { flex: 1; }
.cp-card-label { font-size: 11px; color: var(--p-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.cp-card-value { font-size: 13px; color: var(--p-text-body); font-weight: 500; }
.cp-card-autopay {
    font-size: 11px;
    font-weight: 600;
    color: var(--p-green);
    background: #e8f3f0;
    padding: 2px 7px;
    border-radius: 10px;
    flex-shrink: 0;
}

.cp-balance {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 24px;
    border-bottom: 1px solid var(--p-border);
}
.cp-balance-label { font-size: 12px; color: var(--p-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.cp-balance-amount { font-size: 1.25rem; font-weight: 700; }
.cp-balance-amount.outstanding { color: #c9780c; }
.cp-balance-amount.clear { color: var(--p-green); }

.cp-inv-table { width: 100%; border-collapse: collapse; }
.cp-inv-table th {
    font-size: 11px;
    font-weight: 600;
    color: var(--p-text-muted);
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 10px 24px;
    border-bottom: 1px solid var(--p-border);
    text-align: left;
    background: var(--p-bg-gray);
}
.cp-inv-table td {
    padding: 11px 24px;
    font-size: 13px;
    border-bottom: 1px solid var(--p-border);
    color: var(--p-text-body);
    vertical-align: middle;
}
.cp-inv-table tr:last-child td { border-bottom: none; }
.cp-inv-table tr:hover td { background: var(--p-bg-subtle); }
.cp-inv-number { font-weight: 600; color: var(--p-green); }
.cp-inv-amount { font-weight: 600; text-align: right; }
.cp-inv-empty {
    padding: 28px 24px;
    text-align: center;
    color: var(--p-text-muted);
    font-size: 13px;
}

.cp-divider-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--p-text-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 8px 24px 0;
    display: block;
    background: var(--p-bg-gray);
    border-bottom: 1px solid var(--p-border);
    padding-bottom: 8px;
}

/* Page intro */
.cp-intro {
    max-width: 1100px;
    margin: 0 auto;
    padding: 28px 24px 0;
}
.cp-intro h1 { font-size: 1.35rem; font-weight: 700; color: var(--p-text); margin-bottom: 4px; }
.cp-intro p  { font-size: 14px; color: var(--p-text-mid); }
</style>
</head>
<body>

<!-- Portal Header -->
<header class="portal-header">
    <span class="portal-logo-text">Mowology</span>
    <span style="flex:1;"></span>
    <span style="font-size:13px;color:var(--p-text-mid);font-weight:500;"><?= $fullName ?></span>
</header>

<?php if ($error): ?>
<div style="max-width:520px;margin:60px auto;text-align:center;padding:0 24px;">
    <p style="color:#dc2626;font-size:15px;"><?= htmlspecialchars($error) ?></p>
</div>
<?php else: ?>

<div class="cp-intro">
    <h1>Hi <?= $firstName ?>, here are your accounts.</h1>
    <p>Your personal and business accounts are kept completely separate — different cards, different invoices.</p>
</div>

<div class="cp-grid">

    <!-- ── Personal Panel ───────────────────────────────────────────── -->
    <div class="cp-panel">
        <div class="cp-panel-header">
            <div class="cp-panel-badge personal">Personal</div>
            <div class="cp-panel-name"><?= $fullName ?></div>
            <?php if ($contact['email']): ?>
                <div class="cp-panel-sub"><?= htmlspecialchars($contact['email']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Personal card on file -->
        <div class="cp-card-row">
            <div class="cp-card-icon">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="cp-card-text">
                <div class="cp-card-label">Card on file</div>
                <?php if (!empty($contact['stripe_card_last4'])): ?>
                    <div class="cp-card-value">
                        <?= htmlspecialchars(ucfirst($contact['stripe_card_brand'] ?? 'Card')) ?>
                        &nbsp;···· <?= htmlspecialchars($contact['stripe_card_last4']) ?>
                        &nbsp;<span style="color:var(--p-text-muted);font-size:12px;">exp <?= htmlspecialchars($contact['stripe_card_exp'] ?? '') ?></span>
                    </div>
                <?php else: ?>
                    <div class="cp-card-value" style="color:var(--p-text-muted);">No card on file</div>
                <?php endif; ?>
            </div>
            <?php if (!empty($contact['autopay_enabled'])): ?>
                <span class="cp-card-autopay">Autopay On</span>
            <?php endif; ?>
        </div>

        <!-- Personal balance -->
        <div class="cp-balance">
            <span class="cp-balance-label">Outstanding balance</span>
            <span class="cp-balance-amount <?= $personalBalance > 0 ? 'outstanding' : 'clear' ?>">
                <?= portalFmtMoney($personalBalance) ?>
            </span>
        </div>

        <!-- Personal invoices -->
        <?php if (empty($personalInvoices)): ?>
            <div class="cp-inv-empty">No personal invoices yet.</div>
        <?php else: ?>
            <span class="cp-divider-label">Invoices</span>
            <table class="cp-inv-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($personalInvoices as $inv): ?>
                        <tr>
                            <td>
                                <a class="cp-inv-number" href="<?= $baseUrl ?>/customer/invoice.php?token=<?= urlencode($inv['access_token']) ?>">
                                    <?= htmlspecialchars($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td style="color:var(--p-text-muted);"><?= portalFmtDate($inv['created_at']) ?></td>
                            <td><?= portalStatusBadge($inv['status']) ?></td>
                            <td class="cp-inv-amount"><?= portalFmtMoney(floatval($inv['total_amount'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ── Business Panel ───────────────────────────────────────────── -->
    <div class="cp-panel">
        <div class="cp-panel-header">
            <div class="cp-panel-badge business">Business</div>
            <div class="cp-panel-name"><?= $companyName ?></div>
            <?php
            $bizAddr = array_filter([
                $company['billing_address'] ?? null,
                $company['billing_city'] ?? null,
            ]);
            if ($bizAddr): ?>
                <div class="cp-panel-sub"><?= htmlspecialchars(implode(', ', $bizAddr)) ?></div>
            <?php endif; ?>
        </div>

        <!-- Business card on file -->
        <div class="cp-card-row">
            <div class="cp-card-icon">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="cp-card-text">
                <div class="cp-card-label">Card on file</div>
                <?php if (!empty($company['stripe_card_last4'])): ?>
                    <div class="cp-card-value">
                        <?= htmlspecialchars(ucfirst($company['stripe_card_brand'] ?? 'Card')) ?>
                        &nbsp;···· <?= htmlspecialchars($company['stripe_card_last4']) ?>
                        &nbsp;<span style="color:var(--p-text-muted);font-size:12px;">exp <?= htmlspecialchars($company['stripe_card_exp'] ?? '') ?></span>
                    </div>
                <?php else: ?>
                    <div class="cp-card-value" style="color:var(--p-text-muted);">No card on file</div>
                <?php endif; ?>
            </div>
            <?php if (!empty($company['autopay_enabled'])): ?>
                <span class="cp-card-autopay">Autopay On</span>
            <?php endif; ?>
        </div>

        <!-- Business balance -->
        <div class="cp-balance">
            <span class="cp-balance-label">Outstanding balance</span>
            <span class="cp-balance-amount <?= $businessBalance > 0 ? 'outstanding' : 'clear' ?>">
                <?= portalFmtMoney($businessBalance) ?>
            </span>
        </div>

        <!-- Business invoices -->
        <?php if (empty($businessInvoices)): ?>
            <div class="cp-inv-empty">No business invoices yet.</div>
        <?php else: ?>
            <span class="cp-divider-label">Invoices</span>
            <table class="cp-inv-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($businessInvoices as $inv): ?>
                        <tr>
                            <td>
                                <a class="cp-inv-number" href="<?= $baseUrl ?>/customer/invoice.php?token=<?= urlencode($inv['access_token']) ?>">
                                    <?= htmlspecialchars($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td style="color:var(--p-text-muted);"><?= portalFmtDate($inv['created_at']) ?></td>
                            <td><?= portalStatusBadge($inv['status']) ?></td>
                            <td class="cp-inv-amount"><?= portalFmtMoney(floatval($inv['total_amount'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div><!-- .cp-grid -->

<?php endif; ?>

</body>
</html>
