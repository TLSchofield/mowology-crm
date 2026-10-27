<?php
/**
 * LOCAL TEST ONLY — set up a second business on the local test site, the way TenantProvisioner does it on
 * production, with the cPanel step replaced by MySQL on the local socket.
 *
 *   php scripts/local/provision-tenant.php <key> "<Business name>" <owner-email> [<owner first> [<owner last>]]
 *   e.g. php scripts/local/provision-tenant.php claudia "Claudia's Garden Care" owner@claudia.test Claudia
 *
 * Result: database mowology_<key> (structure + reference rows + cleaned chart copied from mowology_local),
 * an entry in public/app_config/tenants.php (<key>.ittai.ca), its uploads folder, and an owner login whose
 * one-time password is appended to scripts/local/.credentials.local. Re-running keeps what exists.
 *
 * Refuses to run unless secrets.php is the generated LOCAL TEST ONLY file (DB_NAME mowology_local).
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Core/paths.php';
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/TenantProvisioner.php';

if (!defined('LOCAL_TEST_SITE') || DB_NAME !== 'mowology_local' || Tenant::key() !== Tenant::DEFAULT) {
    fwrite(STDERR, "REFUSING: provision-tenant.php only runs on the LOCAL TEST ONLY install (mowology_local), as the default business.\n");
    exit(1);
}
[$script, $key, $name, $email] = $argv + [null, null, null, null];
if (!$key || !$name || !$email) {
    fwrite(STDERR, "usage: php scripts/local/provision-tenant.php <key> \"<Business name>\" <owner-email> [<owner first> [<owner last>]]\n");
    exit(2);
}

// The cPanel stand-in: MySQL root over the local socket (no password, socket only — local-site.sh).
$sock = ini_get('pdo_mysql.default_socket') ?: (getenv('HOME') . '/.mowology-local/mysql.sock');
$root = new PDO('mysql:unix_socket=' . $sock . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = fn(string $id) => '`' . str_replace('`', '``', $id) . '`';
$stub = function (string $fn, array $args) use ($root, $q): array {
    switch ($fn) {
        case 'Mysql/list_databases':
            $rows = $root->query("SHOW DATABASES LIKE 'mowology\\_%'")->fetchAll(PDO::FETCH_COLUMN);
            return ['ok' => true, 'data' => array_map(fn($d) => ['database' => $d], $rows), 'error' => ''];
        case 'Mysql/create_database':
            $root->exec('CREATE DATABASE ' . $q($args['name']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            return ['ok' => true, 'data' => null, 'error' => ''];
        case 'Mysql/set_privileges_on_database':
            foreach (['localhost', '127.0.0.1'] as $h) {
                $root->exec('GRANT ALL PRIVILEGES ON ' . $q($args['database']) . '.* TO ' . $root->quote($args['user']) . '@' . $root->quote($h));
            }
            $root->exec('FLUSH PRIVILEGES');
            return ['ok' => true, 'data' => null, 'error' => ''];
    }
    return ['ok' => false, 'data' => null, 'error' => "local stub: no such UAPI call $fn"];
};

$p = new TenantProvisioner(getDB(), $stub);
$r = $p->provision([
    'key' => $key, 'name' => $name, 'owner_email' => $email,
    'owner_first' => $argv[4] ?? 'Owner', 'owner_last' => $argv[5] ?? '',
]);
foreach ($r['log'] as $line) echo "  - $line\n";
if (!$r['ok']) exit(1);

if ($r['owner_password'] !== null) {
    $cred = __DIR__ . '/.credentials.local';
    file_put_contents($cred, sprintf("\n# %s — second business on the local site (https://%s via scripts/local-android.sh)\n%-16s %-28s %s   (owner, one-time)\n",
        $name, $r['host'], $key . '-owner', $email, $r['owner_password']), FILE_APPEND);
    chmod($cred, 0600);
    echo "Owner one-time password appended to scripts/local/.credentials.local\n";
}
echo "Done: {$r['host']} -> {$r['db_name']}\n";
