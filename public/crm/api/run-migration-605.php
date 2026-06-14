<?php
/**
 * Run Migration 605 — Social Marketing Engine tables + seed templates
 * Admin-only, one-time use. Delete after running.
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

    "CREATE TABLE IF NOT EXISTS social_accounts (
        id                      INT          AUTO_INCREMENT PRIMARY KEY,
        platform                VARCHAR(20)  NOT NULL,
        account_name            VARCHAR(200) NOT NULL,
        account_id_external     VARCHAR(200) DEFAULT NULL,
        location_id_external    VARCHAR(500) DEFAULT NULL,
        location_name_display   VARCHAR(300) DEFAULT NULL,
        access_token_enc        TEXT         DEFAULT NULL,
        refresh_token_enc       TEXT         DEFAULT NULL,
        token_expires_at        DATETIME     DEFAULT NULL,
        token_scope             VARCHAR(500) DEFAULT NULL,
        is_active               TINYINT(1)   NOT NULL DEFAULT 1,
        is_verified             TINYINT(1)   NOT NULL DEFAULT 0,
        connected_by            INT          DEFAULT NULL,
        connected_at            DATETIME     DEFAULT NULL,
        last_sync_at            DATETIME     DEFAULT NULL,
        meta_json               TEXT         DEFAULT NULL,
        created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_platform (platform),
        KEY idx_active   (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_posts (
        id               INT          AUTO_INCREMENT PRIMARY KEY,
        title            VARCHAR(300) DEFAULT NULL,
        caption          TEXT         NOT NULL,
        hashtags         TEXT         DEFAULT NULL,
        cta_action       VARCHAR(50)  DEFAULT NULL,
        cta_url          VARCHAR(500) DEFAULT NULL,
        utm_campaign     VARCHAR(200) DEFAULT NULL,
        status           VARCHAR(30)  NOT NULL DEFAULT 'draft',
        scheduled_at     DATETIME     DEFAULT NULL,
        published_at     DATETIME     DEFAULT NULL,
        template_id      INT          DEFAULT NULL,
        visit_id         INT          DEFAULT NULL,
        contact_id       INT          DEFAULT NULL,
        neighborhood     VARCHAR(200) DEFAULT NULL,
        city             VARCHAR(100) DEFAULT 'Vancouver',
        service_type     VARCHAR(100) DEFAULT NULL,
        created_by       INT          NOT NULL,
        approved_by      INT          DEFAULT NULL,
        fail_count       TINYINT      NOT NULL DEFAULT 0,
        last_fail_reason TEXT         DEFAULT NULL,
        next_retry_at    DATETIME     DEFAULT NULL,
        created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status     (status),
        KEY idx_scheduled  (scheduled_at),
        KEY idx_created_by (created_by),
        KEY idx_template   (template_id),
        KEY idx_visit      (visit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_post_platforms (
        id               INT           AUTO_INCREMENT PRIMARY KEY,
        post_id          INT           NOT NULL,
        account_id       INT           NOT NULL,
        platform         VARCHAR(20)   NOT NULL,
        status           VARCHAR(20)   NOT NULL DEFAULT 'pending',
        platform_post_id VARCHAR(500)  DEFAULT NULL,
        platform_url     VARCHAR(1000) DEFAULT NULL,
        response_payload TEXT          DEFAULT NULL,
        published_at     DATETIME      DEFAULT NULL,
        fail_reason      TEXT          DEFAULT NULL,
        retry_count      TINYINT       NOT NULL DEFAULT 0,
        KEY idx_post     (post_id),
        KEY idx_account  (account_id),
        KEY idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_templates (
        id               INT          AUTO_INCREMENT PRIMARY KEY,
        name             VARCHAR(200) NOT NULL,
        category         VARCHAR(50)  DEFAULT NULL,
        caption_template TEXT         NOT NULL,
        hashtag_preset   TEXT         DEFAULT NULL,
        cta_preset       VARCHAR(50)  DEFAULT NULL,
        cta_url_preset   VARCHAR(500) DEFAULT NULL,
        platform_targets VARCHAR(100) DEFAULT 'gbp,facebook,instagram',
        is_active        TINYINT(1)   NOT NULL DEFAULT 1,
        usage_count      INT          NOT NULL DEFAULT 0,
        created_by       INT          DEFAULT NULL,
        created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_category (category),
        KEY idx_active   (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_post_media (
        id          INT     AUTO_INCREMENT PRIMARY KEY,
        post_id     INT     NOT NULL,
        media_id    INT     NOT NULL,
        sort_order  TINYINT NOT NULL DEFAULT 0,
        KEY idx_post  (post_id),
        KEY idx_media (media_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_approvals (
        id          INT         AUTO_INCREMENT PRIMARY KEY,
        post_id     INT         NOT NULL,
        user_id     INT         NOT NULL,
        action      VARCHAR(30) NOT NULL,
        comment     TEXT        DEFAULT NULL,
        created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_post (post_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_metrics_daily (
        id               INT       AUTO_INCREMENT PRIMARY KEY,
        post_platform_id INT       NOT NULL,
        metric_date      DATE      NOT NULL,
        impressions      INT       NOT NULL DEFAULT 0,
        reach            INT       NOT NULL DEFAULT 0,
        clicks           INT       NOT NULL DEFAULT 0,
        likes            INT       NOT NULL DEFAULT 0,
        comments_count   INT       NOT NULL DEFAULT 0,
        shares           INT       NOT NULL DEFAULT 0,
        saves            INT       NOT NULL DEFAULT 0,
        fetched_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_post_date (post_platform_id, metric_date),
        KEY idx_date (metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_queue (
        id               INT         AUTO_INCREMENT PRIMARY KEY,
        post_id          INT         NOT NULL,
        account_id       INT         NOT NULL,
        platform         VARCHAR(20) NOT NULL,
        scheduled_at     DATETIME    NOT NULL,
        attempts         TINYINT     NOT NULL DEFAULT 0,
        max_attempts     TINYINT     NOT NULL DEFAULT 3,
        next_attempt_at  DATETIME    DEFAULT NULL,
        locked_at        DATETIME    DEFAULT NULL,
        locked_by        VARCHAR(50) DEFAULT NULL,
        status           VARCHAR(20) NOT NULL DEFAULT 'pending',
        result_payload   TEXT        DEFAULT NULL,
        created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_due     (scheduled_at, status),
        KEY idx_post    (post_id),
        KEY idx_locked  (locked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_audit_log (
        id          INT         AUTO_INCREMENT PRIMARY KEY,
        user_id     INT         DEFAULT NULL,
        action      VARCHAR(50) NOT NULL,
        entity_type VARCHAR(30) DEFAULT NULL,
        entity_id   INT         DEFAULT NULL,
        detail      TEXT        DEFAULT NULL,
        ip_address  VARCHAR(45) DEFAULT NULL,
        created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user   (user_id),
        KEY idx_action (action),
        KEY idx_entity (entity_type, entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS social_utm_links (
        id                 INT          AUTO_INCREMENT PRIMARY KEY,
        post_id            INT          DEFAULT NULL,
        utm_campaign       VARCHAR(200) DEFAULT NULL,
        utm_source         VARCHAR(100) NOT NULL DEFAULT 'social',
        utm_medium         VARCHAR(100) NOT NULL DEFAULT 'post',
        utm_content        VARCHAR(200) DEFAULT NULL,
        short_code         VARCHAR(20)  DEFAULT NULL,
        clicks             INT          NOT NULL DEFAULT 0,
        quote_requests     INT          NOT NULL DEFAULT 0,
        jobs_created       INT          NOT NULL DEFAULT 0,
        revenue_attributed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_post       (post_id),
        KEY idx_short_code (short_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Seed templates — use INSERT IGNORE so re-runs are safe
    "INSERT IGNORE INTO social_templates (id, name, category, caption_template, hashtag_preset, cta_preset, platform_targets) VALUES
    (1, 'Lawn Maintenance - Proof of Work', 'proof_of_work',
     'Another pristine lawn in {neighborhood}! Our crew just finished a fresh cut, edge trim, and blow-off. Notice the crisp lines and clean borders - this is what weekly maintenance looks like. Want your lawn looking like this? Book a free quote today.',
     '#VancouverLandscaping #LawnCare #VancouverLawn #CurbAppeal #Mowology #LawnMaintenance #YVRLandscaping',
     'BOOK', 'gbp,facebook,instagram'),
    (2, 'Spring Fertilizer Upsell', 'upsell',
     'Spring Fertilization is OPEN in {city}! Give your lawn the nutrients it needs after a long winter. Our slow-release fertilizer program keeps grass lush and green all season - and prevents costly bare patches. Add fertilizer to your maintenance plan. Ask us how!',
     '#SpringLawn #FertilizerTreatment #LawnHealth #VancouverGardening #Mowology #GreenLawn',
     'LEARN_MORE', 'gbp,facebook,instagram'),
    (3, 'Fall Aeration Upsell', 'upsell',
     'Fall is the BEST time for lawn aeration in {city}. Aeration breaks up compacted soil so nutrients and water reach the roots - giving you a denser, healthier lawn next spring. We are booking aeration slots now. Spaces fill fast!',
     '#LawnAeration #FallLawnCare #VancouverLandscaping #Mowology #HealthyLawn',
     'BOOK', 'gbp,facebook,instagram'),
    (4, 'Spring Power Rake Upsell', 'upsell',
     'Spring Power Raking is here! Thatch buildup smothers your lawn and invites disease. Our power rake service removes dead organic matter and gets your grass ready for the growing season. Book now before our spring slots fill up in {neighborhood}.',
     '#PowerRake #SpringCleanup #VancouverLawn #LawnHealth #Mowology',
     'BOOK', 'gbp,facebook,instagram'),
    (5, 'Hedge Trimming Showcase', 'proof_of_work',
     'Sharp hedges. Sharp edges. Sharp property. Our crew just completed a hedge trimming and shaping service in {neighborhood}. Clean geometric lines, cleared debris - looking pristine. Hedge trimming is available as a one-time service or add-on to your plan.',
     '#HedgeTrimming #VancouverLandscaping #CurbAppeal #Mowology #GardenMaintenance',
     'BOOK', 'gbp,facebook,instagram'),
    (6, 'Before / After Transformation', 'proof_of_work',
     'Before to After. This is why regular maintenance matters. See the difference a professional crew makes on this {neighborhood} property. Left: overgrown and neglected. Right: fresh cut, trimmed edges, clean beds. Your lawn can look like this every week. Book today!',
     '#BeforeAndAfter #LawnTransformation #VancouverLawn #Mowology #LawnCare',
     'BOOK', 'gbp,facebook,instagram'),
    (7, 'New Neighbourhood Route Opening', 'announcement',
     'Now serving {neighborhood}! We have opened new weekly maintenance routes in {neighborhood}. If you have been waiting to get on our schedule - now is the time. Limited spots available - contact us today!',
     '#VancouverLandscaping #NewNeighbourhood #LawnService #Mowology #RouteExpansion',
     'BOOK', 'gbp,facebook,instagram'),
    (8, '5-Star Review Celebration', 'proof_of_work',
     'We love hearing from happy clients! Thank you to our {neighborhood} client for the kind words. Reviews like this inspire our crew every single day. Want to experience the Mowology difference? Free quotes available.',
     '#5StarReview #HappyClient #VancouverLandscaping #Mowology #CustomerLove',
     'LEARN_MORE', 'gbp,facebook,instagram')",

];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), '1050') !== false) {
            $results[] = "SKIP[$i] Table already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

// Log to migrations_log
try {
    $user = getCurrentUser();
    $db->prepare("INSERT IGNORE INTO migrations_log (migration_filename, executed_by, status, checksum, migration_type)
                  VALUES (?, ?, 'success', 'inline-runner', 'sql')")
       ->execute(['605_social_marketing.sql', $user['id']]);
    $results[] = "\nLogged to migrations_log.";
} catch (Exception $e) {
    $results[] = "\nWARN: Could not log to migrations_log: " . $e->getMessage();
}

echo "Migration 605 — Social Marketing Engine\n";
echo "========================================\n\n";
echo implode("\n", $results);
echo "\n\nDone. Delete this file: /crm/api/run-migration-605.php\n";
