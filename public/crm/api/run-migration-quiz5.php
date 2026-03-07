<?php
/**
 * /public/crm/api/run-migration-quiz5.php
 * Adds seasonal knowledge forecasting layer to the quiz system.
 *
 * Adds to quiz_questions:
 *   - relevant_months  VARCHAR(30)  – comma-sep month numbers e.g. "3,4,9,10"
 *   - seasonal_tags    VARCHAR(255) – freeform tags e.g. "spring-lawn,chafer-prevention"
 *   - seasonal_priority TINYINT    – 1–10 boost score (default 5)
 *
 * Creates: quiz_seasonal_campaigns table
 * Seeds:   category-level month assignments + specific overrides
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}
session_write_close();

$db = getDB();
$steps  = [];
$errors = [];

// ── 1. Add relevant_months column ─────────────────────────────────────────────
try {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_questions' AND COLUMN_NAME='relevant_months'")->fetchColumn();
    if (!$exists) {
        $db->exec("ALTER TABLE quiz_questions
            ADD COLUMN relevant_months VARCHAR(30) DEFAULT '1,2,3,4,5,6,7,8,9,10,11,12'
            AFTER seasonal_priority");
        // Backfill existing rows that have NULL (shouldn't happen with DEFAULT, but safe)
        $db->exec("UPDATE quiz_questions SET relevant_months='1,2,3,4,5,6,7,8,9,10,11,12' WHERE relevant_months IS NULL");
        $steps[] = '✅ Added relevant_months column';
    } else {
        $steps[] = '✅ relevant_months already exists';
    }
} catch (PDOException $e) {
    $errors[] = 'relevant_months: ' . $e->getMessage();
}

// ── 2. Add seasonal_tags column ───────────────────────────────────────────────
try {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_questions' AND COLUMN_NAME='seasonal_tags'")->fetchColumn();
    if (!$exists) {
        $db->exec("ALTER TABLE quiz_questions
            ADD COLUMN seasonal_tags VARCHAR(255) DEFAULT NULL
            AFTER relevant_months");
        $steps[] = '✅ Added seasonal_tags column';
    } else {
        $steps[] = '✅ seasonal_tags already exists';
    }
} catch (PDOException $e) {
    $errors[] = 'seasonal_tags: ' . $e->getMessage();
}

// ── 3. Add seasonal_priority column ───────────────────────────────────────────
try {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_questions' AND COLUMN_NAME='seasonal_priority'")->fetchColumn();
    if (!$exists) {
        $db->exec("ALTER TABLE quiz_questions
            ADD COLUMN seasonal_priority TINYINT NOT NULL DEFAULT 5
            AFTER learning_level");
        $steps[] = '✅ Added seasonal_priority column';
    } else {
        $steps[] = '✅ seasonal_priority already exists';
    }
} catch (PDOException $e) {
    $errors[] = 'seasonal_priority: ' . $e->getMessage();
}

// ── 4. Create quiz_seasonal_campaigns table ────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_seasonal_campaigns (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(100)    NOT NULL,
        description     TEXT,
        season_label    VARCHAR(50)     NOT NULL,
        start_month     TINYINT         NOT NULL,
        end_month       TINYINT         NOT NULL,
        priority_boost  TINYINT         NOT NULL DEFAULT 3,
        focus_tags      VARCHAR(255),
        tip_text        TEXT,
        tip_image_path  VARCHAR(255),
        is_active       TINYINT(1)      NOT NULL DEFAULT 1,
        sort_order      TINYINT         NOT NULL DEFAULT 5,
        created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_months (start_month, end_month),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $steps[] = '✅ quiz_seasonal_campaigns table ready';
} catch (PDOException $e) {
    $errors[] = 'campaigns table: ' . $e->getMessage();
}

// ── 5. Seed seasonal campaigns ─────────────────────────────────────────────────
try {
    $campaignCount = (int)$db->query("SELECT COUNT(*) FROM quiz_seasonal_campaigns")->fetchColumn();
    if ($campaignCount === 0) {
        $ins = $db->prepare(
            "INSERT INTO quiz_seasonal_campaigns
             (name, description, season_label, start_month, end_month, priority_boost, focus_tags, tip_text)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $campaigns = [
            ['Late Winter & Early Spring Prep', 'Prepare crew for the spring rush — moss, drainage, early fertilization, crabgrass prevention.', 'late-winter', 2, 4, 5, 'moss-management,early-spring-lawn,chafer-awareness,drainage,spring-fertilizer,pre-emergent', 'Check lawns for moss and drainage issues. This is the window for pre-emergent crabgrass control and early lawn fertilization.'],
            ['Spring Growth Push', 'Peak growing season — lawn density, hedge timing, rhododendron after-bloom, grub prevention.', 'spring', 4, 6, 5, 'spring-flowering-shrub,prune-after-flowering,hedge-timing,lawn-growth,grub-prevention', 'Rhododendrons and azaleas finish blooming — prune lightly right after flowering, before summer growth hardens. Apply Acelepryn for chafer prevention before eggs hatch.'],
            ['Early Summer Service Peak', 'Busy season — irrigation awareness, heat stress, hedge trimming, summer annuals, crabgrass control.', 'early-summer', 6, 8, 4, 'summer-annuals,heat-stress,irrigation-awareness,hedge-timing,summer-lawn', 'Watch for heat stress on lawns when temps exceed 25°C. Raise mowing height, water deeply and infrequently. Monitor for chafer grub damage in spongy patches.'],
            ['Fall Cleanup & Overseeding', 'Critical window for overseeding, aeration, and fall fertilization before winter.', 'fall', 8, 11, 5, 'fall-overseeding,fall-aeration,fall-fertilizer,chafer-damage,fall-bulbs,moss-prep', 'Late August to October is the best window for overseeding — warm soil, cooling air, fall rains. Aerate before seeding. Check lawns for chafer grub damage (spongy turf).'],
            ['Winter Protection & Planning', 'Winter care, frost protection, planning for spring, client education.', 'winter', 11, 2, 3, 'winter-lawn,frost-protection,winter-planning,client-education', 'Lawns are dormant — avoid traffic on frozen or saturated turf. Plan spring programs. This is a great time for staff training and certification.'],
        ];
        foreach ($campaigns as $c) {
            $ins->execute($c);
        }
        $steps[] = '✅ Seeded 5 seasonal campaigns';
    } else {
        $steps[] = "✅ Seasonal campaigns already seeded ($campaignCount existing)";
    }
} catch (PDOException $e) {
    $errors[] = 'campaigns seed: ' . $e->getMessage();
}

// ── 6. Seed category-level relevant_months ─────────────────────────────────────
// Map each category to its peak relevant months. These are conservative defaults.
// Admin can override individual questions later.
try {
    $catRows = $db->query("SELECT id, name FROM quiz_categories")->fetchAll(PDO::FETCH_KEY_PAIR);
    $byName  = array_flip($catRows);

    $categoryMonths = [
        'Weed Identification' => '3,4,5,6,7,8,9',        // weed season
        'Plant & Grass ID'    => '3,4,5,6,7,8,9',        // growing season
        'Equipment & Tools'   => '4,5,6,7,8,9',          // main service season
        'Safety & PPE'        => '1,2,3,4,5,6,7,8,9,10,11,12', // all year
        'Pest & Disease ID'   => '5,6,7,8,9,10',         // pest season
        'Turf & Soil'         => '3,4,5,6,7,8,9,10',     // growing + aeration seasons
        'Customer Service'    => '1,2,3,4,5,6,7,8,9,10,11,12', // all year
    ];

    $updCount = 0;
    foreach ($categoryMonths as $catName => $months) {
        $catId = $byName[$catName] ?? null;
        if (!$catId) continue;
        $db->prepare(
            "UPDATE quiz_questions
             SET relevant_months = ?
             WHERE category_id = ? AND (relevant_months = '1,2,3,4,5,6,7,8,9,10,11,12' OR relevant_months IS NULL)"
        )->execute([$months, $catId]);
        $updCount++;
    }
    $steps[] = "✅ Set category-level relevant_months for $updCount categories";
} catch (PDOException $e) {
    $errors[] = 'category months: ' . $e->getMessage();
}

// ── 7. Targeted seasonal overrides for timing-critical questions ───────────────
// These are the questions where timing matters most — override with more precise months.
try {
    $overrides = [
        // [question_text fragment, relevant_months, seasonal_tags, priority]

        // Pre-emergent / crabgrass — critical early spring window
        ['crabgrass germination', '3,4',     'pre-emergent,early-spring-lawn,crabgrass-prevention', 9],
        ['pre-emergent',          '3,4',     'pre-emergent,early-spring-lawn,crabgrass-prevention', 9],

        // Chafer beetles — lifecycle awareness before treatment season
        ['chafer beetle.*lay eggs',  '5,6',  'chafer-lifecycle,chafer-prevention,grub-prevention', 9],
        ['chafer beetle.*emerge',    '5,6',  'chafer-lifecycle,spring-pest-awareness', 9],
        ['birds.*dig.*lawn',         '8,9,10','chafer-grub-damage,fall-pest,spongy-turf', 9],
        ['spongy.*turf',             '8,9,10','chafer-grub-damage,fall-pest,spongy-turf', 9],
        ['Acelepryn',                '5,6,7', 'chafer-prevention,grub-treatment', 9],

        // Spring flowering shrubs
        ['rhododendron',             '3,4,5', 'spring-flowering-shrub,prune-after-flowering', 8],
        ['Japanese maple.*fall',     '9,10',  'fall-colour,plant-id-fall', 7],
        ['azalea',                   '3,4,5', 'spring-flowering-shrub,prune-after-flowering', 8],

        // Aeration — spring and fall windows
        ['aerat.*Vancouver',         '3,4,9,10','spring-aeration,fall-aeration,key-service', 8],
        ['when.*aerat',              '3,4,9,10','spring-aeration,fall-aeration,key-service', 8],

        // Overseeding — fall window
        ['overseed',                 '8,9,10', 'fall-overseeding,fall-service', 8],

        // Moss — late winter / early spring most visible
        ['moss.*indicate',           '2,3,4',  'moss-management,early-spring-lawn,drainage', 8],
        ['moss.*correction',         '2,3,4',  'moss-management,drainage,spring-lawn', 8],
        ['causes moss',              '2,3,4',  'moss-management,early-spring-lawn', 7],

        // Fertilizer timing
        ['when.*fertilizer',         '3,4,5,9,10','fertilizer-timing,spring-lawn,fall-lawn', 8],
        ['fertilizer.*applied',      '3,4,5,9,10','fertilizer-timing,spring-lawn,fall-lawn', 8],
        ['apply fertilizer',         '3,4,5,9,10','fertilizer-timing,spring-lawn', 8],

        // Summer heat stress
        ['leaf scorch',              '6,7,8',  'summer-heat,heat-stress,plant-care', 7],
        ['heat.*drought',            '6,7,8',  'summer-heat,heat-stress', 7],
        ['lawns slow growth',        '7,8',    'summer-dormancy,mowing-management', 7],

        // Hedge trimming season
        ['trim.*hedge',              '5,6,7,8,9','hedge-timing,growing-season', 7],
        ['hedge.*trim',              '5,6,7,8,9','hedge-timing,growing-season', 7],
        ['bushier.*hedge',           '5,6,7,8,9','hedge-timing,growing-season', 7],

        // Spring weed germination
        ['most.*weed.*germinate',    '3,4',    'weed-germination,spring-weed,pre-emergent', 8],

        // Tulips / spring bulbs — plant in fall, bloom in spring
        ['tulip',                    '3,4,5,9,10','spring-bulb,fall-planting', 7],

        // Dog urine — warmer months when most visible
        ['dog urine',                '4,5,6,7,8,9','dog-urine,lawn-damage', 6],

        // Root rot — wet Vancouver winters
        ['root rot',                 '10,11,12,1,2','root-rot,wet-season,drainage', 7],
        ['waterlogged',              '10,11,12,1,2','drainage,wet-season,root-health', 7],
    ];

    $updStmt = $db->prepare(
        "UPDATE quiz_questions
         SET relevant_months = ?, seasonal_tags = ?, seasonal_priority = ?
         WHERE question_text REGEXP ? LIMIT 20"
    );

    $overrideCount = 0;
    foreach ($overrides as [$pattern, $months, $tags, $priority]) {
        // Use case-insensitive REGEXP
        $updStmt->execute([$months, $tags, $priority, $pattern]);
        $overrideCount += $updStmt->rowCount();
    }
    $steps[] = "✅ Applied $overrideCount targeted seasonal overrides to questions";
} catch (PDOException $e) {
    $errors[] = 'seasonal overrides: ' . $e->getMessage();
}

// ── Done ───────────────────────────────────────────────────────────────────────
echo json_encode([
    'success' => empty($errors),
    'steps'   => $steps,
    'errors'  => $errors,
    'done'    => true,
], JSON_PRETTY_PRINT);
