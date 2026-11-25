<?php
declare(strict_types=1);

/**
 * ContactMergeLinks — the client/account links of a contact that is being merged away.
 *
 * mergeContacts() (CrmFunctions) repointed companies, properties, invoice_contacts and others but NOT the
 * client roster, so after a merge the account still listed the dead contact as its invoice recipient
 * (client_contacts) and its identity row (clients.legacy_contact_id) still pointed at it. Found in the
 * 2026-11-23 customer-model review. Pure SQL on portable statements so it runs the same on SQLite in tests.
 *
 *  - client_contacts: rows move to the kept contact; where the kept contact already has the same
 *    (client, role) row the duplicate is dropped and the kept row absorbs the flags (primary / invoices /
 *    notifications), so nobody loses "receives invoices".
 *  - clients.legacy_contact_id: moves to the kept contact only when the kept contact has no client of its own
 *    (two real clients are never fused here — that is left to the drift report).
 * Never touches invoices, invoice_contacts or any sent record.
 */
class ContactMergeLinks
{
    /** @return array{client_contacts_moved:int, client_contacts_dropped:int, clients_repointed:int} */
    public static function repoint(PDO $db, int $keepId, int $mergeId): array
    {
        $out = ['client_contacts_moved' => 0, 'client_contacts_dropped' => 0, 'clients_repointed' => 0];
        if ($keepId <= 0 || $mergeId <= 0 || $keepId === $mergeId) {
            return $out;
        }

        $rows = $db->prepare("SELECT client_id, role, is_primary, receives_invoices, receives_notifications FROM client_contacts WHERE contact_id = ?");
        $rows->execute([$mergeId]);
        $has = $db->prepare("SELECT COUNT(*) FROM client_contacts WHERE client_id = ? AND contact_id = ? AND " . '(role = ? OR (role IS NULL AND ? IS NULL))');
        $absorb = $db->prepare("UPDATE client_contacts SET is_primary = ?, receives_invoices = ?, receives_notifications = ? WHERE client_id = ? AND contact_id = ? AND (role = ? OR (role IS NULL AND ? IS NULL))");
        $move = $db->prepare("UPDATE client_contacts SET contact_id = ? WHERE client_id = ? AND contact_id = ? AND (role = ? OR (role IS NULL AND ? IS NULL))");
        $drop = $db->prepare("DELETE FROM client_contacts WHERE client_id = ? AND contact_id = ? AND (role = ? OR (role IS NULL AND ? IS NULL))");
        $keepRow = $db->prepare("SELECT is_primary, receives_invoices, receives_notifications FROM client_contacts WHERE client_id = ? AND contact_id = ? AND (role = ? OR (role IS NULL AND ? IS NULL))");

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $role = $r['role'];
            $has->execute([$r['client_id'], $keepId, $role, $role]);
            if ((int)$has->fetchColumn() > 0) {
                $keepRow->execute([$r['client_id'], $keepId, $role, $role]);
                $k = $keepRow->fetch(PDO::FETCH_ASSOC);
                $absorb->execute([
                    max((int)$k['is_primary'], (int)$r['is_primary']),
                    max((int)$k['receives_invoices'], (int)$r['receives_invoices']),
                    max((int)$k['receives_notifications'], (int)$r['receives_notifications']),
                    $r['client_id'], $keepId, $role, $role,
                ]);
                $drop->execute([$r['client_id'], $mergeId, $role, $role]);
                $out['client_contacts_dropped']++;
            } else {
                $move->execute([$keepId, $r['client_id'], $mergeId, $role, $role]);
                $out['client_contacts_moved']++;
            }
        }

        $keepHasClient = $db->prepare("SELECT COUNT(*) FROM clients WHERE legacy_contact_id = ?");
        $keepHasClient->execute([$keepId]);
        if ((int)$keepHasClient->fetchColumn() === 0) {
            $u = $db->prepare("UPDATE clients SET legacy_contact_id = ? WHERE legacy_contact_id = ?");
            $u->execute([$keepId, $mergeId]);
            $out['clients_repointed'] = $u->rowCount();
        }
        return $out;
    }
}
