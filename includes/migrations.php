<?php
/**
 * /crm/includes/migrations.php
 * Database Migration Execution Framework
 *
 * Provides functions to:
 * - Discover migrations in /database/migrations/
 * - Track applied migrations in migrations_log table
 * - Execute SQL migrations safely
 * - Log execution history for audit trail
 */

/**
 * Get the base directory for migrations relative to web root
 */
function getMigrationsDirectory() {
    return dirname(dirname(__DIR__)) . '/database/migrations';
}

/**
 * Read migration file safely with validation
 * @param string $filename Migration filename only (no path)
 * @return string|false File contents or false on failure
 */
function readMigrationFile($filename) {
    // Validate filename - must be alphanumeric, underscores, hyphens, dots
    if (!preg_match('/^[\da-zA-Z_\-\.]+\.sql$/', $filename)) {
        error_log("Invalid migration filename format: {$filename}");
        return false;
    }

    $filepath = getMigrationsDirectory() . '/' . $filename;

    // Ensure file is within migrations directory (prevent path traversal)
    if (realpath($filepath) === false || strpos(realpath($filepath), realpath(getMigrationsDirectory())) !== 0) {
        error_log("Path traversal attempt detected: {$filename}");
        return false;
    }

    if (!file_exists($filepath) || !is_readable($filepath)) {
        error_log("Migration file not found or unreadable: {$filepath}");
        return false;
    }

    return file_get_contents($filepath);
}

/**
 * Parse migration metadata from comment block
 * Expects format:
 * /**
 *  * Migration: Title Here
 *  * Date: YYYY-MM-DD
 *  * Purpose: What this migration does
 *  * /
 *
 * @param string $content SQL file contents
 * @return array Metadata array with keys: title, date, purpose
 */
function parseMigrationMetadata($content) {
    $metadata = [
        'title' => 'Unknown',
        'date' => 'Unknown',
        'purpose' => 'Unknown'
    ];

    // Extract title
    if (preg_match('/\*\s*Migration:\s*(.+?)[\r\n]/i', $content, $matches)) {
        $metadata['title'] = trim($matches[1]);
    }

    // Extract date
    if (preg_match('/\*\s*Date:\s*(.+?)[\r\n]/i', $content, $matches)) {
        $metadata['date'] = trim($matches[1]);
    }

    // Extract purpose
    if (preg_match('/\*\s*Purpose:\s*(.+?)[\r\n]/i', $content, $matches)) {
        $metadata['purpose'] = trim($matches[1]);
    }

    return $metadata;
}

/**
 * Calculate checksum of migration file
 * @param string $content File contents
 * @return string MD5 hash
 */
function getMigrationChecksum($content) {
    return md5($content);
}

/**
 * Log migration execution to migrations_log table
 * @param string $filename Migration filename
 * @param string $status 'success', 'failed', or 'rollback'
 * @param int $userId User ID who executed migration
 * @param string|null $errorMessage Error message if failed
 * @param string $checksum MD5 hash of file
 * @return bool Success
 */
function logMigrationExecution($filename, $status, $userId, $errorMessage = null, $checksum = '') {
    try {
        $db = getDB();

        // Check if this migration is already logged
        $stmt = $db->prepare("SELECT id FROM migrations_log WHERE migration_filename = ? LIMIT 1");
        $stmt->execute([$filename]);
        $existing = $stmt->fetch();

        if ($existing && $status === 'success') {
            // Already applied successfully - skip duplicate logging
            return true;
        }

        if ($existing && $status !== 'success') {
            // Update failed entry
            $stmt = $db->prepare("
                UPDATE migrations_log
                SET status = ?, error_message = ?, executed_by = ?, executed_at = NOW(), checksum = ?
                WHERE migration_filename = ?
            ");
            return $stmt->execute([$status, $errorMessage, $userId, $checksum, $filename]);
        }

        // New entry
        $stmt = $db->prepare("
            INSERT INTO migrations_log (migration_filename, executed_by, status, error_message, checksum)
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$filename, $userId, $status, $errorMessage, $checksum]);
    } catch (Exception $e) {
        error_log("Error logging migration: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a migration has been applied
 * @param string $filename Migration filename
 * @return bool True if successfully applied
 */
function isMigrationApplied($filename) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM migrations_log WHERE migration_filename = ? AND status = 'success' LIMIT 1");
        $stmt->execute([$filename]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        // Table might not exist yet
        return false;
    }
}

/**
 * Execute a migration SQL file
 * @param string $filename Migration filename only
 * @param int $userId User ID executing the migration
 * @return array Result array: ['success' => bool, 'message' => string, 'error' => string|null]
 */
function executeMigration($filename, $userId) {
    $db = getDB();

    // Read migration file
    $content = readMigrationFile($filename);
    if ($content === false) {
        return [
            'success' => false,
            'message' => 'Failed to read migration file',
            'error' => "File not found or invalid: {$filename}"
        ];
    }

    // Check if already applied
    if (isMigrationApplied($filename)) {
        return [
            'success' => true,
            'message' => 'Migration already applied',
            'error' => null
        ];
    }

    // Get checksum
    $checksum = getMigrationChecksum($content);

    try {
        // Split by semicolon to handle multiple statements
        // Remove comments and empty statements
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*\n/', $content)),
            function($stmt) {
                return !empty($stmt) && strpos(trim($stmt), '--') !== 0;
            }
        );

        // Execute each statement
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $db->exec($statement . ';');
            }
        }

        // Log success
        logMigrationExecution($filename, 'success', $userId, null, $checksum);

        return [
            'success' => true,
            'message' => "Migration executed successfully: {$filename}",
            'error' => null
        ];
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        error_log("Migration failed: {$filename} - {$errorMsg}");

        // Log failure
        logMigrationExecution($filename, 'failed', $userId, $errorMsg, $checksum);

        return [
            'success' => false,
            'message' => 'Migration failed',
            'error' => $errorMsg
        ];
    }
}

/**
 * Get list of all pending migrations (not in migrations_log with success status)
 * @return array Array of migration filenames
 */
function getMigrationsPending() {
    try {
        $dir = getMigrationsDirectory();

        if (!is_dir($dir)) {
            return [];
        }

        $files = array_filter(
            scandir($dir),
            function($file) {
                return preg_match('/\.sql$/', $file);
            }
        );

        // Filter to only pending (not successfully applied)
        return array_filter($files, function($file) {
            return !isMigrationApplied($file);
        });
    } catch (Exception $e) {
        error_log("Error getting pending migrations: " . $e->getMessage());
        return [];
    }
}

/**
 * Get list of all applied migrations from migrations_log
 * @return array Array of migration records
 */
function getMigrationsApplied() {
    try {
        $db = getDB();
        $stmt = $db->query("
            SELECT migration_filename, executed_at, executed_by, status, error_message
            FROM migrations_log
            WHERE status = 'success'
            ORDER BY executed_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
    } catch (Exception $e) {
        error_log("Error getting applied migrations: " . $e->getMessage());
        return [];
    }
}

/**
 * Get migrations filtered by status
 * @param string $status 'success', 'failed', or 'all'
 * @return array Array of migration records
 */
function getMigrationsByStatus($status = 'all') {
    try {
        $db = getDB();

        if ($status === 'all') {
            $stmt = $db->query("
                SELECT migration_filename, executed_at, executed_by, status, error_message
                FROM migrations_log
                ORDER BY executed_at DESC
            ");
        } else {
            $stmt = $db->prepare("
                SELECT migration_filename, executed_at, executed_by, status, error_message
                FROM migrations_log
                WHERE status = ?
                ORDER BY executed_at DESC
            ");
            $stmt->execute([$status]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
    } catch (Exception $e) {
        error_log("Error getting migrations by status: " . $e->getMessage());
        return [];
    }
}

/**
 * Get migration details (metadata + file info)
 * @param string $filename Migration filename
 * @return array|null Migration details or null if not found
 */
function getMigrationDetails($filename) {
    $content = readMigrationFile($filename);
    if ($content === false) {
        return null;
    }

    $metadata = parseMigrationMetadata($content);
    $applied = isMigrationApplied($filename);
    $size = strlen($content);
    $filesize = filesize(getMigrationsDirectory() . '/' . $filename);

    // Get creation time
    $filepath = getMigrationsDirectory() . '/' . $filename;
    $created = date('Y-m-d H:i:s', filectime($filepath));

    return [
        'filename' => $filename,
        'title' => $metadata['title'],
        'date' => $metadata['date'],
        'purpose' => $metadata['purpose'],
        'applied' => $applied,
        'created_at' => $created,
        'file_size' => $filesize,
        'content_size' => $size
    ];
}
