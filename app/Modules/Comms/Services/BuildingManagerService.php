<?php
/**
 * BuildingManagerService — Yui knows who manages a building (owner, 2026-10-18: "Yui should know the
 * property manager for the building is Joann Hamelin, she signed the quote; if she's unsure she
 * should ask the question here for confirmation").
 *
 *   onFile()    the building's manager: property_contacts contact_role 'manager' (what the quote CC,
 *               QuoteRecipientService::ccForProperty, already reads first).
 *   suggest()   when none is on file, the person who accepted the building's latest accepted quote —
 *               accepted_by_contact_id, else the contact whose email matches accepted_by_email, else
 *               the quote's contact. Never a guess the owner already said no to.
 *   confirm()   the owner's "Yes": records them as the building's manager (never duplicates).
 *   decline()   the owner's "No": Yui stops proposing that person for that building
 *               (ops_settings yui_building_manager_declined, JSON {property_id: [contact_id]}).
 *
 * Yui only asks; nothing is recorded until a person answers.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class BuildingManagerService
{
    public const DECLINED_KEY = 'yui_building_manager_declined';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{contact_id: int, name: string, email: string}|null */
    public function onFile(int $propertyId): ?array
    {
        try {
            $s = $this->db->prepare("SELECT contact_id FROM property_contacts WHERE property_id = ? AND contact_role = 'manager'
                                     ORDER BY is_primary DESC, id ASC LIMIT 1");
            $s->execute([$propertyId]);
            $cid = (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
        return $cid > 0 ? $this->contact($cid) : null;
    }

    /** @return array{contact_id: int, name: string, email: string, why: string, quote_number: string}|null */
    public function suggest(int $propertyId): ?array
    {
        if ($propertyId < 1 || $this->onFile($propertyId)) return null;
        try {
            $s = $this->db->prepare("SELECT quote_number, contact_id, accepted_by_contact_id, accepted_by_email, accepted_at
                                     FROM quotes WHERE property_id = ? AND status = 'accepted'
                                     ORDER BY accepted_at DESC, id DESC LIMIT 1");
            $s->execute([$propertyId]);
            $q = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!$q) return null;
        $cid = (int)($q['accepted_by_contact_id'] ?? 0);
        if ($cid < 1 && trim((string)($q['accepted_by_email'] ?? '')) !== '') {
            $e = $this->db->prepare('SELECT id FROM contacts WHERE LOWER(email) = LOWER(?) ORDER BY id LIMIT 1');
            $e->execute([trim((string)$q['accepted_by_email'])]);
            $cid = (int)$e->fetchColumn();
        }
        if ($cid < 1) $cid = (int)($q['contact_id'] ?? 0);
        if ($cid < 1 || in_array($cid, $this->declined()[$propertyId] ?? [], true)) return null;
        $c = $this->contact($cid);
        if (!$c) return null;
        $when = !empty($q['accepted_at']) ? ' on ' . date('M j', strtotime((string)$q['accepted_at'])) : '';
        return $c + ['why' => 'accepted ' . $q['quote_number'] . $when, 'quote_number' => (string)$q['quote_number']];
    }

    public function confirm(int $propertyId, int $contactId): bool
    {
        if ($propertyId < 1 || $contactId < 1 || !$this->contact($contactId)) return false;
        $hasPrimary = $this->onFile($propertyId) !== null;
        $s = $this->db->prepare("INSERT INTO property_contacts (property_id, contact_id, contact_role, is_primary)
                                 SELECT ?, ?, 'manager', ? FROM properties p
                                 WHERE p.id = ?
                                   AND NOT EXISTS (SELECT 1 FROM property_contacts pc
                                                   WHERE pc.property_id = ? AND pc.contact_id = ? AND pc.contact_role = 'manager')");
        $s->execute([$propertyId, $contactId, $hasPrimary ? 0 : 1, $propertyId, $propertyId, $contactId]);
        return $s->rowCount() > 0 || $this->onFile($propertyId) !== null;
    }

    public function decline(int $propertyId, int $contactId): void
    {
        $all = $this->declined();
        $all[$propertyId] = array_values(array_unique(array_merge($all[$propertyId] ?? [], [$contactId])));
        $json = json_encode($all);
        $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
        $u->execute([$json, self::DECLINED_KEY]);
        if ($u->rowCount() === 0) {
            $c = $this->db->prepare('SELECT 1 FROM ops_settings WHERE setting_key = ?');
            $c->execute([self::DECLINED_KEY]);
            if ($c->fetchColumn() === false) {
                $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')
                    ->execute([self::DECLINED_KEY, $json, 'Yui: building managers the owner said were not the manager (BuildingManagerService)']);
            }
        }
    }

    /** @return array<int, int[]> */
    private function declined(): array
    {
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::DECLINED_KEY]);
            $j = json_decode((string)$s->fetchColumn(), true);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ((array)$j as $pid => $ids) $out[(int)$pid] = array_map('intval', (array)$ids);
        return $out;
    }

    /** @return array{contact_id: int, name: string, email: string}|null */
    private function contact(int $id): ?array
    {
        $s = $this->db->prepare('SELECT id, first_name, last_name, email FROM contacts WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['contact_id' => (int)$r['id'], 'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 'email' => (string)($r['email'] ?? '')];
    }
}
