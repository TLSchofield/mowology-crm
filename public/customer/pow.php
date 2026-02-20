<?php
/**
 * Customer Portal — Proof of Work View
 * Token-based (no login required).
 * Shows completed visit reports and PDF download link.
 *
 * URL: /customer/pow.php?token=ABC123&visit_id=42
 * Token verified against quotes.access_token or a dedicated pow_access_token on the plan.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db    = getDB();
$error = '';

$token   = trim($_GET['token'] ?? '');
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

// ── Validate token ─────────────────────────────────────────────────────────
// Token is the plan's quote access_token (or we extend it to plans later).
// For now: look up via the quote attached to the plan for this visit.
if (!$token || !$visitId) {
    $error = 'Invalid link. Please check your email for the correct Proof of Work link.';
}

$visit = null;
$plan  = null;
$quote = null;

if (!$error) {
    $stmt = $db->prepare("
        SELECT
            v.*,
            p.plan_number, p.title AS plan_title, p.service_type AS plan_service_type,
            p.id AS plan_db_id,
            pr.address AS property_address, pr.city AS property_city,
            pr.province AS property_province, pr.postal_code AS property_postal,
            c.first_name AS contact_first, c.last_name AS contact_last,
            c.email AS contact_email,
            q.access_token AS quote_token,
            q.token_expires_at
        FROM job_visits v
        JOIN job_plans p ON v.plan_id = p.id
        LEFT JOIN properties pr ON p.property_id = pr.id
        LEFT JOIN contacts c ON pr.site_contact_id = c.id
        LEFT JOIN quotes q ON p.quote_id = q.id
        WHERE v.id = ?
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        $error = 'Visit record not found.';
    } elseif ($visit['proof_complete'] != 1 && empty($visit['pdf_path'])) {
        $error = 'This visit report is not yet available. Please check back after your scheduled service date.';
    } else {
        // Token check: must match quote access token and not be expired
        $tokenValid = false;
        if ($visit['quote_token'] && hash_equals($visit['quote_token'], $token)) {
            if (!$visit['token_expires_at'] || strtotime($visit['token_expires_at']) > time()) {
                $tokenValid = true;
            }
        }

        if (!$tokenValid) {
            $error = 'This link has expired or is invalid. Please contact us for access.';
        }
    }
}

// ── Load visit extras ──────────────────────────────────────────────────────
$photos    = [];
$notes     = [];
$checklist = [];
$confBox   = [];

if (!$error && $visit) {
    $visit['service_type'] = $visit['service_type'] ?? $visit['plan_service_type'] ?? 'general';

    // Photos (public)
    $stmt = $db->prepare("
        SELECT id, photo_type, filename, caption FROM visit_photos
        WHERE visit_id = ? ORDER BY photo_type, uploaded_at
    ");
    $stmt->execute([$visitId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Notes visible to customer
    $stmt = $db->prepare("
        SELECT content, note_type, created_at FROM visit_notes
        WHERE visit_id = ? AND is_visible_to_customer = 1 ORDER BY created_at
    ");
    $stmt->execute([$visitId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $checklist = !empty($visit['checklist_json'])       ? json_decode($visit['checklist_json'], true)       : [];
    $confBox   = !empty($visit['confidence_box_json'])  ? json_decode($visit['confidence_box_json'], true)  : [];
    $materials = !empty($visit['materials_json'])       ? json_decode($visit['materials_json'], true)       : [];
}

$clientName  = $visit ? trim(($visit['contact_first']??'') . ' ' . ($visit['contact_last']??'')) : 'Client';
$visitDate   = $visit && $visit['started_at'] ? date('F j, Y', strtotime($visit['started_at'])) : '';
$address     = $visit ? implode(', ', array_filter([
    $visit['property_address'] ?? '',
    $visit['property_city'] ?? '',
    $visit['property_province'] ?? '',
    $visit['property_postal'] ?? '',
])) : '';

$hasPdf      = $visit && !empty($visit['pdf_path']);
$pdfUrl      = $hasPdf ? $visit['pdf_path'] : '';

$duration    = '';
if ($visit && $visit['started_at'] && $visit['completed_at']) {
    $mins     = (int)round((strtotime($visit['completed_at']) - strtotime($visit['started_at'])) / 60);
    $duration = ($mins >= 60 ? floor($mins/60).'h ' : '') . ($mins % 60) . 'm';
}

$siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
$year    = date('Y');

$serviceLabel = [
    'fertilizer'     => 'Fertilizer / Lawn Treatment',
    'lawn_treatment' => 'Fertilizer / Lawn Treatment',
    'salt'           => 'Salt / De-Icing Service',
    'de_ice'         => 'Salt / De-Icing Service',
    'snow'           => 'Snow Clearance',
    'snow_clearance' => 'Snow Clearance',
    'mowing'         => 'Lawn Mowing',
    'general'        => 'Landscaping Service',
][$visit['service_type'] ?? 'general'] ?? 'Landscaping Service';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Proof of Work — Mowology Landscaping</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #f4f7f5; color: #1a1a1a; font-size: 15px; }
    a { color: #2D8659; text-decoration: none; }
    a:hover { text-decoration: underline; }

    .portal-wrap { max-width: 700px; margin: 0 auto; padding: 20px 16px 60px; }

    /* Header */
    .portal-header { background: #1A5F4A; color: #fff; border-radius: 10px 10px 0 0; padding: 20px 24px; margin-bottom: 0; }
    .portal-header h1 { font-size: 20px; font-weight: 700; }
    .portal-header .sub { font-size: 13px; opacity: 0.8; margin-top: 4px; }
    .portal-badge { background: rgba(127,216,88,0.3); color: #7FD858; font-size: 11px; font-weight: 600;
                    border-radius: 12px; padding: 2px 10px; display: inline-block; margin-top: 6px; }

    /* Main card */
    .portal-card { background: #fff; border-radius: 0 0 10px 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 16px; }
    .portal-section { padding: 20px 24px; border-bottom: 1px solid #e8f3f0; }
    .portal-section:last-child { border-bottom: none; }
    .portal-section h2 { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;
                          color: #1A5F4A; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }

    /* Stats */
    .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .stat-box { background: #e8f3f0; border-radius: 8px; padding: 12px; text-align: center; }
    .stat-box .val { font-size: 20px; font-weight: 700; color: #1A5F4A; }
    .stat-box .lbl { font-size: 11px; color: #666; margin-top: 2px; }

    /* Map */
    .route-map { width: 100%; border-radius: 8px; border: 1px solid #c3e6cb; max-height: 280px; object-fit: cover; }

    /* Checklist */
    .check-item { display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; border-bottom: 1px dotted #eee; }
    .check-item:last-child { border-bottom: none; }
    .check-icon { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; font-size: 14px; }
    .check-done { color: #2D8659; }
    .check-pend { color: #ccc; }

    /* Photos */
    .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin-top: 10px; }
    .photo-item img { width: 100%; height: 100px; object-fit: cover; border-radius: 6px; border: 1px solid #c3e6cb; }
    .photo-item .photo-type { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #2D8659; margin-top: 3px; }

    /* Notes */
    .note-item { background: #f9fdf9; border-left: 3px solid #7FD858; padding: 8px 12px;
                 border-radius: 0 4px 4px 0; font-size: 13px; margin-bottom: 8px; }

    /* Confidence box */
    .confidence-box { background: #f0fbf3; border: 2px solid #2D8659; border-radius: 10px; padding: 18px 20px; }
    .confidence-box h3 { font-size: 15px; color: #1A5F4A; margin-bottom: 12px; }
    .conf-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .conf-col h4 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #2D8659; margin-bottom: 6px; }
    .conf-col ul { list-style: none; padding: 0; }
    .conf-col ul li { padding: 3px 0; font-size: 13px; padding-left: 16px; position: relative; }
    .conf-col ul li::before { content: "✓"; position: absolute; left: 0; color: #2D8659; font-weight: 700; }
    .upsell-block { margin-top: 12px; background: #fff7e6; border-left: 3px solid #e85d04; padding: 8px 12px;
                    border-radius: 0 4px 4px 0; font-size: 13px; }
    .upsell-block strong { color: #e85d04; }

    /* PDF download */
    .pdf-download-btn { display: flex; align-items: center; justify-content: center; gap: 10px;
                         background: #2D8659; color: #fff; padding: 14px 20px; border-radius: 8px;
                         font-weight: 600; font-size: 15px; width: 100%; text-decoration: none;
                         border: none; cursor: pointer; transition: background 0.2s; }
    .pdf-download-btn:hover { background: #1A5F4A; color: #fff; text-decoration: none; }
    .pdf-download-btn svg { width: 20px; height: 20px; }

    /* Footer */
    .portal-footer { text-align: center; padding: 20px; color: #888; font-size: 12px; }
    .portal-footer a { color: #2D8659; }

    /* Error */
    .error-box { background: #fff; border-radius: 10px; padding: 40px 24px; text-align: center;
                  box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .error-box h2 { color: #dc3545; margin-bottom: 10px; }

    @media (max-width: 480px) {
      .stats-row { grid-template-columns: 1fr 1fr; }
      .conf-cols { grid-template-columns: 1fr; }
      .portal-section { padding: 16px; }
    }
  </style>
</head>
<body>
<div class="portal-wrap">

<?php if ($error): ?>
  <div class="error-box">
    <h2>Report Unavailable</h2>
    <p class="text-muted"><?= htmlspecialchars($error) ?></p>
    <p class="mt-3"><a href="<?= htmlspecialchars($siteUrl) ?>">← Return to Mowology.ca</a></p>
  </div>
<?php else: ?>

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <div class="portal-header">
    <h1>Your Service Report</h1>
    <div class="sub"><?= htmlspecialchars($serviceLabel) ?> — <?= htmlspecialchars($visitDate) ?></div>
    <div class="portal-badge">✓ Completed</div>
  </div>

  <div class="portal-card">

    <!-- ── Client & Property ────────────────────────────────────────────── -->
    <div class="portal-section">
      <h2>Visit Summary</h2>
      <p><strong><?= htmlspecialchars($clientName) ?></strong><br>
         <span style="color:#666;font-size:13px;"><?= htmlspecialchars($address) ?></span></p>
      <div class="stats-row mt-3">
        <div class="stat-box">
          <div class="val"><?= htmlspecialchars($duration ?: '—') ?></div>
          <div class="lbl">Duration</div>
        </div>
        <div class="stat-box">
          <div class="val"><?= $visit['distance_m'] ? number_format($visit['distance_m']/1000,2).' km' : '—' ?></div>
          <div class="lbl">Area Covered</div>
        </div>
        <div class="stat-box">
          <div class="val"><?= $visit['actual_crew_count'] ?? 1 ?></div>
          <div class="lbl">Crew Members</div>
        </div>
      </div>
    </div>

    <!-- ── Route Map ─────────────────────────────────────────────────────── -->
    <?php if (!empty($visit['map_snapshot_path'])): ?>
    <div class="portal-section">
      <h2>GPS Route</h2>
      <img src="<?= htmlspecialchars($visit['map_snapshot_path']) ?>"
           alt="GPS Route Map" class="route-map">
      <p style="font-size:11px;color:#888;margin-top:6px;text-align:center;">
        Tracked route for this service visit (<?= (int)$visit['gps_points_count'] ?> GPS points)
      </p>
    </div>
    <?php endif; ?>

    <!-- ── Checklist ──────────────────────────────────────────────────────── -->
    <?php if (!empty($checklist)): ?>
    <div class="portal-section">
      <h2>What We Completed</h2>
      <?php foreach ($checklist as $item): ?>
      <div class="check-item">
        <span class="check-icon <?= !empty($item['checked']) ? 'check-done' : 'check-pend' ?>">
          <?= !empty($item['checked']) ? '✓' : '○' ?>
        </span>
        <div>
          <div style="font-size:13px;"><?= htmlspecialchars($item['item'] ?? '') ?></div>
          <?php if (!empty($item['note'])): ?>
          <div style="font-size:11px;color:#666;"><?= htmlspecialchars($item['note']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Photos ────────────────────────────────────────────────────────── -->
    <?php if (!empty($photos)): ?>
    <div class="portal-section">
      <h2>Site Photos</h2>
      <div class="photo-grid">
        <?php foreach ($photos as $ph): ?>
        <div class="photo-item">
          <a href="/uploads/photos/<?= htmlspecialchars($ph['filename']) ?>" target="_blank">
            <img src="/uploads/photos/<?= htmlspecialchars($ph['filename']) ?>"
                 alt="<?= htmlspecialchars($ph['photo_type']) ?>"
                 onerror="this.parentElement.parentElement.style.display='none'">
          </a>
          <div class="photo-type"><?= htmlspecialchars($ph['photo_type']) ?></div>
          <?php if (!empty($ph['caption'])): ?>
          <div style="font-size:11px;color:#666;"><?= htmlspecialchars($ph['caption']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Notes ─────────────────────────────────────────────────────────── -->
    <?php if (!empty($notes)): ?>
    <div class="portal-section">
      <h2>Crew Notes</h2>
      <?php foreach ($notes as $n): ?>
      <div class="note-item"><?= nl2br(htmlspecialchars($n['content'])) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Client Confidence Box ─────────────────────────────────────────── -->
    <?php if (!empty($confBox)): ?>
    <div class="portal-section">
      <h2>Your Service Summary</h2>
      <div class="confidence-box">
        <h3>What We Did Today for You</h3>
        <div class="conf-cols">
          <?php if (!empty($confBox['done'])): ?>
          <div class="conf-col">
            <h4>Completed</h4>
            <ul>
              <?php foreach ($confBox['done'] as $bullet): ?>
              <li><?= htmlspecialchars($bullet) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
          <?php if (!empty($confBox['next'])): ?>
          <div class="conf-col">
            <h4>What's Next</h4>
            <ul>
              <?php foreach ($confBox['next'] as $bullet): ?>
              <li><?= htmlspecialchars($bullet) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!empty($confBox['upsell'])): ?>
        <div class="upsell-block">
          <strong>Recommendation:</strong> <?= htmlspecialchars($confBox['upsell']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── PDF Download ───────────────────────────────────────────────────── -->
    <div class="portal-section">
      <h2>Full Report</h2>
      <?php if ($hasPdf): ?>
      <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="pdf-download-btn" download>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>
        </svg>
        Download Proof of Work PDF
      </a>
      <p style="font-size:11px;color:#888;margin-top:8px;text-align:center;">
        Includes GPS route, photos, materials, checklist, and your service summary.
      </p>
      <?php else: ?>
      <p style="color:#666;font-size:13px;">
        Your full PDF report will be available shortly. Please check back or contact us.
      </p>
      <?php endif; ?>
    </div>

  </div><!-- /portal-card -->

<?php endif; ?>

  <div class="portal-footer">
    <p><a href="<?= htmlspecialchars($siteUrl) ?>">Mowology Landscaping</a> &middot;
    Questions? <a href="mailto:office@mowology.ca">office@mowology.ca</a></p>
    <p style="margin-top:4px;">&copy; <?= $year ?> Mowology Landscaping. All rights reserved.</p>
  </div>

</div><!-- /portal-wrap -->
</body>
</html>
