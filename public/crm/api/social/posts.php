<?php
/**
 * Social Posts API
 *
 * Actions (GET):
 *   list        — paginated post list with filters
 *   get         — single post with platforms + media + approvals
 *   upcoming    — next N scheduled posts (for dashboard)
 *   failed      — posts with status=failed (for dashboard retry widget)
 *   calendar    — posts grouped by date for calendar view
 *   media-pick  — recent media_assets for the post editor picker
 *
 * Actions (POST JSON):
 *   save        — create or update post (draft/scheduled)
 *   schedule    — approve + enqueue for publishing
 *   approve     — admin approves a pending_approval post
 *   reject      — admin rejects with comment
 *   retry       — re-queue a failed post
 *   delete      — cancel a draft/pending post
 *   from-visit  — auto-create draft from completed visit
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
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
require_once APP_ROOT . '/Modules/Social/Services/SocialEncryption.php';
require_once APP_ROOT . '/Modules/Social/Services/GoogleBusinessService.php';
require_once APP_ROOT . '/Modules/Social/Services/SocialPublisher.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = getCurrentUser();
session_write_close(); // Release session lock early

requirePermission('marketing.view');

$db     = getDB();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── List posts ───────────────────────────────────────────────
        case 'list': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;
            $status  = $_GET['status'] ?? '';
            $platform = $_GET['platform'] ?? '';

            $where  = '1=1';
            $params = [];

            if ($status) {
                $where .= ' AND sp.status = ?';
                $params[] = $status;
            }
            if ($platform) {
                $where .= ' AND EXISTS (SELECT 1 FROM social_post_platforms spp WHERE spp.post_id = sp.id AND spp.platform = ?)';
                $params[] = $platform;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM social_posts sp WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT sp.*,
                       u.full_name AS created_by_name,
                       a.full_name AS approved_by_name,
                       (SELECT COUNT(*) FROM social_post_media WHERE post_id = sp.id) AS media_count,
                       (SELECT GROUP_CONCAT(platform ORDER BY platform SEPARATOR ',')
                        FROM social_post_platforms WHERE post_id = sp.id) AS platforms
                FROM social_posts sp
                LEFT JOIN users u ON u.id = sp.created_by
                LEFT JOIN users a ON a.id = sp.approved_by
                WHERE $where
                ORDER BY sp.scheduled_at IS NULL, sp.scheduled_at ASC, sp.created_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Attach first media thumbnail
            foreach ($posts as &$p) {
                $p['platforms'] = $p['platforms'] ? explode(',', $p['platforms']) : [];
                $thumb = $db->prepare("
                    SELECT ma.file_path FROM social_post_media spm
                    JOIN media_assets ma ON ma.id = spm.media_id
                    WHERE spm.post_id = ? ORDER BY spm.sort_order ASC LIMIT 1
                ");
                $thumb->execute([$p['id']]);
                $row = $thumb->fetch(PDO::FETCH_ASSOC);
                $p['thumb_url'] = $row ? '/' . ltrim($row['file_path'], '/') : null;
            }
            unset($p);

            echo json_encode([
                'success' => true,
                'posts'   => $posts,
                'total'   => $total,
                'page'    => $page,
                'pages'   => ceil($total / $perPage),
            ]);
            break;
        }

        // ── Get single post ──────────────────────────────────────────
        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { throw new InvalidArgumentException('Missing id'); }

            $stmt = $db->prepare("
                SELECT sp.*,
                       u.full_name AS created_by_name
                FROM social_posts sp
                LEFT JOIN users u ON u.id = sp.created_by
                WHERE sp.id = ?
            ");
            $stmt->execute([$id]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$post) { throw new RuntimeException('Post not found'); }

            // Platforms
            $stmt = $db->prepare("
                SELECT spp.*, sa.account_name, sa.location_name_display
                FROM social_post_platforms spp
                JOIN social_accounts sa ON sa.id = spp.account_id
                WHERE spp.post_id = ?
            ");
            $stmt->execute([$id]);
            $post['platforms'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Media
            $stmt = $db->prepare("
                SELECT spm.*, ma.file_path, ma.alt_text, ma.image_width, ma.image_height
                FROM social_post_media spm
                JOIN media_assets ma ON ma.id = spm.media_id
                WHERE spm.post_id = ?
                ORDER BY spm.sort_order
            ");
            $stmt->execute([$id]);
            $mediaRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mediaRows as &$m) {
                $m['url'] = '/' . ltrim($m['file_path'], '/');
            }
            unset($m);
            $post['media'] = $mediaRows;

            // Approvals
            $stmt = $db->prepare("
                SELECT sa.*, u.full_name AS user_name
                FROM social_approvals sa
                JOIN users u ON u.id = sa.user_id
                WHERE sa.post_id = ?
                ORDER BY sa.created_at ASC
            ");
            $stmt->execute([$id]);
            $post['approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'post' => $post]);
            break;
        }

        // ── Upcoming scheduled ───────────────────────────────────────
        case 'upcoming': {
            $limit = min(20, (int)($_GET['limit'] ?? 8));
            $stmt  = $db->prepare("
                SELECT sp.id, sp.title, sp.caption, sp.status, sp.scheduled_at,
                       sp.neighborhood, sp.service_type,
                       u.full_name AS created_by_name,
                       (SELECT GROUP_CONCAT(platform ORDER BY platform SEPARATOR ',')
                        FROM social_post_platforms WHERE post_id = sp.id) AS platforms,
                       (SELECT ma.file_path FROM social_post_media spm
                        JOIN media_assets ma ON ma.id = spm.media_id
                        WHERE spm.post_id = sp.id ORDER BY spm.sort_order LIMIT 1) AS thumb_path,
                       (SELECT GROUP_CONCAT(fail_reason ORDER BY platform SEPARATOR ' | ')
                        FROM social_post_platforms
                        WHERE post_id = sp.id AND fail_reason IS NOT NULL) AS fail_reason
                FROM social_posts sp
                LEFT JOIN users u ON u.id = sp.created_by
                WHERE sp.status IN ('scheduled','approved','pending_approval','publishing')
                ORDER BY sp.scheduled_at IS NULL DESC, sp.scheduled_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($posts as &$p) {
                $p['platforms'] = $p['platforms'] ? explode(',', $p['platforms']) : [];
                $p['thumb_url'] = $p['thumb_path'] ? '/' . ltrim($p['thumb_path'], '/') : null;
                unset($p['thumb_path']);
            }
            unset($p);

            echo json_encode(['success' => true, 'posts' => $posts]);
            break;
        }

        // ── Failed posts ─────────────────────────────────────────────

        case 'failed': {
            $stmt = $db->prepare("
                SELECT sp.id, sp.title, sp.caption, sp.last_fail_reason,
                       sp.fail_count, sp.updated_at,
                       (SELECT GROUP_CONCAT(platform ORDER BY platform SEPARATOR ',')
                        FROM social_post_platforms WHERE post_id = sp.id) AS platforms
                FROM social_posts sp
                WHERE sp.status = 'failed'
                ORDER BY sp.updated_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($posts as &$p) {
                $p['platforms'] = $p['platforms'] ? explode(',', $p['platforms']) : [];
            }
            unset($p);

            echo json_encode(['success' => true, 'posts' => $posts]);
            break;
        }

        // ── Calendar view ────────────────────────────────────────────
        case 'calendar': {
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));

            // Clamp
            $year  = max(2024, min(2030, $year));
            $month = max(1, min(12, $month));

            $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $end   = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

            $stmt = $db->prepare("
                SELECT DATE(sp.scheduled_at) AS day,
                       sp.id, sp.title, sp.status,
                       (SELECT GROUP_CONCAT(platform ORDER BY platform SEPARATOR ',')
                        FROM social_post_platforms WHERE post_id = sp.id) AS platforms
                FROM social_posts sp
                WHERE sp.scheduled_at BETWEEN ? AND ?
                  AND sp.status != 'cancelled'
                ORDER BY sp.scheduled_at ASC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by day
            $byDay = [];
            foreach ($rows as $row) {
                $day = $row['day'];
                if (!isset($byDay[$day])) {
                    $byDay[$day] = [];
                }
                $row['platforms'] = $row['platforms'] ? explode(',', $row['platforms']) : [];
                $byDay[$day][] = $row;
            }

            echo json_encode(['success' => true, 'calendar' => $byDay, 'year' => $year, 'month' => $month]);
            break;
        }

        // ── Media picker (for post editor) ───────────────────────────
        case 'media-pick': {
            requirePermission('marketing.view');
            $search = trim($_GET['q'] ?? '');
            $limit  = min(40, (int)($_GET['limit'] ?? 24));

            $rows = [];

            // ── Source 1: media_assets (uploaded via Upload button) ──
            try {
                $maWhere  = "ma.mime_type LIKE 'image/%'";
                $maParams = [];
                if ($search) {
                    $maWhere .= ' AND (ma.alt_text LIKE ? OR ma.original_filename LIKE ?)';
                    $maParams[] = '%' . $search . '%';
                    $maParams[] = '%' . $search . '%';
                }
                $maStmt = $db->prepare("
                    SELECT ma.id, ma.file_path AS url_path, ma.alt_text AS caption,
                           ma.image_width, ma.image_height,
                           COALESCE(ma.upload_date, ma.created_at) AS taken_at,
                           'media_asset' AS source, ma.id AS source_id
                    FROM media_assets ma
                    WHERE $maWhere
                    ORDER BY COALESCE(ma.upload_date, ma.created_at) DESC
                    LIMIT ?
                ");
                $maParams[] = $limit;
                $maStmt->execute($maParams);
                foreach ($maStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = $r;
                }
            } catch (\Throwable $e) {
                error_log('media-pick media_assets: ' . $e->getMessage());
            }

            // ── Source 2: visit_photos (job site photos) ─────────────
            try {
                $vpWhere  = "vp.deleted_at IS NULL AND vp.filename IS NOT NULL";
                $vpParams = [];
                if ($search) {
                    $vpWhere .= ' AND (vp.caption LIKE ? OR vp.tags LIKE ? OR vp.photo_type LIKE ?)';
                    $vpParams[] = '%' . $search . '%';
                    $vpParams[] = '%' . $search . '%';
                    $vpParams[] = '%' . $search . '%';
                }
                $vpStmt = $db->prepare("
                    SELECT vp.id, vp.filename, vp.thumb_path, vp.caption,
                           vp.uploaded_at AS taken_at, vp.photo_type,
                           'visit_photo' AS source, vp.id AS source_id
                    FROM visit_photos vp
                    WHERE $vpWhere
                    ORDER BY vp.uploaded_at DESC
                    LIMIT ?
                ");
                $vpParams[] = $limit;
                $vpStmt->execute($vpParams);
                foreach ($vpStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = $r;
                }
            } catch (\Throwable $e) {
                error_log('media-pick visit_photos: ' . $e->getMessage());
            }

            // ── Normalise and sort by date ────────────────────────────
            $media = [];
            foreach ($rows as $r) {
                if ($r['source'] === 'media_asset') {
                    $media[] = [
                        'id'        => (int)$r['id'],
                        'url'       => '/' . ltrim($r['url_path'], '/'),
                        'thumb_url' => '/' . ltrim($r['url_path'], '/'),
                        'alt_text'  => $r['caption'] ?? '',
                        'taken_at'  => $r['taken_at'],
                        'source'    => 'media_asset',
                        'source_id' => (int)$r['source_id'],
                    ];
                } else {
                    $origUrl  = '/uploads/photos/' . $r['filename'];
                    $thumbUrl = $r['thumb_path'] ? '/' . ltrim($r['thumb_path'], '/') : $origUrl;
                    $media[]  = [
                        'id'        => null, // resolved on selection
                        'url'       => $origUrl,
                        'thumb_url' => $thumbUrl,
                        'alt_text'  => $r['caption'] ?? $r['photo_type'] ?? '',
                        'taken_at'  => $r['taken_at'],
                        'source'    => 'visit_photo',
                        'source_id' => (int)$r['source_id'],
                    ];
                }
            }

            // Sort newest first
            usort($media, function($a, $b) {
                return strcmp($b['taken_at'] ?? '', $a['taken_at'] ?? '');
            });

            echo json_encode(['success' => true, 'media' => array_slice($media, 0, $limit)]);
            break;
        }

        // ── Import visit_photo into media_assets, return media_assets.id ─
        case 'import-visit-photo': {
            requirePermission('marketing.view');
            $vpId = (int)($_GET['vp_id'] ?? 0);
            if (!$vpId) throw new InvalidArgumentException('Missing vp_id');

            // Check if already imported
            $existing = $db->prepare("
                SELECT id FROM media_assets
                WHERE original_filename = ? AND file_type = 'image'
                LIMIT 1
            ");

            $vp = $db->prepare("SELECT id, filename, thumb_path, caption, photo_type FROM visit_photos WHERE id = ? AND deleted_at IS NULL");
            $vp->execute([$vpId]);
            $vpRow = $vp->fetch(PDO::FETCH_ASSOC);
            if (!$vpRow) throw new RuntimeException('Visit photo not found');

            // Look for existing import by file_path
            $existStmt = $db->prepare("SELECT id FROM media_assets WHERE file_path = ? LIMIT 1");
            $existStmt->execute(['uploads/photos/' . $vpRow['filename']]);
            $existRow = $existStmt->fetch(PDO::FETCH_ASSOC);
            if ($existRow) {
                echo json_encode(['success' => true, 'media_id' => (int)$existRow['id']]);
                break;
            }

            // Create media_assets record pointing to existing visit photo file
            $filePath = 'uploads/photos/' . $vpRow['filename'];
            $ext      = strtolower(pathinfo($vpRow['filename'], PATHINFO_EXTENSION));
            $mimeMap  = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','heic'=>'image/heic'];
            $mime     = $mimeMap[$ext] ?? 'image/jpeg';

            $ins = $db->prepare("
                INSERT INTO media_assets
                    (original_filename, stored_filename, file_path, file_type, mime_type,
                     alt_text, created_by, upload_date)
                VALUES (?, ?, ?, 'image', ?, ?, ?, NOW())
            ");
            $ins->execute([
                $vpRow['filename'],
                $vpRow['filename'],
                $filePath,
                $mime,
                $vpRow['caption'] ?: ($vpRow['photo_type'] . ' photo'),
                $user['id'],
            ]);
            $newId = (int)$db->lastInsertId();

            echo json_encode(['success' => true, 'media_id' => $newId]);
            break;
        }

        // ── Save post (create or update) ─────────────────────────────
        case 'save': {
            requirePermission('marketing.edit');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id          = (int)($input['id'] ?? 0);
            $title       = trim($input['title'] ?? '');
            $caption     = trim($input['caption'] ?? '');
            $hashtags    = trim($input['hashtags'] ?? '');
            $ctaAction   = trim($input['cta_action'] ?? '');
            $ctaUrl      = trim($input['cta_url'] ?? '');
            $neighborhood = trim($input['neighborhood'] ?? '');
            $city        = trim($input['city'] ?? 'Vancouver');
            $serviceType = trim($input['service_type'] ?? '');
            $templateId  = ($input['template_id'] ?? null) ? (int)$input['template_id'] : null;
            $mediaIds    = array_map('intval', $input['media_ids'] ?? []);
            $platformAccounts = $input['platform_accounts'] ?? []; // [['platform'=>'gbp','account_id'=>1], ...]
            $scheduledAt = !empty($input['scheduled_at']) ? $input['scheduled_at'] : null;
            $status      = $input['status'] ?? 'draft';

            if (!$caption) {
                throw new InvalidArgumentException('Caption is required');
            }

            // Validate status transitions
            $allowedStatuses = ['draft', 'pending_approval'];
            if (userHasPermission('marketing.approve')) {
                $allowedStatuses[] = 'approved';
            }
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'draft';
            }

            // Validate CTA URL
            if ($ctaUrl && !filter_var($ctaUrl, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Invalid CTA URL');
            }

            // Validate media IDs are real
            foreach ($mediaIds as $mid) {
                $chk = $db->prepare("SELECT id FROM media_assets WHERE id = ?");
                $chk->execute([$mid]);
                if (!$chk->fetch()) {
                    throw new InvalidArgumentException("Invalid media ID: $mid");
                }
            }

            // Validate account IDs
            foreach ($platformAccounts as $pa) {
                $chk = $db->prepare("SELECT id FROM social_accounts WHERE id = ? AND is_active = 1");
                $chk->execute([(int)($pa['account_id'] ?? 0)]);
                if (!$chk->fetch()) {
                    throw new InvalidArgumentException("Invalid account ID: " . ($pa['account_id'] ?? 0));
                }
            }

            $db->beginTransaction();

            if ($id) {
                // Update — only allow editing non-published posts
                $chk = $db->prepare("SELECT status, created_by FROM social_posts WHERE id = ?");
                $chk->execute([$id]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    throw new RuntimeException('Post not found');
                }
                if (in_array($existing['status'], ['published', 'publishing'], true)) {
                    throw new RuntimeException('Cannot edit a published post');
                }
                // Non-admins can only edit their own posts
                if (!userHasPermission('marketing.approve') && (int)$existing['created_by'] !== (int)$user['id']) {
                    throw new RuntimeException('Permission denied');
                }

                $db->prepare("
                    UPDATE social_posts
                    SET title        = ?, caption      = ?, hashtags     = ?,
                        cta_action   = ?, cta_url      = ?, neighborhood = ?,
                        city         = ?, service_type = ?, template_id  = ?,
                        scheduled_at = ?, status       = ?, updated_at   = NOW()
                    WHERE id = ?
                ")->execute([
                    $title ?: null, $caption, $hashtags ?: null,
                    $ctaAction ?: null, $ctaUrl ?: null, $neighborhood ?: null,
                    $city, $serviceType ?: null, $templateId,
                    $scheduledAt, $status, $id
                ]);

            } else {
                // Insert new
                $db->prepare("
                    INSERT INTO social_posts
                        (title, caption, hashtags, cta_action, cta_url, neighborhood,
                         city, service_type, template_id, scheduled_at, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $title ?: null, $caption, $hashtags ?: null,
                    $ctaAction ?: null, $ctaUrl ?: null, $neighborhood ?: null,
                    $city, $serviceType ?: null, $templateId,
                    $scheduledAt, $status, $user['id']
                ]);
                $id = (int)$db->lastInsertId();
            }

            // Sync media
            $db->prepare("DELETE FROM social_post_media WHERE post_id = ?")->execute([$id]);
            foreach ($mediaIds as $i => $mid) {
                $db->prepare("INSERT INTO social_post_media (post_id, media_id, sort_order) VALUES (?, ?, ?)")
                   ->execute([$id, $mid, $i]);
            }

            // Sync platform targets
            $db->prepare("DELETE FROM social_post_platforms WHERE post_id = ?")->execute([$id]);
            foreach ($platformAccounts as $pa) {
                $db->prepare("
                    INSERT INTO social_post_platforms (post_id, account_id, platform, status)
                    VALUES (?, ?, ?, 'pending')
                ")->execute([$id, (int)$pa['account_id'], $pa['platform']]);
            }

            // If template used, bump usage_count
            if ($templateId) {
                $db->prepare("UPDATE social_templates SET usage_count = usage_count + 1 WHERE id = ?")
                   ->execute([$templateId]);
            }

            // Log approval event
            if ($status === 'pending_approval') {
                $db->prepare("
                    INSERT INTO social_approvals (post_id, user_id, action, comment)
                    VALUES (?, ?, 'submitted', 'Submitted for approval')
                ")->execute([$id, $user['id']]);
            }

            $db->commit();

            // Generate UTM if CTA URL present
            if ($ctaUrl && !empty($input['utm_campaign'])) {
                $utmCampaign = substr(preg_replace('/[^a-z0-9_-]/i', '-', $input['utm_campaign']), 0, 100);
                $existing = $db->prepare("SELECT id FROM social_utm_links WHERE post_id = ?")->execute([$id]);
                $existingRow = $db->prepare("SELECT id FROM social_utm_links WHERE post_id = ?");
                $existingRow->execute([$id]);
                if (!$existingRow->fetch()) {
                    $db->prepare("
                        INSERT INTO social_utm_links (post_id, utm_campaign, utm_content)
                        VALUES (?, ?, ?)
                    ")->execute([$id, $utmCampaign, 'social-post-' . $id]);
                }
            }

            GoogleBusinessService::auditLog($user['id'], 'post_created', 'post', $id, "Post saved with status: $status");

            echo json_encode(['success' => true, 'id' => $id, 'status' => $status]);
            break;
        }

        // ── Schedule post ────────────────────────────────────────────
        case 'schedule': {
            requirePermission('marketing.edit');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id          = (int)($input['id'] ?? 0);
            $scheduledAt = $input['scheduled_at'] ?? '';

            if (!$id || !$scheduledAt) {
                throw new InvalidArgumentException('Missing id or scheduled_at');
            }

            // Validate datetime
            $ts = strtotime($scheduledAt);
            if (!$ts || $ts <= time()) {
                throw new InvalidArgumentException('Scheduled time must be in the future');
            }

            $stmt = $db->prepare("SELECT status FROM social_posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$post) {
                throw new RuntimeException('Post not found');
            }

            if (!in_array($post['status'], ['draft', 'approved', 'pending_approval'], true)) {
                throw new RuntimeException('Post cannot be scheduled from status: ' . $post['status']);
            }

            // Admin can schedule directly; others need approval
            if (!userHasPermission('marketing.approve') && $post['status'] !== 'approved') {
                // Submit for approval instead
                $db->prepare("UPDATE social_posts SET status = 'pending_approval', scheduled_at = ? WHERE id = ?")
                   ->execute([date('Y-m-d H:i:s', $ts), $id]);

                echo json_encode(['success' => true, 'status' => 'pending_approval',
                    'message' => 'Submitted for approval. An admin will schedule this post.']);
                break;
            }

            SocialPublisher::enqueuePost($id, date('Y-m-d H:i:s', $ts));

            GoogleBusinessService::auditLog($user['id'], 'post_scheduled', 'post', $id,
                'Scheduled for ' . date('Y-m-d H:i', $ts));

            echo json_encode(['success' => true, 'status' => 'scheduled',
                'message' => 'Post scheduled for ' . date('D M j g:ia', $ts)]);
            break;
        }

        // ── Approve post ─────────────────────────────────────────────
        case 'approve': {
            requirePermission('marketing.approve');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id      = (int)($input['id'] ?? 0);
            $comment = trim($input['comment'] ?? '');

            $stmt = $db->prepare("SELECT * FROM social_posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$post || $post['status'] !== 'pending_approval') {
                throw new RuntimeException('Post is not pending approval');
            }

            $db->beginTransaction();
            $db->prepare("UPDATE social_posts SET status = 'approved', approved_by = ? WHERE id = ?")
               ->execute([$user['id'], $id]);
            $db->prepare("INSERT INTO social_approvals (post_id, user_id, action, comment) VALUES (?, ?, 'approved', ?)")
               ->execute([$id, $user['id'], $comment ?: 'Approved']);
            $db->commit();

            // If post has a scheduled_at already, enqueue it now
            if ($post['scheduled_at']) {
                SocialPublisher::enqueuePost($id, $post['scheduled_at']);
            }

            GoogleBusinessService::auditLog($user['id'], 'post_approved', 'post', $id, $comment ?: 'Approved');
            echo json_encode(['success' => true, 'message' => 'Post approved']);
            break;
        }

        // ── Reject post ──────────────────────────────────────────────
        case 'reject': {
            requirePermission('marketing.approve');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id      = (int)($input['id'] ?? 0);
            $comment = trim($input['comment'] ?? '');

            $db->prepare("UPDATE social_posts SET status = 'draft' WHERE id = ? AND status = 'pending_approval'")
               ->execute([$id]);
            $db->prepare("INSERT INTO social_approvals (post_id, user_id, action, comment) VALUES (?, ?, 'rejected', ?)")
               ->execute([$id, $user['id'], $comment ?: 'Rejected — please revise']);

            GoogleBusinessService::auditLog($user['id'], 'post_rejected', 'post', $id, $comment);
            echo json_encode(['success' => true, 'message' => 'Post sent back to draft']);
            break;
        }

        // ── Retry failed post ────────────────────────────────────────
        case 'retry': {
            requirePermission('marketing.approve');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            if (!$id) { throw new InvalidArgumentException('Missing id'); }

            $stmt = $db->prepare("SELECT * FROM social_posts WHERE id = ? AND status IN ('failed','publishing')");
            $stmt->execute([$id]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$post) { throw new RuntimeException('Post not found or not in a retryable status'); }

            // Reset post and platform rows
            $db->prepare("UPDATE social_posts SET status = 'scheduled', fail_count = 0, last_fail_reason = NULL WHERE id = ?")
               ->execute([$id]);
            $db->prepare("UPDATE social_post_platforms SET status = 'pending', fail_reason = NULL, retry_count = 0 WHERE post_id = ?")
               ->execute([$id]);
            // Reset any failed queue entries and also unlock stale processing ones
            $db->prepare("UPDATE social_queue SET status = 'pending', attempts = 0, locked_at = NULL, locked_by = NULL,
                          scheduled_at = NOW() WHERE post_id = ? AND status IN ('failed','processing','pending')")
               ->execute([$id]);

            GoogleBusinessService::auditLog($user['id'], 'post_retry', 'post', $id, 'Manually retried');
            echo json_encode(['success' => true, 'message' => 'Post queued for retry']);
            break;
        }

        // ── Delete/cancel post ───────────────────────────────────────
        case 'delete': {
            requirePermission('marketing.edit');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            if (!$id) { throw new InvalidArgumentException('Missing id'); }

            $stmt = $db->prepare("SELECT status FROM social_posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$post) { throw new RuntimeException('Post not found'); }
            if (in_array($post['status'], ['published', 'publishing'], true)) {
                throw new RuntimeException('Cannot delete a published post');
            }

            $db->prepare("UPDATE social_posts SET status = 'cancelled' WHERE id = ?")
               ->execute([$id]);
            $db->prepare("UPDATE social_queue SET status = 'failed' WHERE post_id = ? AND status = 'pending'")
               ->execute([$id]);

            GoogleBusinessService::auditLog($user['id'], 'post_cancelled', 'post', $id);
            echo json_encode(['success' => true, 'message' => 'Post cancelled']);
            break;
        }

        // ── Create draft from completed visit ───────────────────────
        case 'from-visit': {
            requirePermission('marketing.edit');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) { throw new InvalidArgumentException('Missing visit_id'); }

            // Load visit data
            $stmt = $db->prepare("
                SELECT v.id AS visit_id, v.plan_id,
                       p.service_type, p.contact_id,
                       c.first_name, c.last_name,
                       prop.address, prop.city, prop.neighborhood
                FROM visits v
                JOIN plans p ON p.id = v.plan_id
                JOIN contacts c ON c.id = p.contact_id
                LEFT JOIN properties prop ON prop.site_contact_id = c.id
                WHERE v.id = ? AND v.status = 'completed'
            ");
            $stmt->execute([$visitId]);
            $visit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$visit) {
                throw new RuntimeException('Visit not found or not completed');
            }

            // Load visit photos
            $stmt = $db->prepare("
                SELECT vp.media_id FROM visit_photos vp
                WHERE vp.visit_id = ?
                ORDER BY vp.id ASC LIMIT 5
            ");
            $stmt->execute([$visitId]);
            $mediaIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'media_id');

            // Build a caption from a default template
            $neighborhood = $visit['neighborhood'] ?: ($visit['city'] ?? 'Vancouver');
            $service = ucfirst(str_replace('_', ' ', $visit['service_type'] ?? 'lawn maintenance'));
            $caption = "✅ Just completed a {$service} visit in {$neighborhood}!\n\n"
                     . "Our crew delivered top-quality results today. Another happy client in {$neighborhood}. 🌿\n\n"
                     . "Want your property looking this good? Free quotes available — book today!";

            $db->prepare("
                INSERT INTO social_posts
                    (title, caption, hashtags, neighborhood, city, service_type, visit_id, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ")->execute([
                $service . ' — ' . $neighborhood,
                $caption,
                '#VancouverLandscaping #Mowology #' . str_replace(' ', '', ucwords($service)),
                $neighborhood,
                $visit['city'] ?? 'Vancouver',
                $visit['service_type'] ?? '',
                $visitId,
                $user['id'],
            ]);
            $postId = (int)$db->lastInsertId();

            // Attach visit photos
            foreach ($mediaIds as $i => $mid) {
                $db->prepare("INSERT INTO social_post_media (post_id, media_id, sort_order) VALUES (?, ?, ?)")
                   ->execute([$postId, $mid, $i]);
            }

            GoogleBusinessService::auditLog($user['id'], 'post_created', 'post', $postId,
                "Auto-created from visit #$visitId");

            echo json_encode([
                'success' => true,
                'post_id' => $postId,
                'message' => 'Draft created — open the editor to review and schedule',
            ]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (\Throwable $e) {
    error_log('social/posts.php error: ' . $e->getMessage());
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
