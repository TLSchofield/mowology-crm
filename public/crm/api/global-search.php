<?php
/**
 * Global Search API — Spotlight/Command Palette
 *
 * GET ?q=<term>  (min 2 chars)
 * Uses FULLTEXT indexes (MATCH AGAINST BOOLEAN MODE) for 3+ char queries.
 * Falls back to LIKE for 2-char queries (below innodb_ft_min_token_size).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
session_write_close();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$db   = getDB();
$like = '%' . $q . '%';

$tokens       = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
$isMultiToken = count($tokens) > 1;
// Multi-word queries and queries with any short word (< 3 chars) break FULLTEXT — use LIKE instead
$ft = strlen($q) >= 3
    && !$isMultiToken
    && !array_reduce($tokens, fn($c, $t) => $c || strlen($t) < 3, false);

// Boolean-mode term: +word* means "must start with word"
// Sanitise to remove FULLTEXT operator chars that could break the query
$ftTerm = '+' . preg_replace('/[+\-><\(\)~*"@]+/', '', $q) . '*';

// Build (field1 LIKE ? OR field2 LIKE ?) AND (...) for each token.
// Each token must appear in at least one of the supplied fields.
function buildTokenLike(array $tokens, array $fields): array {
    $clauses = [];
    $params  = [];
    foreach ($tokens as $t) {
        $like     = '%' . $t . '%';
        $perField = array_map(fn($f) => "$f LIKE ?", $fields);
        $clauses[] = '(' . implode(' OR ', $perField) . ')';
        foreach ($fields as $_) $params[] = $like;
    }
    return [implode(' AND ', $clauses), $params];
}

$results = [];

// ── Contacts ─────────────────────────────────────────────────────────────
try {
    if ($ft) {
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, phone
            FROM contacts
            WHERE is_active = 1
              AND MATCH(first_name, last_name, email) AGAINST(? IN BOOLEAN MODE)
            ORDER BY first_name, last_name LIMIT 5
        ");
        $stmt->execute([$ftTerm]);
    } elseif ($isMultiToken) {
        [$tokenSql, $tokenParams] = buildTokenLike($tokens, ['first_name', 'last_name', 'email']);
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, phone
            FROM contacts
            WHERE is_active = 1
              AND $tokenSql
            ORDER BY first_name, last_name LIMIT 5
        ");
        $stmt->execute($tokenParams);
    } else {
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, phone
            FROM contacts
            WHERE is_active = 1
              AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
            ORDER BY first_name, last_name LIMIT 5
        ");
        $stmt->execute([$like, $like, $like]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'category' => 'Contacts',
            'icon'     => 'user',
            'label'    => trim($r['first_name'] . ' ' . $r['last_name']),
            'sublabel' => $r['email'] ?: $r['phone'] ?: '',
            'url'      => '/crm/clients_appstack.php?action=view_contact&id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { error_log('global-search contacts: ' . $e->getMessage()); }

// ── Properties ───────────────────────────────────────────────────────────
try {
    if ($ft) {
        $stmt = $db->prepare("
            SELECT p.id, p.address, p.city, p.site_contact_id
            FROM properties p
            WHERE p.status = 'active'
              AND MATCH(p.address, p.city) AGAINST(? IN BOOLEAN MODE)
            ORDER BY p.address LIMIT 5
        ");
        $stmt->execute([$ftTerm]);
    } else {
        $stmt = $db->prepare("
            SELECT p.id, p.address, p.city, p.site_contact_id
            FROM properties p
            WHERE p.status = 'active'
              AND (p.address LIKE ? OR p.city LIKE ?)
            ORDER BY p.address LIMIT 5
        ");
        $stmt->execute([$like, $like]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'category' => 'Properties',
            'icon'     => 'map-pin',
            'label'    => $r['address'],
            'sublabel' => $r['city'] ?: '',
            'url'      => $r['site_contact_id']
                ? '/crm/clients_appstack.php?action=view_contact&id=' . $r['site_contact_id']
                : '/crm/clients_appstack.php',
        ];
    }
} catch (PDOException $e) { error_log('global-search properties: ' . $e->getMessage()); }

// ── Quotes ───────────────────────────────────────────────────────────────
try {
    if ($ft) {
        $stmt = $db->prepare("
            SELECT id, quote_number, title, status, total_amount
            FROM quotes
            WHERE MATCH(quote_number, title) AGAINST(? IN BOOLEAN MODE)
            ORDER BY created_at DESC LIMIT 4
        ");
        $stmt->execute([$ftTerm]);
    } else {
        $stmt = $db->prepare("
            SELECT id, quote_number, title, status, total_amount
            FROM quotes WHERE quote_number LIKE ? OR title LIKE ?
            ORDER BY created_at DESC LIMIT 4
        ");
        $stmt->execute([$like, $like]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sublabel = $r['status'];
        if ($r['total_amount']) $sublabel .= ' · $' . number_format((float)$r['total_amount'], 2);
        $results[] = [
            'category' => 'Quotes',
            'icon'     => 'file-text',
            'label'    => $r['quote_number'] . ($r['title'] ? ' — ' . $r['title'] : ''),
            'sublabel' => $sublabel,
            'url'      => '/crm/quotes/view.php?id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { error_log('global-search quotes: ' . $e->getMessage()); }

// ── Job Plans ────────────────────────────────────────────────────────────
try {
    if ($ft) {
        $stmt = $db->prepare("
            SELECT id, plan_number, title, status, service_type
            FROM job_plans
            WHERE MATCH(plan_number, title) AGAINST(? IN BOOLEAN MODE)
            ORDER BY created_at DESC LIMIT 4
        ");
        $stmt->execute([$ftTerm]);
    } else {
        $stmt = $db->prepare("
            SELECT id, plan_number, title, status, service_type
            FROM job_plans WHERE plan_number LIKE ? OR title LIKE ?
            ORDER BY created_at DESC LIMIT 4
        ");
        $stmt->execute([$like, $like]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sublabel = $r['status'];
        if ($r['service_type']) $sublabel .= ' · ' . $r['service_type'];
        $results[] = [
            'category' => 'Jobs',
            'icon'     => 'briefcase',
            'label'    => $r['plan_number'] . ($r['title'] ? ' — ' . $r['title'] : ''),
            'sublabel' => $sublabel,
            'url'      => '/crm/jobs/view.php?id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { error_log('global-search jobs: ' . $e->getMessage()); }

// ── Invoices ─────────────────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT id, invoice_number, status, total
        FROM invoices WHERE invoice_number LIKE ?
        ORDER BY created_at DESC LIMIT 3
    ");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sublabel = $r['status'];
        if ($r['total']) $sublabel .= ' · $' . number_format((float)$r['total'], 2);
        $results[] = [
            'category' => 'Invoices',
            'icon'     => 'credit-card',
            'label'    => $r['invoice_number'],
            'sublabel' => $sublabel,
            'url'      => '/crm/invoices/view.php?id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { error_log('global-search invoices: ' . $e->getMessage()); }

// ── Team Members ─────────────────────────────────────────────────────────
try {
    if ($isMultiToken) {
        [$tokenSql, $tokenParams] = buildTokenLike($tokens, ['full_name', 'email']);
        $stmt = $db->prepare("
            SELECT id, full_name, email, role
            FROM users WHERE is_active = 1 AND $tokenSql
            ORDER BY full_name LIMIT 3
        ");
        $stmt->execute($tokenParams);
    } else {
        $stmt = $db->prepare("
            SELECT id, full_name, email, role
            FROM users WHERE is_active = 1 AND (full_name LIKE ? OR email LIKE ?)
            ORDER BY full_name LIMIT 3
        ");
        $stmt->execute([$like, $like]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'category' => 'Team',
            'icon'     => 'users',
            'label'    => $r['full_name'],
            'sublabel' => $r['role'] . ($r['email'] ? ' · ' . $r['email'] : ''),
            'url'      => '/crm/team/index.php',
        ];
    }
} catch (PDOException $e) { error_log('global-search team: ' . $e->getMessage()); }

echo json_encode(['success' => true, 'results' => $results]);
