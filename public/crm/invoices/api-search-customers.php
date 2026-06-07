<?php
/**
 * Customer Search API — unified account (clients) typeahead for invoices.
 * GET /crm/invoices/api-search-customers.php?q=searchterm
 *
 * Client/Account model Phase 2: searches the single `clients` spine instead of
 * contacts + companies separately. A strata, a PM firm, and an individual are
 * all clients now, so all are findable here — this is what fixes the original
 * "strata not appearing in invoice search" bug.
 *
 * Back-compat: each result keeps `type` ('contact'|'company') and a legacy `id`
 * (the contact/company id) so the existing picker JS keeps wiring contact_id /
 * company_id unchanged, and ADDS `client_id` for the new path.
 *
 * Falls back to the legacy contacts+companies search if the clients table is
 * absent (e.g. an environment where Phase 1 hasn't been applied).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

$db = getDB();
$like = '%' . $q . '%';
$results = [];

// Detect the clients table once (graceful on pre-Phase-1 environments).
$hasClients = false;
try {
    $hasClients = (bool)$db->query("SHOW TABLES LIKE 'clients'")->fetchColumn();
} catch (PDOException $e) {
    $hasClients = false;
}

if ($hasClients) {
    // Unified search over the account spine. Matches the account's own fields
    // AND any linked person's name/email so an account is findable by whoever
    // you remember (council member, property manager, etc.).
    $stmt = $db->prepare("
        SELECT
            cl.id                                   AS client_id,
            cl.client_type                          AS client_type,
            cl.display_name                         AS display_name,
            cl.billing_email                        AS billing_email,
            cl.billing_address                      AS billing_address,
            cl.billing_city                         AS billing_city,
            cl.legacy_company_id                    AS legacy_company_id,
            cl.legacy_contact_id                    AS legacy_contact_id,
            oc.email                                AS contact_email,
            oc.mobile                               AS contact_mobile,
            oc.receive_sms                          AS contact_receive_sms,
            COALESCE(pr.address, '')                AS property_address,
            COALESCE(pr.city, '')                   AS property_city,
            COALESCE(pr.id, 0)                      AS property_id,
            m.display_name                          AS manager_name,
            m.billing_address                       AS manager_billing_address,
            m.billing_city                          AS manager_billing_city,
            m.billing_province                      AS manager_billing_province,
            m.billing_postal_code                   AS manager_billing_postal
        FROM clients cl
        LEFT JOIN contacts   oc ON oc.id = cl.legacy_contact_id
        LEFT JOIN properties pr ON pr.site_contact_id = cl.legacy_contact_id
        LEFT JOIN clients    m  ON m.id = cl.managed_by_client_id
        LEFT JOIN client_contacts cc ON cc.client_id = cl.id
        LEFT JOIN contacts   sc ON sc.id = cc.contact_id
        WHERE cl.status <> 'inactive'
          AND (
              cl.display_name    LIKE ?
           OR cl.billing_email   LIKE ?
           OR cl.billing_address LIKE ?
           OR cl.billing_phone   LIKE ?
           OR oc.email           LIKE ?
           OR oc.mobile          LIKE ?
           OR CONCAT(COALESCE(sc.first_name,''), ' ', COALESCE(sc.last_name,'')) LIKE ?
           OR sc.email           LIKE ?
          )
        ORDER BY (cl.client_type = 'individual') ASC, cl.display_name
        LIMIT 60
    ");
    $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dedupe by client_id (LEFT JOINs can fan out multiple property/contact rows).
    $seen = [];
    foreach ($rows as $row) {
        $cid = (int)$row['client_id'];
        if (isset($seen[$cid])) {
            continue;
        }
        $seen[$cid] = true;

        $isIndividual = ($row['client_type'] === 'individual');
        // Back-compat: map to the legacy entity the picker already understands.
        $legacyType = $isIndividual ? 'contact' : 'company';
        $legacyId   = $isIndividual
            ? (int)($row['legacy_contact_id'] ?? 0)
            : (int)($row['legacy_company_id'] ?? 0);

        $typeBadge = ucwords(str_replace('_', ' ', (string)$row['client_type']));
        // Join only the non-empty address parts so an empty street doesn't
        // produce a leading ", City".
        $addr = implode(', ', array_filter([
            trim((string)($row['billing_address'] ?? '')),
            trim((string)($row['billing_city'] ?? '')),
        ]));
        $email = $isIndividual ? ($row['contact_email'] ?: $row['billing_email']) : $row['billing_email'];
        $sub = $email ?: $addr;
        if (!$isIndividual && $typeBadge) {
            $sub = $sub ? ($typeBadge . ' · ' . $sub) : $typeBadge;
        }

        $results[] = [
            'type'             => $legacyType,
            'id'               => $legacyId,
            'client_id'        => $cid,
            'client_type'      => $row['client_type'],
            'label'            => $row['display_name'],
            'sublabel'         => $sub,
            'email'            => $email,
            'phone'            => $isIndividual ? $row['contact_mobile'] : null,
            'receive_sms'      => $isIndividual ? (bool)$row['contact_receive_sms'] : false,
            'property_address' => $row['property_address'],
            'property_city'    => $row['property_city'],
            'property_id'      => (int)$row['property_id'],
            // Managing firm (for managed accounts) → bill-to address auto-fill.
            'manager_name'             => $row['manager_name'] ?? null,
            'manager_billing_address'  => $row['manager_billing_address'] ?? null,
            'manager_billing_city'     => $row['manager_billing_city'] ?? null,
            'manager_billing_province' => $row['manager_billing_province'] ?? null,
            'manager_billing_postal'   => $row['manager_billing_postal'] ?? null,
        ];
    }

    // ── Billing entities (Option B): a labelled property (strata #, building,
    // corporation, or owner name) billed C/O its PM-firm account. Lets you find
    // "VR15-40" / "Oakridge Gardens" directly and resolve to (firm + property).
    try {
        // Resolve the property's PM-firm ACCOUNT from whichever link the data
        // has: properties.client_id (clients spine), or the legacy company links
        // company_id / property_manager_id mapped to their backfilled client via
        // clients.legacy_company_id. This BRIDGES the property-edit UI (which sets
        // property_manager_id / company_id) to the clients-based billing flow, so
        // a property configured through the UI is invoiceable with no rewrite.
        $propCols = [];
        foreach ($db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $propCols[$c] = true;
        }
        $firmJoins = [];
        $firmAlias = [];
        if (isset($propCols['client_id']))           { $firmJoins[] = "LEFT JOIN clients clc  ON clc.id = p.client_id";                          $firmAlias[] = 'clc'; }
        if (isset($propCols['company_id']))          { $firmJoins[] = "LEFT JOIN clients clco ON clco.legacy_company_id = p.company_id";          $firmAlias[] = 'clco'; }
        if (isset($propCols['property_manager_id'])) { $firmJoins[] = "LEFT JOIN clients clpm ON clpm.legacy_company_id = p.property_manager_id"; $firmAlias[] = 'clpm'; }
        // COALESCE the same field across every available firm-account alias.
        $pick = function (string $field) use ($firmAlias): string {
            if (!$firmAlias) return 'NULL';
            return 'COALESCE(' . implode(', ', array_map(fn($a) => "$a.$field", $firmAlias)) . ')';
        };
        $nameMatch = $firmAlias ? "{$firmAlias[0]}.display_name LIKE ?" : "p.billing_entity_name LIKE ?";
        $beStmt = $db->prepare("
            SELECT p.id AS property_id, p.billing_entity_name, p.address, p.city,
                   {$pick('id')}                  AS firm_client_id,
                   {$pick('display_name')}        AS firm_name,
                   {$pick('legacy_company_id')}   AS firm_legacy_company_id,
                   {$pick('billing_address')}     AS firm_billing_address,
                   {$pick('billing_city')}        AS firm_billing_city,
                   {$pick('billing_province')}    AS firm_billing_province,
                   {$pick('billing_postal_code')} AS firm_billing_postal,
                   {$pick('billing_email')}       AS firm_billing_email
            FROM properties p
            " . implode("\n            ", $firmJoins) . "
            WHERE p.billing_entity_name IS NOT NULL AND p.billing_entity_name <> ''
              AND ( p.billing_entity_name LIKE ? OR p.address LIKE ? OR {$nameMatch} )
            ORDER BY p.billing_entity_name
            LIMIT 30
        ");
        $beStmt->execute([$like, $like, $like]);
        $beResults = [];
        foreach ($beStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $firm = $r['firm_name'] ?? '';
            $sub  = trim(($firm ? 'C/O ' . $firm : '') . ($r['address'] ? ' · ' . $r['address'] : ''), ' ·');
            $beResults[] = [
                'type'                => 'billing_entity',
                'id'                  => (int)($r['firm_legacy_company_id'] ?? 0),
                'client_id'           => (int)($r['firm_client_id'] ?? 0),
                'label'               => $r['billing_entity_name'],
                'sublabel'            => $sub,
                'email'               => $r['firm_billing_email'],
                'billing_entity_name' => $r['billing_entity_name'],
                'firm_name'           => $firm,
                'property_id'         => (int)$r['property_id'],
                'property_address'    => $r['address'],
                'property_city'       => $r['city'],
                // Firm's own address → billing auto-fill (reuses the manager_* path).
                'manager_name'             => $firm,
                'manager_billing_address'  => $r['firm_billing_address'],
                'manager_billing_city'     => $r['firm_billing_city'],
                'manager_billing_province' => $r['firm_billing_province'],
                'manager_billing_postal'   => $r['firm_billing_postal'],
            ];
        }
        // Billing entities first (most specific), then clients.
        $results = array_merge($beResults, $results);
    } catch (PDOException $e) {
        // billing_entity_name column absent — skip silently.
    }

    echo json_encode(['results' => $results]);
    exit;
}

// ── Legacy fallback (pre-Phase-1): search contacts + companies directly ──
$stmt = $db->prepare("
    SELECT 'contact' AS type, c.id AS id,
           CONCAT(c.first_name, ' ', c.last_name) AS label,
           c.email AS email, c.mobile AS phone, c.receive_sms AS receive_sms,
           COALESCE(p.address, '') AS property_address,
           COALESCE(p.city, '')    AS property_city,
           COALESCE(p.id, 0)       AS property_id
    FROM contacts c
    LEFT JOIN properties p ON p.site_contact_id = c.id
    WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?
       OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
    ORDER BY c.first_name, c.last_name
    LIMIT 20
");
$stmt->execute([$like, $like, $like, $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $results[] = [
        'type'             => 'contact',
        'id'               => (int)$row['id'],
        'client_id'        => null,
        'label'            => $row['label'],
        'sublabel'         => $row['email'] ?: '',
        'email'            => $row['email'],
        'phone'            => $row['phone'],
        'receive_sms'      => (bool)$row['receive_sms'],
        'property_address' => $row['property_address'],
        'property_city'    => $row['property_city'],
        'property_id'      => (int)$row['property_id'],
    ];
}

$stmt2 = $db->prepare("
    SELECT id, company_name, billing_email
    FROM companies
    WHERE company_name LIKE ? OR billing_email LIKE ?
    ORDER BY company_name
    LIMIT 10
");
$stmt2->execute([$like, $like]);
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $results[] = [
        'type'      => 'company',
        'id'        => (int)$row['id'],
        'client_id' => null,
        'label'     => $row['company_name'],
        'sublabel'  => $row['billing_email'] ?: '',
        'email'     => $row['billing_email'],
    ];
}

echo json_encode(['results' => $results]);
