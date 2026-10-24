<?php
declare(strict_types=1);

/**
 * TenantProvisioner — set up a new business on this install, from the original business's structure
 * (Ittai founding businesses, 2026-10-23). Run by the owner, one approved business at a time; never by a visitor.
 *
 * Steps (each logged; safe to re-run — an existing database/table/row is kept):
 *   1. cPanel UAPI: create the database <cpanel>_<key> and grant the install's database user ALL on it
 *      (CPANEL_USER / CPANEL_API_TOKEN in secrets.php; called on https://localhost:2083 only).
 *   2. Copy every BASE TABLE's structure (SHOW CREATE TABLE) — no data.
 *   3. Copy REFERENCE tables' rows (universal: GIFI codes, tax rates, roles/permissions, catalogue taxonomy…)
 *      and migrations_log (so every migration counts as applied).
 *   4. Copy the chart of accounts CLEANED of the original business's own details (cleanChart()).
 *      Seed business_settings (name).
 *   5. Make its uploads folder (Uploads::ensureTenantFolder), then register it in app_config/tenants.php (locked).
 *   6. LAST, the owner's login (a one-time password, returned once, stored hashed) — a retry before a run has
 *      finished once issues a fresh one, so an interrupted run never strands a password nobody saw.
 *
 * No namespace / no autoloader in production: require_once.
 */
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/Uploads.php';

final class TenantProvisioner
{
    /** Universal reference data a new business starts with. Nothing here belongs to the original business. */
    public const REFERENCE_TABLES = [
        'migrations_log', 'gifi_codes', 'tax_rates', 'permissions', 'roles', 'role_permissions', 'cms_block_types',
        'catalog_attribute_defs', 'catalog_attribute_values', 'catalog_categories', 'catalog_category_map',
        'catalog_families', 'catalog_size_equivalents', 'municipal_rules', 'unit_types', 'cert_tiers',
        'quiz_categories', 'data_retention_settings',
    ];

    private PDO $db;          // the original business's connection
    private array $log = [];
    /** @var callable|null (string $fn, array $args): array — a stand-in for cPanel (local rehearsal, tests) */
    private $uapiStub;

    public function __construct(PDO $db, ?callable $uapiStub = null)
    {
        $this->db = $db;
        $this->uapiStub = $uapiStub;
    }

    /** Pure: a business key — lowercase letters and digits, 3–20, not reserved. */
    public static function validKey(string $key): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9]{2,19}$/', $key) && !in_array($key, [Tenant::DEFAULT, 'www', 'mail', 'admin', 'api', 'app', 'ittai'], true);
    }

    /**
     * The only chart_of_accounts columns a new business gets. Free text (description, gifi_note), account numbers,
     * who confirmed a GIFI code and timestamps never leave the original business (Codex 2026-10-23: an allow-list,
     * not a list of known names).
     */
    public const CHART_COLUMNS = [
        'id', 'code', 'gifi_code', 'gifi_status', 'lead_group', 'name', 'type', 'sub_type', 'normal_balance',
        'parent_id', 'is_system', 'is_active', 'display_order', 'expense_category_alias',
    ];

    /**
     * Pure: the chart of accounts without the original business's own details — only CHART_COLUMNS; named
     * shareholder loans dropped (one generic "Due from shareholder" stays); masked or long digit runs, and a bank's
     * or payroll provider's name, stripped from account names.
     * @param list<array<string,mixed>> $rows chart_of_accounts rows
     * @return list<array<string,mixed>>
     */
    public static function cleanChart(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $name = (string)($r['name'] ?? '');
            $sub  = (string)($r['sub_type'] ?? '');
            // A loan account in a person's name ("Shareholder loan — Katrenna Marenych") is the original business's.
            if ($sub === 'shareholder_loan' && preg_match('/[—–-]\s*\S+\s+\S+/u', $name)) continue;
            // A loan's own lender/asset ("Loan Payable — RAM 3500HD") is the original business's: the generic name stays.
            if ($sub === 'loan') $name = (string)preg_replace('/\s*[—–-]\s.*$/u', '', $name);
            $name = (string)preg_replace('/\s*[—–-]\s[^—–]*\d{3,}.*$/u', '', $name);                     // "Vehicle Loan — RAM 3500HD"
            $name = (string)preg_replace('/\s*\([^()]*\d{3,}[^()]*\)/u', '', $name);                  // "(Savings ••6827)", even mis-encoded
            $name = (string)preg_replace('/\s*(?:[^\x00-\x7F]+|\*\*|#|x{2,})\s*\d{3,}/iu', '', $name);  // "••6827", "#123456"
            $name = (string)preg_replace('/\s*\d{5,}/u', '', $name);                                     // an account number
            $name = (string)preg_replace('/\s*[—–-]\s*(Wave|Vancity|Jobber|QuickBooks|Stripe|RBC|TD|BMO|CIBC|Scotiabank|Coast Capital|ATB|Desjardins)\b.*$/iu', '', $name);
            $name = trim((string)preg_replace('/\s*\(\s*\)/u', '', $name));
            if ($name === '') continue;
            $clean = [];
            foreach (self::CHART_COLUMNS as $c) {
                if (array_key_exists($c, $r)) $clean[$c] = $r[$c];
            }
            $clean['name'] = $name;
            $out[] = $clean;
        }
        return $out;
    }

    /** Pure: tenants.php with one entry added or replaced (other entries kept). */
    public static function registryFile(array $existing, string $key, array $entry): string
    {
        $existing[$key] = $entry;
        ksort($existing);
        return "<?php\n// Businesses on this install (Ittai). Written by TenantProvisioner. Not secret: no passwords here\n"
            . "// (a business with its own database user puts db_user/db_pass in secrets.php only).\n"
            . '$ITTAI_TENANTS = ' . var_export($existing, true) . ";\n";
    }

    /**
     * Set up a business. Returns ['ok' => bool, 'log' => list<string>, 'owner_password' => ?string (shown once)].
     * @param array{key:string, name:string, owner_email:string, owner_first:string, owner_last?:string} $b
     */
    public function provision(array $b): array
    {
        $key = strtolower(trim((string)$b['key']));
        $name = trim((string)$b['name']);
        $email = filter_var(trim((string)$b['owner_email']), FILTER_VALIDATE_EMAIL) ?: '';
        if (!self::validKey($key)) return $this->fail('That business key is not allowed (3–20 lowercase letters/digits).');
        if ($name === '' || mb_strlen($name) > 120) return $this->fail('A business name is needed.');
        if ($email === '') return $this->fail('A valid owner email is needed.');
        $cpUser = defined('CPANEL_USER') ? (string)CPANEL_USER : ($this->uapiStub ? 'mowology' : '');
        if ($cpUser === '' || (!defined('CPANEL_API_TOKEN') && !$this->uapiStub) || !defined('DB_USER')) return $this->fail('cPanel API token or database user is not configured.');
        $dbName = $cpUser . '_' . $key;
        $host = $key . Tenant::ROUTED_SUFFIX;
        if (defined('DB_NAME') && $dbName === DB_NAME) return $this->fail('That would be the original business\'s database.');

        // 1. Database + grant (cPanel).
        $have = $this->uapi('Mysql/list_databases');
        if (!$have['ok']) return $this->fail('cPanel: ' . $have['error']);
        $names = array_column((array)$have['data'], 'database');
        if (!in_array($dbName, $names, true)) {
            $c = $this->uapi('Mysql/create_database', ['name' => $dbName]);
            if (!$c['ok']) return $this->fail('cPanel create_database: ' . $c['error']);
            $this->log[] = "Created database {$dbName}.";
        } else {
            $this->log[] = "Database {$dbName} already exists — kept.";
        }
        $g = $this->uapi('Mysql/set_privileges_on_database', ['user' => DB_USER, 'database' => $dbName, 'privileges' => 'ALL PRIVILEGES']);
        if (!$g['ok']) return $this->fail('cPanel set_privileges_on_database: ' . $g['error']);
        $this->log[] = 'Granted the install\'s database user on it.';
        if (defined('DB_DDL_USER') && (string)DB_DDL_USER !== '' && DB_DDL_USER !== DB_USER) {
            $g2 = $this->uapi('Mysql/set_privileges_on_database', ['user' => DB_DDL_USER, 'database' => $dbName, 'privileges' => 'ALL PRIVILEGES']);
            if (!$g2['ok']) return $this->fail('cPanel set_privileges_on_database (schema user): ' . $g2['error']);
        }
        // Tables are created on the schema-owning connection (Database::ddl()); the everyday user may be data-only.
        $ddl = class_exists('Database') ? Database::ddl() : $this->db;

        // 2–4. Structure, reference rows, cleaned chart (same MySQL server, so cross-database statements).
        $src = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        $q = fn(string $id) => '`' . str_replace('`', '``', $id) . '`';
        // Session-scoped: off on BOTH connections — tables are made on $ddl, rows copied on $this->db (Codex 2026-10-23).
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        if ($ddl !== $this->db) $ddl->exec('SET FOREIGN_KEY_CHECKS = 0');
        $wasRegistered = isset(self::readRegistry()[$key]);
        try {
            $tables = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            $existing = $this->db->query('SELECT table_name FROM information_schema.tables WHERE table_schema = ' . $this->db->quote($dbName))->fetchAll(PDO::FETCH_COLUMN);
            $made = 0;
            foreach ($tables as $t) {
                if (in_array($t, $existing, true)) continue;
                $create = (string)$this->db->query('SHOW CREATE TABLE ' . $q($t))->fetch(PDO::FETCH_NUM)[1];
                $create = (string)preg_replace('/\sAUTO_INCREMENT=\d+/', '', $create);
                $create = (string)preg_replace('/^CREATE TABLE `/', 'CREATE TABLE ' . $q($dbName) . '.`', $create, 1);
                $ddl->exec($create);
                $made++;
            }
            $this->log[] = "Copied the structure of {$made} tables (" . count($tables) . ' in all).';
            $copied = 0;
            foreach (self::REFERENCE_TABLES as $t) {
                if (!in_array($t, $tables, true)) continue;
                $n = (int)$this->db->query('SELECT COUNT(*) FROM ' . $q($dbName) . '.' . $q($t))->fetchColumn();
                if ($n > 0) continue;   // already filled: keep
                $copied += (int)$this->db->exec('INSERT INTO ' . $q($dbName) . '.' . $q($t) . ' SELECT * FROM ' . $q($src) . '.' . $q($t));
            }
            $this->log[] = "Copied {$copied} reference rows.";
            if (in_array('chart_of_accounts', $tables, true)
                && (int)$this->db->query('SELECT COUNT(*) FROM ' . $q($dbName) . '.`chart_of_accounts`')->fetchColumn() === 0) {
                $rows = self::cleanChart($this->db->query('SELECT * FROM `chart_of_accounts` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
                // All or nothing: a half-copied chart would read as "already filled" on a retry (Codex 2026-10-23).
                $this->db->beginTransaction();
                try {
                    foreach ($rows as $r) {
                        $cols = array_keys($r);
                        $st = $this->db->prepare('INSERT INTO ' . $q($dbName) . '.`chart_of_accounts` (' . implode(',', array_map($q, $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
                        $st->execute(array_values($r));
                    }
                    $this->db->commit();
                } catch (Throwable $e) {
                    $this->db->rollBack();
                    throw $e;
                }
                $this->log[] = 'Copied the chart of accounts (' . count($rows) . ' accounts, cleaned).';
            }

            if ((int)$this->db->query('SELECT COUNT(*) FROM ' . $q($dbName) . '.`business_settings`')->fetchColumn() === 0) {
                $this->db->prepare('INSERT INTO ' . $q($dbName) . '.`business_settings` (id, company_name, company_email) VALUES (1, ?, ?)')->execute([$name, $email]);
            }
        } finally {
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            if ($ddl !== $this->db) $ddl->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // 5. Folders, then register — both checked; the owner login comes LAST, so a failure here never strands a
        // password nobody saw (Codex 2026-10-23).
        try {
            Uploads::ensureTenantFolder($key, [$host]);
        } catch (Throwable $e) {
            return $this->fail('Uploads folder: ' . $e->getMessage());
        }
        $this->log[] = 'Made its uploads folder (own address only; receipts never served directly).';
        $entry = ['hosts' => [$host], 'db_name' => $dbName, 'app_url' => 'https://' . $host, 'name' => $name];
        if (!self::writeRegistry($key, $entry)) return $this->fail('Could not write tenants.php.');
        $this->log[] = "Registered {$host}.";

        // 6. Owner login. Until a run has finished once (the business was not registered when this run began), a
        // retry gives the owner a fresh one-time password instead of keeping one that was never shown.
        $password = null;
        $u = $this->db->prepare('SELECT id FROM ' . $q($dbName) . '.`users` WHERE email = ?');
        $u->execute([$email]);
        $ownerId = (int)$u->fetchColumn();
        if ($ownerId === 0 || !$wasRegistered) {
            $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kq'), '=');
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($ownerId === 0) {
                $first = trim((string)($b['owner_first'] ?? '')) ?: 'Owner';
                $last = trim((string)($b['owner_last'] ?? ''));
                $this->db->prepare('INSERT INTO ' . $q($dbName) . '.`users` (email, username, password_hash, role, is_active, first_name, last_name, full_name, created_at) VALUES (?, ?, ?, \'admin\', 1, ?, ?, ?, NOW())')
                    ->execute([$email, $key . '-owner', $hash, $first, $last, trim($first . ' ' . $last)]);
                $this->log[] = "Owner login created for {$email}.";
            } else {
                $this->db->prepare('UPDATE ' . $q($dbName) . '.`users` SET password_hash = ? WHERE id = ?')->execute([$hash, $ownerId]);
                $this->log[] = "Owner login for {$email} given a new one-time password (the earlier run did not finish).";
            }
        }
        return ['ok' => true, 'log' => $this->log, 'owner_password' => $password, 'host' => $host, 'db_name' => $dbName];
    }

    private static function registryPath(): string
    {
        return PUBLIC_ROOT . '/app_config/tenants.php';
    }

    /** The registry as it is on disk now (not Tenant's cached copy). */
    private static function readRegistry(): array
    {
        $file = self::registryPath();
        if (!is_file($file)) return [];
        $ITTAI_TENANTS = [];
        include $file;
        return is_array($ITTAI_TENANTS) ? $ITTAI_TENANTS : [];
    }

    /**
     * Add/replace one entry under an exclusive lock: re-read, write a temp file in the same folder, flush, rename —
     * two runs at once can never drop each other's business (Codex 2026-10-23).
     */
    private static function writeRegistry(string $key, array $entry): bool
    {
        $file = self::registryPath();
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) return false;
        try {
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            $body = self::registryFile(self::readRegistry(), $key, $entry);
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) return false;
            $ok = fwrite($fh, $body) === strlen($body) && fflush($fh);
            if (function_exists('fsync')) $ok = $ok && @fsync($fh);
            fclose($fh);
            if (!$ok || !rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** cPanel UAPI on this server only. Returns ['ok', 'data', 'error']. */
    private function uapi(string $fn, array $args = []): array
    {
        if ($this->uapiStub) return ($this->uapiStub)($fn, $args);
        $ch = curl_init('https://localhost:2083/execute/' . $fn . ($args ? '?' . http_build_query($args) : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,   // localhost: the certificate names the hostname
            CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN]]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $j = json_decode((string)$body, true);
        if (!is_array($j)) return ['ok' => false, 'data' => null, 'error' => $err ?: 'no answer'];
        return ['ok' => (int)($j['status'] ?? 0) === 1, 'data' => $j['data'] ?? null, 'error' => implode('; ', (array)($j['errors'] ?? []))];
    }

    private function fail(string $msg): array
    {
        $this->log[] = 'Stopped: ' . $msg;
        return ['ok' => false, 'log' => $this->log, 'owner_password' => null];
    }
}
