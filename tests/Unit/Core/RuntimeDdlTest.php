<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fable security audit #27 (2026-10-12): production's app MySQL user becomes DATA-ONLY
 * (SELECT, INSERT, UPDATE, DELETE, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES). Any
 * CREATE / ALTER / DROP / TRUNCATE run from ordinary request or cron code then fails —
 * CREATE TABLE IF NOT EXISTS included (MySQL checks the privilege before existence), and
 * TRUNCATE needs DROP. Schema changes belong in database/migrations, run by the migration
 * runners on the DDL connection (Database::ddl() / useDdlForRequest()).
 *
 * This test fails when a new file runs DDL outside that. The allow-list holds the sites that
 * only run when a table/column is MISSING (never on production, where all exist — checked
 * 2026-10-12), admin repair tools behind migrationRunnerGuard, and Database::ddl() callers.
 */
final class RuntimeDdlTest extends TestCase
{
    private const ALLOWED = [
        // Only when the table/column is missing (exists on production 2026-10-12):
        'app/Modules/Settings/Api/service-types.php',
        'app/Modules/Settings/Api/ops-settings.php',
        'app/Modules/Contacts/Services/ContactService.php',
        'public/crm/clients_appstack.php',
        'app/Modules/Products/Api/api-products.php',
        // Admin repair tool, columns already present; fails harmlessly as data-only:
        'app/Modules/Database/Api/fix-properties-columns.php',
        // Behind migrationRunnerGuard() → DDL connection:
        'public/crm/api/purge-home-pings.php',
        'public/crm/api/migrate-ppv-to-net.php',
        // Uses Database::ddl() until migration 1345 has run:
        'public/crm/api/measurement-groups.php',
    ];

    public function test_no_new_runtime_ddl(): void
    {
        $root = dirname(__DIR__, 3);
        $re = '/["\']\s*(CREATE\s+(UNIQUE\s+)?(TABLE|INDEX|VIEW|TRIGGER|PROCEDURE|FUNCTION)|ALTER\s+TABLE|DROP\s+(TABLE|INDEX|VIEW)|RENAME\s+TABLE)\b|["\']\s*TRUNCATE\s+(TABLE\s+)?`?\w/i';
        $hits = [];
        foreach (['app', 'public'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = substr($f->getPathname(), strlen($root) + 1);
                if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false) continue;
                if (preg_match('#(^|/)run-(migration|fix)-[^/]*\.php$|/migrations-manager\.php$|/database/migrations/#', $p)) continue;
                $src = (string)file_get_contents($f->getPathname());
                if (strpos($src, 'migrationRunnerGuard()') !== false && strpos($p, 'MigrationRunnerGuard.php') === false) continue;
                if (preg_match($re, $src) && !in_array($p, self::ALLOWED, true)) $hits[] = $p;
            }
        }
        $this->assertSame([], $hits, 'Runtime DDL fails under the data-only DB user — write a migration instead (or Database::ddl() for an admin tool)');
    }
}
