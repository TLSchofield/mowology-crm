<?php
declare(strict_types=1);

/**
 * ClientPropertySearchIndex — which property labels belong to which client row
 * on the Clients page, so searching "laburnum" or "VR738" finds the client that
 * owns/manages that property (not only clients whose own name matches).
 *
 * A property reaches a company (the rows on /crm/clients_appstack.php) or a
 * standalone contact through MANY links; all are followed:
 *   company: properties.company_id / property_manager_id / billing_company_id,
 *            properties.client_id -> clients.legacy_company_id,
 *            company_properties rows,
 *            and the company's primary/billing contact via the contact links.
 *   contact: properties.site_contact_id / quote_contact_id,
 *            property_contacts rows (ended_on IS NULL when the column exists).
 * See Known-Failure-Patterns "No properties found for this client".
 *
 * Read-only; no user input reaches SQL (all queries are static).
 */
class ClientPropertySearchIndex
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{company: array<int,string[]>, contact: array<int,string[]>}
     *         entity id => property labels
     */
    public function build(): array
    {
        $propCols = $this->columns('properties');
        if (!$propCols) {
            return ['company' => [], 'contact' => []];
        }

        $nameCols = [];
        foreach (['property_name', 'billing_entity_name', 'address'] as $c) {
            if (isset($propCols[$c])) {
                $nameCols[] = "p.`$c`";
            }
        }
        if (!$nameCols) {
            return ['company' => [], 'contact' => []];
        }
        $props = [];
        $sel = 'p.id' . ', ' . implode(', ', $nameCols);
        foreach ($this->db->query("SELECT $sel FROM properties p")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $props[(int)$r['id']] = self::label($r);
        }

        $companyLinks = [];   // [propertyId, companyId]
        foreach (['company_id', 'property_manager_id', 'billing_company_id'] as $c) {
            if (isset($propCols[$c])) {
                foreach ($this->db->query("SELECT id, `$c` FROM properties WHERE `$c` IS NOT NULL")->fetchAll(PDO::FETCH_NUM) as $r) {
                    $companyLinks[] = [(int)$r[0], (int)$r[1]];
                }
            }
        }
        if (isset($propCols['client_id']) && $this->columns('clients') && isset($this->columns('clients')['legacy_company_id'])) {
            $sql = "SELECT p.id, cl.legacy_company_id FROM properties p
                    INNER JOIN clients cl ON cl.id = p.client_id
                    WHERE cl.legacy_company_id IS NOT NULL";
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) {
                $companyLinks[] = [(int)$r[0], (int)$r[1]];
            }
        }
        if ($this->columns('company_properties')) {
            foreach ($this->db->query("SELECT property_id, company_id FROM company_properties")->fetchAll(PDO::FETCH_NUM) as $r) {
                $companyLinks[] = [(int)$r[0], (int)$r[1]];
            }
        }

        $contactLinks = [];   // [propertyId, contactId]
        foreach (['site_contact_id', 'quote_contact_id'] as $c) {
            if (isset($propCols[$c])) {
                foreach ($this->db->query("SELECT id, `$c` FROM properties WHERE `$c` IS NOT NULL")->fetchAll(PDO::FETCH_NUM) as $r) {
                    $contactLinks[] = [(int)$r[0], (int)$r[1]];
                }
            }
        }
        $pcCols = $this->columns('property_contacts');
        if ($pcCols) {
            $sql = "SELECT property_id, contact_id FROM property_contacts"
                 . (isset($pcCols['ended_on']) ? " WHERE ended_on IS NULL" : "");
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) {
                $contactLinks[] = [(int)$r[0], (int)$r[1]];
            }
        }

        // Company -> its own contacts (properties reached through them count too).
        $companyContacts = [];  // [companyId, contactId]
        $coCols = $this->columns('companies');
        foreach (['primary_contact_id', 'billing_contact_id'] as $c) {
            if (isset($coCols[$c])) {
                foreach ($this->db->query("SELECT id, `$c` FROM companies WHERE `$c` IS NOT NULL")->fetchAll(PDO::FETCH_NUM) as $r) {
                    $companyContacts[] = [(int)$r[0], (int)$r[1]];
                }
            }
        }

        return self::aggregate($props, $companyLinks, $contactLinks, $companyContacts);
    }

    /**
     * Pure: combine labels and links into entity => unique labels.
     *
     * @param array<int,string> $props            propertyId => label
     * @param array<int,int[]>  $companyLinks     [propertyId, companyId]
     * @param array<int,int[]>  $contactLinks     [propertyId, contactId]
     * @param array<int,int[]>  $companyContacts  [companyId, contactId]
     * @return array{company: array<int,string[]>, contact: array<int,string[]>}
     */
    public static function aggregate(array $props, array $companyLinks, array $contactLinks, array $companyContacts = []): array
    {
        $company = [];
        $contact = [];
        $add = function (array &$bucket, int $id, int $pid) use ($props): void {
            if ($id <= 0 || !isset($props[$pid]) || $props[$pid] === '') {
                return;
            }
            $bucket[$id][$props[$pid]] = true;
        };
        foreach ($companyLinks as $l) {
            $add($company, $l[1], $l[0]);
        }
        $byContact = [];
        foreach ($contactLinks as $l) {
            $add($contact, $l[1], $l[0]);
            $byContact[$l[1]][] = $l[0];
        }
        foreach ($companyContacts as $cc) {
            foreach ($byContact[$cc[1]] ?? [] as $pid) {
                $add($company, $cc[0], $pid);
            }
        }
        $out = ['company' => [], 'contact' => []];
        foreach ($company as $id => $labels) {
            $out['company'][$id] = array_keys($labels);
        }
        foreach ($contact as $id => $labels) {
            $out['contact'][$id] = array_keys($labels);
        }
        return $out;
    }

    /** "Laburnum Heights — VR738 Laburnum Heights — 1551 West 11th Avenue" (blank parts dropped, duplicates collapsed). */
    public static function label(array $row): string
    {
        $parts = [];
        foreach (['property_name', 'billing_entity_name', 'address'] as $k) {
            $v = trim((string)($row[$k] ?? ''));
            if ($v !== '' && !in_array($v, $parts, true)) {
                $parts[] = $v;
            }
        }
        return implode(' — ', $parts);
    }

    /** @return array<string,bool> column name => true (empty if the table is missing) */
    private function columns(string $table): array
    {
        try {
            $out = [];
            foreach ($this->db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) as $c) {
                $out[$c] = true;
            }
            return $out;
        } catch (PDOException $e) {
            return [];
        }
    }
}
