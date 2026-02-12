<?php
/**
 * /crm/includes/schema-functions.php
 * Database Schema Introspection Functions
 *
 * Shared logic for querying INFORMATION_SCHEMA, generating snapshots,
 * reading/writing snapshot files, and comparing snapshots for drift detection.
 *
 * All queries are MySQL 5.7 compatible — no CTEs, window functions, or JSON functions.
 */

/**
 * Get the storage directory path for schema snapshots.
 * Creates the directory if it doesn't exist.
 *
 * @return string Absolute path to /storage/ directory
 */
function getStoragePath() {
    $path = dirname(__DIR__, 2) . '/storage';
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

/**
 * Get the history directory path for archived snapshots.
 *
 * @return string Absolute path to /storage/schema_history/ directory
 */
function getHistoryPath() {
    $path = getStoragePath() . '/schema_history';
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

/**
 * Generate a full schema snapshot from the live database.
 * Queries INFORMATION_SCHEMA for tables, columns, indexes, and foreign keys.
 *
 * @param PDO $db Database connection
 * @return array Complete snapshot array
 */
function getSchemaSnapshot(PDO $db) {
    $startTime = microtime(true);

    // Database metadata
    $dbInfo = $db->query("SELECT VERSION() as version, DATABASE() as db_name")->fetch(PDO::FETCH_ASSOC);

    // All columns
    $columnsStmt = $db->query("
        SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
               COLUMN_KEY, COLUMN_DEFAULT, EXTRA, ORDINAL_POSITION
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");
    $allColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);

    // All indexes
    $indexStmt = $db->query("
        SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
    ");
    $allIndexes = $indexStmt->fetchAll(PDO::FETCH_ASSOC);

    // Foreign keys
    $fkStmt = $db->query("
        SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
               REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ");
    $allForeignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

    // Table metadata (row counts, engine, sizes)
    $tablesStmt = $db->query("
        SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH,
               CREATE_TIME, UPDATE_TIME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ");
    $allTables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build structured snapshot
    $tables = [];
    $totalRows = 0;
    $totalSize = 0;
    $totalColumns = 0;
    $totalIndexes = 0;
    $totalForeignKeys = 0;

    // Initialize tables from table metadata
    foreach ($allTables as $tbl) {
        $tableName = $tbl['TABLE_NAME'];
        $rowCount = (int)$tbl['TABLE_ROWS'];
        $dataLen = (int)$tbl['DATA_LENGTH'];
        $indexLen = (int)$tbl['INDEX_LENGTH'];

        $totalRows += $rowCount;
        $totalSize += $dataLen + $indexLen;

        $tables[$tableName] = [
            'engine' => $tbl['ENGINE'],
            'row_count' => $rowCount,
            'data_length' => $dataLen,
            'index_length' => $indexLen,
            'created' => $tbl['CREATE_TIME'],
            'updated' => $tbl['UPDATE_TIME'],
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
        ];
    }

    // Map columns to tables
    foreach ($allColumns as $col) {
        $tableName = $col['TABLE_NAME'];
        if (!isset($tables[$tableName])) continue;

        $totalColumns++;
        $tables[$tableName]['columns'][$col['COLUMN_NAME']] = [
            'ordinal' => (int)$col['ORDINAL_POSITION'],
            'type' => $col['COLUMN_TYPE'],
            'nullable' => ($col['IS_NULLABLE'] === 'YES'),
            'key' => $col['COLUMN_KEY'],
            'default' => $col['COLUMN_DEFAULT'],
            'extra' => $col['EXTRA'],
        ];
    }

    // Map indexes to tables
    foreach ($allIndexes as $idx) {
        $tableName = $idx['TABLE_NAME'];
        if (!isset($tables[$tableName])) continue;

        $indexName = $idx['INDEX_NAME'];
        if (!isset($tables[$tableName]['indexes'][$indexName])) {
            $totalIndexes++;
            $tables[$tableName]['indexes'][$indexName] = [
                'columns' => [],
                'unique' => ($idx['NON_UNIQUE'] == 0),
            ];
        }
        $tables[$tableName]['indexes'][$indexName]['columns'][] = $idx['COLUMN_NAME'];
    }

    // Map foreign keys to tables
    foreach ($allForeignKeys as $fk) {
        $tableName = $fk['TABLE_NAME'];
        if (!isset($tables[$tableName])) continue;

        $totalForeignKeys++;
        $tables[$tableName]['foreign_keys'][$fk['CONSTRAINT_NAME']] = [
            'column' => $fk['COLUMN_NAME'],
            'references_table' => $fk['REFERENCED_TABLE_NAME'],
            'references_column' => $fk['REFERENCED_COLUMN_NAME'],
        ];
    }

    $runtime = round(microtime(true) - $startTime, 3);

    return [
        'generated_at' => date('c'),
        'generator' => 'schema_snapshot.php v1.0',
        'database' => $dbInfo['db_name'] ?? 'unknown',
        'mysql_version' => $dbInfo['version'] ?? 'unknown',
        'summary' => [
            'table_count' => count($tables),
            'total_rows' => $totalRows,
            'total_columns' => $totalColumns,
            'total_indexes' => $totalIndexes,
            'total_foreign_keys' => $totalForeignKeys,
            'total_size_mb' => round($totalSize / 1048576, 2),
            'generation_time_s' => $runtime,
        ],
        'tables' => $tables,
    ];
}

/**
 * Write a schema snapshot to disk.
 * Archives the previous snapshot to schema_history/ and prunes old history files.
 *
 * @param array  $snapshot    Snapshot data array
 * @param string $storagePath Path to /storage/ directory
 * @return bool Success
 */
function writeSchemaSnapshot(array $snapshot, $storagePath) {
    $snapshotFile = $storagePath . '/schema_snapshot.json';
    $historyDir = $storagePath . '/schema_history';

    if (!is_dir($historyDir)) {
        mkdir($historyDir, 0755, true);
    }

    // Archive existing snapshot before overwriting
    if (file_exists($snapshotFile)) {
        $existing = file_get_contents($snapshotFile);
        $existingData = json_decode($existing, true);
        $timestamp = date('Y-m-d_H-i-s');
        if ($existingData && isset($existingData['generated_at'])) {
            // Use the original generation time for the archive filename
            $origTime = strtotime($existingData['generated_at']);
            if ($origTime) {
                $timestamp = date('Y-m-d_H-i-s', $origTime);
            }
        }
        $archiveFile = $historyDir . '/snapshot_' . $timestamp . '.json';
        if (!file_exists($archiveFile)) {
            file_put_contents($archiveFile, $existing);
        }
    }

    // Write new snapshot
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $result = file_put_contents($snapshotFile, $json);

    // Prune old history files (keep last 30)
    pruneSnapshotHistory($historyDir, 30);

    return ($result !== false);
}

/**
 * Read the current schema snapshot from disk.
 *
 * @param string $storagePath Path to /storage/ directory
 * @return array|null Snapshot data or null if not found
 */
function readSchemaSnapshot($storagePath) {
    $snapshotFile = $storagePath . '/schema_snapshot.json';

    if (!file_exists($snapshotFile)) {
        return null;
    }

    $json = file_get_contents($snapshotFile);
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Failed to parse schema snapshot: ' . json_last_error_msg());
        return null;
    }

    return $data;
}

/**
 * Compare two snapshots and return the differences.
 *
 * @param array $current  Current snapshot
 * @param array $previous Previous snapshot
 * @return array Diff array with keys: tables_added, tables_removed, changes
 */
function compareSnapshots(array $current, array $previous) {
    $currentTables = $current['tables'] ?? [];
    $previousTables = $previous['tables'] ?? [];

    $currentNames = array_keys($currentTables);
    $previousNames = array_keys($previousTables);

    $tablesAdded = array_diff($currentNames, $previousNames);
    $tablesRemoved = array_diff($previousNames, $currentNames);
    $tablesCommon = array_intersect($currentNames, $previousNames);

    $changes = [];

    foreach ($tablesCommon as $tableName) {
        $curTable = $currentTables[$tableName];
        $prevTable = $previousTables[$tableName];

        $tableChanges = [
            'columns_added' => [],
            'columns_removed' => [],
            'columns_modified' => [],
            'indexes_added' => [],
            'indexes_removed' => [],
            'indexes_modified' => [],
            'fk_added' => [],
            'fk_removed' => [],
        ];

        // Compare columns
        $curCols = $curTable['columns'] ?? [];
        $prevCols = $prevTable['columns'] ?? [];
        $curColNames = array_keys($curCols);
        $prevColNames = array_keys($prevCols);

        $tableChanges['columns_added'] = array_diff($curColNames, $prevColNames);
        $tableChanges['columns_removed'] = array_diff($prevColNames, $curColNames);

        foreach (array_intersect($curColNames, $prevColNames) as $colName) {
            $curCol = $curCols[$colName];
            $prevCol = $prevCols[$colName];
            $diffs = [];

            if ($curCol['type'] !== $prevCol['type']) {
                $diffs['type'] = ['from' => $prevCol['type'], 'to' => $curCol['type']];
            }
            if ($curCol['nullable'] !== $prevCol['nullable']) {
                $diffs['nullable'] = ['from' => $prevCol['nullable'], 'to' => $curCol['nullable']];
            }
            if ($curCol['key'] !== $prevCol['key']) {
                $diffs['key'] = ['from' => $prevCol['key'], 'to' => $curCol['key']];
            }
            if ($curCol['default'] !== $prevCol['default']) {
                $diffs['default'] = ['from' => $prevCol['default'], 'to' => $curCol['default']];
            }
            if ($curCol['extra'] !== $prevCol['extra']) {
                $diffs['extra'] = ['from' => $prevCol['extra'], 'to' => $curCol['extra']];
            }

            if (!empty($diffs)) {
                $tableChanges['columns_modified'][$colName] = $diffs;
            }
        }

        // Compare indexes
        $curIdx = $curTable['indexes'] ?? [];
        $prevIdx = $prevTable['indexes'] ?? [];
        $tableChanges['indexes_added'] = array_diff(array_keys($curIdx), array_keys($prevIdx));
        $tableChanges['indexes_removed'] = array_diff(array_keys($prevIdx), array_keys($curIdx));

        foreach (array_intersect(array_keys($curIdx), array_keys($prevIdx)) as $idxName) {
            if ($curIdx[$idxName] !== $prevIdx[$idxName]) {
                $tableChanges['indexes_modified'][$idxName] = [
                    'from' => $prevIdx[$idxName],
                    'to' => $curIdx[$idxName],
                ];
            }
        }

        // Compare foreign keys
        $curFk = $curTable['foreign_keys'] ?? [];
        $prevFk = $prevTable['foreign_keys'] ?? [];
        $tableChanges['fk_added'] = array_diff(array_keys($curFk), array_keys($prevFk));
        $tableChanges['fk_removed'] = array_diff(array_keys($prevFk), array_keys($curFk));

        // Only include tables with actual changes
        $hasChanges = false;
        foreach ($tableChanges as $changeType => $items) {
            if (!empty($items)) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            $changes[$tableName] = $tableChanges;
        }
    }

    return [
        'current_generated_at' => $current['generated_at'] ?? 'unknown',
        'previous_generated_at' => $previous['generated_at'] ?? 'unknown',
        'tables_added' => array_values($tablesAdded),
        'tables_removed' => array_values($tablesRemoved),
        'tables_modified_count' => count($changes),
        'changes' => $changes,
        'has_drift' => (!empty($tablesAdded) || !empty($tablesRemoved) || !empty($changes)),
    ];
}

/**
 * List available historical snapshots.
 *
 * @param string $historyPath Path to /storage/schema_history/
 * @return array Array of snapshot metadata sorted newest first
 */
function getSnapshotHistory($historyPath) {
    if (!is_dir($historyPath)) {
        return [];
    }

    $files = glob($historyPath . '/snapshot_*.json');
    if (empty($files)) {
        return [];
    }

    $history = [];
    foreach ($files as $file) {
        $filename = basename($file);
        // Extract timestamp from filename: snapshot_2026-02-12_14-30-00.json
        $dateStr = str_replace(['snapshot_', '.json', '_'], ['', '', ' '], $filename);
        // dateStr is now like "2026-02-12 14-30-00"
        $dateStr = preg_replace('/ (\d{2})-(\d{2})-(\d{2})$/', ' $1:$2:$3', $dateStr);

        $history[] = [
            'filename' => $filename,
            'path' => $file,
            'size' => filesize($file),
            'size_kb' => round(filesize($file) / 1024, 1),
            'date' => $dateStr,
            'timestamp' => filemtime($file),
        ];
    }

    // Sort newest first
    usort($history, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    return $history;
}

/**
 * Prune old snapshot history files, keeping the most recent N files.
 *
 * @param string $historyDir Path to /storage/schema_history/
 * @param int    $keepCount  Number of files to keep
 */
function pruneSnapshotHistory($historyDir, $keepCount = 30) {
    $files = glob($historyDir . '/snapshot_*.json');
    if (count($files) <= $keepCount) {
        return;
    }

    // Sort by modification time, newest first
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    // Delete files beyond the keep count
    $toDelete = array_slice($files, $keepCount);
    foreach ($toDelete as $file) {
        unlink($file);
    }
}
