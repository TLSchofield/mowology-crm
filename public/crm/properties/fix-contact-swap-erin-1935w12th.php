<?php
/**
 * One-off: swap the principal/site contact for 1935 West 12th Avenue from
 * Reka Pataky to Erin Hope-Goldsmith, and update everything that stores a
 * standing reference to the old contact (property site contact, contract
 * CTR-2026-0016, and any still-open invoices/quotes whose recipient is the
 * old contact). Recurring visit notifications and quote/invoice creation
 * defaults resolve `properties.site_contact_id` live, so fixing the
 * property row is sufficient for those — they need no separate edit.
 *
 * GET  = dry-run report only, no writes.
 * POST = apply, gated by CSRF + confirmation.
 *
 * Delete this file after running it once.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

$db = getDB();

const SOURCE_INVOICE_NUMBER = 'INV-2026-0229';
const CONTRACT_NUMBER       = 'CTR-2026-0016';
const NEW_FIRST_NAME        = 'Erin';
const NEW_LAST_NAME         = 'Hope-Goldsmith';
const NEW_EMAIL             = 'e.hopegold@gmail.com';

function columnsOf(PDO $db, string $table): array {
    $cols = [];
    try {
        foreach ($db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[$c] = true;
        }
    } catch (Throwable $e) { /* table doesn't exist */ }
    return $cols;
}
function tableExists(PDO $db, string $table): bool {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Resolve the property from the source invoice ────────────────────────────
$stmt = $db->prepare("SELECT id, property_id FROM invoices WHERE invoice_number = ?");
$stmt->execute([SOURCE_INVOICE_NUMBER]);
$srcInvoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$srcInvoice || empty($srcInvoice['property_id'])) {
    http_response_code(404);
    die('Could not resolve a property from invoice ' . htmlspecialchars(SOURCE_INVOICE_NUMBER) . '.');
}
$propertyId = (int)$srcInvoice['property_id'];

$stmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
$stmt->execute([$propertyId]);
$property = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$property) {
    http_response_code(404);
    die('Property not found.');
}
$oldContactId = (int)($property['site_contact_id'] ?? 0);

$oldContact = null;
if ($oldContactId) {
    $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
    $stmt->execute([$oldContactId]);
    $oldContact = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Does Erin already exist as a contact? (idempotency — match by email) ────
$stmt = $db->prepare("SELECT * FROM contacts WHERE email = ? LIMIT 1");
$stmt->execute([NEW_EMAIL]);
$existingNewContact = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Contract lookup ──────────────────────────────────────────────────────────
$contract = null;
$contractBelongsToProperty = false;
if (tableExists($db, 'contracts')) {
    $stmt = $db->prepare("SELECT * FROM contracts WHERE contract_number = ?");
    $stmt->execute([CONTRACT_NUMBER]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($contract) {
        $contractBelongsToProperty = ((int)$contract['property_id'] === $propertyId);
    }
}

// ── Other open (non-final) invoices for this property still recipienting the old contact ──
$openInvoices = [];
if ($oldContactId) {
    $stmt = $db->prepare("
        SELECT i.id, i.invoice_number, i.status, ic.id AS invoice_contact_id, ic.contact_role, ic.email_address
        FROM invoices i
        JOIN invoice_contacts ic ON ic.invoice_id = i.id
        WHERE i.property_id = ?
          AND i.invoice_number != ?
          AND i.status NOT IN ('paid', 'cancelled')
          AND ic.contact_id = ?
    ");
    $stmt->execute([$propertyId, SOURCE_INVOICE_NUMBER, $oldContactId]);
    $openInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Other open quotes for this property pointing at the old contact (defensive — column may not exist) ──
$openQuotes = [];
$quoteCols = columnsOf($db, 'quotes');
if ($oldContactId && isset($quoteCols['contact_id'])) {
    $stmt = $db->prepare("
        SELECT id, quote_number, status
        FROM quotes
        WHERE property_id = ? AND contact_id = ? AND status NOT IN ('accepted', 'declined', 'expired')
    ");
    $stmt->execute([$propertyId, $oldContactId]);
    $openQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$clientsLive = tableExists($db, 'clients');
$clientContactsLive = tableExists($db, 'client_contacts');

$done = false;
$error = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            $db->beginTransaction();

            // 1. Find-or-create Erin's contact
            if ($existingNewContact) {
                $newContactId = (int)$existingNewContact['id'];
                $results[] = "Using existing contact #{$newContactId} for " . NEW_EMAIL;
            } else {
                require_once dirname(dirname(__DIR__), 2) . '/app/Modules/Contacts/Services/ContactService.php';
                $contactSvc = new ContactService($db);
                $newContactId = $contactSvc->createContact([
                    'first_name' => NEW_FIRST_NAME,
                    'last_name'  => NEW_LAST_NAME,
                    'email'      => NEW_EMAIL,
                ]);
                $results[] = "Created new contact #{$newContactId} (" . NEW_FIRST_NAME . ' ' . NEW_LAST_NAME . ')';
            }

            // 2. Property site contact
            $db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
               ->execute([$newContactId, $propertyId]);
            $results[] = "properties.id={$propertyId}: site_contact_id "
                . ($oldContactId ?: '(none)') . " → {$newContactId}";

            // 3. Contract
            if ($contract && $contractBelongsToProperty) {
                updateContract((int)$contract['id'], [
                    'title'                => $contract['title'],
                    'billing_cycle'        => $contract['billing_cycle'],
                    'billing_amount'       => $contract['billing_amount'],
                    'start_date'           => $contract['start_date'],
                    'end_date'             => $contract['end_date'],
                    'renewal_date'         => $contract['renewal_date'],
                    'auto_renew'           => $contract['auto_renew'],
                    'renewal_increase_pct' => $contract['renewal_increase_pct'],
                    'notes'                => $contract['notes'],
                    'contact_id'           => $newContactId,
                ], $user['id']);
                $results[] = CONTRACT_NUMBER . ": contact_id → {$newContactId}";
            } elseif ($contract && !$contractBelongsToProperty) {
                $results[] = 'SKIPPED ' . CONTRACT_NUMBER . ' — property_id does not match (' . $contract['property_id'] . " != {$propertyId}), left untouched.";
            }

            // 4. Open invoices' recipient rows
            foreach ($openInvoices as $inv) {
                $db->prepare("UPDATE invoice_contacts SET contact_id = ?, email_address = ? WHERE id = ?")
                   ->execute([$newContactId, NEW_EMAIL, $inv['invoice_contact_id']]);
                $results[] = "invoice {$inv['invoice_number']}: recipient → {$newContactId} (" . NEW_EMAIL . ')';
            }

            // 5. Open quotes
            foreach ($openQuotes as $q) {
                $db->prepare("UPDATE quotes SET contact_id = ? WHERE id = ?")
                   ->execute([$newContactId, $q['id']]);
                $results[] = "quote {$q['quote_number']}: contact_id → {$newContactId}";
            }

            logActivityExtended(
                $user['id'],
                'Contact updated',
                'Site contact for property #' . $propertyId . ' (1935 West 12th Ave) changed from '
                    . ($oldContact ? trim($oldContact['first_name'] . ' ' . $oldContact['last_name']) : '(none)')
                    . ' to ' . NEW_FIRST_NAME . ' ' . NEW_LAST_NAME . ' — one-off script, touched: '
                    . implode('; ', $results),
                null, null, null, null
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
<head><meta charset="utf-8"><title>Contact swap — 1935 West 12th Ave</title></head>
<body style="font-family: sans-serif; max-width: 800px; margin: 40px auto;">
<h2>Contact swap — 1935 West 12th Avenue</h2>

<?php if ($done): ?>
    <p style="color: green;">Done. Changes applied:</p>
    <ul><?php foreach ($results as $r) echo '<li>' . htmlspecialchars($r) . '</li>'; ?></ul>
    <p><a href="view.php?id=<?php echo $propertyId; ?>">View property</a></p>
    <p><strong>Delete this file from the server now.</strong></p>
<?php else: ?>
    <?php if ($error): ?><p style="color:red;"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>

    <h3>Current state (live, read-only)</h3>
    <p><strong>Property #<?php echo $propertyId; ?>:</strong> <?php echo htmlspecialchars($property['address'] ?? ''); ?></p>
    <p><strong>Current site contact:</strong>
        <?php echo $oldContact ? htmlspecialchars(trim($oldContact['first_name'] . ' ' . $oldContact['last_name']) . ' (#' . $oldContactId . ', ' . ($oldContact['email'] ?: 'no email') . ')') : '(none set)'; ?>
    </p>
    <p><strong>New contact:</strong>
        <?php echo $existingNewContact
            ? 'Already exists — #' . (int)$existingNewContact['id'] . ' (' . htmlspecialchars(NEW_EMAIL) . ')'
            : 'Will create: ' . htmlspecialchars(NEW_FIRST_NAME . ' ' . NEW_LAST_NAME . ' <' . NEW_EMAIL . '>'); ?>
    </p>

    <p><strong>Contract <?php echo htmlspecialchars(CONTRACT_NUMBER); ?>:</strong>
        <?php if (!$contract): ?>
            not found — nothing to update.
        <?php elseif (!$contractBelongsToProperty): ?>
            <span style="color:red;">found, but belongs to property_id=<?php echo (int)$contract['property_id']; ?>, NOT this property (<?php echo $propertyId; ?>) — will be SKIPPED.</span>
        <?php else: ?>
            found, currently contact_id=<?php echo (int)($contract['contact_id'] ?? 0); ?> — will be updated.
        <?php endif; ?>
    </p>

    <p><strong>Other open invoices for this property still pointing at the old contact:</strong>
        <?php if (empty($openInvoices)): ?>
            none.
        <?php else: ?>
            <ul><?php foreach ($openInvoices as $inv): ?>
                <li><?php echo htmlspecialchars($inv['invoice_number'] . ' (' . $inv['status'] . ', role=' . $inv['contact_role'] . ')'); ?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </p>

    <p><strong>Other open quotes for this property still pointing at the old contact:</strong>
        <?php if (!isset($quoteCols['contact_id'])): ?>
            (quotes table has no contact_id column on this environment — skipped.)
        <?php elseif (empty($openQuotes)): ?>
            none.
        <?php else: ?>
            <ul><?php foreach ($openQuotes as $q): ?>
                <li><?php echo htmlspecialchars($q['quote_number'] . ' (' . $q['status'] . ')'); ?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </p>

    <p><strong>clients / client_contacts tables (Phase 1 client-account migration):</strong>
        clients table <?php echo $clientsLive ? 'EXISTS' : 'does not exist yet'; ?>;
        client_contacts table <?php echo $clientContactsLive ? 'EXISTS' : 'does not exist yet'; ?>.
        <?php if ($clientsLive || $clientContactsLive): ?>
            <span style="color:red;">These are live but this script does NOT touch them — flag to Tim before proceeding, may need a manual follow-up.</span>
        <?php else: ?>
            Not live yet — nothing to do here.
        <?php endif; ?>
    </p>

    <p style="color:#666;">Recurring visit scheduling/notifications, invoice/quote creation defaults, the customer portal,
        and messaging all resolve <code>properties.site_contact_id</code> live at the time they run — they need no separate edit
        once the property row above is updated.</p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <button type="submit" style="padding: 10px 20px;">Confirm — apply all changes above</button>
    </form>
<?php endif; ?>
</body>
</html>
