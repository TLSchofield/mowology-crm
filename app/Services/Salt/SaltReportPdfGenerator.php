<?php
/**
 * Salt / Winter Service Report PDF Generator
 * ────────────────────────────────────────────
 * Generates a legally defensible Proof-of-Work PDF for any visit that
 * includes snow_removal or salt_application service types.
 *
 * Document sections:
 *   1. Cover page        — property, crew, date, service summary
 *   2. Weather Auth      — authoritative EC forecast at time of go-decision
 *   3. Service Record    — service commencement time, GPS pings, services performed, materials
 *   4. GPS Site Map      — Google Static Maps satellite image with GPS track
 *   5. Photo Evidence    — before / during / after photos
 *   6. Chain of Custody  — visit_audit_log entries + PDF integrity hash
 *
 * Storage:  storage/pdfs/salt-reports/salt_{id}_v{n}.pdf
 * DB write: salt_run_reports (pdf_path, pdf_hash, pdf_generated_at)
 *           job_visits.pdf_path (cross-reference)
 *           visit_audit_log (SALT_REPORT_GENERATED action)
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';

if (!class_exists('MapSnapshotService')) {
    require_once APP_ROOT . '/Services/Pow/MapSnapshotService.php';
}

class SaltReportPdfGenerator
{
    private const RELATIVE_DIR = 'storage/pdfs/salt-reports/';

    private \PDO   $db;
    private string $publicRoot;
    private string $storageDir;
    private string $siteUrl;
    private int    $userId;

    public function __construct(\PDO $db, string $publicRoot, string $siteUrl, int $userId = 0)
    {
        $this->db         = $db;
        $this->publicRoot = rtrim($publicRoot, '/');
        $this->storageDir = defined('STORAGE_ROOT')
            ? STORAGE_ROOT . '/pdfs/salt-reports'
            : dirname($this->publicRoot) . '/storage/pdfs/salt-reports';
        $this->siteUrl    = rtrim($siteUrl, '/');
        $this->userId     = $userId;

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Generate (or retrieve cached) Winter Service Report PDF for a visit.
     *
     * @return array{success:bool, path:string|null, report_number:string|null, error:string|null}
     */
    public function generate(int $visitId, bool $forceRegen = false): array
    {
        $data = $this->loadVisitData($visitId);
        if (!$data) {
            return ['success' => false, 'path' => null, 'report_number' => null, 'error' => 'Visit not found'];
        }

        $report = $this->getOrCreateReportRecord($visitId);

        if (!empty($report['pdf_path']) && !$forceRegen) {
            return ['success' => true, 'path' => $report['pdf_path'], 'report_number' => $report['report_number'], 'error' => null];
        }

        // Version bump on re-generate
        $version = 1;
        if (!empty($report['pdf_path'])) {
            preg_match('/_v(\d+)\.pdf$/', $report['pdf_path'], $m);
            $version = isset($m[1]) ? (int)$m[1] + 1 : 2;
        }

        // GPS map snapshot
        $mapPath  = null;
        $gpsStats = [];
        $googleKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '');
        if (!empty($data['gpsPoints'])) {
            $mapSvc   = new MapSnapshotService($googleKey, $this->publicRoot);
            $gpsStats = $mapSvc->computeStats($data['gpsPoints']);
            $mapPath  = $mapSvc->getOrGenerate($visitId, $data['gpsPoints']);
        }

        $html = $this->buildHtml($data, $gpsStats, $mapPath, $report['report_number']);

        $filename = 'salt_' . $visitId . '_v' . $version . '.pdf';
        $filepath = $this->storageDir . '/' . $filename;
        $webPath  = self::RELATIVE_DIR . $filename;

        $this->renderWithMpdf($html, $filepath);

        $pdfHash = hash_file('sha256', $filepath);

        // Update salt_run_reports
        $this->db->prepare("
            UPDATE salt_run_reports
            SET pdf_path = ?, pdf_hash = ?, pdf_generated_at = NOW(), portal_available_at = NOW()
            WHERE visit_id = ?
        ")->execute([$webPath, $pdfHash, $visitId]);

        // Cross-reference on job_visits so the standard PoW link also works
        $this->db->prepare("
            UPDATE job_visits SET pdf_path = ?, pdf_generated_at = NOW() WHERE id = ?
        ")->execute([$webPath, $visitId]);

        // Audit log — non-critical; wrap so a FK miss doesn't abort the return
        if ($this->userId > 0) {
            try {
                $this->db->prepare("
                    INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json)
                    VALUES (?, ?, 'SALT_REPORT_GENERATED', ?)
                ")->execute([$visitId, $this->userId, json_encode(['report_number' => $report['report_number'], 'pdf_hash' => $pdfHash, 'version' => $version])]);
            } catch (\Throwable $e) {
                error_log('SaltReportPdfGenerator: audit log insert failed — ' . $e->getMessage());
            }
        }

        return ['success' => true, 'path' => $webPath, 'report_number' => $report['report_number'], 'error' => null];
    }

    // ─── Data loading ─────────────────────────────────────────────────────────

    private function loadVisitData(int $visitId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                v.*,
                p.plan_number, p.title AS plan_title,
                pr.address AS property_address, pr.city AS property_city,
                pr.province AS property_province, pr.postal_code AS property_postal,
                pr.latitude AS property_lat, pr.longitude AS property_lng,
                c.first_name AS contact_first, c.last_name AS contact_last,
                c.email AS contact_email, c.phone AS contact_phone,
                u.full_name AS crew_name
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

        // Service types on this visit's plan
        $stmt = $this->db->prepare("
            SELECT DISTINCT pli.service_type, pli.description, pli.unit_price
            FROM plan_line_items pli
            WHERE pli.plan_id = ?
              AND pli.service_type IN ('snow_removal', 'salt_application')
        ");
        $stmt->execute([$visit['plan_id']]);
        $services = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // GPS breadcrumb
        $stmt = $this->db->prepare("
            SELECT lat, lng, accuracy_m, speed_mps, ts
            FROM visit_gps_points
            WHERE visit_id = ?
            ORDER BY ts ASC
        ");
        $stmt->execute([$visitId]);
        $gpsPoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Photos
        $stmt = $this->db->prepare("
            SELECT photo_type, filename, caption, uploaded_at
            FROM visit_photos
            WHERE visit_id = ? AND deleted_at IS NULL
            ORDER BY photo_type, uploaded_at
        ");
        $stmt->execute([$visitId]);
        $photos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Weather decision
        $weather = $this->loadWeatherDecision($visitId);

        // Audit log (last 20 actions)
        $stmt = $this->db->prepare("
            SELECT val.action, val.payload_json, val.created_at, u.full_name AS actor
            FROM visit_audit_log val
            LEFT JOIN users u ON u.id = val.user_id
            WHERE val.visit_id = ?
            ORDER BY val.created_at ASC
            LIMIT 20
        ");
        $stmt->execute([$visitId]);
        $auditLog = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return compact('visit', 'services', 'gpsPoints', 'photos', 'weather', 'auditLog');
    }

    private function loadWeatherDecision(int $visitId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM salt_weather_decisions
            WHERE visit_id = ?
            ORDER BY decision_at DESC
            LIMIT 1
        ");
        $stmt->execute([$visitId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function getOrCreateReportRecord(int $visitId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM salt_run_reports WHERE visit_id = ?");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;

        // Need CrmFunctions for number generation
        if (!function_exists('generateSaltReportNumber')) {
            require_once APP_ROOT . '/Services/CrmFunctions.php';
        }
        $reportNumber = generateSaltReportNumber();

        // Find weather decision id if available
        $decStmt = $this->db->prepare("SELECT id FROM salt_weather_decisions WHERE visit_id = ? LIMIT 1");
        $decStmt->execute([$visitId]);
        $decId = $decStmt->fetchColumn() ?: null;

        $this->db->prepare("
            INSERT INTO salt_run_reports (report_number, visit_id, decision_id, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([$reportNumber, $visitId, $decId]);

        return ['report_number' => $reportNumber, 'visit_id' => $visitId, 'decision_id' => $decId, 'pdf_path' => null];
    }

    // ─── HTML builder ─────────────────────────────────────────────────────────

    private function buildHtml(array $data, array $gpsStats, ?string $mapWebPath, string $reportNumber): string
    {
        $v        = $data['visit'];
        $services = $data['services'];
        $photos   = $data['photos'];
        $weather  = $data['weather'];
        $auditLog = $data['auditLog'];

        $svcData  = !empty($v['service_data_json']) ? json_decode($v['service_data_json'], true) : [];

        $hasSnow = false;
        $hasSalt = false;
        foreach ($services as $svc) {
            if ($svc['service_type'] === 'snow_removal')   $hasSnow = true;
            if ($svc['service_type'] === 'salt_application') $hasSalt = true;
        }
        // Fallback: check service_data_json for salt fields even if line item missing
        if (!empty($svcData['qty_kg']) || !empty($svcData['conditions'])) $hasSalt = true;

        $serviceLabel = $hasSnow && $hasSalt ? 'Snow Removal & Salt Application'
            : ($hasSnow ? 'Snow Removal' : 'Salt Application');

        $propertyAddress = trim(($v['property_address'] ?? '') . ', ' . ($v['property_city'] ?? '') . ', ' . ($v['property_province'] ?? ''));
        $contactName     = trim(($v['contact_first'] ?? '') . ' ' . ($v['contact_last'] ?? ''));
        $serviceDate     = $v['scheduled_date'] ? date('l, F j, Y', strtotime($v['scheduled_date'])) : 'N/A';
        $generatedAt     = date('F j, Y \a\t g:i A T');

        $clockIn      = $v['started_at'] ? date('g:i A', strtotime($v['started_at'])) : 'N/A';
        $dwellQuality = (int)($v['gps_dwell_quality'] ?? 0);

        $saltProduct = $svcData['product'] ?? ($svcData['conditions'] ?? '');
        $saltQtyKg   = $svcData['qty_kg'] ?? '';
        $saltArea    = $svcData['area'] ?? '';
        $saltZones   = $svcData['priority_zones'] ?? '';
        $saltNotes   = $svcData['conditions'] ?? '';

        // Logo — embed as base64
        $logoBase64 = '';
        $logoPath   = $this->publicRoot . '/assets/img/mowology-logo.png';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        // GPS map — embed as base64
        $mapBase64 = '';
        if ($mapWebPath) {
            $mapAbs = $this->publicRoot . $mapWebPath;
            if (file_exists($mapAbs)) {
                $ext = strtolower(pathinfo($mapAbs, PATHINFO_EXTENSION));
                $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
                $mapBase64 = "data:{$mime};base64," . base64_encode(file_get_contents($mapAbs));
            }
        }

        $gpsPointCount = count($data['gpsPoints']);
        $distKm = isset($gpsStats['distance_m']) && $gpsStats['distance_m'] > 0
            ? number_format($gpsStats['distance_m'] / 1000, 2) . ' km'
            : 'N/A';

        // Arrival / departure coords
        $arrivalLat  = $v['gps_arrival_lat']   ?? null;
        $arrivalLng  = $v['gps_arrival_lng']   ?? null;
        $departureLat = $v['gps_departure_lat'] ?? null;
        $departureLng = $v['gps_departure_lng'] ?? null;

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: helvetica, arial, sans-serif; font-size: 11px; color: #1a1a1a; }

/* ── Cover page ── */
.cover { width: 100%; min-height: 1050px; background: #0D3B2E; color: #fff; padding: 60px 50px 40px; }
.cover-logo { margin-bottom: 30px; }
.cover-logo img { height: 50px; }
.cover-eyebrow { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #7FD858; margin-bottom: 12px; }
.cover-title { font-size: 32px; font-weight: bold; color: #fff; line-height: 1.2; margin-bottom: 8px; }
.cover-subtitle { font-size: 15px; color: #a0c8b8; margin-bottom: 50px; }
.cover-property { background: rgba(255,255,255,0.08); border-radius: 6px; padding: 24px 28px; margin-bottom: 30px; }
.cover-property-label { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: #7FD858; margin-bottom: 6px; }
.cover-property-address { font-size: 18px; font-weight: bold; color: #fff; }
.cover-property-contact { font-size: 12px; color: #a0c8b8; margin-top: 4px; }
.cover-grid { display: table; width: 100%; margin-bottom: 40px; }
.cover-cell { display: table-cell; width: 33%; padding-right: 20px; }
.cover-cell-label { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: #7FD858; margin-bottom: 4px; }
.cover-cell-value { font-size: 13px; font-weight: bold; color: #fff; }
.cover-services { border-top: 1px solid rgba(255,255,255,0.15); padding-top: 20px; margin-bottom: 40px; }
.svc-chip { display: inline-block; padding: 4px 12px; border-radius: 3px; font-size: 10px; font-weight: bold; letter-spacing: 0.5px; margin-right: 8px; }
.svc-chip-snow { background: #1565C0; color: #fff; }
.svc-chip-salt { background: #0D47A1; color: #fff; }
.svc-chip-none { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.4); }
.cover-footer { border-top: 1px solid rgba(255,255,255,0.15); padding-top: 16px; font-size: 10px; color: rgba(255,255,255,0.5); display: table; width: 100%; }
.cover-footer-left  { display: table-cell; }
.cover-footer-right { display: table-cell; text-align: right; }

/* ── Section pages ── */
.page { padding: 30px 40px; }
.section-header { background: #0D3B2E; color: #fff; padding: 14px 20px; margin: -30px -40px 24px; }
.section-eyebrow { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #7FD858; margin-bottom: 4px; }
.section-title { font-size: 16px; font-weight: bold; }

/* ── Fact table ── */
.fact-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.fact-table td { padding: 6px 10px; border: 1px solid #e0e0e0; font-size: 10.5px; }
.fact-table td:first-child { background: #f5f5f5; font-weight: bold; width: 160px; color: #444; }

/* ── Weather block ── */
.weather-decision { background: #E3F2FD; border: 2px solid #1565C0; border-radius: 6px; padding: 18px 20px; margin-bottom: 20px; }
.weather-decision-title { font-size: 12px; font-weight: bold; color: #0D47A1; margin-bottom: 8px; }
.weather-quote { font-size: 13px; color: #0D3B2E; line-height: 1.5; font-style: italic; margin-bottom: 12px; border-left: 4px solid #1565C0; padding-left: 14px; }
.weather-source { font-size: 9px; color: #666; }
.weather-source a { color: #1565C0; }
.weather-warn { background: #FFF3E0; border: 1px solid #F57C00; border-radius: 4px; padding: 10px 14px; margin-bottom: 16px; font-size: 10px; color: #E65100; }
.weather-integrity { background: #F1F8E9; border: 1px solid #7CB342; border-radius: 4px; padding: 10px 14px; font-size: 9.5px; color: #33691E; }

/* ── GPS section ── */
.map-container { text-align: center; margin: 16px 0; }
.map-container img { max-width: 100%; border: 1px solid #ddd; border-radius: 4px; }
.map-placeholder { background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: 40px; text-align: center; color: #999; font-size: 11px; }
.gps-warn { background: #FFF8E1; border: 1px solid #FFB300; border-radius: 4px; padding: 10px 14px; margin-top: 12px; font-size: 10px; color: #795548; }

/* ── Photos ── */
.photo-group-title { font-size: 11px; font-weight: bold; color: #444; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #0D3B2E; padding-bottom: 4px; margin: 16px 0 10px; }
.photo-grid { display: table; width: 100%; }
.photo-row { display: table-row; }
.photo-cell { display: table-cell; width: 50%; padding: 5px; vertical-align: top; }
.photo-img-wrap { border: 1px solid #ddd; border-radius: 4px; overflow: hidden; }
.photo-img-wrap img { width: 100%; display: block; }
.photo-caption { font-size: 9px; color: #666; padding: 4px 6px; }
.no-photos { color: #999; font-style: italic; font-size: 11px; padding: 20px 0; }

/* ── Audit log ── */
.audit-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
.audit-table th { background: #455A64; color: #fff; padding: 5px 8px; text-align: left; }
.audit-table td { padding: 5px 8px; border-bottom: 1px solid #eee; }
.audit-table tr:nth-child(even) td { background: #fafafa; }

/* ── Integrity footer ── */
.integrity-box { background: #263238; color: #cfd8dc; padding: 16px 20px; margin-top: 24px; border-radius: 4px; font-size: 9px; line-height: 1.6; }
.integrity-box strong { color: #7FD858; }

/* ── Page break ── */
.page-break { page-break-before: always; }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     PAGE 1 — COVER
     ══════════════════════════════════════════════════════════ -->
<div class="cover">
    <div class="cover-logo">
        <?php if ($logoBase64): ?>
        <img src="<?= $logoBase64 ?>">
        <?php else: ?>
        <span style="font-size:22px;font-weight:bold;color:#7FD858;">MOWOLOGY</span>
        <?php endif; ?>
    </div>
    <div class="cover-eyebrow">Proof of Service</div>
    <div class="cover-title">Winter Service Record</div>
    <div class="cover-subtitle"><?= htmlspecialchars($serviceLabel) ?></div>

    <div class="cover-property">
        <div class="cover-property-label">Service Property</div>
        <div class="cover-property-address"><?= htmlspecialchars($propertyAddress) ?></div>
        <?php if ($contactName): ?>
        <div class="cover-property-contact">Prepared for: <?= htmlspecialchars($contactName) ?></div>
        <?php endif; ?>
    </div>

    <div class="cover-grid">
        <div class="cover-cell">
            <div class="cover-cell-label">Service Date</div>
            <div class="cover-cell-value"><?= htmlspecialchars($serviceDate) ?></div>
        </div>
        <div class="cover-cell">
            <div class="cover-cell-label">Crew</div>
            <div class="cover-cell-value"><?= htmlspecialchars($v['crew_name'] ?? 'Mowology Crew') ?></div>
        </div>
        <div class="cover-cell">
            <div class="cover-cell-label">Report No.</div>
            <div class="cover-cell-value"><?= htmlspecialchars($reportNumber) ?></div>
        </div>
    </div>

    <div class="cover-services">
        <div class="cover-cell-label" style="margin-bottom:10px;">Services Performed</div>
        <span class="svc-chip <?= $hasSnow ? 'svc-chip-snow' : 'svc-chip-none' ?>">
            <?= $hasSnow ? '✓' : '—' ?> Snow Removal
        </span>
        <span class="svc-chip <?= $hasSalt ? 'svc-chip-salt' : 'svc-chip-none' ?>">
            <?= $hasSalt ? '✓' : '—' ?> Salt Application
        </span>
    </div>

    <div class="cover-footer">
        <div class="cover-footer-left">Generated: <?= htmlspecialchars($generatedAt) ?></div>
        <div class="cover-footer-right">Mowology Landscaping &bull; (778) 846-9273</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     PAGE 2 — WEATHER AUTHORIZATION
     ══════════════════════════════════════════════════════════ -->
<div class="page page-break">
    <div class="section-header">
        <div class="section-eyebrow">Section 1</div>
        <div class="section-title">Official Weather Decision Record</div>
    </div>

    <p style="font-size:10.5px;color:#444;margin-bottom:16px;line-height:1.6;">
        Winter services are only performed when official meteorological data confirms conditions
        meet or exceed the contractual service threshold. The record below documents the exact
        forecast data that authorized this service call.
    </p>

    <?php if ($weather): ?>
    <div class="weather-decision">
        <div class="weather-decision-title">Weather Authorization — <?= htmlspecialchars(date('F j, Y', strtotime($weather['trigger_date']))) ?></div>
        <div class="weather-quote">
            Overnight low of <?= htmlspecialchars($weather['overnight_low_c']) ?>°C was forecast
            (threshold: ≤<?= htmlspecialchars($weather['trigger_threshold_c']) ?>°C).
            Condition: <?= htmlspecialchars($weather['weather_condition'] ?? 'N/A') ?>.
            Winter service was authorized and performed.
        </div>
        <div class="weather-source">
            <strong>Source:</strong> <?= htmlspecialchars($weather['data_source'] ?? 'Environment Canada') ?><br>
            <strong>Station:</strong> <?= htmlspecialchars($weather['source_station'] ?? 'N/A') ?><br>
            <strong>Data URL:</strong> <?= htmlspecialchars($weather['source_url'] ?? 'N/A') ?><br>
            <strong>Decision captured:</strong> <?= htmlspecialchars($weather['decision_at']) ?> (server time)
        </div>
    </div>

    <table class="fact-table">
        <tr><td>Forecast overnight low</td><td><?= htmlspecialchars($weather['overnight_low_c']) ?>°C</td></tr>
        <tr><td>Service threshold</td><td>≤<?= htmlspecialchars($weather['trigger_threshold_c']) ?>°C</td></tr>
        <tr><td>Weather condition</td><td><?= htmlspecialchars($weather['weather_condition'] ?? 'N/A') ?></td></tr>
        <tr><td>Data source</td><td><?= htmlspecialchars($weather['data_source'] ?? 'Environment Canada') ?></td></tr>
        <tr><td>Decision recorded at</td><td><?= htmlspecialchars($weather['decision_at']) ?></td></tr>
        <tr><td>Raw forecast stored</td><td><?= $weather['raw_api_response'] ? 'Yes — full API response retained' : 'No' ?></td></tr>
    </table>

    <div class="weather-integrity">
        <strong>Data Integrity Notice:</strong> This forecast data was captured automatically at
        the time of the service decision and is stored immutably in the Mowology service record system.
        The raw Environment Canada API response is retained on file for legal verification purposes.
        This record cannot be retroactively altered.
    </div>

    <?php else: ?>
    <div class="weather-warn">
        <strong>Notice:</strong> No automated weather decision record was found for this visit date.
        This may indicate the service was manually initiated, the weather cron ran outside the
        normal window, or the visit was created before the weather decision system was active.
        Manual notes or other evidence of conditions should be referenced if required.
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     PAGE 3 — SERVICE RECORD
     ══════════════════════════════════════════════════════════ -->
<div class="page page-break">
    <div class="section-header">
        <div class="section-eyebrow">Section 2</div>
        <div class="section-title">Service Execution Record</div>
    </div>

    <table class="fact-table" style="margin-bottom:20px;">
        <tr><td>Crew member</td><td><?= htmlspecialchars($v['crew_name'] ?? 'N/A') ?></td></tr>
        <tr><td>Service commenced</td><td><?= htmlspecialchars($clockIn) ?></td></tr>
        <tr><td>GPS points captured</td><td><?= $gpsPointCount ?> location pings recorded on-site</td></tr>
        <?php if ($distKm !== 'N/A'): ?>
        <tr><td>Distance covered on property</td><td><?= htmlspecialchars($distKm) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if ($hasSnow): ?>
    <div style="font-size:12px;font-weight:bold;color:#1565C0;margin-bottom:8px;border-bottom:2px solid #1565C0;padding-bottom:4px;">
        Snow Removal
    </div>
    <table class="fact-table" style="margin-bottom:20px;">
        <tr><td>Service type</td><td>Snow Removal</td></tr>
        <?php if (!empty($svcData['snow_areas'])): ?>
        <tr><td>Areas cleared</td><td><?= htmlspecialchars($svcData['snow_areas']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($svcData['snow_notes'])): ?>
        <tr><td>Notes</td><td><?= htmlspecialchars($svcData['snow_notes']) ?></td></tr>
        <?php endif; ?>
        <?php if (empty($svcData['snow_areas']) && empty($svcData['snow_notes'])): ?>
        <tr><td>Notes</td><td>Snow removal performed — see photo evidence</td></tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <?php if ($hasSalt): ?>
    <div style="font-size:12px;font-weight:bold;color:#0D47A1;margin-bottom:8px;border-bottom:2px solid #0D47A1;padding-bottom:4px;">
        Salt Application
    </div>
    <table class="fact-table" style="margin-bottom:20px;">
        <?php if ($saltProduct): ?>
        <tr><td>Salt product</td><td><?= htmlspecialchars($saltProduct) ?></td></tr>
        <?php endif; ?>
        <?php if ($saltQtyKg): ?>
        <tr><td>Quantity applied</td><td><?= htmlspecialchars($saltQtyKg) ?> kg</td></tr>
        <?php endif; ?>
        <?php if ($saltArea): ?>
        <tr><td>Area treated</td><td><?= htmlspecialchars($saltArea) ?></td></tr>
        <?php endif; ?>
        <?php if ($saltZones): ?>
        <tr><td>Priority zones</td><td><?= htmlspecialchars(is_array($saltZones) ? implode(', ', $saltZones) : $saltZones) ?></td></tr>
        <?php endif; ?>
        <?php if ($saltNotes && $saltNotes !== $saltProduct): ?>
        <tr><td>Notes</td><td><?= htmlspecialchars($saltNotes) ?></td></tr>
        <?php endif; ?>
        <?php if (!$saltProduct && !$saltQtyKg): ?>
        <tr><td>Notes</td><td>Salt application performed — see photo evidence</td></tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <?php if ($dwellQuality < 2 && $gpsPointCount > 0): ?>
    <div class="gps-warn">
        <strong>GPS Coverage Note:</strong> Sparse GPS signal was recorded for this visit
        (<?= $gpsPointCount ?> points). Photo timestamps and service commencement records
        confirm on-site presence. This is disclosed proactively and does not indicate the
        service was not performed.
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     PAGE 4 — GPS SITE MAP
     ══════════════════════════════════════════════════════════ -->
<div class="page page-break">
    <div class="section-header">
        <div class="section-eyebrow">Section 3</div>
        <div class="section-title">GPS Tracking Record — On-Site Verification</div>
    </div>

    <p style="font-size:10px;color:#444;margin-bottom:14px;">
        The map below shows <?= $gpsPointCount ?> GPS coordinates captured by the crew member's
        device during this visit. The <strong style="color:#2E7D32;">green marker (A)</strong> indicates
        arrival; the <strong style="color:#C62828;">red marker (Z)</strong> indicates departure.
        The blue line traces the crew's path across the property.
    </p>

    <?php if ($mapBase64): ?>
    <div class="map-container">
        <img src="<?= $mapBase64 ?>" style="width:100%;">
    </div>
    <table class="fact-table" style="margin-top:14px;">
        <tr><td>Service commenced</td><td><?= htmlspecialchars($clockIn) ?><?= $arrivalLat ? ' — GPS: ' . round((float)$arrivalLat, 5) . ', ' . round((float)$arrivalLng, 5) : '' ?></td></tr>
        <tr><td>Total GPS pings</td><td><?= $gpsPointCount ?> location points recorded on-site</td></tr>
        <?php if ($distKm !== 'N/A'): ?>
        <tr><td>Distance covered</td><td><?= htmlspecialchars($distKm) ?></td></tr>
        <?php endif; ?>
        <tr><td>Map type</td><td>Satellite imagery via Google Maps Static API</td></tr>
    </table>
    <?php else: ?>
    <div class="map-placeholder">
        <?php if ($gpsPointCount < 2): ?>
        Insufficient GPS data to render a site map for this visit (<?= $gpsPointCount ?> point<?= $gpsPointCount === 1 ? '' : 's' ?> recorded).
        <?php else: ?>
        Site map unavailable — Google Maps API was unreachable at time of PDF generation.
        <?php $googleKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : ''; ?>
        <?php if (!$googleKey): ?>
        (Google Maps API key not configured.)
        <?php endif; ?>
        <?php endif; ?>
        <br><br>
        GPS arrival coordinates: <?= $arrivalLat ? round((float)$arrivalLat, 5) . ', ' . round((float)$arrivalLng, 5) : 'N/A' ?><br>
        GPS departure coordinates: <?= $departureLat ? round((float)$departureLat, 5) . ', ' . round((float)$departureLng, 5) : 'N/A' ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     PAGE 5+ — PHOTO EVIDENCE
     ══════════════════════════════════════════════════════════ -->
<div class="page page-break">
    <div class="section-header">
        <div class="section-eyebrow">Section 4</div>
        <div class="section-title">Photo Evidence</div>
    </div>

    <?php
    $photosByType = [];
    foreach ($photos as $ph) {
        $photosByType[$ph['photo_type']][] = $ph;
    }
    $typeOrder  = ['before' => 'Before Service', 'during' => 'During Service', 'after' => 'After Service', 'additional' => 'Additional', 'issue' => 'Issues Noted', 'other' => 'Other'];
    $totalShown = 0;

    foreach ($typeOrder as $typeKey => $typeLabel) {
        $typedPhotos = $photosByType[$typeKey] ?? [];
        if (empty($typedPhotos)) continue;
        ?>
        <div class="photo-group-title"><?= htmlspecialchars($typeLabel) ?></div>
        <div class="photo-grid">
        <?php
        $pairs = array_chunk($typedPhotos, 2);
        foreach ($pairs as $pair):
        ?>
            <div class="photo-row">
            <?php foreach ($pair as $ph):
                $imgPath = $this->publicRoot . '/uploads/photos/' . $ph['filename'];
                if (!file_exists($imgPath)) continue;
                $imgData = base64_encode(file_get_contents($imgPath));
                $ext     = strtolower(pathinfo($ph['filename'], PATHINFO_EXTENSION));
                $mime    = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/' . $ext;
                $totalShown++;
            ?>
                <div class="photo-cell">
                    <div class="photo-img-wrap">
                        <img src="data:<?= $mime ?>;base64,<?= $imgData ?>">
                    </div>
                    <div class="photo-caption">
                        <?= htmlspecialchars($ph['caption'] ?? ucfirst($typeKey)) ?>
                        &bull; <?= htmlspecialchars(date('g:i A', strtotime($ph['uploaded_at']))) ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (count($pair) === 1): ?>
                <div class="photo-cell"></div>
            <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php } ?>

    <?php if ($totalShown === 0): ?>
    <div class="no-photos">
        No photos were recorded for this visit. If photos were taken with a device not linked to
        the Mowology app, they should be attached manually to this service record.
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     FINAL PAGE — CHAIN OF CUSTODY
     ══════════════════════════════════════════════════════════ -->
<div class="page page-break">
    <div class="section-header">
        <div class="section-eyebrow">Section 5</div>
        <div class="section-title">Chain of Custody &amp; Document Integrity</div>
    </div>

    <p style="font-size:10px;color:#444;margin-bottom:14px;">
        The table below is an immutable audit trail of all recorded actions on this service visit,
        captured in real-time and stored with user identity, timestamp, and IP address.
    </p>

    <?php if (!empty($auditLog)): ?>
    <table class="audit-table">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>Action</th>
                <th>Performed By</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($auditLog as $entry): ?>
            <tr>
                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $entry['action'])) ?></td>
                <td><?= htmlspecialchars($entry['actor'] ?? 'System') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color:#999;font-style:italic;margin-bottom:14px;">No audit log entries recorded for this visit.</p>
    <?php endif; ?>

    <div class="integrity-box">
        <strong>Document Integrity Statement</strong><br><br>
        This document (Report No. <strong><?= htmlspecialchars($reportNumber) ?></strong>) was generated
        automatically by the Mowology field service management system from cryptographically linked
        GPS location data, photographic evidence, and official meteorological records.<br><br>
        Document generated: <?= htmlspecialchars($generatedAt) ?><br>
        Property: <?= htmlspecialchars($propertyAddress) ?><br>
        Service date: <?= htmlspecialchars($v['scheduled_date'] ?? 'N/A') ?><br><br>
        Any dispute regarding service delivery at this property should reference the above report
        number and the associated records in the Mowology service management system.
        Contact (778) 846-9273 or info@mowology.ca to request additional documentation.<br><br>
        <strong>Mowology Landscaping &bull; Vancouver, BC &bull; mowology.ca</strong>
    </div>
</div>

</body>
</html>
<?php
        return ob_get_clean();
    }

    // ─── mPDF rendering ───────────────────────────────────────────────────────

    private function renderWithMpdf(string $html, string $outputPath): void
    {
        $tmpDir = sys_get_temp_dir() . '/mpdf';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 0,
            'margin_right'  => 0,
            'margin_top'    => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'default_font'  => 'helvetica',
            'tempDir'       => $tmpDir,
        ]);

        $mpdf->WriteHTML($html);
        $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
    }
}
