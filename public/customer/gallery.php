<?php
/**
 * Client Photo Gallery — Property-wide view
 * ──────────────────────────────────────────
 * Shows ALL photos across all of a contact's properties, grouped by
 * property then by visit date.
 *
 * URL:  /customer/gallery.php?token={contacts.portal_token}
 * Auth: contacts.portal_token (plain text — same as portal.php)
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/app_config/config.php';

$db      = getDB();
$error   = '';
$contact = null;

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    $error = 'No gallery token provided.';
} else {
    $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE portal_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) $error = 'This gallery link is invalid or has expired.';
}

// ── Load all photos grouped by property → visit ────────────────────────────
$properties   = [];   // [ property_id => ['address', 'city', 'visits' => [...]] ]
$totalPhotos  = 0;
$allPhotoIds  = [];

if (!$error && $contact) {
    // Try with consent column (migration 1025); fall back if missing
    try {
        $stmt = $db->prepare("
            SELECT
                vp.id, vp.photo_type, vp.filename, vp.caption,
                vp.thumb_path, vp.view_path, vp.sort_order, vp.uploaded_at,
                vp.visit_id, vp.service_type AS photo_service_type,
                vp.consent_portfolio, vp.before_after_pair_id,
                v.visit_number, v.status, v.completed_at, v.started_at,
                v.service_type AS visit_service_type,
                jp.service_type AS plan_service_type,
                pr.id AS pr_id,
                pr.address AS property_address,
                pr.city AS property_city,
                pr.province AS property_province
            FROM visit_photos vp
            JOIN job_visits v ON v.id = vp.visit_id
            JOIN job_plans jp ON jp.id = v.plan_id
            JOIN properties pr ON pr.id = jp.property_id
            JOIN contacts c ON c.id = pr.site_contact_id
            WHERE c.id = ?
              AND vp.deleted_at IS NULL
              AND v.status = 'completed'
            ORDER BY pr.id ASC,
                     v.completed_at DESC,
                     FIELD(vp.photo_type,'before','after','additional','during','issue','other'),
                     vp.sort_order ASC, vp.uploaded_at ASC
            LIMIT 500
        ");
    } catch (PDOException $e) {
        $stmt = $db->prepare("
            SELECT
                vp.id, vp.photo_type, vp.filename, vp.caption,
                vp.thumb_path, vp.view_path, vp.sort_order, vp.uploaded_at,
                vp.visit_id, vp.service_type AS photo_service_type,
                NULL AS consent_portfolio, NULL AS before_after_pair_id,
                v.visit_number, v.status, v.completed_at, v.started_at,
                v.service_type AS visit_service_type,
                jp.service_type AS plan_service_type,
                pr.id AS pr_id,
                pr.address AS property_address,
                pr.city AS property_city,
                pr.province AS property_province
            FROM visit_photos vp
            JOIN job_visits v ON v.id = vp.visit_id
            JOIN job_plans jp ON jp.id = v.plan_id
            JOIN properties pr ON pr.id = jp.property_id
            JOIN contacts c ON c.id = pr.site_contact_id
            WHERE c.id = ?
              AND vp.deleted_at IS NULL
              AND v.status = 'completed'
            ORDER BY pr.id ASC,
                     v.completed_at DESC,
                     FIELD(vp.photo_type,'before','after','additional','during','issue','other'),
                     vp.sort_order ASC, vp.uploaded_at ASC
            LIMIT 500
        ");
    }
    $stmt->execute([$contact['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $ph) {
        $prId    = (int)$ph['pr_id'];
        $vId     = (int)$ph['visit_id'];
        $origUrl = '/uploads/photos/' . $ph['filename'];
        $ph['_thumb_url'] = $ph['thumb_path'] ?? $origUrl;
        $ph['_view_url']  = $ph['view_path']  ?? $origUrl;

        if (!isset($properties[$prId])) {
            $properties[$prId] = [
                'address'  => trim(implode(', ', array_filter([
                    $ph['property_address'] ?? '',
                    $ph['property_city'] ?? '',
                    $ph['property_province'] ?? '',
                ]))),
                'short'    => $ph['property_address'] ?? 'Property',
                'visits'   => [],
            ];
        }
        if (!isset($properties[$prId]['visits'][$vId])) {
            $svcType = $ph['visit_service_type'] ?? $ph['plan_service_type'] ?? 'general';
            $properties[$prId]['visits'][$vId] = [
                'id'          => $vId,
                'number'      => $ph['visit_number'],
                'completed'   => $ph['completed_at'],
                'service'     => $svcType,
                'photos'      => [],
            ];
        }
        $properties[$prId]['visits'][$vId]['photos'][] = $ph;
        $allPhotoIds[] = (int)$ph['id'];
        $totalPhotos++;
    }

    // Load annotations
    $annotationsByPhoto = [];
    if ($allPhotoIds) {
        $phs   = implode(',', array_fill(0, count($allPhotoIds), '?'));
        $stmtA = $db->prepare("SELECT photo_id, shapes_json FROM visit_photo_annotations WHERE photo_id IN ($phs)");
        $stmtA->execute($allPhotoIds);
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $ann) {
            $shapes = json_decode($ann['shapes_json'] ?? '[]', true);
            if ($shapes) $annotationsByPhoto[(int)$ann['photo_id']] = $shapes;
        }
    }
}

$clientName = $contact ? trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) : '';
$year       = date('Y');
$siteUrl    = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
$annJson    = isset($annotationsByPhoto) ? json_encode($annotationsByPhoto) : '{}';

$serviceLabels = [
    'fertilizer'     => 'Fertilizer Treatment',
    'lawn_treatment' => 'Lawn Treatment',
    'salt'           => 'Salt / De-Icing',
    'de_ice'         => 'De-Icing Service',
    'snow'           => 'Snow Clearance',
    'snow_clearance' => 'Snow Clearance',
    'mowing'         => 'Lawn Mowing',
    'general'        => 'Landscaping Service',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Your Photo Gallery — Mowology Landscaping</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', system-ui, sans-serif; background: #f0f4f2; color: #1a1a1a; font-size: 15px; line-height: 1.5; }
    a { color: #2D8659; text-decoration: none; }

    /* ── Layout ──────────────────────────────── */
    .gal-wrap { max-width: 900px; margin: 0 auto; padding: 0 0 60px; }

    /* ── Page header ─────────────────────────── */
    .gal-header {
      background: linear-gradient(135deg, #1A5F4A 0%, #0D3B2E 100%);
      padding: 28px 32px 24px; color: #fff; margin-bottom: 0;
    }
    .gal-header-top { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
    .gal-logo {
      width: 40px; height: 40px; border-radius: 10px;
      background: rgba(127,216,88,0.18); display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .gal-logo svg { width: 24px; height: 24px; fill: #7FD858; }
    .gal-brand { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; }
    .gal-title { font-size: 24px; font-weight: 700; line-height: 1.2; }
    .gal-subtitle { font-size: 13px; opacity: 0.75; margin-top: 5px; }
    .gal-stats-row { display: flex; gap: 24px; margin-top: 16px; flex-wrap: wrap; }
    .gal-stat { }
    .gal-stat-val { font-size: 20px; font-weight: 700; display: block; }
    .gal-stat-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.6; }

    /* ── Filter bar ─────────────────────────── */
    .gal-filters {
      background: #fff; border-bottom: 1px solid #e0ebe6;
      padding: 12px 32px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
      position: sticky; top: 0; z-index: 50; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .gal-filter-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-right: 4px; }
    .gal-filter-btn {
      border: 1px solid #d0e5d8; border-radius: 20px; padding: 5px 14px;
      font-size: 12px; font-weight: 600; background: transparent; cursor: pointer;
      color: #444; transition: all 0.15s;
    }
    .gal-filter-btn:hover { border-color: #2D8659; color: #2D8659; }
    .gal-filter-btn.active { background: #2D8659; border-color: #2D8659; color: #fff; }

    /* ── Body ───────────────────────────────── */
    .gal-body { padding: 24px 32px; }

    /* ── Property group ─────────────────────── */
    .gal-property { margin-bottom: 36px; }
    .gal-property-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 16px; background: #fff;
      border-radius: 8px 8px 0 0; border: 1px solid #e0ebe6;
      border-bottom: none;
    }
    .gal-property-name { font-size: 14px; font-weight: 700; color: #1A5F4A; display: flex; align-items: center; gap: 8px; }
    .gal-property-name svg { width: 16px; height: 16px; stroke: #1A5F4A; flex-shrink: 0; }
    .gal-property-count { font-size: 11px; color: #999; }

    /* ── Visit group ────────────────────────── */
    .gal-visit {
      border: 1px solid #e0ebe6; border-top: none;
      background: #fff; overflow: hidden;
    }
    .gal-visit:last-child { border-radius: 0 0 8px 8px; }
    .gal-visit-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 16px; background: #f8fbfa;
      border-bottom: 1px solid #e0ebe6; flex-wrap: wrap; gap: 6px;
    }
    .gal-visit-date { font-size: 13px; font-weight: 600; color: #333; }
    .gal-visit-service {
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
      padding: 3px 9px; border-radius: 12px; background: #e8f3f0; color: #1A5F4A;
    }
    .gal-visit-count { font-size: 11px; color: #999; }

    /* ── Compare slider inside visit ────────── */
    .gal-compare { padding: 12px 16px 0; }
    .gal-cmp-wrap {
      position: relative; width: 100%; overflow: hidden;
      border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.12);
      user-select: none; -webkit-user-select: none; touch-action: none; cursor: ew-resize;
    }
    .gal-cmp-before { display: block; width: 100%; aspect-ratio: 16/9; object-fit: cover; }
    .gal-cmp-after {
      position: absolute; inset: 0; clip-path: inset(0 50% 0 0);
    }
    .gal-cmp-after img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .gal-cmp-handle {
      position: absolute; top: 0; bottom: 0; left: 50%;
      width: 2px; background: #fff; transform: translateX(-50%);
      pointer-events: none; z-index: 4;
    }
    .gal-cmp-circle {
      position: absolute; top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      width: 34px; height: 34px; border-radius: 50%;
      background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; color: #1A5F4A; font-weight: 700; letter-spacing: -1px;
    }
    .gal-cmp-label {
      position: absolute; bottom: 8px; font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.5px; padding: 2px 7px;
      border-radius: 3px; pointer-events: none; z-index: 5;
    }
    .gal-cmp-label-before { left: 8px; background: rgba(0,86,179,0.75); color: #fff; }
    .gal-cmp-label-after  { right: 8px; background: rgba(45,134,89,0.75); color: #fff; }
    .gal-cmp-hint { font-size: 11px; color: #aaa; text-align: center; padding: 6px 0; }

    /* ── Photo grid ─────────────────────────── */
    .gal-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
      gap: 6px; padding: 12px 16px 16px;
    }
    .gal-photo {
      position: relative; border-radius: 6px; overflow: hidden;
      cursor: pointer; line-height: 0; box-shadow: 0 1px 4px rgba(0,0,0,0.1);
      transition: transform 0.15s;
    }
    .gal-photo:hover { transform: scale(1.03); }
    .gal-photo img { width: 100%; aspect-ratio: 1/1; object-fit: cover; display: block; }
    .gal-photo-type {
      position: absolute; top: 4px; left: 4px;
      font-size: 9px; font-weight: 700; text-transform: uppercase;
      padding: 2px 5px; border-radius: 3px; letter-spacing: 0.3px;
    }
    .gal-photo-type--before { background: rgba(0,86,179,0.8); color: #fff; }
    .gal-photo-type--after  { background: rgba(45,134,89,0.8); color: #fff; }
    .gal-photo-type--additional { background: rgba(0,0,0,0.5); color: #fff; }
    .gal-photo-ann {
      position: absolute; top: 4px; right: 4px;
      background: rgba(232,93,4,0.85); color: #fff;
      font-size: 9px; padding: 2px 5px; border-radius: 3px;
    }
    .gal-photo-consent {
      position: absolute; bottom: 4px; right: 4px;
      background: rgba(0,0,0,0.55); color: #fff;
      border: none; cursor: pointer;
      width: 26px; height: 26px; border-radius: 50%;
      font-size: 14px; line-height: 1;
      display: flex; align-items: center; justify-content: center;
      transition: background 0.15s, color 0.15s;
    }
    .gal-photo-consent:hover { background: rgba(232,93,4,0.95); color: #fff; }
    .gal-photo.is-shared .gal-photo-consent {
      background: #e85d04; color: #fff;
    }
    .gal-photo.is-shared { outline: 2px solid #e85d04; outline-offset: -2px; }

    /* Header actions (download all, share hint) */
    .gal-header-actions {
      display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px;
    }
    .gal-header-btn {
      display: inline-block;
      background: #fff; color: #1A5F4A;
      padding: 9px 18px; border-radius: 8px;
      font-size: 14px; font-weight: 600;
      text-decoration: none; border: none; cursor: pointer;
      transition: transform 0.15s;
    }
    .gal-header-btn:hover { transform: translateY(-1px); }
    .gal-header-btn-outline {
      background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,0.4);
    }
    .gal-header-btn-outline:hover { background: rgba(255,255,255,0.1); }

    /* ── Empty ──────────────────────────────── */
    .gal-empty { text-align: center; padding: 60px 24px; color: #888; }
    .gal-empty svg { width: 48px; height: 48px; opacity: 0.25; margin: 0 auto 14px; display: block; }
    .gal-empty h3 { font-size: 18px; color: #555; margin-bottom: 8px; }

    /* ── Error page ─────────────────────────── */
    .gal-error {
      max-width: 500px; margin: 60px auto; background: #fff;
      border-radius: 12px; padding: 48px 32px; text-align: center;
      box-shadow: 0 4px 20px rgba(0,0,0,0.07);
    }
    .gal-error h2 { color: #dc3545; font-size: 20px; margin-bottom: 10px; }
    .gal-error p { color: #555; font-size: 14px; line-height: 1.6; }

    /* ── Footer ─────────────────────────────── */
    .gal-footer { text-align: center; padding: 20px; color: #999; font-size: 12px; border-top: 1px solid #e0ebe6; margin-top: 24px; }
    .gal-footer a { color: #2D8659; }

    /* ── Lightbox ───────────────────────────── */
    .glb {
      display: none; position: fixed; inset: 0; z-index: 9999;
      align-items: center; justify-content: center; background: rgba(0,0,0,0.92);
    }
    .glb.is-open { display: flex; }
    .glb-inner {
      position: relative; max-width: 95vw; max-height: 95vh;
      display: flex; flex-direction: column; align-items: center;
    }
    .glb-img-wrap { position: relative; display: inline-block; line-height: 0; }
    .glb-img { max-width: 92vw; max-height: 82vh; object-fit: contain; border-radius: 4px; display: block; }
    .glb-canvas {
      position: absolute; inset: 0; width: 100%; height: 100%;
      pointer-events: none; display: none;
    }
    .glb-close {
      position: fixed; top: 16px; right: 20px;
      background: none; border: none; color: #fff; font-size: 28px; cursor: pointer; z-index: 10;
    }
    .glb-nav { display: flex; align-items: center; gap: 16px; margin-top: 12px; }
    .glb-btn {
      background: rgba(255,255,255,0.12); border: none; color: #fff;
      font-size: 22px; cursor: pointer; border-radius: 50%;
      width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
      transition: background 0.15s;
    }
    .glb-btn:hover { background: rgba(255,255,255,0.25); }
    .glb-btn:disabled { opacity: 0.3; cursor: default; }
    .glb-counter { color: rgba(255,255,255,0.6); font-size: 12px; min-width: 60px; text-align: center; }
    .glb-caption { color: rgba(255,255,255,0.65); font-size: 13px; margin-top: 8px; text-align: center; max-width: 600px; }
    .glb-label {
      position: fixed; top: 16px; left: 20px;
      background: rgba(255,255,255,0.12); color: #fff;
      font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.5px; padding: 4px 10px; border-radius: 20px;
    }

    /* ── Responsive ─────────────────────────── */
    @media (max-width: 640px) {
      .gal-header { padding: 20px 18px; }
      .gal-filters { padding: 10px 18px; }
      .gal-body { padding: 16px 14px; }
      .gal-grid { grid-template-columns: repeat(3, 1fr); gap: 4px; }
      .gal-cmp-before { aspect-ratio: 4/3; }
    }
  </style>
</head>
<body>

<?php if ($error): ?>
<div style="padding:20px;">
  <div class="gal-error">
    <svg viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" style="width:40px;height:40px;margin:0 auto 16px;display:block;">
      <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
    </svg>
    <h2>Gallery Unavailable</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <p style="margin-top:16px;"><a href="<?= htmlspecialchars($siteUrl) ?>">&#8592; Return to Mowology.ca</a></p>
  </div>
</div>
<?php else: ?>

<div class="gal-wrap">

  <!-- ── Page header ───────────────────────────────────────────────────── -->
  <div class="gal-header">
    <div class="gal-header-top">
      <div class="gal-logo">
        <svg viewBox="0 0 24 24"><path d="M17 8C8 10 5.9 16.17 3.82 19.82L5.71 21l1-2.3A4.49 4.49 0 0 0 8 19c9-3 6.17-8 2-10zm0 0a5 5 0 0 1 5 5"/></svg>
      </div>
      <div>
        <div class="gal-brand">Mowology Landscaping</div>
      </div>
    </div>
    <div class="gal-title">Your Photo Gallery</div>
    <?php if ($clientName): ?>
    <div class="gal-subtitle"><?= htmlspecialchars($clientName) ?>'s service history</div>
    <?php endif; ?>
    <div class="gal-stats-row">
      <div class="gal-stat">
        <span class="gal-stat-val"><?= $totalPhotos ?></span>
        <span class="gal-stat-lbl">Total Photos</span>
      </div>
      <div class="gal-stat">
        <span class="gal-stat-val"><?= count($properties) ?></span>
        <span class="gal-stat-lbl"><?= count($properties) === 1 ? 'Property' : 'Properties' ?></span>
      </div>
    </div>
    <?php if ($totalPhotos > 0): ?>
    <div class="gal-header-actions">
      <a href="/customer/gallery-download.php?token=<?= urlencode($token) ?>" class="gal-header-btn">
        ⬇ Download All as ZIP
      </a>
      <button type="button" class="gal-header-btn gal-header-btn-outline" onclick="mwShowConsentHelp()">
        ⭐ Share in portfolio
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Filter bar ────────────────────────────────────────────────────── -->
  <div class="gal-filters">
    <span class="gal-filter-label">Show:</span>
    <button class="gal-filter-btn active" data-filter="all">All</button>
    <button class="gal-filter-btn" data-filter="before">Before</button>
    <button class="gal-filter-btn" data-filter="after">After</button>
    <button class="gal-filter-btn" data-filter="additional">Additional</button>
  </div>

  <!-- ── Gallery body ──────────────────────────────────────────────────── -->
  <div class="gal-body">

    <?php if (empty($properties)): ?>
    <div class="gal-empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
      </svg>
      <h3>No photos yet</h3>
      <p style="font-size:13px;">Photos will appear here after your first completed service visit.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($properties as $prId => $prop): ?>
    <div class="gal-property">

      <!-- Property heading -->
      <div class="gal-property-header">
        <div class="gal-property-name">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
          <?= htmlspecialchars($prop['address']) ?>
        </div>
        <span class="gal-property-count">
          <?php
          $prTotal = 0;
          foreach ($prop['visits'] as $v) $prTotal += count($v['photos']);
          echo $prTotal . ' photo' . ($prTotal !== 1 ? 's' : '');
          ?>
        </span>
      </div>

      <!-- Visit groups -->
      <?php foreach ($prop['visits'] as $vid => $visitGroup): ?>
      <?php
      $vPhotos   = $visitGroup['photos'];
      $vBefore   = array_values(array_filter($vPhotos, fn($p) => $p['photo_type'] === 'before'));
      $vAfter    = array_values(array_filter($vPhotos, fn($p) => $p['photo_type'] === 'after'));
      $vHasCmp   = !empty($vBefore) && !empty($vAfter);
      $visitDate = $visitGroup['completed'] ? date('F j, Y', strtotime($visitGroup['completed'])) : '';
      $svcLabel  = $serviceLabels[$visitGroup['service']] ?? ucwords(str_replace('_', ' ', $visitGroup['service']));
      ?>
      <div class="gal-visit">
        <div class="gal-visit-header">
          <div>
            <span class="gal-visit-date"><?= htmlspecialchars($visitDate) ?></span>
            &nbsp;
            <span class="gal-visit-count">(<?= count($vPhotos) ?> photo<?= count($vPhotos) !== 1 ? 's' : '' ?>)</span>
          </div>
          <span class="gal-visit-service"><?= htmlspecialchars($svcLabel) ?></span>
        </div>

        <!-- Comparison slider for this visit -->
        <?php if ($vHasCmp): ?>
        <div class="gal-compare">
          <div class="gal-cmp-wrap" data-cmp="<?= $vid ?>">
            <img class="gal-cmp-before" src="<?= htmlspecialchars($vBefore[0]['_view_url']) ?>" alt="Before" loading="lazy">
            <div class="gal-cmp-after">
              <img src="<?= htmlspecialchars($vAfter[0]['_view_url']) ?>" alt="After" loading="lazy">
            </div>
            <div class="gal-cmp-handle">
              <div class="gal-cmp-circle">&#8644;</div>
            </div>
            <span class="gal-cmp-label gal-cmp-label-before">Before</span>
            <span class="gal-cmp-label gal-cmp-label-after">After</span>
          </div>
          <p class="gal-cmp-hint">&#8592; Drag to compare &nbsp; &#8594;</p>
        </div>
        <?php endif; ?>

        <!-- Photo grid -->
        <div class="gal-grid" data-visit-grid="<?= $vid ?>">
          <?php foreach ($vPhotos as $ph): ?>
          <?php
          $pType  = $ph['photo_type'];
          $hasAnn = isset($annotationsByPhoto[$ph['id']]) && !empty($annotationsByPhoto[$ph['id']]);
          $typeClass = in_array($pType, ['before','after','additional']) ? $pType : 'additional';
          ?>
          <div class="gal-photo<?= !empty($ph['consent_portfolio']) ? ' is-shared' : '' ?>"
               data-view="<?= htmlspecialchars($ph['_view_url']) ?>"
               data-type="<?= htmlspecialchars($pType) ?>"
               data-caption="<?= htmlspecialchars($ph['caption'] ?? '') ?>"
               data-photo-id="<?= (int)$ph['id'] ?>"
               data-consent="<?= (int)($ph['consent_portfolio'] ?? 0) ?>"
               data-filter-type="<?= htmlspecialchars($typeClass) ?>">
            <img src="<?= htmlspecialchars($ph['_thumb_url']) ?>"
                 loading="lazy"
                 alt="<?= htmlspecialchars($pType) ?> photo"
                 onerror="this.parentElement.style.display='none'">
            <span class="gal-photo-type gal-photo-type--<?= $typeClass ?>"><?= htmlspecialchars(ucfirst($pType)) ?></span>
            <?php if ($hasAnn): ?>
            <span class="gal-photo-ann">&#9998;</span>
            <?php endif; ?>
            <button type="button" class="gal-photo-consent"
                    title="<?= !empty($ph['consent_portfolio']) ? 'You\'ve allowed this photo in our portfolio. Click to change.' : 'Allow this photo to appear in our public portfolio?' ?>"
                    onclick="event.stopPropagation(); mwToggleConsent(<?= (int)$ph['id'] ?>, this);">
              <?= !empty($ph['consent_portfolio']) ? '★' : '☆' ?>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

    </div><!-- /gal-property -->
    <?php endforeach; ?>

  </div><!-- /gal-body -->

  <div class="gal-footer">
    <a href="<?= htmlspecialchars($siteUrl) ?>">Mowology Landscaping</a>
    &middot; <a href="mailto:office@mowology.ca">office@mowology.ca</a><br>
    &copy; <?= $year ?> Mowology Landscaping. This gallery is private &mdash; please do not share.
  </div>

</div><!-- /gal-wrap -->

<!-- ── Lightbox ────────────────────────────────────────────────────────────── -->
<div class="glb" id="galLightbox" role="dialog" aria-modal="true" aria-label="Photo viewer">
  <button class="glb-close" id="glbClose" aria-label="Close">&times;</button>
  <div class="glb-label" id="glbType"></div>
  <div class="glb-inner">
    <div class="glb-img-wrap">
      <img class="glb-img" id="glbImg" src="" alt="">
      <canvas id="glbCanvas" class="glb-canvas"></canvas>
    </div>
    <div class="glb-caption" id="glbCaption"></div>
    <div class="glb-nav">
      <button class="glb-btn" id="glbPrev" aria-label="Previous">&#8249;</button>
      <span class="glb-counter" id="glbCounter">1 / 1</span>
      <button class="glb-btn" id="glbNext" aria-label="Next">&#8250;</button>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';

  // ── Annotation data ─────────────────────────────────────────────────────
  var annData = <?= $annJson ?>;

  function renderAnnotations(photoId, canvas, img) {
    var shapes = annData[photoId] || [];
    if (!shapes.length || !canvas || !img) {
      if (canvas) canvas.style.display = 'none';
      return;
    }
    canvas.width  = img.offsetWidth;
    canvas.height = img.offsetHeight;
    canvas.style.display = 'block';
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';

    shapes.forEach(function(s) {
      var x1 = s.x1 * canvas.width,  y1 = s.y1 * canvas.height;
      var x2 = s.x2 * canvas.width,  y2 = s.y2 * canvas.height;
      ctx.strokeStyle = s.color || '#e85d04';
      ctx.fillStyle   = s.color || '#e85d04';
      if (s.type === 'rect') {
        ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
      } else if (s.type === 'circle') {
        var cx = (x1+x2)/2, cy = (y1+y2)/2;
        var rx = Math.abs(x2-x1)/2, ry = Math.abs(y2-y1)/2;
        ctx.beginPath(); ctx.ellipse(cx, cy, rx||4, ry||4, 0, 0, 2*Math.PI); ctx.stroke();
      } else if (s.type === 'arrow') {
        var hl = 14, angle = Math.atan2(y2-y1, x2-x1);
        ctx.beginPath();
        ctx.moveTo(x1,y1); ctx.lineTo(x2,y2);
        ctx.lineTo(x2-hl*Math.cos(angle-Math.PI/6), y2-hl*Math.sin(angle-Math.PI/6));
        ctx.moveTo(x2,y2);
        ctx.lineTo(x2-hl*Math.cos(angle+Math.PI/6), y2-hl*Math.sin(angle+Math.PI/6));
        ctx.stroke();
      }
      if (s.label) { ctx.font = 'bold 11px Inter,sans-serif'; ctx.fillText(s.label, Math.min(x1,x2)+3, Math.min(y1,y2)-4); }
    });
  }

  // ── Lightbox ────────────────────────────────────────────────────────────
  var allPhotos = Array.from(document.querySelectorAll('.gal-photo'));
  var glb       = document.getElementById('galLightbox');
  var glbImg    = document.getElementById('glbImg');
  var glbCanvas = document.getElementById('glbCanvas');
  var glbType   = document.getElementById('glbType');
  var glbCap    = document.getElementById('glbCaption');
  var glbCount  = document.getElementById('glbCounter');
  var glbPrev   = document.getElementById('glbPrev');
  var glbNext   = document.getElementById('glbNext');
  var cur       = 0;
  var visPhotos = allPhotos; // updated by filter

  function openAt(idx) {
    if (!visPhotos.length) return;
    cur = Math.max(0, Math.min(idx, visPhotos.length - 1));
    var el      = visPhotos[cur];
    var photoId = el.dataset.photoId || '';

    glbImg.src = el.dataset.view || el.querySelector('img').src;
    glbType.textContent = (el.dataset.type||'').charAt(0).toUpperCase() + (el.dataset.type||'').slice(1);
    glbCap.textContent  = el.dataset.caption || '';
    glbCount.textContent = (cur + 1) + ' / ' + visPhotos.length;
    glbPrev.disabled = (cur === 0);
    glbNext.disabled = (cur === visPhotos.length - 1);

    glb.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    if (glbCanvas) glbCanvas.style.display = 'none';
    glbImg.onload = function() { renderAnnotations(photoId, glbCanvas, glbImg); };
    if (glbImg.complete && glbImg.naturalWidth) renderAnnotations(photoId, glbCanvas, glbImg);
  }

  function closeGlb() {
    glb.classList.remove('is-open');
    glbImg.src = ''; glbImg.onload = null;
    if (glbCanvas) glbCanvas.style.display = 'none';
    document.body.style.overflow = '';
  }

  allPhotos.forEach(function(el, idx) {
    el.addEventListener('click', function() {
      // find index in visPhotos
      var vi = visPhotos.indexOf(el);
      if (vi >= 0) openAt(vi);
    });
  });

  document.getElementById('glbClose').addEventListener('click', closeGlb);
  glb.addEventListener('click', function(e) { if (e.target === glb) closeGlb(); });
  glbPrev.addEventListener('click', function() { if (cur > 0) openAt(cur - 1); });
  glbNext.addEventListener('click', function() { if (cur < visPhotos.length - 1) openAt(cur + 1); });

  document.addEventListener('keydown', function(e) {
    if (!glb.classList.contains('is-open')) return;
    if (e.key === 'Escape') closeGlb();
    if (e.key === 'ArrowLeft'  && cur > 0)                   openAt(cur - 1);
    if (e.key === 'ArrowRight' && cur < visPhotos.length - 1) openAt(cur + 1);
  });

  var tX = null;
  glb.addEventListener('touchstart', function(e) { tX = e.changedTouches[0].clientX; }, { passive: true });
  glb.addEventListener('touchend',   function(e) {
    if (tX === null) return;
    var dx = e.changedTouches[0].clientX - tX; tX = null;
    if (Math.abs(dx) < 50) return;
    if (dx > 0 && cur > 0) openAt(cur - 1);
    if (dx < 0 && cur < visPhotos.length - 1) openAt(cur + 1);
  }, { passive: true });

  // ── Filter buttons ──────────────────────────────────────────────────────
  document.querySelectorAll('.gal-filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.gal-filter-btn').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      var filter = btn.dataset.filter;
      allPhotos.forEach(function(el) {
        var ft = el.dataset.filterType || 'additional';
        el.style.display = (filter === 'all' || ft === filter) ? '' : 'none';
      });
      visPhotos = allPhotos.filter(function(el) { return el.style.display !== 'none'; });
    });
  });

  // ── Comparison sliders ──────────────────────────────────────────────────
  document.querySelectorAll('.gal-cmp-wrap').forEach(function(wrap) {
    var afterEl  = wrap.querySelector('.gal-cmp-after');
    var handleEl = wrap.querySelector('.gal-cmp-handle');
    if (!afterEl || !handleEl) return;

    function setPos(pct) {
      var p = Math.max(2, Math.min(98, pct));
      afterEl.style.clipPath = 'inset(0 ' + (100 - p) + '% 0 0)';
      handleEl.style.left    = p + '%';
    }
    function pctFromEvt(e) {
      var rect = wrap.getBoundingClientRect();
      var x    = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
      return (x / rect.width) * 100;
    }

    var dragging = false;
    wrap.addEventListener('mousedown',  function(e) { dragging = true; setPos(pctFromEvt(e)); });
    wrap.addEventListener('touchstart', function(e) { dragging = true; setPos(pctFromEvt(e)); e.preventDefault(); }, { passive: false });
    document.addEventListener('mousemove',  function(e) { if (dragging) setPos(pctFromEvt(e)); });
    document.addEventListener('touchmove',  function(e) { if (dragging) setPos(pctFromEvt(e)); }, { passive: true });
    document.addEventListener('mouseup',   function() { dragging = false; });
    document.addEventListener('touchend',  function() { dragging = false; });
  });

})();

// ── Consent toggle ─────────────────────────────────────────────────────
window.mwToggleConsent = function(photoId, btn) {
  var tile = btn.closest('.gal-photo');
  var current = tile && tile.dataset.consent === '1';
  var next = current ? 0 : 1;
  btn.disabled = true;
  var formData = new FormData();
  formData.append('token', <?= json_encode($token) ?>);
  formData.append('photo_id', photoId);
  formData.append('consent', next);
  fetch('/customer/api/photo-consent.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      if (d.success) {
        tile.dataset.consent = next;
        tile.classList.toggle('is-shared', next === 1);
        btn.textContent = next === 1 ? '\u2605' : '\u2606';
        btn.title = next === 1
          ? 'You\'ve allowed this photo in our portfolio. Click to change.'
          : 'Allow this photo to appear in our public portfolio?';
      } else {
        alert('Could not save: ' + (d.error || 'unknown'));
      }
    })
    .catch(function(err) { btn.disabled = false; alert('Network error'); });
};

window.mwShowConsentHelp = function() {
  alert(
    'Click the \u2606 star on any photo to allow Mowology to use it in our public portfolio.\n\n' +
    'Starred photos (\u2605) show your home\u2019s transformations to help others discover our work. ' +
    'We never include identifying details. You can turn this off anytime.'
  );
};
</script>

<?php endif; ?>
</body>
</html>
