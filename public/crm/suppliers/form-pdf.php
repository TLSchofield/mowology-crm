<?php
/**
 * A supplier account form, as Otto filled it (or the original with ?which=original) — admins only.
 * Linked from Otto's "supplier_form" suggestion. Files live in STORAGE_ROOT (closed to the web);
 * this page is the only way to see them. Thin controller: SupplierFormService holds the logic.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Admins only'); }

require_once APP_ROOT . '/Modules/Operations/Services/SupplierFormService.php';
$svc = new SupplierFormService(getDB());
$f = $svc->ready() ? $svc->get((int)($_GET['id'] ?? 0)) : null;
if (!$f) { http_response_code(404); exit('Not found'); }
$rel = ($_GET['which'] ?? '') === 'original' || empty($f['filled_path']) ? $f['original_path'] : $f['filled_path'];
$path = rtrim(STORAGE_ROOT, '/') . '/' . $rel;
if (!is_file($path)) { http_response_code(404); exit('File missing'); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$f['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
