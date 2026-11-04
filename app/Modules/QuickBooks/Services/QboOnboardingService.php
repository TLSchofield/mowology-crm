<?php
/**
 * QboOnboardingService — Penny sets Ittai up from a client's QuickBooks (owner, 2026-10-20: "preview and
 * local"). The QuickBooks onboarding module's five parts:
 *
 *   business   company name, address, phone, GST/HST number (on the tax agency), fiscal year, default terms
 *   clients    customers → Sam's clients (a contact, its property from the ship/bill address, its company
 *              when the company name is not the person's name). A sub-customer (a job, BillWithParent) is
 *              a property of its parent, not a client. Matched to who is already in Ittai (email, then
 *              name + address) — a match is never added twice and never merged by itself.
 *   services   Service / NonInventory / Inventory items → Otto's service catalogue (Category items are
 *              folders, skipped); matched by name.
 *   suppliers  vendors → Penny's suppliers; matched by name.
 *   accounts   the account mapping filled from guesses by name and kind (QboConnectionService).
 *
 * pull() reads QuickBooks into qbo_onboarding_snapshot (production, read-only on QuickBooks). export() /
 * import() move that snapshot to the local test site, where PHP can't call Intuit (no CA bundle).
 *
 * WRITES on the local test site (LOCAL_TEST_SITE in secrets.php) and on every business except the default one
 * (Mowology). Each Ittai business has its own database (Tenant), so its import lands only in its own books;
 * Mowology's live CRM keeps the preview (a test company's customers must never land there). 2026-11-03, Claudia
 * walk 1: the old "one business" guard blocked founding businesses too.
 * Imported clients get NO marketing consent (CASL): they are clients, not subscribers.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QboConnectionService.php';

class QboOnboardingService
{
    public const KINDS = ['customer' => 'Customer', 'item' => 'Item', 'vendor' => 'Vendor', 'term' => 'Term', 'agency' => 'TaxAgency', 'account' => 'Account'];
    public const SERVICE_TYPES = ['Service', 'NonInventory', 'Inventory'];
    public const CATEGORY = 'Imported from QuickBooks';

    private PDO $db;
    private int $siteId;
    private ?QboConnectionService $conn;
    /** @var callable(): int */
    private $clock;
    private bool $local;

    public function __construct(PDO $db, ?QboConnectionService $conn = null, int $siteId = 1, ?callable $clock = null, ?bool $local = null)
    {
        $this->db = $db;
        $this->conn = $conn;
        $this->siteId = $siteId;
        $this->clock = $clock ?? 'time';
        $this->local = $local ?? self::writesAllowed(defined('LOCAL_TEST_SITE') && LOCAL_TEST_SITE === true,
            !class_exists('Tenant') || Tenant::isDefault());
    }

    /** Pure rule: the local test site, or any business other than the default (Mowology) one. */
    public static function writesAllowed(bool $localTestSite, bool $defaultBusiness): bool
    {
        return $localTestSite || !$defaultBusiness;
    }

    public function canWrite(): bool { return $this->local; }

    // ─────────────────────────────────────────────────────────────────────────
    // Snapshot
    // ─────────────────────────────────────────────────────────────────────────

    /** Read the client's QuickBooks into the snapshot. @return array{ok:bool, message:string} */
    public function pull(): array
    {
        if (!$this->conn || !$this->conn->company()) return ['ok' => false, 'message' => 'Connect QuickBooks first.'];
        $c = $this->conn->client();
        $rows = [];
        foreach (self::KINDS as $kind => $entity) {
            $r = $c->all($entity);
            if (!$r['ok']) return ['ok' => false, 'message' => $r['error']];
            foreach ($r['rows'] as $row) if (isset($row['Id'])) $rows[] = [$kind, (string)$row['Id'], $row];
        }
        $co = $this->conn->company();
        $ci = $c->companyInfo($co['realm_id']);
        if ($ci['ok'] && !empty($ci['data']['CompanyInfo'])) $rows[] = ['company', '1', $ci['data']['CompanyInfo']];
        $this->replace($rows);
        $n = array_count_values(array_column($rows, 0));
        return ['ok' => true, 'message' => sprintf('Read %d customers, %d items, %d suppliers.', $n['customer'] ?? 0, $n['item'] ?? 0, $n['vendor'] ?? 0)];
    }

    public function export(): string
    {
        $s = $this->db->prepare('SELECT kind, qbo_id, data FROM qbo_onboarding_snapshot WHERE site_id = ? ORDER BY kind, id');
        $s->execute([$this->siteId]);
        return (string)json_encode(['v' => 1, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    /** The local test site loads a snapshot exported from production. */
    public function import(string $json): array
    {
        if (!$this->local) return ['ok' => false, 'message' => 'Only the local test site loads a snapshot.'];
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['v'] ?? 0) !== 1 || !is_array($d['rows'] ?? null)) return ['ok' => false, 'message' => 'Not a QuickBooks onboarding snapshot.'];
        $rows = [];
        foreach ($d['rows'] as $r) {
            $data = json_decode((string)($r['data'] ?? ''), true);
            if (is_array($data) && isset($r['kind'], $r['qbo_id'])) $rows[] = [(string)$r['kind'], (string)$r['qbo_id'], $data];
        }
        $this->replace($rows);
        return ['ok' => true, 'message' => 'Loaded ' . count($rows) . ' QuickBooks records.'];
    }

    public function hasSnapshot(): bool
    {
        try {
            $s = $this->db->prepare('SELECT COUNT(*) FROM qbo_onboarding_snapshot WHERE site_id = ?');
            $s->execute([$this->siteId]);
            return (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array> the QuickBooks rows of one kind */
    public function rows(string $kind): array
    {
        $s = $this->db->prepare('SELECT data FROM qbo_onboarding_snapshot WHERE site_id = ? AND kind = ? ORDER BY id');
        $s->execute([$this->siteId, $kind]);
        return array_values(array_filter(array_map(fn($v) => json_decode((string)$v, true), $s->fetchAll(PDO::FETCH_COLUMN) ?: []), 'is_array'));
    }

    private function replace(array $rows): void
    {
        $now = date('Y-m-d H:i:s', ($this->clock)());
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM qbo_onboarding_snapshot WHERE site_id = ?')->execute([$this->siteId]);
            // A new read is reviewed again: the old "Looks right" belonged to the old data (Codex review 2026-10-20).
            $this->db->prepare('DELETE FROM qbo_onboarding_parts WHERE site_id = ?')->execute([$this->siteId]);
            $ins = $this->db->prepare('INSERT INTO qbo_onboarding_snapshot (site_id, kind, qbo_id, data, pulled_at) VALUES (?, ?, ?, ?, ?)');
            foreach ($rows as [$kind, $id, $data]) $ins->execute([$this->siteId, $kind, $id, json_encode($data), $now]);
            $this->db->commit();
            $this->storePreviews(null);   // worked out again from the new read, once
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure helpers (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** QuickBooks address → Ittai's fields, or null with no street. */
    public static function address(?array $a): ?array
    {
        if (!$a || trim((string)($a['Line1'] ?? '')) === '') return null;
        return ['address' => trim((string)$a['Line1']), 'city' => trim((string)($a['City'] ?? '')),
                'province' => trim((string)($a['CountrySubDivisionCode'] ?? '')), 'postal_code' => trim((string)($a['PostalCode'] ?? ''))];
    }

    /** First / last name: QuickBooks' Given/Family, else split the display name. @return array{0:string,1:string} */
    public static function personName(array $c): array
    {
        $g = trim((string)($c['GivenName'] ?? '')); $f = trim((string)($c['FamilyName'] ?? ''));
        if ($g !== '' || $f !== '') return [$g !== '' ? $g : $f, $g !== '' ? $f : ''];
        $parts = preg_split('/\s+/', trim((string)($c['DisplayName'] ?? '')), 2);
        return [(string)($parts[0] ?? ''), (string)($parts[1] ?? '')];
    }

    /** The company when it is not just the person's own name. */
    public static function companyOf(array $c): ?string
    {
        $co = trim((string)($c['CompanyName'] ?? ''));
        [$g, $f] = self::personName($c);
        return ($co !== '' && strcasecmp($co, trim($g . ' ' . $f)) !== 0) ? $co : null;
    }

    public static function norm(string $s): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Previews (what each part would do) — read-only everywhere
    // ─────────────────────────────────────────────────────────────────────────

    public function business(): array
    {
        $ci = $this->rows('company')[0] ?? [];
        $cra = null;
        foreach ($this->rows('agency') as $a) if (!empty($a['TaxRegistrationNumber'])) { $cra = (string)$a['TaxRegistrationNumber']; break; }
        $terms = null;
        foreach ($this->rows('term') as $t) if (($t['Active'] ?? true) && (int)($t['DueDays'] ?? -1) === 30) $terms = (string)$t['Name'];
        $addr = self::address($ci['CompanyAddr'] ?? null);
        return [
            'name'         => (string)($ci['CompanyName'] ?? ''),
            'legal_name'   => (string)($ci['LegalName'] ?? ''),
            'address'      => $addr ? trim($addr['address'] . ', ' . $addr['city'] . ' ' . $addr['province'] . ' ' . $addr['postal_code']) : '',
            'phone'        => (string)($ci['PrimaryPhone']['FreeFormNumber'] ?? ''),
            'email'        => (string)($ci['Email']['Address'] ?? ''),
            'gst_number'   => (string)($cra ?? ''),
            'fiscal_start' => (string)($ci['FiscalYearStartMonth'] ?? ''),
            'terms'        => (string)($terms ?? ''),
        ];
    }

    /**
     * @return array{new:int, match:int, properties:int, rows:list<array{name:string, company:?string, email:?string, address:?string, status:string, why:string, parent:?string}>}
     */
    public function clients(): array
    {
        $all = $this->rows('customer');
        $byId = [];
        foreach ($all as $c) $byId[(string)$c['Id']] = $c;
        $out = ['new' => 0, 'match' => 0, 'properties' => 0, 'rows' => []];
        foreach ($all as $c) {
            if (($c['Active'] ?? true) === false) continue;
            $name = (string)($c['DisplayName'] ?? '');
            $ship = self::address($c['ShipAddr'] ?? null) ?? self::address($c['BillAddr'] ?? null);
            if (!empty($c['ParentRef']['value'])) {
                $parent = $byId[(string)$c['ParentRef']['value']] ?? [];
                $out['properties']++;
                $out['rows'][] = ['name' => $name, 'company' => null, 'email' => null, 'address' => $ship['address'] ?? null, 'status' => 'property',
                                  'why' => 'A job of ' . ($parent['DisplayName'] ?? 'its parent') . ' — kept with that client.', 'parent' => (string)($parent['DisplayName'] ?? '')];
                continue;
            }
            $email = strtolower(trim((string)($c['PrimaryEmailAddr']['Address'] ?? '')));
            [$g, $f] = self::personName($c);
            $hit = $this->findContact($email, $g, $f, $ship['address'] ?? '', (string)$c['Id']);
            $out[$hit ? 'match' : 'new']++;
            $out['rows'][] = ['name' => $name, 'company' => self::companyOf($c), 'email' => $email ?: null, 'address' => $ship['address'] ?? null,
                              'status' => $hit ? 'match' : 'new', 'why' => $hit ? $hit : 'New client.', 'parent' => null];
        }
        return $out;
    }

    /** The note an imported client carries — how a re-run knows it (8 sandbox customers have no email or address). */
    public static function importNote(string $qboId): string
    {
        return 'Imported from QuickBooks (customer ' . $qboId . ').';
    }

    /**
     * Already in Ittai? This QuickBooks customer imported before, then an email, then the same first + last
     * name at the same address.
     */
    private function findContact(string $email, string $first, string $last, string $address, string $qboId = ''): ?string
    {
        $m = $this->matchContact($email, $first, $last, $address, $qboId);
        return $m ? $m[1] : null;
    }

    /** @return array{0:int, 1:string}|null the existing contact and why it matched */
    private function matchContact(string $email, string $first, string $last, string $address, string $qboId = ''): ?array
    {
        if ($qboId !== '') {
            $s = $this->db->prepare('SELECT id FROM contacts WHERE notes LIKE ? LIMIT 1');
            $s->execute([self::importNote($qboId) . '%']);
            if ($id = $s->fetchColumn()) return [(int)$id, 'Imported from QuickBooks already.'];
        }
        if ($email !== '') {
            $s = $this->db->prepare('SELECT id FROM contacts WHERE LOWER(TRIM(email)) = ? LIMIT 1');
            $s->execute([$email]);
            if ($id = $s->fetchColumn()) return [(int)$id, 'Already in Ittai (same email).'];
        }
        if ($first !== '' && $address !== '') {
            $s = $this->db->prepare('SELECT c.id FROM contacts c JOIN properties p ON p.site_contact_id = c.id
                                     WHERE LOWER(c.first_name) = ? AND LOWER(c.last_name) = ? AND LOWER(p.address) = ? LIMIT 1');
            $s->execute([strtolower($first), strtolower($last), strtolower($address)]);
            if ($id = $s->fetchColumn()) return [(int)$id, 'Already in Ittai (same name and address).'];
        }
        return null;
    }

    /** @return array{new:int, match:int, rows:list<array{name:string, group:string, price:?float, status:string}>} */
    public function services(): array
    {
        $have = [];
        foreach ($this->db->query('SELECT name FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) $have[self::norm((string)$n)] = true;
        $out = ['new' => 0, 'match' => 0, 'rows' => []];
        foreach ($this->rows('item') as $i) {
            if (!in_array($i['Type'] ?? '', self::SERVICE_TYPES, true) || ($i['Active'] ?? true) === false) continue;
            $fq = (string)($i['FullyQualifiedName'] ?? $i['Name'] ?? '');
            $parts = explode(':', $fq);
            $name = trim((string)array_pop($parts));
            $status = isset($have[self::norm($name)]) ? 'match' : 'new';
            $out[$status]++;
            $out['rows'][] = ['name' => $name, 'group' => implode(' › ', array_map('trim', $parts)), 'price' => isset($i['UnitPrice']) ? (float)$i['UnitPrice'] : null,
                              'status' => $status, 'description' => (string)($i['Description'] ?? '')];
        }
        return $out;
    }

    /** @return array{new:int, match:int, rows:list<array{name:string, status:string}>} */
    public function suppliers(): array
    {
        $have = [];
        foreach ($this->db->query('SELECT name FROM vendors')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) $have[self::norm((string)$n)] = true;
        $out = ['new' => 0, 'match' => 0, 'rows' => []];
        foreach ($this->rows('vendor') as $v) {
            if (($v['Active'] ?? true) === false) continue;
            $name = trim((string)($v['DisplayName'] ?? ''));
            if ($name === '') continue;
            $status = isset($have[self::norm($name)]) ? 'match' : 'new';
            $out[$status]++;
            $out['rows'][] = ['name' => $name, 'status' => $status, 'phone' => (string)($v['PrimaryPhone']['FreeFormNumber'] ?? '')];
        }
        return $out;
    }

    /** @return array{matched:int, total:int, rows:list<array{code:string, name:string, guess:?string}>} */
    public function accounts(): array
    {
        // Their accounts: the live connection's list, else the snapshot's (the local test site has no connection).
        // An empty stored list (a connection whose list read failed) falls back to the snapshot too.
        $remote = $this->conn ? $this->conn->remote('account') : [];
        if (!$remote) $remote = array_map(fn($a) => ['qbo_id' => (string)$a['Id'], 'name' => (string)($a['FullyQualifiedName'] ?? $a['Name'] ?? ''),
                                                     'detail' => (string)($a['AccountType'] ?? '')], $this->rows('account'));
        $maps = $this->conn ? $this->conn->mappings()['account'] : [];
        $ittai = $this->db->query("SELECT code, name, type FROM chart_of_accounts WHERE is_active = 1 AND (type IN ('revenue', 'expense') OR code = '1500') ORDER BY code")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = ['matched' => 0, 'total' => 0, 'rows' => []];
        foreach ($ittai as $a) {
            $out['total']++;
            $guess = $maps[$a['code']]['qbo_id'] ?? QboConnectionService::suggestAccount((string)$a['name'], QboConnectionService::fitting((string)$a['type'], $remote));
            $gname = null;
            foreach ($remote as $r) if ($r['qbo_id'] === $guess) $gname = $r['name'];
            if ($gname) $out['matched']++;
            $out['rows'][] = ['code' => (string)$a['code'], 'name' => (string)$a['name'], 'guess' => $gname, 'qbo_id' => $guess];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Accept a part ("Looks right") — local test site only
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, message:string} */
    public function apply(string $part, int $userId): array
    {
        if (!$this->local) return ['ok' => false, 'message' => 'This is a preview: nothing is imported into this business from QuickBooks.'];
        if (!$this->hasSnapshot()) return ['ok' => false, 'message' => 'Read QuickBooks first.'];
        switch ($part) {
            case 'business':  $r = $this->applyBusiness(); break;
            case 'clients':   $r = $this->applyClients(); break;
            case 'services':  $r = $this->applyServices($userId); break;
            case 'suppliers': $r = $this->applySuppliers(); break;
            case 'accounts':  $r = $this->applyAccounts($userId); break;
            default: return ['ok' => false, 'message' => 'No such part.'];
        }
        $this->markPart($part, $r['message'], $userId);
        return $r;
    }

    private function applyBusiness(): array
    {
        $b = $this->business();
        $sets = []; $vals = [];
        foreach (['company_name' => $b['name'], 'company_address' => $b['address'], 'company_phone' => $b['phone'], 'company_email' => $b['email'], 'gst_registration' => $b['gst_number']] as $col => $v) {
            if (trim($v) !== '') { $sets[] = "$col = ?"; $vals[] = $v; }
        }
        if ($sets) {
            $u = $this->db->prepare('UPDATE business_settings SET ' . implode(', ', $sets) . ' WHERE id = 1');
            $u->execute($vals);
        }
        return ['ok' => true, 'message' => 'Business details saved' . ($b['gst_number'] ? ' (GST ' . $b['gst_number'] . ')' : '') . '.'];
    }

    private function applyClients(): array
    {
        if (!class_exists('ContactService')) require_once dirname(__DIR__, 2) . '/Contacts/Services/ContactService.php';
        $cs = new ContactService($this->db);
        $all = $this->rows('customer');
        $made = 0; $props = 0; $skipped = 0; $contactOf = [];
        $terms = [];
        foreach ($this->rows('term') as $t) $terms[(string)$t['Id']] = (string)$t['Name'];
        foreach ($all as $c) {
            if (($c['Active'] ?? true) === false || !empty($c['ParentRef']['value'])) continue;
            $email = strtolower(trim((string)($c['PrimaryEmailAddr']['Address'] ?? '')));
            [$g, $f] = self::personName($c);
            $ship = self::address($c['ShipAddr'] ?? null) ?? self::address($c['BillAddr'] ?? null);
            $bill = self::address($c['BillAddr'] ?? null) ?? $ship;
            if ($m = $this->matchContact($email, $g, $f, $ship['address'] ?? '', (string)$c['Id'])) {
                // Already in Ittai: its jobs (sub-customers) still go onto it (Codex review 2026-10-20).
                $contactOf[(string)$c['Id']] = $m[0];
                $skipped++;
                continue;
            }
            $co = self::companyOf($c);
            $id = $cs->createContact([
                'first_name' => $g, 'last_name' => $f, 'email' => $email,
                'phone' => (string)($c['PrimaryPhone']['FreeFormNumber'] ?? ''), 'mobile' => (string)($c['Mobile']['FreeFormNumber'] ?? ''),
                // CASL: imported clients are not subscribers — no marketing, no texts until they say yes.
                'receive_marketing' => 0, 'receive_sms' => 0, 'consent_quote_followup' => 0,
                'notes' => self::importNote((string)$c['Id']),
                'property_address' => $ship['address'] ?? '', 'property_city' => $ship['city'] ?? '', 'property_postal_code' => $ship['postal_code'] ?? '',
                'property_latitude' => null, 'property_longitude' => null,
                'link_company' => $co !== null, 'company_mode' => 'new', 'company_name' => (string)$co, 'company_type' => 'business',
                'billing_address' => $bill['address'] ?? '', 'billing_city' => $bill['city'] ?? '', 'billing_province' => $bill['province'] ?? '',
                'billing_postal_code' => $bill['postal_code'] ?? '', 'account_status' => 'active',
                'payment_terms' => $terms[(string)($c['SalesTermRef']['value'] ?? '')] ?? 'Net 30', 'pref_attach_pdf' => 1,
            ]);
            $contactOf[(string)$c['Id']] = $id;
            $made++;
        }
        foreach ($all as $c) {
            if (empty($c['ParentRef']['value'])) continue;
            $parent = $contactOf[(string)$c['ParentRef']['value']] ?? null;
            $a = self::address($c['ShipAddr'] ?? null);
            if (!$parent || !$a) continue;
            $r = $cs->addPropertyToContact($parent, ['address' => $a['address'], 'city' => $a['city'], 'province' => $a['province'], 'postal_code' => $a['postal_code'],
                                                     'property_name' => (string)($c['DisplayName'] ?? $a['address'])]);
            if (!empty($r['created'])) $props++;
        }
        return ['ok' => true, 'message' => sprintf('%d clients added for Sam%s%s.', $made, $props ? ", $props extra properties" : '', $skipped ? ", $skipped already in Ittai left as they were" : '')];
    }

    private function applyServices(int $userId): array
    {
        $cat = $this->db->prepare('SELECT id FROM product_categories WHERE name = ? LIMIT 1');
        $cat->execute([self::CATEGORY]);
        $catId = (int)$cat->fetchColumn();
        if (!$catId) {
            $this->db->prepare('INSERT INTO product_categories (name, description, active) VALUES (?, ?, 1)')
                     ->execute([self::CATEGORY, 'Services read from QuickBooks during onboarding — check prices and move them into your own groups.']);
            $catId = (int)$this->db->lastInsertId();
        }
        $unit = (int)($this->db->query("SELECT id FROM unit_types ORDER BY (LOWER(name) IN ('each', 'service', 'visit')) DESC, id LIMIT 1")->fetchColumn() ?: 1);
        $ins = $this->db->prepare('INSERT INTO products (category_id, name, description, unit_type_id, base_price, taxable, active, created_by) VALUES (?, ?, ?, ?, ?, 1, 1, ?)');
        $made = 0;
        foreach ($this->services()['rows'] as $r) {
            if ($r['status'] !== 'new') continue;
            $ins->execute([$catId, mb_substr($r['name'], 0, 200), $r['description'] ?: ($r['group'] ? 'From QuickBooks: ' . $r['group'] : null), $unit, $r['price'] ?? 0, $userId ?: null]);
            $made++;
        }
        return ['ok' => true, 'message' => $made . ' services added to Otto\'s catalogue under "' . self::CATEGORY . '".'];
    }

    private function applySuppliers(): array
    {
        $ins = $this->db->prepare('INSERT INTO vendors (name, phone, notes, is_active) VALUES (?, ?, ?, 1)');
        $made = 0;
        foreach ($this->suppliers()['rows'] as $r) {
            if ($r['status'] !== 'new') continue;
            $ins->execute([mb_substr($r['name'], 0, 255), mb_substr($r['phone'], 0, 20) ?: null, 'Imported from QuickBooks.']);
            $made++;
        }
        return ['ok' => true, 'message' => $made . ' suppliers added for Penny.'];
    }

    private function applyAccounts(int $userId): array
    {
        $set = 0;
        foreach ($this->accounts()['rows'] as $r) {
            if (!$r['guess']) continue;
            if ($this->conn && $this->conn->company()) {
                if ($this->conn->setMapping('account', $r['code'], (string)$r['qbo_id'], $userId)) $set++;
                continue;
            }
            // The local rehearsal (no connection): the guesses are kept as the mapping a connection would start from.
            $this->db->prepare('DELETE FROM qbo_mappings WHERE site_id = ? AND kind = ? AND ittai_key = ?')->execute([$this->siteId, 'account', $r['code']]);
            $this->db->prepare('INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name, set_by) VALUES (?, ?, ?, ?, ?, ?)')
                     ->execute([$this->siteId, 'account', $r['code'], (string)$r['qbo_id'], $r['guess'], $userId ?: null]);
            $set++;
        }
        return ['ok' => true, 'message' => $set . ' accounts matched. Check the rest on the QuickBooks page.'];
    }

    private function markPart(string $part, string $summary, int $userId): void
    {
        $now = date('Y-m-d H:i:s', ($this->clock)());
        $u = $this->db->prepare('UPDATE qbo_onboarding_parts SET summary = ?, applied_by = ?, applied_at = ? WHERE site_id = ? AND part = ?');
        $u->execute([mb_substr($summary, 0, 500), $userId ?: null, $now, $this->siteId, $part]);
        // MySQL counts changed rows: an identical re-accept in the same second reports 0. Insert only when missing.
        $chk = $this->db->prepare('SELECT 1 FROM qbo_onboarding_parts WHERE site_id = ? AND part = ?');
        $chk->execute([$this->siteId, $part]);
        if ($chk->fetchColumn() === false) {
            $this->db->prepare('INSERT INTO qbo_onboarding_parts (site_id, part, summary, applied_by, applied_at) VALUES (?, ?, ?, ?, ?)')
                     ->execute([$this->siteId, $part, mb_substr($summary, 0, 500), $userId ?: null, $now]);
        }
    }

    /**
     * What accepting each part WOULD do, from the snapshot — shown on a row before Accept (Claudia walk 1: the rows
     * were blank until accepted). Empty array before the first read. A part that fails to preview is left out.
     * @return array<string, string>
     */
    public function partPreviews(): array
    {
        if (!$this->hasSnapshot()) return [];
        // Worked out once per read and stored (Codex 2026-11-03: the clients preview runs queries per customer,
        // too much for every screen load). A snapshot read before this existed is worked out on first use.
        $stored = $this->storedPreviews();
        if ($stored !== null) return $stored;
        $out = $this->computePreviews();
        $this->storePreviews($out);
        return $out;
    }

    public const PREVIEWS_KEY = 'qbo_onboarding_previews';

    /** @return array<string,string>|null */
    private function storedPreviews(): ?array
    {
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::PREVIEWS_KEY]);
            $v = $s->fetchColumn();
            if ($v === false || $v === null || $v === '') return null;
            $d = json_decode((string)$v, true);
            return is_array($d) ? array_map('strval', $d) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function storePreviews(?array $previews): void
    {
        try {
            if ($previews === null) { $this->db->prepare('DELETE FROM ops_settings WHERE setting_key = ?')->execute([self::PREVIEWS_KEY]); return; }
            $json = (string)json_encode($previews);
            $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
            $u->execute([$json, self::PREVIEWS_KEY]);
            $chk = $this->db->prepare('SELECT 1 FROM ops_settings WHERE setting_key = ?');
            $chk->execute([self::PREVIEWS_KEY]);
            if ($chk->fetchColumn() === false) $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')
                ->execute([self::PREVIEWS_KEY, $json, 'QuickBooks onboarding: what each part would bring in, as of the last read']);
        } catch (Throwable $e) {
            error_log('[qbo-onboarding] previews not stored: ' . get_class($e));
        }
    }

    /** @return array<string,string> */
    private function computePreviews(): array
    {
        $out = [];
        $try = function (string $part, callable $f) use (&$out): void {
            try { $out[$part] = (string)$f(); } catch (Throwable $e) { error_log('[qbo-onboarding] preview ' . $part . ' failed: ' . get_class($e)); }
        };
        $try('business', function (): string {
            $b = $this->business();
            $bits = array_values(array_filter([$b['name'], $b['gst_number'] !== '' ? 'GST ' . $b['gst_number'] : '']));
            return $bits ? implode(' · ', $bits) : 'Nothing to bring in.';
        });
        $try('clients', function (): string {
            $c = $this->clients();
            return self::countLine($c['new'], $c['match'], 'client', 'clients') . ($c['properties'] ? ', ' . $c['properties'] . ' extra ' . ($c['properties'] === 1 ? 'property' : 'properties') : '');
        });
        $try('services', function (): string { $s = $this->services(); return self::countLine($s['new'], $s['match'], 'service', 'services'); });
        $try('suppliers', function (): string { $s = $this->suppliers(); return self::countLine($s['new'], $s['match'], 'supplier', 'suppliers'); });
        $try('accounts', function (): string { $a = $this->accounts(); return $a['matched'] . ' of ' . $a['total'] . ' accounts matched'; });
        return $out;
    }

    /** "3 clients to add, 2 already here" / "Nothing new — 2 already here" / "None found". */
    public static function countLine(int $new, int $match, string $one, string $many): string
    {
        if ($new === 0 && $match === 0) return 'None found';
        $here = $match ? $match . ' already here' : '';
        if ($new === 0) return 'Nothing new — ' . $here;
        return $new . ' ' . ($new === 1 ? $one : $many) . ' to add' . ($here !== '' ? ', ' . $here : '');
    }

    /** @return array<string, string> part => what accepting it did */
    public function partSummaries(): array
    {
        try {
            $s = $this->db->prepare('SELECT part, summary FROM qbo_onboarding_parts WHERE site_id = ?');
            $s->execute([$this->siteId]);
            return $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
