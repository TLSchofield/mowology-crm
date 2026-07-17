<?php
/**
 * One-off correction: INV-2026-0229 was billed to the wrong contact name.
 * edit.php intentionally blocks bill_to_name changes once an invoice is
 * paid (see its header comment) — this script performs the same single
 * field update edit.php would, scoped ONLY to this one invoice number,
 * with the same activity_log + pdf_version reset side effects.
 *
 * Delete this file after running it once.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

$db = getDB();

const TARGET_INVOICE_NUMBER = 'INV-2026-0229';
const NEW_BILL_TO_NAME = 'Erin Hope-Goldsmith';

$stmt = $db->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
$stmt->execute([TARGET_INVOICE_NUMBER]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    die('Invoice ' . htmlspecialchars(TARGET_INVOICE_NUMBER) . ' not found.');
}

$currentBillTo = $invoice['bill_to_name'] ?: '(default — derived from contact/company)';
$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            $db->beginTransaction();

            $db->prepare("
                UPDATE invoices
                SET bill_to_name     = ?,
                    pdf_version      = 0,
                    pdf_generated_at = NULL,
                    updated_at       = NOW()
                WHERE id = ?
            ")->execute([NEW_BILL_TO_NAME, $invoice['id']]);

            logActivityExtended(
                $user['id'],
                'Invoice updated',
                'Invoice ' . $invoice['invoice_number'] . ' bill-to corrected — "'
                    . $currentBillTo . '" → "' . NEW_BILL_TO_NAME . '" (paid-invoice override, one-off script)',
                $invoice['company_id'] ?: null,
                null,
                null,
                $invoice['id'],
                $invoice['plan_id'] ?: null,
                $invoice['visit_id'] ?: null
            );

            $db->commit();
            $done = true;
        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Fix Bill-To — <?php echo htmlspecialchars(TARGET_INVOICE_NUMBER); ?></title></head>
<body style="font-family: sans-serif; max-width: 600px; margin: 40px auto;">
<h2>Correct Bill-To — <?php echo htmlspecialchars(TARGET_INVOICE_NUMBER); ?></h2>

<?php if ($done): ?>
    <p style="color: green;">Done. Bill-To is now "<?php echo htmlspecialchars(NEW_BILL_TO_NAME); ?>".</p>
    <p><a href="view.php?id=<?php echo (int)$invoice['id']; ?>">View invoice</a></p>
    <p><strong>Delete this file from the server now.</strong></p>
<?php else: ?>
    <?php if ($error): ?><p style="color:red;"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <p>Status: <strong><?php echo htmlspecialchars($invoice['status']); ?></strong></p>
    <p>Current Bill-To: <strong><?php echo htmlspecialchars($currentBillTo); ?></strong></p>
    <p>New Bill-To: <strong><?php echo htmlspecialchars(NEW_BILL_TO_NAME); ?></strong></p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <button type="submit" style="padding: 10px 20px;">Confirm correction</button>
    </form>
<?php endif; ?>
</body>
</html>
