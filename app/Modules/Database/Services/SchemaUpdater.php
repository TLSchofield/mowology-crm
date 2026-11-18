<?php
declare(strict_types=1);

/** A statement the database refused; `$mysqlCode` is MySQL's own error number (0 when unknown). */
class SchemaUpdaterException extends RuntimeException
{
    public int $mysqlCode;

    public function __construct(string $message, int $mysqlCode = 0)
    {
        parent::__construct($message);
        $this->mysqlCode = $mysqlCode;
    }
}

/** The database side of the updater, so tests need no MySQL. */
interface SchemaUpdaterStore
{
    /** One run per database at a time. False when another run holds the lock. */
    public function lock(): bool;

    public function unlock(): void;

    public function hasLog(): bool;

    public function createLog(): void;

    /** @return array<string, array{status: string, checksum: string, error: string, at: int}> per file: its success row if any, else its latest failed row */
    public function logRows(): array;

    /** Runs one statement on the schema connection; throws SchemaUpdaterException. */
    public function run(string $statement): void;

    public function recordSuccess(string $file, string $checksum, string $notes): void;

    /** Replaces any earlier failed row for the file. */
    public function recordFailed(string $file, string $checksum, string $error, string $notes): void;
}

/**
 * Applies pending migration files (database/migrations/NNNN_name.sql, number >= FIRST_AUTOMATIC or listed in
 * ADOPTED) to one business's database, in order, and records each in migrations_log. Older files are history.
 * Cron: app/Modules/Database/Cron/schema_update.php, once per business. Statements must be safe to run twice.
 */
final class SchemaUpdater
{
    public const FIRST_AUTOMATIC = 1445;

    /** Older files the updater also owns (checked against production by the main session). */
    public const ADOPTED = [];

    /** MySQL errors that mean "already there": table exists, duplicate column, duplicate key name, cannot drop. */
    public const TOLERATED = [1050, 1060, 1061, 1091];

    /** A failed file is tried again after this long: a lock wait, a lost connection or a missing grant must not block it for good. */
    public const RETRY_AFTER_SEC = 1800;

    /** A file changed this recently may still be uploading (the deploy checks its size only afterwards): it waits. */
    public const SETTLE_SEC = 120;

    private SchemaUpdaterStore $store;
    private string $dir;

    public function __construct(SchemaUpdaterStore $store, string $dir)
    {
        $this->store = $store;
        $this->dir = rtrim($dir, '/');
    }

    /** First existing migrations folder: the web root's (where deploy-migration.sh uploads), then the project root's. */
    public static function resolveDir(?array $candidates = null): ?string
    {
        $candidates = $candidates ?? [PUBLIC_ROOT . '/database/migrations', PROJECT_ROOT . '/database/migrations'];
        foreach ($candidates as $c) {
            if (is_dir($c)) return $c;
        }
        return null;
    }

    public static function isAutomatic(string $name): bool
    {
        if (in_array($name, self::ADOPTED, true)) return true;
        return preg_match('/^(\d{4,})_[a-z0-9_]+\.sql$/', $name, $m) === 1 && (int)$m[1] >= self::FIRST_AUTOMATIC;
    }

    /** Automatic file names from a list of names: number ascending, then name. */
    public static function select(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if (self::isAutomatic((string)$n)) $out[] = (string)$n;
        }
        usort($out, static function (string $a, string $b): int {
            $na = (int)$a;
            $nb = (int)$b;
            return $na !== $nb ? $na <=> $nb : strcmp($a, $b);
        });
        return $out;
    }

    /**
     * @return array{pending: string[], applied: string[], skipped: int, failed: ?array{file: string, error: string, earlier: bool},
     *               waiting: int, warnings: string[], created_log: bool, dry_run: bool}
     */
    public function run(bool $dryRun = false, bool $retry = false, ?int $now = null): array
    {
        $result = ['pending' => [], 'applied' => [], 'skipped' => 0, 'failed' => null, 'waiting' => 0,
                   'warnings' => [], 'created_log' => false, 'dry_run' => $dryRun, 'locked' => false, 'settling' => null];
        if ($dryRun) return $this->runLocked($result, true, $retry, $now ?? time());
        if (!$this->store->lock()) {
            $result['locked'] = true;
            return $result;
        }
        try {
            return $this->runLocked($result, false, $retry, $now ?? time());
        } finally {
            $this->store->unlock();
        }
    }

    private function runLocked(array $result, bool $dryRun, bool $retry, int $now): array
    {

        $hasLog = $this->store->hasLog();
        if (!$hasLog && !$dryRun) {
            $this->store->createLog();
            $result['created_log'] = true;
            $hasLog = true;
        }
        $rows = $hasLog ? $this->store->logRows() : [];

        $names = is_dir($this->dir) ? (scandir($this->dir) ?: []) : [];
        foreach ($names as $n) {
            // A file numbered for the updater but named so it is not picked up would otherwise be skipped in silence.
            if (preg_match('/^(\d{4,})[_-].*\.sql$/i', (string)$n, $m) === 1 && (int)$m[1] >= self::FIRST_AUTOMATIC && !self::isAutomatic((string)$n)) {
                $result['warnings'][] = $n . ' is not named NNNN_lower_case.sql (ignored)';
            }
        }
        $pending = [];
        foreach (self::select($names) as $name) {
            $sql = (string)@file_get_contents($this->dir . '/' . $name);
            $sum = hash('sha256', $sql);
            $row = $rows[$name] ?? null;
            if ($row !== null && $row['status'] === 'success') {
                if ($row['checksum'] !== '' && $row['checksum'] !== $sum) {
                    $result['warnings'][] = $name . ' changed after it was applied (not re-run)';
                }
                continue;
            }
            $pending[] = ['name' => $name, 'sql' => $sql, 'sum' => $sum, 'row' => $row];
        }
        $result['pending'] = array_column($pending, 'name');
        if ($dryRun) return $result;

        foreach ($pending as $i => $p) {
            $row = $p['row'];
            $mtime = (int)@filemtime($this->dir . '/' . $p['name']);
            if ($mtime > 0 && $now - $mtime < self::SETTLE_SEC) {
                $result['settling'] = $p['name'];
                $result['waiting'] = count($pending) - $i;
                return $result;
            }
            if ($row !== null && $row['status'] === 'failed' && !$retry && $row['checksum'] === $p['sum']
                && $now - (int)($row['at'] ?? 0) < self::RETRY_AFTER_SEC) {
                $result['failed'] = ['file' => $p['name'], 'error' => $row['error'], 'earlier' => true];
                $result['waiting'] = count($pending) - $i - 1;
                return $result;
            }
            $ran = 0;
            $there = 0;
            try {
                $statements = self::splitStatements($p['sql']);
                // An unreadable or empty file must never be recorded as applied.
                if (!$statements) throw new SchemaUpdaterException('the file has no statements (empty or unreadable)');
                foreach ($statements as $statement) {
                    // The splitter cuts at every ';', so a trigger or procedure body would run in pieces.
                    if (preg_match('/^DELIMITER\b/i', $statement) === 1) throw new SchemaUpdaterException('DELIMITER is not supported: run this file by hand');
                }
                foreach ($statements as $statement) {
                    try {
                        $this->store->run($statement);
                        $ran++;
                    } catch (SchemaUpdaterException $e) {
                        if (!in_array($e->mysqlCode, self::TOLERATED, true)) throw $e;
                        $there++;
                    }
                }
            } catch (SchemaUpdaterException $e) {
                $this->store->recordFailed($p['name'], $p['sum'], $e->getMessage(), 'automatic');
                $result['failed'] = ['file' => $p['name'], 'error' => $e->getMessage(), 'earlier' => false];
                $result['waiting'] = count($pending) - $i - 1;
                return $result;
            }
            $this->store->recordSuccess($p['name'], $p['sum'], 'automatic (' . $ran . ' run, ' . $there . ' already there)');
            $result['applied'][] = $p['name'];
            $result['skipped'] += $there;
        }
        return $result;
    }

    /** One readable line for the cron log, naming the business. */
    public static function summary(string $business, array $r): string
    {
        $line = 'schema_update ' . $business . ': ';
        if ($r['failed'] !== null) {
            $f = $r['failed'];
            $line .= ($f['earlier'] ? 'failed earlier: ' : 'FAILED ') . $f['file'] . ': ' . $f['error']
                . '; ' . $r['waiting'] . ' ' . ($r['waiting'] === 1 ? 'file' : 'files') . ' waiting';
        } elseif (!empty($r['locked'])) {
            $line .= 'another run holds the lock, nothing done';
        } elseif ($r['dry_run']) {
            $n = count($r['pending']);
            $line .= 'dry run, ' . $n . ' pending' . ($n ? ' (' . implode(', ', $r['pending']) . ')' : '');
        } else {
            $n = count($r['applied']);
            $line .= $n . ' applied' . ($n ? ' (' . implode(', ', $r['applied']) . ')' : '')
                . (!empty($r['settling']) ? ', ' . $r['waiting'] . ' waiting (' . $r['settling'] . ' was changed in the last two minutes)' : ', 0 pending');
        }
        if ($r['warnings']) $line .= '; warning: ' . implode('; ', $r['warnings']);
        return $line;
    }

    public static function failed(array $r): bool
    {
        return $r['failed'] !== null;
    }

    /**
     * Run for the business this process was started for. The per-business fan-out leaves the original business out,
     * so business_crons.php calls this for it in its own process.
     * @return array{summary: string, failed: bool, error: ?string, warnings: bool}
     */
    public static function runHere(string $business, PDO $ddl, bool $dryRun = false, bool $retry = false): array
    {
        $dir = self::resolveDir();
        if ($dir === null) {
            return ['summary' => 'schema_update ' . $business . ': FAILED no migrations folder found', 'failed' => true,
                    'error' => 'no migrations folder', 'warnings' => false];
        }
        $r = (new self(new PdoSchemaUpdaterStore($ddl), $dir))->run($dryRun, $retry);
        return ['summary' => self::summary($business, $r), 'failed' => self::failed($r),
                'error' => $r['failed']['error'] ?? null, 'warnings' => (bool)$r['warnings']];
    }

    /**
     * Split a SQL string into individual statements, respecting quoted strings.
     * Handles single-quoted strings with escaped quotes ('it''s ok').
     * Strips SQL comments (-- line comments and block comments).
     * Returns only non-empty statements.
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            // Skip -- line comments
            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                $i = ($end === false) ? $len : $end + 1;
                continue;
            }

            // Skip /* block comments */
            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = ($end === false) ? $len : $end + 2;
                continue;
            }

            // Skip # line comments (at the start of a line only: '#' also appears inside values)
            if ($ch === '#' && trim(substr($current, (int)strrpos("\n" . $current, "\n"))) === '') {
                $end = strpos($sql, "\n", $i);
                $i = ($end === false) ? $len : $end + 1;
                continue;
            }

            // Quoted strings and identifiers: '…', "…" and `…`, with doubled-quote and backslash escapes
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $q = $ch;
                $current .= $ch;
                $i++;
                while ($i < $len) {
                    if ($q !== '`' && $sql[$i] === '\\' && $i + 1 < $len) {
                        $current .= $sql[$i] . $sql[$i + 1];
                        $i += 2;
                    } elseif ($sql[$i] === $q && $i + 1 < $len && $sql[$i + 1] === $q) {
                        $current .= $q . $q;
                        $i += 2;
                    } elseif ($sql[$i] === $q) {
                        $current .= $q;
                        $i++;
                        break;
                    } else {
                        $current .= $sql[$i];
                        $i++;
                    }
                }
                continue;
            }

            // Statement terminator
            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        // Last statement (may not end with ;)
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}

/** The real thing: the business's schema connection (Database::ddl()). */
final class PdoSchemaUpdaterStore implements SchemaUpdaterStore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function lock(): bool
    {
        // Named for this database: MySQL 5.7 lock names are server-wide.
        $s = $this->db->query("SELECT GET_LOCK(CONCAT('schema_update:', DATABASE()), 0)");
        $got = (int)$s->fetchColumn() === 1;
        $s->closeCursor();
        return $got;
    }

    public function unlock(): void
    {
        try {
            $this->db->query("SELECT RELEASE_LOCK(CONCAT('schema_update:', DATABASE()))")->closeCursor();
        } catch (Throwable $e) {
            // The lock ends with the connection anyway.
        }
    }

    public function hasLog(): bool
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute(['migrations_log']);
        return (int)$s->fetchColumn() > 0;
    }

    public function createLog(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS migrations_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            migration_filename VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_by INT NULL,
            status ENUM('success','failed','rollback') NOT NULL DEFAULT 'success',
            error_message TEXT NULL,
            migration_type ENUM('sql','php') NOT NULL DEFAULT 'sql',
            checksum VARCHAR(64) NULL,
            notes TEXT NULL,
            UNIQUE KEY uq_migration_filename (migration_filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    public function logRows(): array
    {
        $rows = [];
        foreach ($this->db->query("SELECT migration_filename, status, checksum, error_message, UNIX_TIMESTAMP(executed_at) AS at FROM migrations_log ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string)$r['migration_filename'];
            if (isset($rows[$name]) && $rows[$name]['status'] === 'success' && $r['status'] !== 'success') continue;
            $rows[$name] = ['status' => (string)$r['status'], 'checksum' => (string)($r['checksum'] ?? ''),
                            'error' => (string)($r['error_message'] ?? ''), 'at' => (int)($r['at'] ?? 0)];
        }
        return $rows;
    }

    public function run(string $statement): void
    {
        try {
            // query() + closeCursor(), as the Migrations tab does: PREPARE/EXECUTE migrations can return a result set.
            $this->db->query($statement)->closeCursor();
        } catch (PDOException $e) {
            throw new SchemaUpdaterException($e->getMessage(), (int)($e->errorInfo[1] ?? 0));
        }
    }

    public function recordSuccess(string $file, string $checksum, string $notes): void
    {
        $this->db->prepare('DELETE FROM migrations_log WHERE migration_filename = ?')->execute([$file]);
        $this->db->prepare("INSERT INTO migrations_log (migration_filename, executed_by, status, checksum, migration_type, notes)
            VALUES (?, NULL, 'success', ?, 'sql', ?)")->execute([$file, $checksum, $notes]);
    }

    public function recordFailed(string $file, string $checksum, string $error, string $notes): void
    {
        $this->db->prepare('DELETE FROM migrations_log WHERE migration_filename = ?')->execute([$file]);
        $this->db->prepare("INSERT INTO migrations_log (migration_filename, executed_by, status, error_message, checksum, migration_type, notes)
            VALUES (?, NULL, 'failed', ?, ?, 'sql', ?)")->execute([$file, $error, $checksum, $notes]);
    }
}
