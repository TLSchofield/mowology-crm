<?php
/**
 * Global Search API — Spotlight/Command Palette
 *
 * GET ?q=<term>  (min 2 chars)
 * Returns results grouped by entity type for the global search bar.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

$db = getDB();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$term = '%' . $q . '%';
$results = [];

// ── Contacts ──────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, phone
        FROM contacts
        WHERE is_active = 1
          AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?
               OR CONCAT(first_name, ' ', last_name) LIKE ?)
        ORDER BY first_name, last_name
        LIMIT 5
    ");
    $stmt->execute([$term, $term, $term, $term, $term]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'category' => 'Contacts',
            'icon'     => 'user',
            'label'    => trim($r['first_name'] . ' ' . $r['last_name']),
            'sublabel' => $r['email'] ?: $r['phone'] ?: '',
            'url'      => '/crm/clients_appstack.php?contact_id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { /* skip silently */ }

// ── Properties ────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT p.id, p.address, p.city, p.postal_code, p.property_type,
               c.first_name, c.last_name
        FROM properties p
        LEFT JOIN contacts c ON p.site_contact_id = c.id
        WHERE p.status = 'active'
          AND (p.address LIKE ? OR p.city LIKE ? OR p.postal_code LIKE ?)
        ORDER BY p.address
        LIMIT 5
    ");
    $stmt->execute([$term, $term, $term]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $owner = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $sublabel = $r['city'] ?: '';
        if ($owner) $sublabel .= ($sublabel ? ' · ' : '') . $owner;
        $results[] = [
            'category' => 'Properties',
            'icon'     => 'map-pin',
            'label'    => $r['address'],
            'sublabel' => $sublabel,
            'url'      => '/crm/clients_appstack.php?property_id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { /* skip silently */ }

// ── Quotes ────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT q.id, q.quote_number, q.title, q.status, q.total_amount
        FROM quotes q
        WHERE q.quote_number LIKE ? OR q.title LIKE ?
        ORDER BY q.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$term, $term]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sublabel = $r['status'];
        if ($r['total_amount']) $sublabel .= ' · $' . number_format($r['total_amount'], 2);
        $results[] = [
            'category' => 'Quotes',
            'icon'     => 'file-text',
            'label'    => $r['quote_number'] . ($r['title'] ? ' — ' . $r['title'] : ''),
            'sublabel' => $sublabel,
            'url'      => '/crm/quotes/view.php?id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { /* skip silently */ }

// ── Job Plans ─────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT j.id, j.plan_number, j.title, j.status, j.service_type
        FROM job_plans j
        WHERE j.plan_number LIKE ? OR j.title LIKE ? OR j.service_type LIKE ?
        ORDER BY j.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$term, $term, $term]);
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
} catch (PDOException $e) { /* skip silently */ }

// ── Invoices ──────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT i.id, i.invoice_number, i.status, i.total, i.balance_due
        FROM invoices i
        WHERE i.invoice_number LIKE ?
        ORDER BY i.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$term]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sublabel = $r['status'];
        if ($r['total']) $sublabel .= ' · $' . number_format($r['total'], 2);
        $results[] = [
            'category' => 'Invoices',
            'icon'     => 'credit-card',
            'label'    => $r['invoice_number'],
            'sublabel' => $sublabel,
            'url'      => '/crm/invoices/view.php?id=' . $r['id'],
        ];
    }
} catch (PDOException $e) { /* skip silently */ }

// ── Team Members ──────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT id, full_name, email, role
        FROM users
        WHERE is_active = 1
          AND (full_name LIKE ? OR email LIKE ?)
        ORDER BY full_name
        LIMIT 5
    ");
    $stmt->execute([$term, $term]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'category' => 'Team',
            'icon'     => 'users',
            'label'    => $r['full_name'],
            'sublabel' => $r['role'] . ($r['email'] ? ' · ' . $r['email'] : ''),
            'url'      => '/crm/team/index.php',
        ];
    }
} catch (PDOException $e) { /* skip silently */ }

echo json_encode(['success' => true, 'results' => $results]);
