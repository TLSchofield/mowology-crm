<?php
/**
 * Invoice Email Open Tracking — 1x1 Transparent PNG
 *
 * Embedded in invoice emails as:
 *   <img src="https://mowology.ca/crm/api/track-invoice-open.php?rid=INVOICE_CONTACTS_ID">
 *
 * Updates invoice_contacts.invoice_opened_at on first load per recipient,
 * and sets invoices.email_opened_at for the first-ever open across all recipients.
 *
 * Stateless — just UPDATE + serve transparent pixel.
 */

$rid = isset($_GET['rid']) ? intval($_GET['rid']) : 0;

if ($rid > 0) {
    try {
        // Minimal bootstrap — just need DB
        $__dir = __DIR__;
        for ($__i = 0; $__i < 5; $__i++) {
            $__dir = dirname($__dir);
            if (is_file($__dir . '/app/Core/paths.php')) {
                require_once $__dir . '/app/Core/paths.php';
                break;
            }
        }
        unset($__dir, $__i);

        if (defined('APP_ROOT')) {
            require_once APP_ROOT . '/Core/config.php';
        }

        $db = getDB();

        // Get the invoice_contacts row + invoice_id
        $stmt = $db->prepare("
            SELECT ic.id, ic.invoice_id, ic.invoice_opened_at, ic.contact_id,
                   COALESCE(c.first_name, '') as first_name,
                   COALESCE(c.last_name, '') as last_name
            FROM invoice_contacts ic
            LEFT JOIN contacts c ON ic.contact_id = c.id
            WHERE ic.id = ?
            LIMIT 1
        ");
        $stmt->execute([$rid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Update per-recipient open timestamp (first open only)
            if (empty($row['invoice_opened_at'])) {
                $db->prepare("
                    UPDATE invoice_contacts SET invoice_opened_at = NOW() WHERE id = ?
                ")->execute([$rid]);
            }

            // Update invoice-level email_opened_at (first open across all recipients)
            $db->prepare("
                UPDATE invoices
                SET email_opened_at = COALESCE(email_opened_at, NOW())
                WHERE id = ?
            ")->execute([$row['invoice_id']]);

            // Log to activity_log (only on first open per recipient)
            if (empty($row['invoice_opened_at'])) {
                $contactName = trim($row['first_name'] . ' ' . $row['last_name']) ?: 'Unknown contact';
                $db->prepare("
                    INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
                    VALUES (NULL, 'Invoice email opened', ?, ?, NOW())
                ")->execute([
                    "Email opened by {$contactName}",
                    $row['invoice_id']
                ]);
            }
        }

    } catch (\Throwable $e) {
        // Silent failure — don't break the image
        error_log('track-invoice-open error: ' . $e->getMessage());
    }
}

// Serve 1x1 transparent PNG
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Minimal 1x1 transparent PNG (67 bytes)
echo base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
);
