<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Core/CronLock.php';
require_once APP_ROOT . '/Core/BusinessCrons.php';
require_once APP_ROOT . '/Core/BusinessCronFanout.php';

if (!Tenant::isDefault()) {
    echo "Business cron fanout only runs for the default business\n";
    exit(0);
}

\App\Core\CronLock::acquire('business_crons');
$started = microtime(true);
try {
    $db = getDB();
    $now = time();
    $last = $now - 300;
    $read = $db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1');
    $read->execute(['business_crons_last_tick']);
    $stored = $read->fetchColumn();
    if ($stored !== false && preg_match('/^[0-9]+$/', (string)$stored)) $last = (int)$stored;

    // Advance first so a crashed child cannot cause a retry storm. (Tim, 2026-11-04)
    $write = $db->prepare('INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $write->execute(['business_crons_last_tick', (string)$now]);

    $due = BusinessCrons::due($last, $now);
    $keys = Tenant::registeredKeys();
    $pairs = [];
    foreach ($keys as $key) {
        foreach ($due as $cronKey) {
            $cron = BusinessCrons::REGISTRY[$cronKey];
            $pairs[] = ['key' => $key, 'script' => BusinessCrons::scriptPath($cron['path']),
                        'timeout' => $cron['timeout'], 'cron' => $cronKey];
        }
    }
    $results = BusinessCronFanout::runPairs($pairs);
    // The fan-out covers the other businesses only; the original business's database is updated here, in this process.
    $ownUpdate = null;
    if (in_array('schema_update', $due, true)) {
        require_once APP_ROOT . '/Modules/Database/Services/SchemaUpdater.php';
        $t0 = microtime(true);
        try {
            $ownUpdate = SchemaUpdater::runHere('mowology', Database::ddl());
        } catch (Throwable $e) {
            $ownUpdate = ['summary' => 'schema_update mowology: FAILED ' . $e->getMessage(), 'failed' => true,
                          'error' => $e->getMessage(), 'warnings' => false];
        }
        try {
            recordCronRun('schema_update', $ownUpdate['failed'] ? 'error' : ($ownUpdate['warnings'] ? 'warning' : 'success'),
                $ownUpdate['summary'], (int)round((microtime(true) - $t0) * 1000), $ownUpdate['error'], false);
        } catch (Throwable $e) {
            error_log('[schema_update] could not record the run: ' . $e->getMessage());
        }
        if ($ownUpdate['failed']) {
            $results[] = ['key' => 'mowology', 'cron' => 'schema_update', 'exit' => 1, 'last_line' => $ownUpdate['summary']];
        }
    }
    $failed = array_values(array_filter($results, static function (array $r): bool {
        return (int)$r['exit'] !== 0;
    }));
    if (!$results) {
        $summary = 'nothing due';
    } else {
        $ok = count($results) - count($failed);
        $summary = count($results) . ' run(s) for ' . count(array_unique(array_column($results, 'key'))) . ' business(es): ' . $ok . ' ok';
        if ($failed) {
            $parts = [];
            foreach ($failed as $r) $parts[] = $r['key'] . '/' . $r['cron'] . ' (exit ' . (int)$r['exit'] . ')';
            $summary .= '; failed: ' . implode(', ', $parts);
        }
    }
    $error = $failed ? implode('; ', array_map(static function (array $r): string {
        return $r['key'] . '/' . $r['cron'] . ' exit ' . (int)$r['exit']
            . ((string)$r['last_line'] !== '' ? ': ' . $r['last_line'] : '');
    }, $failed)) : null;
    recordCronRun('business_crons', $failed ? 'warning' : 'success', $summary,
        (int)round((microtime(true) - $started) * 1000), $error, false);
    echo $summary . "\n";
} catch (Throwable $e) {
    $error = cronFailureDetail($e);
    recordCronRun('business_crons', 'warning', 'business cron fanout failed',
        (int)round((microtime(true) - $started) * 1000), $error, false);
    echo "business cron fanout failed: {$error}\n";
    exit(1);
}
