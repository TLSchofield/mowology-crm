<?php
/**
 * Run Migration 1043 — Data fix: clear invalid phone/mobile values from contacts
 *
 * Contacts where phone or mobile contains a URL, an email address, or is
 * otherwise clearly not a phone number are corrected (field set to NULL/empty).
 * This is a one-time data hygiene pass — safe to run multiple times.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
requirePermission('admin');

$db = getDB();
header('Content-Type: text/plain');

$fixed = [];

try {
    // Fetch all contacts that have something in phone or mobile
    $stmt = $db->query("SELECT id, first_name, last_name, phone, mobile FROM contacts WHERE phone != '' OR mobile != ''");
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $urlPattern = '/https?:\/\/|ftp:\/\/|www\.|\.php|\.html?|\.com|\.ca|\.org|\.net/i';

    foreach ($contacts as $c) {
        $newPhone  = null;
        $newMobile = null;
        $changed   = false;

        // Check phone
        if (!empty($c['phone'])) {
            $val = trim($c['phone']);
            $isInvalid = preg_match($urlPattern, $val)
                      || strpos($val, '@') !== false
                      || strlen($val) > 20
                      || strlen(preg_replace('/\D/', '', $val)) < 7;
            if ($isInvalid) {
                $newPhone = '';
                $changed = true;
            }
        }

        // Check mobile
        if (!empty($c['mobile'])) {
            $val = trim($c['mobile']);
            $isInvalid = preg_match($urlPattern, $val)
                      || strpos($val, '@') !== false
                      || strlen($val) > 20
                      || strlen(preg_replace('/\D/', '', $val)) < 7;
            if ($isInvalid) {
                $newMobile = '';
                $changed = true;
            }
        }

        if ($changed) {
            $updates = [];
            $params  = [];

            if ($newPhone !== null) {
                $updates[] = 'phone = ?';
                $params[]  = $newPhone;
            }
            if ($newMobile !== null) {
                $updates[] = 'mobile = ?';
                $params[]  = $newMobile;
            }
            $params[] = $c['id'];

            $db->prepare("UPDATE contacts SET " . implode(', ', $updates) . " WHERE id = ?")
               ->execute($params);

            $name = trim($c['first_name'] . ' ' . $c['last_name']);
            $details = [];
            if ($newPhone !== null)  $details[] = 'phone was: ' . substr($c['phone'], 0, 60);
            if ($newMobile !== null) $details[] = 'mobile was: ' . substr($c['mobile'], 0, 60);
            $fixed[] = "Contact #{$c['id']} {$name} — " . implode('; ', $details);
        }
    }

    echo "Migration 1043 complete.\n\n";
    if ($fixed) {
        echo count($fixed) . " contact(s) had invalid phone data cleared:\n\n";
        foreach ($fixed as $f) echo "  • {$f}\n";
    } else {
        echo "  No invalid phone values found — all contacts are clean.\n";
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
    if ($fixed) {
        echo "\nContacts fixed before failure:\n";
        foreach ($fixed as $f) echo "  • {$f}\n";
    }
}
