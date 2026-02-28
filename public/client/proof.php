<?php
/**
 * Client Photo Gallery — Token-based (no login required)
 * ────────────────────────────────────────────────────────
 * Shows the before/after/additional photos for a completed visit.
 *
 * URL: /client/proof.php?token={64-char hex}&visit={visit_id}
 *
 * Token validation:
 *   stored: SHA-256(rawToken) in visit_share_tokens.token_hash
 *   verify: hash_equals(stored_hash, hash('sha256', incoming_token))
 *
 * Security:
 *   - Token is hashed in DB (raw never stored)
 *   - Revocable and optionally expiring
 *   - Access count tracked
 *   - Cannot browse other visits (visit_id is part of validation)
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');   // Prevent caching of authenticated gallery

// Bootstrap paths
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

// For standalone pages outside CRM: load config directly
if (!function_exists('getDB')) {
    require_once PUBLIC_ROOT . '/app_config/config.php';
}

$db    = getDB();
$error = '';

$rawToken = trim($_GET['token'] ?? '');
$visitId  = isset($_GET['visit']) ? (int)$_GET['visit'] : 0;

// ── Validate inputs ─────────────────────────────────────────────────────────
if (!$rawToken || strlen($rawToken) !== 64 || $visitId < 1) {
    $error = 'Invalid gallery link. Please check the link in your email.';
}

$visit     = null;
$shareRow  = null;
$photos    = [];

if (!$error) {
    // Load token record (indexed by visit_id)
    $stmtT = $db->prepare("
        SELECT token_hash, expires_at, revoked_at
        FROM visit_share_tokens
        WHERE visit_id = ?
    ");
    $stmtT->execute([$visitId]);
    $shareRow = $stmtT->fetch(PDO::FETCH_ASSOC);

    if (!$shareRow) {
        $error = 'This gallery link is not valid. Please contact us.';
    } elseif ($shareRow['revoked_at'] !== null) {
        $error = 'This gallery link has been revoked. Please contact us for a new link.';
    } elseif ($shareRow['expires_at'] !== null && strtotime($shareRow['expires_at']) < time()) {
        $error = 'This gallery link has expired. Please contact us for a new link.';
    } elseif (!hash_equals($shareRow['token_hash'], hash('sha256', $rawToken))) {
        $error = 'This gallery link is invalid. Please check the link in your email.';
    }
}

// ── Load visit data ─────────────────────────────────────────────────────────
if (!$error) {
    $stmtV = $db->prepare("
        SELECT
            v.id, v.visit_number, v.status,
            v.started_at, v.completed_at, v.distance_m, v.actual_crew_count,
            p.title AS plan_title, p.service_type AS plan_service_type,
            v.service_type,
            pr.address AS property_address,
            pr.city AS property_city, pr.province AS property_province,
            c.first_name AS contact_first, c.last_name AS contact_last
        FROM job_visits v
        JOIN job_plans p ON v.plan_id = p.id
        LEFT JOIN properties pr ON p.property_id = pr.id
        LEFT JOIN contacts c ON pr.site_contact_id = c.id
        WHERE v.id = ?
    ");
    $stmtV->execute([$visitId]);
    $visit = $stmtV->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        $error = 'Visit not found.';
    } elseif (!in_array($visit['status'], ['completed'])) {
        $error = 'This service report is not yet available. Please check back after the visit is completed.';
    }
}

// ── Load photos ─────────────────────────────────────────────────────────────
$photoGroups = ['before' => [], 'after' => [], 'additional' => []];
$photoCount  = 0;

if (!$error && $visit) {
    $stmtP = $db->prepare("
        SELECT id, photo_type, filename, caption, thumb_path, view_path
        FROM visit_photos
        WHERE visit_id = ? AND deleted_at IS NULL
        ORDER BY
            FIELD(photo_type,'before','after','additional','during','issue','other'),
            sort_order ASC, uploaded_at ASC
    ");
    $stmtP->execute([$visitId]);
    $allPhotos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allPhotos as $ph) {
        $origUrl = '/uploads/photos/' . $ph['filename'];
        $ph['_thumb_url'] = $ph['thumb_path'] ?? $origUrl;
        $ph['_view_url']  = $ph['view_path']  ?? $origUrl;

        $bucket = in_array($ph['photo_type'], ['before','after','additional']) ? $ph['photo_type'] : 'additional';
        $photoGroups[$bucket][] = $ph;
        $photoCount++;
    }

    // Update access tracking
    $db->prepare("
        UPDATE visit_share_tokens
        SET last_accessed_at = NOW(),
            access_count = access_count + 1
        WHERE visit_id = ?
    ")->execute([$visitId]);
}

// ── Computed display values ──────────────────────────────────────────────────
$serviceType  = $visit['service_type'] ?? $visit['plan_service_type'] ?? 'general';
$serviceLabel = [
    'fertilizer'     => 'Fertilizer / Lawn Treatment',
    'lawn_treatment' => 'Fertilizer / Lawn Treatment',
    'salt'           => 'Salt / De-Icing Service',
    'de_ice'         => 'Salt / De-Icing Service',
    'snow'           => 'Snow Clearance',
    'snow_clearance' => 'Snow Clearance',
    'mowing'         => 'Lawn Mowing',
    'general'        => 'Landscaping Service',
][$serviceType] ?? 'Landscaping Service';

$visitDate   = $visit && $visit['started_at'] ? date('F j, Y', strtotime($visit['started_at'])) : '';
$clientName  = $visit ? trim(($visit['contact_first'] ?? '') . ' ' . ($visit['contact_last'] ?? '')) : '';
$address     = $visit ? implode(', ', array_filter([
    $visit['property_address'] ?? '',
    $visit['property_city'] ?? '',
    $visit['property_province'] ?? '',
])) : '';

$duration = '';
if ($visit && $visit['started_at'] && $visit['completed_at']) {
    $mins     = (int)round((strtotime($visit['completed_at']) - strtotime($visit['started_at'])) / 60);
    $duration = ($mins >= 60 ? floor($mins/60) . 'h ' : '') . ($mins % 60) . 'm';
}

$siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
$year    = date('Y');

// Check if we have any before+after pairs for side-by-side
$hasBeforeAfter = !empty($photoGroups['before']) || !empty($photoGroups['after']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Your Service Gallery — Mowology Landscaping</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', system-ui, sans-serif; background: #f2f5f3; color: #1a1a1a; font-size: 15px; line-height: 1.5; }
    a { color: #2D8659; text-decoration: none; }
    a:hover { text-decoration: underline; }
    img { display: block; max-width: 100%; }

    /* ── Layout ──────────────────────────────────────────────── */
    .pg-wrap { max-width: 780px; margin: 0 auto; padding: 20px 16px 60px; }

    /* ── Header ──────────────────────────────────────────────── */
    .pg-header {
      background: linear-gradient(135deg, #1A5F4A 0%, #0D3B2E 100%);
      border-radius: 12px 12px 0 0;
      padding: 24px 28px 20px;
      color: #fff;
    }
    .pg-header-top { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .pg-logo { width: 36px; height: 36px; border-radius: 8px; background: rgba(127,216,88,0.2);
                display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .pg-logo svg { width: 22px; height: 22px; fill: #7FD858; }
    .pg-brand { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; opacity: 0.75; }
    .pg-title { font-size: 22px; font-weight: 700; line-height: 1.2; }
    .pg-subtitle { font-size: 13px; opacity: 0.8; margin-top: 4px; }
    .pg-badge {
      display: inline-flex; align-items: center; gap: 5px;
      background: rgba(127,216,88,0.2); color: #7FD858;
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
      border: 1px solid rgba(127,216,88,0.4); border-radius: 20px; padding: 3px 10px; margin-top: 10px;
    }
    .pg-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: #7FD858; }

    /* ── Stats bar ───────────────────────────────────────────── */
    .pg-stats {
      background: rgba(255,255,255,0.08);
      border-top: 1px solid rgba(255,255,255,0.1);
      display: flex; gap: 0;
    }
    .pg-stat { flex: 1; padding: 12px 8px; text-align: center; border-right: 1px solid rgba(255,255,255,0.1); }
    .pg-stat:last-child { border-right: none; }
    .pg-stat-val { font-size: 18px; font-weight: 700; color: #fff; display: block; line-height: 1.2; }
    .pg-stat-lbl { font-size: 10px; color: rgba(255,255,255,0.55); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; display: block; }

    /* ── Card ────────────────────────────────────────────────── */
    .pg-card { background: #fff; border-radius: 0 0 12px 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.07); margin-bottom: 16px; overflow: hidden; }
    .pg-section { padding: 22px 24px; border-bottom: 1px solid #e8f3f0; }
    .pg-section:last-child { border-bottom: none; }
    .pg-section-title {
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;
      color: #1A5F4A; margin-bottom: 14px; display: flex; align-items: center; gap: 6px;
    }
    .pg-section-title svg { width: 14px; height: 14px; }

    /* ── Before / After ──────────────────────────────────────── */
    .ba-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .ba-col { }
    .ba-col-label {
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
      padding: 5px 0 8px; display: flex; align-items: center; gap: 5px;
    }
    .ba-col-label--before { color: #0056b3; }
    .ba-col-label--after  { color: #2D8659; }
    .ba-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .ba-dot--before { background: #007bff; }
    .ba-dot--after  { background: #2D8659; }

    .ba-photo-stack { display: flex; flex-direction: column; gap: 8px; }
    .ba-photo {
      position: relative; border-radius: 8px; overflow: hidden;
      cursor: pointer; line-height: 0;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      transition: transform 0.15s, box-shadow 0.15s;
    }
    .ba-photo:hover { transform: scale(1.01); box-shadow: 0 4px 16px rgba(0,0,0,0.18); }
    .ba-photo img {
      width: 100%; aspect-ratio: 4/3; object-fit: cover;
      border: 2px solid #e8f3f0;
      border-radius: 6px;
    }
    .ba-photo-caption {
      position: absolute; bottom: 0; left: 0; right: 0;
      background: linear-gradient(transparent, rgba(0,0,0,0.55));
      color: #fff; font-size: 10px; padding: 14px 8px 6px;
    }
    .ba-expand-icon {
      position: absolute; top: 6px; right: 6px;
      background: rgba(0,0,0,0.4); color: #fff; border: none;
      border-radius: 4px; padding: 3px 5px; font-size: 10px; cursor: pointer;
      opacity: 0; transition: opacity 0.15s;
    }
    .ba-photo:hover .ba-expand-icon { opacity: 1; }

    /* ── Additionals grid ────────────────────────────────────── */
    .add-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 10px;
    }
    .add-photo {
      position: relative; border-radius: 8px; overflow: hidden;
      cursor: pointer; line-height: 0;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      transition: transform 0.15s;
    }
    .add-photo:hover { transform: scale(1.02); }
    .add-photo img { width: 100%; aspect-ratio: 1/1; object-fit: cover; }
    .add-photo-caption {
      position: absolute; bottom: 0; left: 0; right: 0;
      background: rgba(0,0,0,0.45); color: #fff; font-size: 9px;
      padding: 10px 6px 4px;
    }

    /* ── Empty state ─────────────────────────────────────────── */
    .pg-empty {
      text-align: center; padding: 32px 16px; color: #888;
    }
    .pg-empty svg { width: 36px; height: 36px; opacity: 0.3; margin: 0 auto 10px; display: block; }

    /* ── Error page ──────────────────────────────────────────── */
    .pg-error { background: #fff; border-radius: 12px; padding: 48px 24px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.07); }
    .pg-error h2 { color: #dc3545; font-size: 20px; margin-bottom: 10px; }
    .pg-error p { color: #555; font-size: 14px; line-height: 1.6; }

    /* ── Footer ──────────────────────────────────────────────── */
    .pg-footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    .pg-footer a { color: #2D8659; }

    /* ── Lightbox ────────────────────────────────────────────── */
    .lb {
      display: none; position: fixed; inset: 0; z-index: 9999;
      align-items: center; justify-content: center; background: rgba(0,0,0,0.92);
    }
    .lb.is-open { display: flex; }
    .lb-inner {
      position: relative; max-width: 95vw; max-height: 95vh;
      display: flex; flex-direction: column; align-items: center;
    }
    .lb-img {
      max-width: 92vw; max-height: 82vh;
      object-fit: contain; border-radius: 4px;
    }
    .lb-close {
      position: fixed; top: 16px; right: 20px;
      background: none; border: none; color: #fff; font-size: 28px; cursor: pointer;
      line-height: 1; z-index: 10;
    }
    .lb-nav {
      display: flex; align-items: center; gap: 16px; margin-top: 12px;
    }
    .lb-nav-btn {
      background: rgba(255,255,255,0.12); border: none; color: #fff;
      font-size: 22px; cursor: pointer; border-radius: 50%;
      width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
      transition: background 0.15s;
    }
    .lb-nav-btn:hover { background: rgba(255,255,255,0.25); }
    .lb-nav-btn:disabled { opacity: 0.3; cursor: default; }
    .lb-counter { color: rgba(255,255,255,0.6); font-size: 12px; min-width: 60px; text-align: center; }
    .lb-type-label {
      position: fixed; top: 16px; left: 20px;
      background: rgba(255,255,255,0.12); color: #fff;
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
      padding: 4px 10px; border-radius: 20px;
    }
    .lb-caption { color: rgba(255,255,255,0.65); font-size: 13px; margin-top: 8px; text-align: center; }

    /* ── Responsive ──────────────────────────────────────────── */
    @media (max-width: 560px) {
      .pg-header { padding: 18px 18px 0; }
      .pg-stats { flex-wrap: wrap; }
      .pg-stat { min-width: 33.3%; }
      .pg-section { padding: 16px 16px; }
      .ba-grid { grid-template-columns: 1fr; gap: 0; }
      .ba-col:not(:last-child) { margin-bottom: 16px; }
      .add-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 380px) {
      .add-grid { grid-template-columns: repeat(2, 1fr); }
    }
  </style>
</head>
<body>
<div class="pg-wrap">

<?php if ($error): ?>
  <div class="pg-error">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" style="width:40px;height:40px;margin:0 auto 16px;display:block;">
      <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
    </svg>
    <h2>Gallery Unavailable</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <p style="margin-top:16px;"><a href="<?= htmlspecialchars($siteUrl) ?>">← Return to Mowology.ca</a></p>
  </div>
<?php else: ?>

  <!-- ── Header ────────────────────────────────────────────────────────── -->
  <div class="pg-header">
    <div class="pg-header-top">
      <div class="pg-logo">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
      </div>
      <div class="pg-brand">Mowology Landscaping</div>
    </div>
    <div class="pg-title">
      <?= htmlspecialchars($serviceLabel) ?>
    </div>
    <div class="pg-subtitle">
      <?= htmlspecialchars($visitDate) ?>
      <?php if ($address): ?> &middot; <?= htmlspecialchars($address) ?><?php endif; ?>
    </div>
    <div class="pg-badge">
      <span class="pg-badge-dot"></span> Service Completed
    </div>

    <!-- Stats bar -->
    <div class="pg-stats" style="margin-top:16px; margin-left:-28px; margin-right:-28px;">
      <div class="pg-stat">
        <span class="pg-stat-val"><?= htmlspecialchars($duration ?: '—') ?></span>
        <span class="pg-stat-lbl">Duration</span>
      </div>
      <div class="pg-stat">
        <span class="pg-stat-val"><?= $photoCount ?></span>
        <span class="pg-stat-lbl">Photos</span>
      </div>
      <div class="pg-stat">
        <span class="pg-stat-val"><?= htmlspecialchars($visit['actual_crew_count'] ?? '1') ?></span>
        <span class="pg-stat-lbl">Crew</span>
      </div>
      <?php if ($visit['distance_m']): ?>
      <div class="pg-stat">
        <span class="pg-stat-val"><?= number_format($visit['distance_m'] / 1000, 1) ?> km</span>
        <span class="pg-stat-lbl">Coverage</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="pg-card">

    <?php if ($photoCount === 0): ?>
    <div class="pg-section">
      <div class="pg-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
          <polyline points="21 15 16 10 5 21"/>
        </svg>
        <p>No photos are available for this service visit yet.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Before / After ─────────────────────────────────────────────── -->
    <?php if ($hasBeforeAfter): ?>
    <div class="pg-section">
      <div class="pg-section-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
        </svg>
        Before &amp; After
      </div>

      <div class="ba-grid">
        <?php foreach (['before' => 'before', 'after' => 'after'] as $baType => $baClass): ?>
        <?php if (!empty($photoGroups[$baType])): ?>
        <div class="ba-col">
          <div class="ba-col-label ba-col-label--<?= $baClass ?>">
            <span class="ba-dot ba-dot--<?= $baClass ?>"></span>
            <?= ucfirst($baClass) ?>
            <span style="font-weight:400;opacity:0.6;">(<?= count($photoGroups[$baType]) ?>)</span>
          </div>
          <div class="ba-photo-stack">
            <?php foreach ($photoGroups[$baType] as $ph): ?>
            <div class="ba-photo" data-view="<?= htmlspecialchars($ph['_view_url']) ?>"
                 data-type="<?= htmlspecialchars($baType) ?>"
                 data-caption="<?= htmlspecialchars($ph['caption'] ?? '') ?>">
              <img src="<?= htmlspecialchars($ph['_thumb_url']) ?>"
                   loading="lazy"
                   alt="<?= htmlspecialchars($baType) ?> photo"
                   srcset="<?= htmlspecialchars($ph['_thumb_url']) ?> 256w,
                           <?= htmlspecialchars($ph['_view_url']) ?> 1280w"
                   sizes="(max-width: 560px) 45vw, 300px"
                   onerror="this.parentElement.style.display='none'">
              <?php if (!empty($ph['caption'])): ?>
              <div class="ba-photo-caption"><?= htmlspecialchars($ph['caption']) ?></div>
              <?php endif; ?>
              <button class="ba-expand-icon" tabindex="-1" aria-hidden="true">⤢</button>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Additional photos ──────────────────────────────────────────── -->
    <?php if (!empty($photoGroups['additional'])): ?>
    <div class="pg-section">
      <div class="pg-section-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
        </svg>
        Additional Photos
        <span style="font-weight:400;opacity:0.6;">(<?= count($photoGroups['additional']) ?>)</span>
      </div>
      <div class="add-grid">
        <?php foreach ($photoGroups['additional'] as $ph): ?>
        <div class="add-photo" data-view="<?= htmlspecialchars($ph['_view_url']) ?>"
             data-type="additional"
             data-caption="<?= htmlspecialchars($ph['caption'] ?? '') ?>">
          <img src="<?= htmlspecialchars($ph['_thumb_url']) ?>"
               loading="lazy"
               alt="additional photo"
               srcset="<?= htmlspecialchars($ph['_thumb_url']) ?> 256w,
                       <?= htmlspecialchars($ph['_view_url']) ?> 1280w"
               sizes="(max-width: 380px) 45vw, 130px"
               onerror="this.parentElement.style.display='none'">
          <?php if (!empty($ph['caption'])): ?>
          <div class="add-photo-caption"><?= htmlspecialchars($ph['caption']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Footer CTA ────────────────────────────────────────────────── -->
    <div class="pg-section" style="text-align:center;">
      <p style="color:#666;font-size:13px;margin-bottom:12px;">
        Questions about your service? We're here to help.
      </p>
      <a href="mailto:office@mowology.ca"
         style="display:inline-block;background:#2D8659;color:#fff;padding:10px 24px;border-radius:6px;font-weight:600;font-size:14px;text-decoration:none;">
        Contact Us
      </a>
    </div>

  </div><!-- /pg-card -->

<?php endif; ?>

  <div class="pg-footer">
    <a href="<?= htmlspecialchars($siteUrl) ?>">Mowology Landscaping</a>
    &middot; <a href="mailto:office@mowology.ca">office@mowology.ca</a><br>
    &copy; <?= $year ?> Mowology Landscaping. This link is private — please do not share.
  </div>

</div><!-- /pg-wrap -->

<!-- ── Lightbox ──────────────────────────────────────────────────────────── -->
<div class="lb" id="lightbox" role="dialog" aria-modal="true" aria-label="Photo viewer">
  <button class="lb-close" id="lb-close" aria-label="Close">&times;</button>
  <div class="lb-type-label" id="lb-type"></div>
  <div class="lb-inner">
    <img class="lb-img" id="lb-img" src="" alt="">
    <div class="lb-caption" id="lb-caption"></div>
    <div class="lb-nav">
      <button class="lb-nav-btn" id="lb-prev" aria-label="Previous">&#8249;</button>
      <span class="lb-counter" id="lb-counter">1 / 1</span>
      <button class="lb-nav-btn" id="lb-next" aria-label="Next">&#8250;</button>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';

  // Collect all clickable photo elements in display order
  var allPhotos = Array.from(document.querySelectorAll('.ba-photo, .add-photo'));
  var lb        = document.getElementById('lightbox');
  var lbImg     = document.getElementById('lb-img');
  var lbType    = document.getElementById('lb-type');
  var lbCaption = document.getElementById('lb-caption');
  var lbCounter = document.getElementById('lb-counter');
  var lbPrev    = document.getElementById('lb-prev');
  var lbNext    = document.getElementById('lb-next');
  var current   = 0;

  function openAt(idx) {
    if (!allPhotos.length) return;
    current = Math.max(0, Math.min(idx, allPhotos.length - 1));
    var el = allPhotos[current];

    lbImg.src = el.dataset.view || el.querySelector('img').src;
    lbType.textContent = (el.dataset.type || '').charAt(0).toUpperCase() + (el.dataset.type || '').slice(1);
    lbCaption.textContent = el.dataset.caption || '';
    lbCounter.textContent = (current + 1) + ' / ' + allPhotos.length;
    lbPrev.disabled = (current === 0);
    lbNext.disabled = (current === allPhotos.length - 1);

    lb.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function closeLb() {
    lb.classList.remove('is-open');
    lbImg.src = '';
    document.body.style.overflow = '';
  }

  allPhotos.forEach(function(el, idx) {
    el.addEventListener('click', function() { openAt(idx); });
    el.style.cursor = 'pointer';
  });

  document.getElementById('lb-close').addEventListener('click', closeLb);
  lb.addEventListener('click', function(e) { if (e.target === lb) closeLb(); });
  lbPrev.addEventListener('click', function() { if (current > 0) openAt(current - 1); });
  lbNext.addEventListener('click', function() { if (current < allPhotos.length - 1) openAt(current + 1); });

  // Keyboard navigation
  document.addEventListener('keydown', function(e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape') closeLb();
    if (e.key === 'ArrowLeft'  && current > 0)                    openAt(current - 1);
    if (e.key === 'ArrowRight' && current < allPhotos.length - 1) openAt(current + 1);
  });

  // Touch swipe for mobile
  var touchStartX = null;
  lb.addEventListener('touchstart', function(e) { touchStartX = e.changedTouches[0].clientX; }, { passive: true });
  lb.addEventListener('touchend', function(e) {
    if (touchStartX === null) return;
    var dx = e.changedTouches[0].clientX - touchStartX;
    touchStartX = null;
    if (Math.abs(dx) < 50) return; // Too small — ignore
    if (dx > 0 && current > 0)                    openAt(current - 1);
    if (dx < 0 && current < allPhotos.length - 1) openAt(current + 1);
  }, { passive: true });

})();
</script>

</body>
</html>
