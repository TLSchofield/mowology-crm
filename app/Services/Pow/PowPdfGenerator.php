<?php
/**
 * Proof of Work PDF Generator
 * ────────────────────────────
 * Generates a branded PDF for a completed job visit.
 * Uses pure-PHP HTML→PDF via the html2pdf technique (no Composer):
 * renders structured HTML into a self-contained document that browsers
 * and Mowology's server can produce via wkhtmltopdf or html2canvas+jsPDF
 * on the client side.
 *
 * SERVER-SIDE: This class generates the HTML and, if wkhtmltopdf is
 * available, converts it to PDF. If not, it returns the HTML for
 * client-side rendering via window.print() / jsPDF.
 *
 * Storage:  /uploads/pow/pdfs/visit_{id}_v{n}.pdf
 * DB write: Sets job_visits.pdf_path, pdf_generated_at, locked_at, distance_m
 *
 * Client Confidence Box is included as the last content section.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

if (!class_exists('MapSnapshotService')) {
    require_once __DIR__ . '/MapSnapshotService.php';
}

class PowPdfGenerator
{
    private const STORAGE_DIR = '/uploads/pow/pdfs/';

    private \PDO    $db;
    private string  $publicRoot;
    private string  $storageDir;
    private string  $siteUrl;
    private string  $companyName;
    private string  $logoPath;

    public function __construct(\PDO $db, string $publicRoot, string $siteUrl)
    {
        $this->db          = $db;
        $this->publicRoot  = rtrim($publicRoot, '/');
        $this->storageDir  = $this->publicRoot . self::STORAGE_DIR;
        $this->siteUrl     = rtrim($siteUrl, '/');
        $this->companyName = 'Mowology Landscaping';
        $this->logoPath    = $this->publicRoot . '/assets/img/mowology-logo.png';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Generate a Proof of Work PDF for the given visit.
     *
     * @param int  $visitId
     * @param bool $forceRegen  If true, regenerates even if PDF exists
     * @return array{success:bool, path:string|null, error:string|null}
     */
    public function generate(int $visitId, bool $forceRegen = false): array
    {
        // Load full visit data
        $data = $this->loadVisitData($visitId);
        if (!$data) {
            return ['success' => false, 'path' => null, 'error' => 'Visit not found'];
        }

        // Version number (existing + 1)
        $version  = 1;
        if (!empty($data['visit']['pdf_path']) && !$forceRegen) {
            // PDF exists and not forcing — return cached
            return ['success' => true, 'path' => $data['visit']['pdf_path'], 'error' => null];
        }
        if (!empty($data['visit']['pdf_path'])) {
            // Bump version
            preg_match('/_v(\d+)\.pdf$/', $data['visit']['pdf_path'], $m);
            $version = isset($m[1]) ? (int)$m[1] + 1 : 2;
        }

        // Compute GPS stats & generate map snapshot
        $gpsStats   = [];
        $mapPath    = null;
        $googleKey  = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '');
        if (!empty($data['gps_points'])) {
            $mapSvc   = new MapSnapshotService($googleKey, $this->publicRoot);
            $gpsStats = $mapSvc->computeStats($data['gps_points']);
            $mapPath  = $mapSvc->getOrGenerate($visitId, $data['gps_points']);
        }

        // Build HTML
        $html = $this->buildHtml($data, $gpsStats, $mapPath);

        // Determine output path
        $filename = 'visit_' . $visitId . '_v' . $version . '.pdf';
        $filepath = $this->storageDir . $filename;
        $webPath  = self::STORAGE_DIR . $filename;

        // Try wkhtmltopdf
        $ok = $this->renderWithWkhtmltopdf($html, $filepath);

        if (!$ok) {
            // Fallback: save HTML as the "PDF" (client-side printing)
            $htmlFile = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFile, $html);
            $webPath = self::STORAGE_DIR . str_replace('.pdf', '.html', $filename);
        }

        // Update DB
        $distanceM = $gpsStats['distance_m'] ?? null;
        $this->db->prepare("
            UPDATE job_visits
            SET pdf_path         = ?,
                pdf_generated_at = NOW(),
                map_snapshot_path = ?,
                distance_m       = ?,
                gps_points_count = ?,
                locked_at        = NOW(),
                proof_complete   = 1,
                status           = IF(status = 'in_progress', 'completed', status)
            WHERE id = ?
        ")->execute([
            $webPath,
            $mapPath,
            $distanceM,
            count($data['gps_points']),
            $visitId,
        ]);

        return ['success' => true, 'path' => $webPath, 'error' => null];
    }

    // ─── Data loading ─────────────────────────────────────────────────────────

    private function loadVisitData(int $visitId): ?array
    {
        // Visit + plan + property + contact
        $stmt = $this->db->prepare("
            SELECT
                v.*,
                p.plan_number, p.title AS plan_title, p.service_type AS plan_service_type,
                p.checklist_template, p.description AS plan_description,
                pr.address AS property_address, pr.city AS property_city,
                pr.province AS property_province, pr.postal_code AS property_postal,
                c.first_name AS contact_first, c.last_name AS contact_last,
                c.email AS contact_email, c.phone AS contact_phone,
                c.mobile AS contact_mobile,
                u.full_name AS crew_name, u.phone AS crew_phone
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            LEFT JOIN properties pr ON p.property_id = pr.id
            LEFT JOIN contacts c ON pr.site_contact_id = c.id
            LEFT JOIN users u ON v.assigned_crew_id = u.id
            WHERE v.id = ?
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$visit) return null;

        // Use service_type from visit if set, else fall back to plan
        if (empty($visit['service_type'])) {
            $visit['service_type'] = $visit['plan_service_type'] ?? 'general';
        }

        // GPS points
        $stmt = $this->db->prepare("
            SELECT lat, lng, accuracy_m, speed_mps, heading, ts
            FROM visit_gps_points
            WHERE visit_id = ?
            ORDER BY ts ASC
        ");
        $stmt->execute([$visitId]);
        $gpsPoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Photos
        $stmt = $this->db->prepare("
            SELECT id, photo_type, filename, caption, uploaded_at
            FROM visit_photos
            WHERE visit_id = ?
            ORDER BY photo_type, uploaded_at
        ");
        $stmt->execute([$visitId]);
        $photos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Notes (public ones)
        $stmt = $this->db->prepare("
            SELECT content, note_type, created_at
            FROM visit_notes
            WHERE visit_id = ? AND is_visible_to_customer = 1
            ORDER BY created_at
        ");
        $stmt->execute([$visitId]);
        $notes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'visit'      => $visit,
            'gps_points' => $gpsPoints,
            'photos'     => $photos,
            'notes'      => $notes,
        ];
    }

    // ─── HTML builder ─────────────────────────────────────────────────────────

    private function buildHtml(array $data, array $gpsStats, ?string $mapWebPath): string
    {
        $v          = $data['visit'];
        $photos     = $data['photos'];
        $notes      = $data['notes'];
        $serviceType = strtolower($v['service_type'] ?? 'general');

        $materials  = !empty($v['materials_json'])   ? json_decode($v['materials_json'], true)   : [];
        $checklist  = !empty($v['checklist_json'])   ? json_decode($v['checklist_json'], true)   : [];
        $svcData    = !empty($v['service_data_json'])? json_decode($v['service_data_json'], true): [];
        $confBox    = !empty($v['confidence_box_json'])? json_decode($v['confidence_box_json'], true): null;

        // Fallback confidence box
        if (!$confBox) {
            $confBox = $this->generateConfidenceBox($v, $serviceType);
        }

        $startedAt   = $v['started_at']    ? date('D, F j, Y g:i A', strtotime($v['started_at'])) : 'N/A';
        $completedAt = $v['completed_at']  ? date('g:i A', strtotime($v['completed_at']))           : 'N/A';
        $duration    = '';
        if ($v['started_at'] && $v['completed_at']) {
            $mins = (int)round((strtotime($v['completed_at']) - strtotime($v['started_at'])) / 60);
            $duration = ($mins >= 60 ? floor($mins/60) . 'h ' : '') . ($mins % 60) . 'm';
        }

        $distKm  = isset($gpsStats['distance_m']) && $gpsStats['distance_m'] > 0
            ? number_format($gpsStats['distance_m'] / 1000, 2) . ' km'
            : 'N/A';

        $clientName = trim(($v['contact_first'] ?? '') . ' ' . ($v['contact_last'] ?? '')) ?: 'Valued Client';
        $address    = implode(', ', array_filter([
            $v['property_address'] ?? '',
            $v['property_city'] ?? '',
            $v['property_province'] ?? '',
            $v['property_postal'] ?? '',
        ]));

        $serviceLabel = $this->serviceLabel($serviceType);
        $logoTag      = '';
        if (file_exists($this->logoPath)) {
            $logoData = base64_encode(file_get_contents($this->logoPath));
            $logoTag  = '<img src="data:image/png;base64,' . $logoData . '" class="logo" alt="Mowology">';
        }

        $mapTag = '';
        if ($mapWebPath && file_exists($this->publicRoot . $mapWebPath)) {
            $imgData = base64_encode(file_get_contents($this->publicRoot . $mapWebPath));
            $mapTag  = '<img src="data:image/png;base64,' . $imgData . '" class="route-map" alt="GPS Route">';
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Proof of Work – <?= htmlspecialchars($v['visit_number'] ?? "Visit {$v['id']}") ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11pt; color: #1a1a1a; background: #fff; }
  a { color: #2D8659; text-decoration: none; }

  /* ── Header ── */
  .pow-header { background: #1A5F4A; color: #fff; padding: 20px 28px; display: flex; align-items: center; justify-content: space-between; }
  .pow-header .logo { height: 48px; }
  .pow-header .co-name { font-size: 20pt; font-weight: 700; letter-spacing: -0.5px; }
  .pow-header .doc-title { font-size: 10pt; opacity: 0.8; margin-top: 2px; }
  .pow-header .visit-badge { text-align: right; }
  .pow-header .visit-num { font-size: 9pt; font-family: monospace; background: rgba(255,255,255,0.15); padding: 2px 8px; border-radius: 4px; }

  /* ── Service type banner ── */
  .service-banner { background: #2D8659; color: #fff; padding: 7px 28px; font-size: 10pt; font-weight: 600; letter-spacing: 0.5px; display: flex; justify-content: space-between; }

  /* ── Sections ── */
  .section { padding: 18px 28px; border-bottom: 1px solid #e8f3f0; }
  .section:last-child { border-bottom: none; }
  .section-title { font-size: 10pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #1A5F4A; margin-bottom: 10px; border-left: 3px solid #2D8659; padding-left: 8px; }

  /* ── Info grid ── */
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
  .info-grid .label { font-size: 8.5pt; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
  .info-grid .value { font-size: 10.5pt; font-weight: 600; }

  /* ── Stats row ── */
  .stats-row { display: flex; gap: 20px; }
  .stat-box { flex: 1; background: #e8f3f0; border-radius: 6px; padding: 10px 14px; text-align: center; }
  .stat-box .stat-val { font-size: 16pt; font-weight: 700; color: #1A5F4A; }
  .stat-box .stat-lbl { font-size: 8pt; color: #555; margin-top: 2px; }

  /* ── Map ── */
  .route-map { width: 100%; max-height: 300px; object-fit: cover; border-radius: 6px; border: 1px solid #cde5d8; margin-top: 10px; }

  /* ── Materials table ── */
  table.materials { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10pt; }
  table.materials th { background: #1A5F4A; color: #fff; padding: 6px 10px; text-align: left; font-size: 9pt; }
  table.materials td { padding: 6px 10px; border-bottom: 1px solid #e0ede8; }
  table.materials tr:last-child td { border-bottom: none; }
  table.materials tr:nth-child(even) td { background: #f5faf7; }

  /* ── Checklist ── */
  .checklist-item { display: flex; align-items: flex-start; gap: 8px; padding: 4px 0; border-bottom: 1px dotted #dde; }
  .checklist-item:last-child { border-bottom: none; }
  .check-icon { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
  .check-done { color: #2D8659; }
  .check-skip { color: #bbb; }
  .check-text { font-size: 10pt; }
  .check-note { font-size: 8.5pt; color: #666; margin-top: 2px; }

  /* ── Photos ── */
  .photos-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
  .photo-item { width: 180px; }
  .photo-item img { width: 100%; height: 120px; object-fit: cover; border-radius: 4px; border: 1px solid #cde5d8; }
  .photo-item .photo-type { font-size: 7.5pt; font-weight: 700; text-transform: uppercase; color: #2D8659; margin-top: 3px; }
  .photo-item .photo-cap { font-size: 8pt; color: #555; }

  /* ── Notes ── */
  .note-item { background: #f9fdf9; border-left: 3px solid #7FD858; padding: 8px 12px; border-radius: 0 4px 4px 0; margin-bottom: 8px; font-size: 10pt; }

  /* ── Signature ── */
  .sig-block { display: flex; align-items: flex-end; gap: 24px; margin-top: 10px; }
  .sig-block img { max-width: 200px; max-height: 80px; border-bottom: 1px solid #333; }
  .sig-line { width: 200px; border-bottom: 1px solid #333; padding-top: 50px; font-size: 8.5pt; color: #555; text-align: center; }

  /* ── Confidence Box ── */
  .confidence-box { background: #f0fbf3; border: 2px solid #2D8659; border-radius: 8px; padding: 16px 20px; }
  .confidence-box h3 { color: #1A5F4A; font-size: 11pt; margin-bottom: 10px; }
  .confidence-col { display: inline-block; vertical-align: top; width: 48%; margin-right: 2%; }
  .confidence-col h4 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; color: #2D8659; margin-bottom: 6px; }
  .confidence-col ul { list-style: none; padding: 0; }
  .confidence-col ul li { padding: 3px 0; padding-left: 14px; position: relative; font-size: 10pt; }
  .confidence-col ul li::before { content: "✓"; position: absolute; left: 0; color: #2D8659; }
  .upsell-box { margin-top: 10px; background: #fff7e6; border-left: 3px solid #e85d04; padding: 8px 12px; border-radius: 0 4px 4px 0; font-size: 10pt; }
  .upsell-box strong { color: #e85d04; }

  /* ── Footer ── */
  .pow-footer { background: #0D3B2E; color: rgba(255,255,255,0.7); padding: 12px 28px; font-size: 8.5pt; display: flex; justify-content: space-between; }
  .pow-footer a { color: #7FD858; }

  /* ── Service-specific ── */
  .svc-detail { background: #f5faf7; border-radius: 6px; padding: 12px 16px; margin-top: 8px; }
  .svc-detail .row { display: flex; gap: 20px; margin-bottom: 6px; }
  .svc-detail .kv { flex: 1; }
  .svc-detail .kv .k { font-size: 8.5pt; color: #666; }
  .svc-detail .kv .v { font-size: 10.5pt; font-weight: 600; }

  @media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none; }
    .section { page-break-inside: avoid; }
  }
  @page { margin: 0; size: A4; }
</style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<div class="pow-header">
  <div>
    <?= $logoTag ?>
    <?php if (!$logoTag): ?>
    <div class="co-name"><?= htmlspecialchars($this->companyName) ?></div>
    <?php endif; ?>
    <div class="doc-title">PROOF OF WORK — SERVICE REPORT</div>
  </div>
  <div class="visit-badge">
    <div class="visit-num"><?= htmlspecialchars($v['visit_number'] ?? "VIS-{$v['id']}") ?></div>
    <div style="font-size:9pt;margin-top:4px;opacity:.8;">Generated <?= date('M j, Y') ?></div>
  </div>
</div>

<!-- ── Service banner ───────────────────────────────────────────────────── -->
<div class="service-banner">
  <span><?= htmlspecialchars($serviceLabel) ?></span>
  <span><?= htmlspecialchars($startedAt) ?></span>
</div>

<!-- ── Client & Job Info ────────────────────────────────────────────────── -->
<div class="section">
  <div class="section-title">Client &amp; Job Details</div>
  <div class="info-grid">
    <div>
      <div class="label">Client</div>
      <div class="value"><?= htmlspecialchars($clientName) ?></div>
    </div>
    <div>
      <div class="label">Service Address</div>
      <div class="value"><?= htmlspecialchars($address ?: 'Not recorded') ?></div>
    </div>
    <div>
      <div class="label">Plan</div>
      <div class="value"><?= htmlspecialchars(($v['plan_number'] ?? '') . ' — ' . ($v['plan_title'] ?? '')) ?></div>
    </div>
    <div>
      <div class="label">Crew</div>
      <div class="value"><?= htmlspecialchars($v['crew_name'] ?? 'Not assigned') ?></div>
    </div>
    <div>
      <div class="label">Visit Date</div>
      <div class="value"><?= htmlspecialchars($startedAt) ?></div>
    </div>
    <div>
      <div class="label">Finished</div>
      <div class="value"><?= htmlspecialchars($completedAt) ?></div>
    </div>
  </div>
</div>

<!-- ── Time & Route Stats ────────────────────────────────────────────────── -->
<div class="section">
  <div class="section-title">Visit Summary</div>
  <div class="stats-row">
    <div class="stat-box">
      <div class="stat-val"><?= htmlspecialchars($duration ?: 'N/A') ?></div>
      <div class="stat-lbl">Total Duration</div>
    </div>
    <div class="stat-box">
      <div class="stat-val"><?= htmlspecialchars($distKm) ?></div>
      <div class="stat-lbl">Distance Covered</div>
    </div>
    <div class="stat-box">
      <div class="stat-val"><?= (int)($gpsStats['points_used'] ?? 0) ?></div>
      <div class="stat-lbl">GPS Points Recorded</div>
    </div>
    <div class="stat-box">
      <div class="stat-val"><?= $v['actual_crew_count'] ?? 1 ?></div>
      <div class="stat-lbl">Crew Members</div>
    </div>
  </div>

  <?php if ($mapTag): ?>
  <div style="margin-top:14px;">
    <div class="label" style="margin-bottom:6px;">GPS Route Map</div>
    <?= $mapTag ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Service-Specific Section ────────────────────────────────────────── -->
<?php if (!empty($svcData) || $serviceType !== 'general'): ?>
<div class="section">
  <div class="section-title"><?= htmlspecialchars($serviceLabel) ?> Details</div>
  <?php echo $this->renderServiceSection($serviceType, $svcData); ?>
</div>
<?php endif; ?>

<!-- ── Materials Used ─────────────────────────────────────────────────── -->
<?php if (!empty($materials)): ?>
<div class="section">
  <div class="section-title">Materials Used</div>
  <table class="materials">
    <thead>
      <tr><th>Product / Material</th><th>Quantity</th><th>Unit</th><th>Notes</th></tr>
    </thead>
    <tbody>
      <?php foreach ($materials as $m): ?>
      <tr>
        <td><?= htmlspecialchars($m['name'] ?? '') ?></td>
        <td><?= htmlspecialchars(isset($m['qty']) ? number_format((float)$m['qty'], 2) : '') ?></td>
        <td><?= htmlspecialchars($m['unit'] ?? '') ?></td>
        <td><?= htmlspecialchars($m['note'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Checklist ──────────────────────────────────────────────────────── -->
<?php if (!empty($checklist)): ?>
<div class="section">
  <div class="section-title">Completion Checklist</div>
  <?php foreach ($checklist as $item): ?>
  <?php $done = !empty($item['checked']); ?>
  <div class="checklist-item">
    <span class="check-icon <?= $done ? 'check-done' : 'check-skip' ?>"><?= $done ? '✓' : '○' ?></span>
    <div>
      <div class="check-text"><?= htmlspecialchars($item['item'] ?? '') ?></div>
      <?php if (!empty($item['note'])): ?>
      <div class="check-note"><?= htmlspecialchars($item['note']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Photos ─────────────────────────────────────────────────────────── -->
<?php if (!empty($photos)): ?>
<div class="section">
  <div class="section-title">Site Photos</div>
  <div class="photos-grid">
    <?php foreach ($photos as $ph):
        $imgPath = $this->publicRoot . '/uploads/photos/' . $ph['filename'];
        if (!file_exists($imgPath)) continue;
        $imgData = base64_encode(file_get_contents($imgPath));
        $ext     = strtolower(pathinfo($ph['filename'], PATHINFO_EXTENSION));
        $mime    = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/' . $ext;
    ?>
    <div class="photo-item">
      <img src="data:<?= $mime ?>;base64,<?= $imgData ?>" alt="<?= htmlspecialchars($ph['photo_type']) ?>">
      <div class="photo-type"><?= htmlspecialchars($ph['photo_type']) ?></div>
      <?php if (!empty($ph['caption'])): ?>
      <div class="photo-cap"><?= htmlspecialchars($ph['caption']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Notes ──────────────────────────────────────────────────────────── -->
<?php if (!empty($notes)): ?>
<div class="section">
  <div class="section-title">Crew Notes</div>
  <?php foreach ($notes as $n): ?>
  <div class="note-item"><?= nl2br(htmlspecialchars($n['content'])) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Client Confidence Box ──────────────────────────────────────────── -->
<div class="section">
  <div class="section-title">Summary for <?= htmlspecialchars($clientName) ?></div>
  <div class="confidence-box">
    <h3>What We Did Today</h3>
    <div class="confidence-col">
      <h4>Completed</h4>
      <ul>
        <?php foreach (($confBox['done'] ?? []) as $bullet): ?>
        <li><?= htmlspecialchars($bullet) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="confidence-col">
      <h4>What to Expect Next</h4>
      <ul>
        <?php foreach (($confBox['next'] ?? []) as $bullet): ?>
        <li><?= htmlspecialchars($bullet) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php if (!empty($confBox['upsell'])): ?>
    <div class="upsell-box">
      <strong>Recommendation for You:</strong> <?= htmlspecialchars($confBox['upsell']) ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Signature ──────────────────────────────────────────────────────── -->
<div class="section">
  <div class="section-title">Sign-Off</div>
  <div class="sig-block">
    <?php if (!empty($v['signature_path']) && file_exists($this->publicRoot . $v['signature_path'])): ?>
    <?php $sigData = base64_encode(file_get_contents($this->publicRoot . $v['signature_path'])); ?>
    <div>
      <img src="data:image/png;base64,<?= $sigData ?>" alt="Client Signature">
      <div style="font-size:8.5pt;color:#555;margin-top:4px;">Client Signature</div>
    </div>
    <?php else: ?>
    <div class="sig-line">Client Signature</div>
    <?php endif; ?>
    <div>
      <div class="sig-line">Date: <?= date('Y-m-d') ?></div>
    </div>
  </div>
</div>

<!-- ── Footer ─────────────────────────────────────────────────────────── -->
<div class="pow-footer">
  <span><?= htmlspecialchars($this->companyName) ?> · <?= htmlspecialchars($this->siteUrl) ?></span>
  <span>Visit <?= htmlspecialchars($v['visit_number'] ?? $v['id']) ?> · Generated <?= date('Y-m-d H:i') ?> UTC</span>
</div>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render service-type-specific section HTML.
     */
    private function renderServiceSection(string $serviceType, array $svcData): string
    {
        ob_start();
        switch ($serviceType) {
            case 'fertilizer':
            case 'lawn_treatment':
                ?>
                <div class="svc-detail">
                  <div class="row">
                    <div class="kv"><div class="k">Product</div><div class="v"><?= htmlspecialchars($svcData['product'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Application Rate</div><div class="v"><?= htmlspecialchars($svcData['rate'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Area Treated</div><div class="v"><?= htmlspecialchars($svcData['area'] ?? 'N/A') ?></div></div>
                  </div>
                  <div class="row">
                    <div class="kv"><div class="k">Weather Conditions</div><div class="v"><?= htmlspecialchars($svcData['weather_note'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Temperature (°C)</div><div class="v"><?= htmlspecialchars($svcData['temp_c'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Wind Speed</div><div class="v"><?= htmlspecialchars($svcData['wind'] ?? 'N/A') ?></div></div>
                  </div>
                </div>
                <?php break;

            case 'salt':
            case 'de_ice':
                ?>
                <div class="svc-detail">
                  <div class="row">
                    <div class="kv"><div class="k">Salt Applied (kg)</div><div class="v"><?= htmlspecialchars($svcData['qty_kg'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Area Cleared</div><div class="v"><?= htmlspecialchars($svcData['area'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Air Temp (°C)</div><div class="v"><?= htmlspecialchars($svcData['temp_c'] ?? 'N/A') ?></div></div>
                  </div>
                  <div class="row">
                    <div class="kv"><div class="k">Priority Zones</div><div class="v"><?= htmlspecialchars($svcData['priority_zones'] ?? 'All zones') ?></div></div>
                    <div class="kv"><div class="k">Ice Conditions</div><div class="v"><?= htmlspecialchars($svcData['conditions'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Compliance Note</div><div class="v"><?= htmlspecialchars($svcData['compliance'] ?? 'Standard application') ?></div></div>
                  </div>
                </div>
                <?php break;

            case 'snow':
            case 'snow_clearance':
                ?>
                <div class="svc-detail">
                  <div class="row">
                    <div class="kv"><div class="k">Snow Depth (cm)</div><div class="v"><?= htmlspecialchars($svcData['depth_cm'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Equipment Used</div><div class="v"><?= htmlspecialchars($svcData['equipment'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Air Temp (°C)</div><div class="v"><?= htmlspecialchars($svcData['temp_c'] ?? 'N/A') ?></div></div>
                  </div>
                  <div class="row">
                    <div class="kv"><div class="k">Hazards Noted</div><div class="v"><?= htmlspecialchars($svcData['hazards'] ?? 'None') ?></div></div>
                    <div class="kv"><div class="k">Push/Stack Location</div><div class="v"><?= htmlspecialchars($svcData['push_location'] ?? 'N/A') ?></div></div>
                    <div class="kv"><div class="k">Salt Applied After</div><div class="v"><?= !empty($svcData['salt_applied']) ? 'Yes' : 'No' ?></div></div>
                  </div>
                </div>
                <?php break;

            default: // general
                if (!empty($svcData['notes'])): ?>
                <div class="svc-detail"><?= nl2br(htmlspecialchars($svcData['notes'])) ?></div>
                <?php endif;
                break;
        }
        return ob_get_clean();
    }

    /**
     * Auto-generate Client Confidence Box based on service type and season.
     */
    private function generateConfidenceBox(array $visit, string $serviceType): array
    {
        $month   = (int)date('n');
        $season  = $month >= 3 && $month <= 5 ? 'spring'
                 : ($month >= 6 && $month <= 8 ? 'summer'
                 : ($month >= 9 && $month <= 11 ? 'fall' : 'winter'));

        $done    = [];
        $next    = [];
        $upsell  = '';

        switch ($serviceType) {
            case 'fertilizer':
            case 'lawn_treatment':
                $done   = ['Applied premium fertilizer to all lawn areas', 'Inspected turf for disease or pest signs', 'Confirmed even coverage across property'];
                $next   = ['Results visible within 7–10 days', 'Next scheduled application in 6–8 weeks'];
                $upsell = $season === 'fall'
                    ? 'Winterizer application (high-potassium) before freeze — locks in root strength for spring green-up.'
                    : 'Overseeding thinner areas will thicken your lawn before the next season.';
                break;

            case 'salt':
            case 'de_ice':
                $done   = ['Applied de-icing agent to all priority surfaces', 'Checked walkways, steps, and parking areas', 'Ensured safe pedestrian access'];
                $next   = ['Monitor for refreezing overnight', 'Next service triggered by forecast conditions'];
                $upsell = 'A liquid de-icer pre-treatment before the next snowfall reduces solid salt usage by up to 40%.';
                break;

            case 'snow':
            case 'snow_clearance':
                $done   = ['Cleared all driveways and walkways', 'Stacked snow away from drainage areas', 'Checked for ice hazards after clearing'];
                $next   = ['Area ready for normal use', 'Monitoring forecast for next service requirement'];
                $upsell = 'Add a salt/sand application visit to prevent black ice formation after clearance.';
                break;

            default: // mowing / general
                $done   = ['Completed all scheduled service tasks', 'Inspected property for issues or concerns', 'Left site clean and ready'];
                $next   = ['Next scheduled visit as per your plan', 'We\'ll be in touch if conditions warrant an extra visit'];
                $upsell = $season === 'fall'
                    ? 'Fall cleanup and leaf removal — protect your lawn from matting before snowfall.'
                    : ($season === 'spring'
                        ? 'Spring aeration and overseeding opens up compacted soil and thickens your turf.'
                        : 'Moss control treatment — prevents invasive moss from crowding out your grass.');
                break;
        }

        return ['done' => $done, 'next' => $next, 'upsell' => $upsell];
    }

    private function serviceLabel(string $type): string
    {
        $map = [
            'fertilizer'     => 'Fertilizer / Lawn Treatment',
            'lawn_treatment' => 'Fertilizer / Lawn Treatment',
            'salt'           => 'Salt / De-Icing Service',
            'de_ice'         => 'Salt / De-Icing Service',
            'snow'           => 'Snow Clearance',
            'snow_clearance' => 'Snow Clearance',
            'mowing'         => 'Lawn Mowing Service',
            'general'        => 'General Landscaping Service',
        ];
        return $map[strtolower($type)] ?? 'Landscaping Service';
    }

    /**
     * Convert HTML to PDF using wkhtmltopdf if available on the server.
     * Returns true on success.
     */
    private function renderWithWkhtmltopdf(string $html, string $outputPath): bool
    {
        $wk = '/usr/local/bin/wkhtmltopdf';
        if (!file_exists($wk)) $wk = '/usr/bin/wkhtmltopdf';
        if (!file_exists($wk)) return false;

        $tmpHtml = sys_get_temp_dir() . '/pow_' . uniqid() . '.html';
        file_put_contents($tmpHtml, $html);

        $cmd = escapeshellarg($wk)
             . ' --quiet'
             . ' --page-size A4'
             . ' --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0'
             . ' --enable-local-file-access'
             . ' ' . escapeshellarg($tmpHtml)
             . ' ' . escapeshellarg($outputPath)
             . ' 2>&1';

        exec($cmd, $out, $rc);
        unlink($tmpHtml);

        return $rc === 0 && file_exists($outputPath);
    }
}
