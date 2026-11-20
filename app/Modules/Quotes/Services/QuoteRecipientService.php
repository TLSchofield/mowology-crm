<?php
/**
 * QuoteRecipientService — who a quote goes to, and who is copied (migration 1186).
 *
 * The CRM's properties.site_contact_id is the BILLING contact (invoices). For managed
 * buildings that person is often the management firm's accountant (Vancouver Management:
 * Jodi Peacock), who must never receive quotes. Quotes go to the firm's quote person
 * (Alena Radosovska), who takes them to the property managers for signature.
 *
 * Resolution order for a new quote's recipient (forProperty):
 *   1. properties.quote_contact_id               per-building override
 *   2. companies.quote_contact_id                 of the building's related companies, in
 *      order: property_manager_id → billing_company_id → company_id → company_properties
 *      primary row → inferred (site contact is a company's primary/billing contact)
 *   3. properties.site_contact_id                 today's behaviour
 *
 * CC (ccForProperty): the building's property-manager person, if known — a
 * property_contacts 'manager' row, else the on-site contact or the management firm's
 * primary contact when that contact's contact_role is 'property_manager'. Skipped when it
 * is the To: person or has no usable email. Never used for SMS.
 *
 * Every new-column read is guarded: before 1186 runs, resolution falls straight through
 * to the site contact and no CC flag exists (CC defaults on).
 *
 * This service never writes to the contacts table.
 */
declare(strict_types=1);

class QuoteRecipientService
{
    private PDO $db;
    /** @var array<string,bool> */
    private array $columnCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCHEMA GUARDS
    // ══════════════════════════════════════════════════════════════════════

    /** Does $table.$column exist? Portable probe (MySQL + SQLite), cached per instance. */
    public function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->columnCache)) {
            try {
                $this->db->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
                $this->columnCache[$key] = true;
            } catch (Throwable $e) {
                $this->columnCache[$key] = false;
            }
        }
        return $this->columnCache[$key];
    }

    /** Migration 1186 has run (the settings pickers and the per-quote picker need it). */
    public function isAvailable(): bool
    {
        return $this->hasColumn('properties', 'quote_contact_id')
            && $this->hasColumn('companies', 'quote_contact_id')
            && $this->hasColumn('quotes', 'recipient_chosen');
    }

    // ══════════════════════════════════════════════════════════════════════
    // RESOLUTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The contact a NEW quote for this property should go to.
     *
     * @return array{contact_id:?int, source:string, company_id:?int}
     *   source: 'property' | 'company' | 'site_contact' | 'none'
     */
    public function forProperty(int $propertyId): array
    {
        $property = $this->loadProperty($propertyId);
        if (!$property) {
            return ['contact_id' => null, 'source' => 'none', 'company_id' => null];
        }

        $own = (int)($property['quote_contact_id'] ?? 0);
        if ($own > 0) {
            return ['contact_id' => $own, 'source' => 'property', 'company_id' => null];
        }

        if ($this->hasColumn('companies', 'quote_contact_id')) {
            foreach ($this->relatedCompanyIds($property) as $companyId) {
                $stmt = $this->db->prepare("SELECT quote_contact_id FROM companies WHERE id = ?");
                $stmt->execute([$companyId]);
                $cid = (int)$stmt->fetchColumn();
                if ($cid > 0) {
                    return ['contact_id' => $cid, 'source' => 'company', 'company_id' => $companyId];
                }
            }
        }

        $site = (int)($property['site_contact_id'] ?? 0);
        return $site > 0
            ? ['contact_id' => $site, 'source' => 'site_contact', 'company_id' => null]
            : ['contact_id' => null, 'source' => 'none', 'company_id' => null];
    }

    /**
     * Companies related to a property, most specific first, de-duplicated.
     *
     * @return int[]
     */
    public function relatedCompanyIds(array $property): array
    {
        $ids = [];
        foreach (['property_manager_id', 'billing_company_id', 'company_id'] as $col) {
            $v = (int)($property[$col] ?? 0);
            if ($v > 0) $ids[] = $v;
        }
        $pid = (int)($property['id'] ?? 0);
        if ($pid > 0) {
            try {
                $s = $this->db->prepare("SELECT company_id FROM company_properties WHERE property_id = ? ORDER BY is_primary DESC, id ASC");
                $s->execute([$pid]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    if ((int)$v > 0) $ids[] = (int)$v;
                }
            } catch (Throwable $e) { /* junction missing on this build */ }
        }
        $site = (int)($property['site_contact_id'] ?? 0);
        if ($site > 0) {
            try {
                $s = $this->db->prepare("SELECT id FROM companies WHERE primary_contact_id = ? OR billing_contact_id = ? ORDER BY id");
                $s->execute([$site, $site]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    if ((int)$v > 0) $ids[] = (int)$v;
                }
            } catch (Throwable $e) { /* inferred link unavailable */ }
        }
        return array_values(array_unique($ids));
    }

    /**
     * The property manager to copy on a quote for this building, or null.
     *
     * @return array{contact_id:int, name:string, email:string, why:string}|null
     */
    public function ccForProperty(int $propertyId, ?int $toContactId = null, ?string $toEmail = null): ?array
    {
        $property = $this->loadProperty($propertyId);
        if (!$property) return null;

        $candidates = [];   // [contact_id, why]

        // 1. A building-specific manager row.
        try {
            $s = $this->db->prepare("SELECT contact_id FROM property_contacts WHERE property_id = ? AND contact_role = 'manager' ORDER BY is_primary DESC, id ASC");
            $s->execute([$propertyId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $candidates[] = [(int)$v, 'building manager'];
            }
        } catch (Throwable $e) { /* table missing */ }

        // 2. The on-site contact, when they are a property manager.
        try {
            $s = $this->db->prepare("SELECT contact_id FROM property_contacts WHERE property_id = ? AND contact_role = 'site_supervisor' ORDER BY is_primary DESC, id ASC");
            $s->execute([$propertyId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $candidates[] = [(int)$v, 'on-site property manager', true];
            }
        } catch (Throwable $e) { /* table missing */ }

        // 3. The management firm's primary contact, when they are a property manager.
        $pm = (int)($property['property_manager_id'] ?? 0);
        if ($pm > 0) {
            $s = $this->db->prepare("SELECT primary_contact_id FROM companies WHERE id = ?");
            $s->execute([$pm]);
            $v = (int)$s->fetchColumn();
            if ($v > 0) $candidates[] = [$v, 'management company property manager', true];
        }

        $toEmail = strtolower(trim((string)$toEmail));
        foreach ($candidates as $c) {
            [$cid, $why] = $c;
            $needsPmRole = !empty($c[2]);
            if ($cid <= 0 || ($toContactId && $cid === (int)$toContactId)) continue;
            $contact = $this->loadContact($cid);
            if (!$contact) continue;
            if ($needsPmRole && ($contact['contact_role'] ?? '') !== 'property_manager') continue;
            $email = trim((string)($contact['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if ($toEmail !== '' && strtolower($email) === $toEmail) continue;
            return [
                'contact_id' => $cid,
                'name'       => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'email'      => $email,
                'why'        => $why,
            ];
        }
        return null;
    }

    /**
     * CC for a specific quote: null when the quote has CC switched off.
     * $quote needs property_id (and cc_property_manager when 1186 has run).
     */
    public function ccForQuote(array $quote, ?int $toContactId, ?string $toEmail): ?array
    {
        if (array_key_exists('cc_property_manager', $quote) && (int)$quote['cc_property_manager'] === 0) {
            return null;
        }
        $pid = (int)($quote['property_id'] ?? 0);
        return $pid > 0 ? $this->ccForProperty($pid, $toContactId, $toEmail) : null;
    }

    /**
     * CC addresses for a follow-up covering several quotes (Sam's cards).
     *
     * @param int[] $quoteIds
     * @return string[]
     */
    public function ccEmailsForQuotes(array $quoteIds, ?int $toContactId, ?string $toEmail): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $quoteIds)) as $qid) {
            if ($qid <= 0) continue;
            $s = $this->db->prepare("SELECT * FROM quotes WHERE id = ?");
            $s->execute([$qid]);
            $q = $s->fetch(PDO::FETCH_ASSOC);
            if (!$q) continue;
            $cc = $this->ccForQuote($q, $toContactId, $toEmail);
            if ($cc) $out[strtolower($cc['email'])] = $cc['email'];
        }
        return array_values($out);
    }

    /**
     * People the quote's "Send to" picker offers: contacts at the related companies,
     * the building's billing / on-site / manager contacts, and the current recipient.
     *
     * @return array<int, array{id:int, name:string, email:string, why:string}>
     */
    public function pickerOptions(array $quote): array
    {
        $property = $this->loadProperty((int)($quote['property_id'] ?? 0)) ?: [];
        $companyIds = $property ? $this->relatedCompanyIds($property) : [];
        if (!empty($quote['company_id'])) array_unshift($companyIds, (int)$quote['company_id']);
        $companyIds = array_values(array_unique(array_filter($companyIds)));

        $why = [];   // contact_id => reason
        $add = static function ($id, string $reason) use (&$why) {
            $id = (int)$id;
            if ($id > 0 && !isset($why[$id])) $why[$id] = $reason;
        };

        $add($quote['quote_contact_id'] ?? $quote['contact_id'] ?? 0, 'current');
        foreach ($companyIds as $co) {
            $cols = 'company_name, primary_contact_id, billing_contact_id'
                  . ($this->hasColumn('companies', 'quote_contact_id') ? ', quote_contact_id' : '');
            $s = $this->db->prepare("SELECT {$cols} FROM companies WHERE id = ?");
            $s->execute([$co]);
            $c = $s->fetch(PDO::FETCH_ASSOC);
            if (!$c) continue;
            $name = (string)($c['company_name'] ?? 'company');
            $add($c['quote_contact_id'] ?? 0, "{$name} — quotes");
            $add($c['primary_contact_id'] ?? 0, "{$name} — primary");
            $add($c['billing_contact_id'] ?? 0, "{$name} — billing");
            try {
                $e = $this->db->prepare("SELECT id FROM contacts WHERE employer_company_id = ? ORDER BY first_name, last_name");
                $e->execute([$co]);
                foreach ($e->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, $name);
            } catch (Throwable $ex) { /* employer_company_id missing */ }
        }
        $add($property['site_contact_id'] ?? 0, 'billing contact');
        try {
            $s = $this->db->prepare("SELECT contact_id, contact_role FROM property_contacts WHERE property_id = ?");
            $s->execute([(int)($property['id'] ?? 0)]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $add($r['contact_id'], $r['contact_role'] === 'site_supervisor' ? 'on-site contact' : 'building ' . $r['contact_role']);
            }
        } catch (Throwable $e) { /* table missing */ }

        $out = [];
        foreach ($why as $id => $reason) {
            $c = $this->loadContact($id);
            if (!$c) continue;
            $out[] = [
                'id'    => $id,
                'name'  => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: ('Contact #' . $id),
                'email' => (string)($c['email'] ?? ''),
                'why'   => $reason,
            ];
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════
    // MUTATIONS — quotes / companies / properties only, never contacts
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Point a quote at a different recipient. Changes quotes.contact_id only.
     *
     * @return array{ok:bool, message:string, old_contact_id:?int}
     */
    public function setRecipient(int $quoteId, int $contactId, int $userId): array
    {
        if ($contactId <= 0 || !$this->loadContact($contactId)) {
            return ['ok' => false, 'message' => 'Pick a contact to send this quote to.', 'old_contact_id' => null];
        }
        $s = $this->db->prepare("SELECT contact_id FROM quotes WHERE id = ?");
        $s->execute([$quoteId]);
        $old = $s->fetchColumn();
        if ($old === false) {
            return ['ok' => false, 'message' => 'Quote not found.', 'old_contact_id' => null];
        }
        $old = $old !== null ? (int)$old : null;

        if ($this->hasColumn('quotes', 'recipient_chosen')) {
            $this->db->prepare("UPDATE quotes SET contact_id = ?, recipient_chosen = 1 WHERE id = ?")
                     ->execute([$contactId, $quoteId]);
        } else {
            $this->db->prepare("UPDATE quotes SET contact_id = ? WHERE id = ?")
                     ->execute([$contactId, $quoteId]);
        }

        $new = $this->loadContact($contactId);
        $label = trim(($new['first_name'] ?? '') . ' ' . ($new['last_name'] ?? '')) . (!empty($new['email']) ? " <{$new['email']}>" : '');
        if (function_exists('logActivityExtended')) {
            try {
                logActivityExtended($userId, 'Quote recipient changed',
                    'Quote now goes to ' . $label . ' (contact #' . $contactId . ')' . ($old ? ', was contact #' . $old : ''),
                    null, null, $quoteId);
            } catch (Throwable $e) { /* non-critical */ }
        }
        return ['ok' => true, 'message' => 'This quote now goes to ' . $label . '.', 'old_contact_id' => $old];
    }

    /** Mark a quote's contact_id as deliberately chosen (resolution or picker). */
    public function markChosen(int $quoteId): void
    {
        if ($this->hasColumn('quotes', 'recipient_chosen')) {
            $this->db->prepare("UPDATE quotes SET recipient_chosen = 1 WHERE id = ?")->execute([$quoteId]);
        }
    }

    /** Per-quote "copy the property manager" switch. */
    public function setCcEnabled(int $quoteId, bool $on, int $userId): bool
    {
        if (!$this->hasColumn('quotes', 'cc_property_manager')) return false;
        $this->db->prepare("UPDATE quotes SET cc_property_manager = ? WHERE id = ?")->execute([$on ? 1 : 0, $quoteId]);
        if (function_exists('logActivityExtended')) {
            try {
                logActivityExtended($userId, 'Quote CC changed', $on ? 'Property manager will be copied' : 'Property manager will not be copied', null, null, $quoteId);
            } catch (Throwable $e) { /* non-critical */ }
        }
        return true;
    }

    public function setCompanyQuoteContact(int $companyId, ?int $contactId): bool
    {
        if (!$this->hasColumn('companies', 'quote_contact_id')) return false;
        $this->db->prepare("UPDATE companies SET quote_contact_id = ? WHERE id = ?")
                 ->execute([$contactId && $contactId > 0 ? $contactId : null, $companyId]);
        return true;
    }

    public function setPropertyQuoteContact(int $propertyId, ?int $contactId): bool
    {
        if (!$this->hasColumn('properties', 'quote_contact_id')) return false;
        $this->db->prepare("UPDATE properties SET quote_contact_id = ? WHERE id = ?")
                 ->execute([$contactId && $contactId > 0 ? $contactId : null, $propertyId]);
        return true;
    }

    // ══════════════════════════════════════════════════════════════════════

    private function loadProperty(int $propertyId): ?array
    {
        if ($propertyId <= 0) return null;
        $s = $this->db->prepare("SELECT * FROM properties WHERE id = ?");
        $s->execute([$propertyId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadContact(int $contactId): ?array
    {
        if ($contactId <= 0) return null;
        $s = $this->db->prepare("SELECT * FROM contacts WHERE id = ?");
        $s->execute([$contactId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
