<?php
/**
 * Social Campaign Scheduler API
 *
 * Actions:
 *   preview   — return proposed date/time slots for a campaign (no DB write)
 *   create    — create a social_schedule + all initial scheduled posts
 *   list      — list active schedules with post counts
 *   pause     — set is_active = 0
 *   activate  — set is_active = 1
 *   delete    — cancel schedule + all future auto-scheduled posts
 *   density   — monthly post density for next 12 months (for dashboard bar)
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
session_write_close();
requirePermission('marketing.view');

$db     = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = [];
if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
}

// ── Seasonal cadence: posts per month (Vancouver landscaping) ─────────────
function seasonalPostsPerMonth(int $month): int {
    $cadence = [
        1 => 1,  // Jan — quiet
        2 => 2,  // Feb — early prep content
        3 => 3,  // Mar — season start
        4 => 4,  // Apr — ramp up
        5 => 5,  // May — peak
        6 => 4,  // Jun — sustained
        7 => 4,  // Jul — sustained
        8 => 3,  // Aug — late summer
        9 => 3,  // Sep — fall prep
        10 => 2, // Oct — taper
        11 => 1, // Nov — quiet
        12 => 1, // Dec — quiet
    ];
    return $cadence[$month] ?? 2;
}

// ── Fetch best time slots for a service type ─────────────────────────────
function getBestSlots(PDO $db, string $serviceType): array {
    // Try service-type specific slots first; fall back to general
    $stmt = $db->prepare("
        SELECT day_of_week, hour, score
        FROM social_time_slots
        WHERE service_type = ? OR service_type IS NULL
        ORDER BY (service_type IS NOT NULL) DESC, score DESC
        LIMIT 14
    ");
    $stmt->execute([$serviceType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // De-duplicate: keep highest score per (day,hour) pair
    $seen  = [];
    $slots = [];
    foreach ($rows as $r) {
        $key = $r['day_of_week'] . '-' . $r['hour'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $slots[]    = ['dow' => (int)$r['day_of_week'], 'hour' => (int)$r['hour'], 'score' => (float)$r['score']];
        }
    }
    // Ensure we always have at least 7 slots (fallbacks)
    if (count($slots) < 7) {
        $defaults = [
            ['dow' => 6, 'hour' => 9,  'score' => 2.0],
            ['dow' => 4, 'hour' => 19, 'score' => 1.9],
            ['dow' => 3, 'hour' => 11, 'score' => 1.8],
            ['dow' => 5, 'hour' => 8,  'score' => 1.7],
            ['dow' => 2, 'hour' => 18, 'score' => 1.6],
            ['dow' => 0, 'hour' => 10, 'score' => 1.5],
            ['dow' => 1, 'hour' => 9,  'score' => 1.4],
        ];
        foreach ($defaults as $d) {
            $key = $d['dow'] . '-' . $d['hour'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $slots[]    = $d;
                if (count($slots) >= 7) break;
            }
        }
    }
    return $slots;
}

// ── Fetch already-occupied slots (to avoid collisions) ───────────────────
function getOccupiedSlots(PDO $db, string $fromDate, string $toDate): array {
    $stmt = $db->prepare("
        SELECT DATE(scheduled_at) AS dy,
               HOUR(scheduled_at)  AS hr,
               COUNT(*)            AS cnt
        FROM social_posts
        WHERE scheduled_at BETWEEN ? AND ?
          AND status IN ('scheduled','approved','published')
        GROUP BY dy, hr
    ");
    $stmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
    $occupied = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $occupied[$r['dy']][$r['hr']] = (int)$r['cnt'];
    }
    return $occupied;
}

// ── Core slot generator ───────────────────────────────────────────────────
function generateSlots(
    array  $bestSlots,
    array  $occupied,
    int    $year,
    int    $month,
    int    $count,
    string $excludePostId = ''
): array {
    $daysInMonth  = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $today        = date('Y-m-d');
    $slotIdx      = 0;
    $generated    = [];
    $attempts     = 0;
    $maxAttempts  = 200;

    while (count($generated) < $count && $attempts < $maxAttempts) {
        $attempts++;
        $slot = $bestSlots[$slotIdx % count($bestSlots)];
        $slotIdx++;

        // Find next occurrence of this day-of-week in the month
        // that hasn't already been used
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $ts      = mktime(0, 0, 0, $month, $day, $year);
            $dow     = (int)date('w', $ts);
            $dateStr = date('Y-m-d', $ts);

            if ($dow !== $slot['dow']) continue;
            if ($dateStr <= $today)    continue;
            // Skip if already generated this exact date+hour
            $dtStr = $dateStr . ' ' . str_pad((string)$slot['hour'], 2, '0', STR_PAD_LEFT) . ':00:00';
            if (in_array($dtStr, array_column($generated, 'scheduled_at'))) continue;
            // Skip if slot is busy (already has 2+ posts)
            if (isset($occupied[$dateStr][$slot['hour']]) && $occupied[$dateStr][$slot['hour']] >= 2) continue;

            $generated[] = ['scheduled_at' => $dtStr, 'score' => $slot['score']];
            break;
        }
    }

    // Sort chronologically
    usort($generated, function($a, $b) {
        return strcmp($a['scheduled_at'], $b['scheduled_at']);
    });

    return $generated;
}

try {
    switch ($action) {

        // ── Preview proposed slots (no DB write) ─────────────────────
        case 'preview': {
            $postId      = (int)($_GET['post_id'] ?? 0);
            $cadence     = $_GET['cadence'] ?? 'seasonal';
            $cadenceDays = (int)($_GET['cadence_days'] ?? 14);
            $months      = min(12, max(1, (int)($_GET['months'] ?? 3)));

            if (!$postId) { echo json_encode(['success' => false, 'error' => 'Missing post_id']); break; }

            $stmt = $db->prepare("SELECT service_type FROM social_posts WHERE id = ?");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$post) { echo json_encode(['success' => false, 'error' => 'Post not found']); break; }

            $serviceType = $post['service_type'] ?? '';
            $bestSlots   = getBestSlots($db, $serviceType);

            $fromDate = date('Y-m-d');
            $toDate   = date('Y-m-d', strtotime("+{$months} months"));
            $occupied = getOccupiedSlots($db, $fromDate, $toDate);

            $allSlots  = [];
            $curYear   = (int)date('Y');
            $curMonth  = (int)date('n');

            for ($m = 0; $m < $months; $m++) {
                $yr  = $curYear  + (int)floor(($curMonth + $m - 1) / 12);
                $mo  = (($curMonth + $m - 1) % 12) + 1;

                if ($cadence === 'seasonal') {
                    $count = seasonalPostsPerMonth($mo);
                } else {
                    // Fixed: approximate from cadence_days
                    $daysInMo = (int)date('t', mktime(0, 0, 0, $mo, 1, $yr));
                    $count    = max(1, (int)round($daysInMo / $cadenceDays));
                }

                $slots = generateSlots($bestSlots, $occupied, $yr, $mo, $count, (string)$postId);
                foreach ($slots as $s) {
                    $allSlots[] = $s;
                    // Mark as occupied so subsequent months don't collide
                    $d = substr($s['scheduled_at'], 0, 10);
                    $h = (int)substr($s['scheduled_at'], 11, 2);
                    $occupied[$d][$h] = ($occupied[$d][$h] ?? 0) + 1;
                }
            }

            echo json_encode([
                'success'    => true,
                'slots'      => $allSlots,
                'total'      => count($allSlots),
                'service_type' => $serviceType,
            ]);
            break;
        }

        // ── Create schedule + posts ───────────────────────────────────
        case 'create': {
            requirePermission('marketing.edit');
            $postId      = (int)($input['post_id'] ?? 0);
            $cadence     = $input['cadence'] ?? 'seasonal';
            $cadenceDays = (int)($input['cadence_days'] ?? 14);
            $months      = min(12, max(1, (int)($input['months'] ?? 3)));
            $name        = trim($input['name'] ?? '');

            if (!$postId) { echo json_encode(['success' => false, 'error' => 'Missing post_id']); break; }
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'error' => 'Invalid token']); break;
            }

            // Load source post
            $stmt = $db->prepare("
                SELECT sp.*, GROUP_CONCAT(spp.account_id ORDER BY spp.id SEPARATOR ',') AS account_ids,
                       GROUP_CONCAT(spp.platform ORDER BY spp.id SEPARATOR ',') AS platforms
                FROM social_posts sp
                LEFT JOIN social_post_platforms spp ON spp.post_id = sp.id
                WHERE sp.id = ?
                GROUP BY sp.id
            ");
            $stmt->execute([$postId]);
            $src = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$src) { echo json_encode(['success' => false, 'error' => 'Post not found']); break; }

            $userId      = (int)($_SESSION['user_id'] ?? 0);
            $serviceType = $src['service_type'] ?? '';
            $bestSlots   = getBestSlots($db, $serviceType);

            $fromDate = date('Y-m-d');
            $toDate   = date('Y-m-d', strtotime("+{$months} months"));
            $occupied = getOccupiedSlots($db, $fromDate, $toDate);

            // Generate all slots
            $allSlots = [];
            $curYear  = (int)date('Y');
            $curMonth = (int)date('n');

            for ($m = 0; $m < $months; $m++) {
                $yr = $curYear + (int)floor(($curMonth + $m - 1) / 12);
                $mo = (($curMonth + $m - 1) % 12) + 1;

                if ($cadence === 'seasonal') {
                    $count = seasonalPostsPerMonth($mo);
                } else {
                    $daysInMo = (int)date('t', mktime(0, 0, 0, $mo, 1, $yr));
                    $count    = max(1, (int)round($daysInMo / $cadenceDays));
                }

                $slots = generateSlots($bestSlots, $occupied, $yr, $mo, $count);
                foreach ($slots as $s) {
                    $allSlots[] = $s;
                    $d = substr($s['scheduled_at'], 0, 10);
                    $h = (int)substr($s['scheduled_at'], 11, 2);
                    $occupied[$d][$h] = ($occupied[$d][$h] ?? 0) + 1;
                }
            }

            if (empty($allSlots)) {
                echo json_encode(['success' => false, 'error' => 'No available slots found']); break;
            }

            $db->beginTransaction();

            // Create the schedule record
            $scheduleName = $name ?: (($src['title'] ?? '') ?: 'Campaign') . ' — ' . ($cadence === 'seasonal' ? 'Seasonal' : 'Every ' . $cadenceDays . ' days');
            $insSchedStmt = $db->prepare("
                INSERT INTO social_schedules
                    (source_post_id, name, service_type, cadence_type, cadence_days, lookforward_months, is_active, last_generated_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE(), ?)
            ");
            $insSchedStmt->execute([
                $postId, $scheduleName, $serviceType,
                $cadence === 'seasonal' ? 'seasonal' : 'fixed',
                $cadence === 'seasonal' ? null : $cadenceDays,
                $months, $userId,
            ]);
            $scheduleId = (int)$db->lastInsertId();

            // Account + platform rows from source
            $accountIds = !empty($src['account_ids']) ? explode(',', $src['account_ids']) : [];
            $platforms  = !empty($src['platforms'])   ? explode(',', $src['platforms'])   : [];

            // Load media from source post
            $mediaStmt = $db->prepare("SELECT media_id, sort_order FROM social_post_media WHERE post_id = ?");
            $mediaStmt->execute([$postId]);
            $sourceMedia = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);

            $insPostStmt = $db->prepare("
                INSERT INTO social_posts
                    (title, caption, hashtags, neighborhood, service_type, template_id,
                     schedule_id, is_trial, is_auto_scheduled,
                     cta_type, cta_url, status, scheduled_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, 'approved', ?, ?)
            ");
            $insPlatStmt = $db->prepare("
                INSERT INTO social_post_platforms (post_id, account_id, platform, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $insMediaStmt = $db->prepare("
                INSERT INTO social_post_media (post_id, media_id, sort_order) VALUES (?, ?, ?)
            ");

            $createdIds = [];
            foreach ($allSlots as $slot) {
                $insPostStmt->execute([
                    $src['title'], $src['caption'], $src['hashtags'],
                    $src['neighborhood'], $src['service_type'],
                    $src['template_id'] ?? null,
                    $scheduleId, 0, 1,
                    $src['cta_type'], $src['cta_url'],
                    $slot['scheduled_at'], $userId,
                ]);
                $newPostId = (int)$db->lastInsertId();
                $createdIds[] = $newPostId;

                // Clone platform rows
                foreach ($accountIds as $i => $acctId) {
                    $pl = $platforms[$i] ?? '';
                    if ($acctId && $pl) {
                        $insPlatStmt->execute([$newPostId, (int)$acctId, $pl]);
                    }
                }

                // Clone media
                foreach ($sourceMedia as $m) {
                    $insMediaStmt->execute([$newPostId, (int)$m['media_id'], (int)$m['sort_order']]);
                }
            }

            $db->commit();

            echo json_encode([
                'success'     => true,
                'schedule_id' => $scheduleId,
                'posts_created' => count($createdIds),
                'post_ids'    => $createdIds,
                'name'        => $scheduleName,
            ]);
            break;
        }

        // ── List schedules ────────────────────────────────────────────
        case 'list': {
            $stmt = $db->query("
                SELECT ss.*, sp.caption, sp.service_type AS src_service,
                       COUNT(CASE WHEN p.status IN ('scheduled','approved') AND p.scheduled_at > NOW() THEN 1 END) AS future_count,
                       COUNT(p.id) AS total_count,
                       MIN(CASE WHEN p.status IN ('scheduled','approved') AND p.scheduled_at > NOW() THEN p.scheduled_at END) AS next_at
                FROM social_schedules ss
                LEFT JOIN social_posts sp ON sp.id = ss.source_post_id
                LEFT JOIN social_posts p  ON p.schedule_id = ss.id
                WHERE ss.is_active = 1
                GROUP BY ss.id
                ORDER BY ss.created_at DESC
            ");
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'schedules' => $schedules]);
            break;
        }

        // ── Density: posts per month for next 12 months ───────────────
        case 'density': {
            $stmt = $db->query("
                SELECT DATE_FORMAT(scheduled_at, '%Y-%m') AS ym,
                       COUNT(*) AS cnt
                FROM social_posts
                WHERE scheduled_at >= CURDATE()
                  AND scheduled_at <  DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
                  AND status IN ('scheduled','approved')
                GROUP BY ym
                ORDER BY ym
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $density = [];
            foreach ($rows as $r) { $density[$r['ym']] = (int)$r['cnt']; }

            // Fill in missing months with 0
            $result = [];
            for ($m = 0; $m < 12; $m++) {
                $ym  = date('Y-m', strtotime("+{$m} months"));
                $max = seasonalPostsPerMonth((int)date('n', strtotime("+{$m} months")));
                $result[] = ['month' => $ym, 'count' => $density[$ym] ?? 0, 'target' => $max];
            }
            echo json_encode(['success' => true, 'density' => $result]);
            break;
        }

        // ── Pause / activate / delete ─────────────────────────────────
        case 'pause': {
            requirePermission('marketing.edit');
            $id = (int)($input['id'] ?? 0);
            if (!$id || !verifyCSRFToken($input['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'error' => 'Invalid']); break;
            }
            $db->prepare("UPDATE social_schedules SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'activate': {
            requirePermission('marketing.edit');
            $id = (int)($input['id'] ?? 0);
            if (!$id || !verifyCSRFToken($input['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'error' => 'Invalid']); break;
            }
            $db->prepare("UPDATE social_schedules SET is_active = 1 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'delete': {
            requirePermission('marketing.edit');
            $id = (int)($input['id'] ?? 0);
            if (!$id || !verifyCSRFToken($input['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'error' => 'Invalid']); break;
            }
            // Cancel future auto-scheduled posts tied to this schedule
            $db->prepare("
                UPDATE social_posts
                SET status = 'cancelled'
                WHERE schedule_id = ?
                  AND status IN ('scheduled','approved')
                  AND scheduled_at > NOW()
            ")->execute([$id]);
            $db->prepare("UPDATE social_schedules SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (\Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log('social/schedules.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
