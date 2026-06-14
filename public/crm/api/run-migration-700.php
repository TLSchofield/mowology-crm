<?php
/**
 * Run Migration 700 — Marketing Automation Engine tables
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

    // contact_tags
    "CREATE TABLE IF NOT EXISTS contact_tags (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        contact_id INT NOT NULL,
        tag        VARCHAR(100) NOT NULL,
        added_by   INT,
        added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_contact_tag (contact_id, tag),
        KEY idx_tag     (tag),
        KEY idx_contact (contact_id),
        CONSTRAINT fk_ct_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        CONSTRAINT fk_ct_user    FOREIGN KEY (added_by)   REFERENCES users(id)    ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // automation_rules
    "CREATE TABLE IF NOT EXISTS automation_rules (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        name              VARCHAR(255) NOT NULL,
        description       TEXT,
        is_enabled        TINYINT(1) NOT NULL DEFAULT 1,
        trigger_type      VARCHAR(100) NOT NULL,
        trigger_config    TEXT,
        conditions        TEXT,
        actions           TEXT,
        cooldown_hours    INT NOT NULL DEFAULT 0,
        max_executions    INT NOT NULL DEFAULT 0,
        execution_count   INT NOT NULL DEFAULT 0,
        last_executed_at  DATETIME,
        created_by        INT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_trigger (trigger_type),
        KEY idx_enabled (is_enabled),
        CONSTRAINT fk_ar_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // automation_logs
    "CREATE TABLE IF NOT EXISTS automation_logs (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        rule_id            INT NOT NULL,
        contact_id         INT,
        trigger_type       VARCHAR(100),
        trigger_data       TEXT,
        conditions_matched TINYINT(1) DEFAULT 0,
        actions_taken      TEXT,
        status             ENUM('triggered','conditions_failed','completed','failed') NOT NULL DEFAULT 'triggered',
        error_message      TEXT,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rule    (rule_id),
        KEY idx_contact (contact_id),
        KEY idx_created (created_at),
        CONSTRAINT fk_al_rule    FOREIGN KEY (rule_id)    REFERENCES automation_rules(id) ON DELETE CASCADE,
        CONSTRAINT fk_al_contact FOREIGN KEY (contact_id) REFERENCES contacts(id)         ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // queued_actions
    "CREATE TABLE IF NOT EXISTS queued_actions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        rule_id      INT,
        contact_id   INT,
        action_type  VARCHAR(100) NOT NULL,
        action_data  TEXT,
        status       ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
        attempts     INT NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 3,
        scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        started_at   DATETIME,
        completed_at DATETIME,
        error_message TEXT,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status    (status),
        KEY idx_scheduled (scheduled_at),
        KEY idx_rule      (rule_id),
        KEY idx_contact   (contact_id),
        CONSTRAINT fk_qa_rule    FOREIGN KEY (rule_id)    REFERENCES automation_rules(id) ON DELETE SET NULL,
        CONSTRAINT fk_qa_contact FOREIGN KEY (contact_id) REFERENCES contacts(id)         ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // campaign_attribution
    "CREATE TABLE IF NOT EXISTS campaign_attribution (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id      INT NOT NULL,
        send_id          INT,
        contact_id       INT,
        attribution_type ENUM('click','quote_created','job_booked','invoice_paid') NOT NULL,
        entity_type      VARCHAR(50),
        entity_id        INT,
        revenue          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        attributed_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_campaign (campaign_id),
        KEY idx_contact  (contact_id),
        KEY idx_type     (attribution_type),
        CONSTRAINT fk_ca_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
        CONSTRAINT fk_ca_contact  FOREIGN KEY (contact_id)  REFERENCES contacts(id)             ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // campaign_revenue_log
    "CREATE TABLE IF NOT EXISTS campaign_revenue_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        contact_id  INT,
        event_type  VARCHAR(50) NOT NULL,
        revenue     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        entity_type VARCHAR(50),
        entity_id   INT,
        recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        notes       TEXT,
        KEY idx_campaign (campaign_id),
        KEY idx_contact  (contact_id),
        KEY idx_recorded (recorded_at),
        CONSTRAINT fk_crl_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
        CONSTRAINT fk_crl_contact  FOREIGN KEY (contact_id)  REFERENCES contacts(id)             ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // marketing_optin_tokens
    "CREATE TABLE IF NOT EXISTS marketing_optin_tokens (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        contact_id    INT NOT NULL,
        email         VARCHAR(254) NOT NULL,
        token         VARCHAR(128) NOT NULL,
        status        ENUM('pending','confirmed','expired','unsubscribed') NOT NULL DEFAULT 'pending',
        sent_at       DATETIME,
        confirmed_at  DATETIME,
        expires_at    DATETIME,
        resent_count  INT NOT NULL DEFAULT 0,
        last_resent_at DATETIME,
        ip_address    VARCHAR(45),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token),
        KEY idx_contact (contact_id),
        KEY idx_email   (email),
        KEY idx_status  (status),
        CONSTRAINT fk_mot_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // client_marketing_preferences
    "CREATE TABLE IF NOT EXISTS client_marketing_preferences (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        contact_id           INT NOT NULL,
        email_opt_in         TINYINT(1) NOT NULL DEFAULT 0,
        sms_opt_in           TINYINT(1) NOT NULL DEFAULT 0,
        confirmed_at         DATETIME,
        confirmation_method  ENUM('web_form','optin_email','admin_manual','imported') DEFAULT 'admin_manual',
        unsubscribed_at      DATETIME,
        unsubscribe_reason   TEXT,
        gdpr_consent         TINYINT(1) NOT NULL DEFAULT 0,
        consent_text         TEXT,
        preference_tags      TEXT,
        updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_contact (contact_id),
        CONSTRAINT fk_cmp_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Add columns to campaign_sends (ignore errors if already exist)
    "ALTER TABLE campaign_sends ADD COLUMN user_agent  VARCHAR(500) AFTER clicked_at",
    "ALTER TABLE campaign_sends ADD COLUMN device_type VARCHAR(20)  AFTER user_agent",
    "ALTER TABLE campaign_sends ADD COLUMN open_count  INT NOT NULL DEFAULT 0 AFTER device_type",
    "ALTER TABLE campaign_sends ADD COLUMN click_count INT NOT NULL DEFAULT 0 AFTER open_count",
    "ALTER TABLE campaign_sends ADD COLUMN clicked_url VARCHAR(500) AFTER click_count",

    // Seed automation rules
    "INSERT IGNORE INTO automation_rules (name, description, is_enabled, trigger_type, trigger_config, conditions, actions, cooldown_hours)
     VALUES
     ('Post-Job Thank You + Review Request','Send thank-you after job completed and request a review.',0,'job_completed','{}','[]','[{\"type\":\"send_campaign\",\"config\":{\"template_category\":\"post_job\"}},{\"type\":\"add_tag\",\"config\":{\"tag\":\"post-job-thanked\"}}]',168),
     ('Quote Follow-Up (Not Yet Accepted)','Follow up on quotes viewed but not accepted within 48h.',0,'quote_viewed','{\"hours_since_view\":48}','[{\"field\":\"receive_marketing\",\"operator\":\"eq\",\"value\":\"1\"}]','[{\"type\":\"send_campaign\",\"config\":{\"template_category\":\"quote_followup\"}},{\"type\":\"notify_admin\",\"config\":{\"message\":\"Quote follow-up sent to {{first_name}} {{last_name}}\"}}]',72),
     ('Inactive Client Re-engagement (6mo)','Reach out to clients with no service in 6 months.',0,'client_inactive','{\"inactive_days\":180}','[{\"field\":\"receive_marketing\",\"operator\":\"eq\",\"value\":\"1\"}]','[{\"type\":\"send_campaign\",\"config\":{\"template_category\":\"newsletter\"}},{\"type\":\"add_tag\",\"config\":{\"tag\":\"re-engagement-sent\"}}]',2160),
     ('Spring Season Campaign','Trigger outreach at start of spring.',0,'season_change','{\"season\":\"spring\"}','[]','[{\"type\":\"send_campaign\",\"config\":{\"template_category\":\"seasonal_upsell\"}},{\"type\":\"add_tag\",\"config\":{\"tag\":\"spring-campaign-2026\"}}]',8760)",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        // Duplicate column errors are OK (ALTER TABLE IF NOT EXISTS not supported in MySQL 5.7)
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false) {
            $results[] = "SKIP[$i] Column already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration 700 — Results:\n\n" . implode("\n", $results) . "\n\nDone.";
