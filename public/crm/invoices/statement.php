<?php
/**
 * Client Statement of Account — preview, PDF download, and email.
 * AppStack page. Actions: view (default) | pdf | email.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

$db        = getDB();
$contactId = (int)($_GET['contact_id'] ?? 0);
$period    = $_GET['period'] ?? 'all_outstanding';
$action    = $_GET['action'] ?? 'view';
if (!$contactId) { header('Location: /crm/clients_appstack.php'); exit; }

// Resolve the period preset to a date range.
$from = null; $to = null; $allOut = false;
switch ($period) {
    case 'this_month': $from = date('Y-m-01'); $to = date('Y-m-d'); break;
    case 'last_month': $from = date('Y-m-01', strtotime('first day of last month'));
                       $to   = date('Y-m-t',  strtotime('last day of last month')); break;
    case 'custom':     $from = !empty($_GET['from']) ? $_GET['from'] : null;
                       $to   = !empty($_GET['to'])   ? $_GET['to']   : null; break;
    case 'all_outstanding':
    default:           $allOut = true; $period = 'all_outstanding'; break;
}

require_once APP_ROOT . '/Modules/Invoices/Services/StatementService.php';
try {
    $data = (new StatementService($db))->getStatementData($contactId, $from, $to, $allOut);
} catch (\Throwable $e) {
    http_response_code(404);
    die('Could not build statement: ' . h($e->getMessage()));
}

$contact    = $data['contact'];
$ledger     = $data['ledger'];
$recipient  = $data['recipient'];
$clientName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));

// ── PDF download / email (must run before any HTML output) ──────────────────
if ($action === 'pdf' || $action === 'email') {
    require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
    require_once dirname(__DIR__) . '/includes/PdfGenerator.php';
    $gen = new PdfGenerator();
    $pdf = $gen->generateStatementPdf($data);
    if (empty($pdf['success'])) { http_response_code(500); die('PDF generation failed: ' . h($pdf['error'] ?? '')); }

    if ($action === 'pdf') {
        $gen->streamPdf($pdf['path'], $pdf['filename']);
        exit;
    }

    // action === email
    $back = '/crm/clients_appstack.php?action=view_contact&id=' . $contactId;
    if (empty($recipient['email'])) { header('Location: ' . $back . '&statement=noemail'); exit; }

    require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
    $closing = (float)$ledger['closing'];
    $first   = $contact['first_name'] ?: ($recipient['name'] ?: 'there');
    $bodyHtml =
        '<p>Hi ' . h($first) . ',</p>' .
        '<p>Please find attached your statement of account (' . h($data['period']['label']) . ').</p>' .
        '<p style="font-size:16px;"><strong>Balance ' . ($closing > 0 ? 'due' : '') . ': $' . number_format($closing, 2) . '</strong></p>' .
        ($closing > 0 ? '<p>Payment details are on the attached statement. If you\'ve already paid, please disregard.</p>' : '') .
        '<p>Thank you for your business.</p>';
    $html = EmailWrapper::wrap($bodyHtml, null, null, EmailWrapper::getCompanyInfo());

    $res = sendEmail($recipient['email'], 'Your Statement of Account', $html, $pdf['path']);
    $ok  = is_array($res) ? !empty($res['success']) : (bool)$res;

    try {
        logActivityExtended($user['id'], 'Statement sent',
            'Statement emailed to ' . $recipient['email'] . ' (' . $data['period']['label'] . ')',
            $contactId, null, null, null);
    } catch (\Throwable $e) { /* activity log is best-effort */ }

    header('Location: ' . $back . '&statement=' . ($ok ? 'sent' : 'failed'));
    exit;
}

// ── Preview page ────────────────────────────────────────────────────────────
$fmt        = static fn($n) => '$' . number_format((float)$n, 2);
$pageTitle  = 'Statement — ' . $clientName;
$activePage = 'clients';
$qs = static fn($p) => '/crm/invoices/statement.php?contact_id=' . $contactId . '&period=' . $p
    . ($p === 'custom' && $from ? '&from=' . urlencode($from) . '&to=' . urlencode((string)$to) : '');
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Statement of Account</h1>
        <p class="text-muted mb-0 small"><?= h($clientName) ?> &middot; <?= h($data['period']['label']) ?></p>
    </div>
    <a href="/crm/clients_appstack.php?action=view_contact&id=<?= $contactId ?>" class="btn btn-sm btn-outline-secondary">← Back to client</a>
</div>

<div class="card mw-card mb-3"><div class="card-body">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
        <input type="hidden" name="contact_id" value="<?= $contactId ?>">
        <div class="btn-group btn-group-sm" role="group">
            <?php foreach (['all_outstanding'=>'All outstanding','this_month'=>'This month','last_month'=>'Last month'] as $k=>$lbl): ?>
                <a href="<?= $qs($k) ?>" class="btn <?= $period===$k ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
        <div class="d-flex gap-1 align-items-end ms-2">
            <div><label class="form-label small mb-0">From</label>
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#stmt-date-from"
                        data-mw-dp-range-group="stmt-range" data-mw-dp-range-role="start" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="stmt-date-from" name="from" value="<?= h($from) ?>" class="form-control form-control-sm" hidden></div>
            <div><label class="form-label small mb-0">To</label>
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#stmt-date-to"
                        data-mw-dp-range-group="stmt-range" data-mw-dp-range-role="end" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="stmt-date-to" name="to" value="<?= h($to) ?>" class="form-control form-control-sm" hidden></div>
            <input type="hidden" name="period" value="custom">
            <button class="btn btn-sm btn-outline-primary">Custom range</button>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="<?= $qs($period) ?>&action=pdf" class="btn btn-sm btn-outline-secondary">⬇ Download PDF</a>
            <?php if (!empty($recipient['email'])): ?>
                <a href="<?= $qs($period) ?>&action=email"
                   onclick="return confirm('Email this statement to <?= h($recipient['email']) ?>?');"
                   class="btn btn-sm btn-primary">✉ Email to <?= h($recipient['email']) ?></a>
            <?php else: ?>
                <span class="btn btn-sm btn-outline-secondary disabled" title="No email on file">✉ No email on file</span>
            <?php endif; ?>
        </div>
    </form>
</div></div>

<div class="card mw-card"><div class="card-body p-0">
    <table class="table table-sm mb-0">
        <thead><tr>
            <th>Date</th><th>Description</th>
            <th class="text-end">Charge</th><th class="text-end">Payment</th><th class="text-end">Balance</th>
        </tr></thead>
        <tbody>
            <tr class="table-light fw-semibold">
                <td><?= $from ? date('M j, Y', strtotime($from)) : '' ?></td>
                <td>Opening balance</td><td></td><td></td>
                <td class="text-end"><?= $fmt($ledger['opening']) ?></td>
            </tr>
            <?php if (empty($ledger['rows'])): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No activity in this period.</td></tr>
            <?php else: foreach ($ledger['rows'] as $r): ?>
                <tr>
                    <td><?= $r['date'] ? date('M j, Y', strtotime($r['date'])) : '' ?></td>
                    <td>
                        <?php if (!empty($r['invoice_id']) && $r['type']==='charge'): ?>
                            <a href="/crm/invoices/view.php?id=<?= (int)$r['invoice_id'] ?>"><?= h($r['desc']) ?></a>
                        <?php else: ?><?= h($r['desc']) ?><?php endif; ?>
                    </td>
                    <td class="text-end"><?= $r['charge']  > 0 ? $fmt($r['charge'])  : '' ?></td>
                    <td class="text-end text-success"><?= $r['payment'] > 0 ? '('.$fmt($r['payment']).')' : '' ?></td>
                    <td class="text-end"><?= $fmt($r['balance']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr class="fw-bold border-top">
                <td colspan="4" class="text-end"><?= $ledger['closing'] > 0 ? 'Balance Due' : 'Balance' ?></td>
                <td class="text-end"><?= $fmt($ledger['closing']) ?></td>
            </tr>
        </tfoot>
    </table>
</div></div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
