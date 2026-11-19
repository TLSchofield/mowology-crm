<?php
// Daily retry of contract invoices held for a missing PDF (creates nothing new) — contract_billing.php in
// retry-only mode. Its own file because BusinessCrons::REGISTRY entries carry no arguments.
declare(strict_types=1);
$argv = array_merge(array_slice($_SERVER['argv'] ?? [], 0, 1), ['retry-only'], array_slice($_SERVER['argv'] ?? [], 1));
$_SERVER['argv'] = $argv;
require __DIR__ . '/contract_billing.php';
