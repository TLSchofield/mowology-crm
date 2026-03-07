<?php
/**
 * Run Migration — Jobber Re-Consent Campaign tables
 * Admin-only, one-time use.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [

    // jobber_reconsent_queue — tracks imported Jobber contacts and FSA approval
    "CREATE TABLE IF NOT EXISTS jobber_reconsent_queue (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        contact_id     INT NOT NULL,
        fsa            VARCHAR(3),
        fsa_approved   TINYINT(1) NOT NULL DEFAULT 0,
        status         ENUM('pending_approval','queued','sent','failed','skipped') NOT NULL DEFAULT 'pending_approval',
        sent_at        DATETIME,
        error_message  VARCHAR(500),
        attempts       INT NOT NULL DEFAULT 0,
        import_batch   VARCHAR(50) NOT NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fsa          (fsa),
        KEY idx_status       (status),
        KEY idx_fsa_approved (fsa_approved),
        KEY idx_contact      (contact_id),
        KEY idx_batch        (import_batch),
        CONSTRAINT fk_jrq_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Add import_source column to contacts for tracking Jobber imports
    "ALTER TABLE contacts ADD COLUMN import_source VARCHAR(50) DEFAULT NULL",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false) {
            $results[] = "SKIP[$i] Column already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration Reconsent — Results:\n\n" . implode("\n", $results) . "\n\nDone.";
