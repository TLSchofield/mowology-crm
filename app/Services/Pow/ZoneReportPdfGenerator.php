<?php
declare(strict_types=1);

/**
 * ZoneReportPdfGenerator
 * ───────────────────────
 * Generates a client-facing PDF showing named work zones, time spent in each,
 * and photos auto-attributed to each zone.
 *
 * Transit time is intentionally excluded from the client report (internal KPI only).
 *
 * Output: storage/pdfs/zone-report/visit_{id}.pdf
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Pow/ZoneReportPdfGenerator.php';
 *   $gen = new ZoneReportPdfGenerator($db, PUBLIC_ROOT, SITE_URL);
 *   $result = $gen->generate($visitId);
 */

class ZoneReportPdfGenerator
{
    private PDO    $db;
    private string $publicRoot;
    private string $siteUrl;

    public function __construct(PDO $db, string $publicRoot, string $siteUrl)
    {
        $this->db         = $db;
        $this->publicRoot = rtrim($publicRoot, '/');
        $this->siteUrl    = rtrim($siteUrl, '/');
    }

    /**
     * Generate the zone report PDF for a visit.
     *
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function generate(int $visitId, bool $forceRegen = false): array
    {
        try {
            // Check if mPDF is available
            $mpdfClass = $this->publicRoot . '/vendor/mpdf/mpdf/src/Mpdf.php';
            if (!is_file($mpdfClass)) {
                return ['success' => false, 'error' => 'mPDF not available'];
            }

            // Check existing file
            $outDir  = $this->publicRoot . '/storage/pdfs/zone-report/';
            $outFile = $outDir . 'visit_' . $visitId . '.pdf';

            if (!$forceRegen && is_file($outFile)) {
                return [
                    'success' => true,
                    'path'    => '/storage/pdfs/zone-report/visit_' . $visitId . '.pdf',
                    'cached'  => true,
                ];
            }

            if (!is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }

            // Load visit data
            $data = $this->loadVisitData($visitId);
            if (!$data) {
                return ['success' => false, 'error' => 'Visit not found'];
            }

            // Build HTML
            $html = $this->buildHtml($data);

            // Generate PDF via mPDF
            require_once $mpdfClass;
            $mpdf = new \Mpdf\Mpdf([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_left'   => 15,
                'margin_right'  => 15,
                'margin_top'    => 20,
                'margin_bottom' => 20,
                'tempDir'       => $this->publicRoot . '/storage/tmp',
            ]);
            $mpdf->SetTitle('Zone Report — ' . ($data['visit']['property_address'] ?? 'Visit #' . $visitId));
            $mpdf->WriteHTML($html);
            $mpdf->Output($outFile, 'F');

            return [
                'success' => true,
                'path'    => '/storage/pdfs/zone-report/visit_' . $visitId . '.pdf',
            ];

        } catch (Throwable $e) {
            error_log('[ZoneReportPdfGenerator] Error for visit ' . $visitId . ': ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function loadVisitData(int $visitId): ?array
    {
        // Visit + property + contact
        $stmt = $this->db->prepare("
            SELECT
                jv.id, jv.scheduled_date, jv.completed_at,
                jp.title AS plan_title, jp.service_type, jp.property_id,
                pr.address AS property_address,
                c.first_name, c.last_name, c.company_name
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties pr ON pr.id = jp.property_id
            LEFT JOIN contacts c ON c.id = jp.contact_id
            WHERE jv.id = ?
            LIMIT 1
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit) return null;

        // Zone sessions
        $zStmt = $this->db->prepare("
            SELECT vzs.zone_id, vzs.zone_label, vzs.in_seconds, vzs.entry_count,
                   jp2.title AS plan_title
            FROM visit_zone_sessions vzs
            LEFT JOIN job_plans jp2 ON jp2.id = vzs.plan_id
            WHERE vzs.visit_id = ?
            ORDER BY vzs.in_seconds DESC
        ");
        $zStmt->execute([$visitId]);
        $zones = $zStmt->fetchAll(PDO::FETCH_ASSOC);

        // Photos per zone
        $pStmt = $this->db->prepare("
            SELECT id, work_zone_id, photo_type, thumb_path, view_path, caption
            FROM visit_photos
            WHERE visit_id = ? AND deleted_at IS NULL
            ORDER BY work_zone_id ASC, sort_order ASC, uploaded_at ASC
        ");
        $pStmt->execute([$visitId]);
        $photos = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // Index photos by zone_id
        $photosByZone = ['__unzoned__' => []];
        foreach ($photos as $ph) {
            $key = $ph['work_zone_id'] !== null ? (int)$ph['work_zone_id'] : '__unzoned__';
            $photosByZone[$key][] = $ph;
        }

        return compact('visit', 'zones', 'photosByZone');
    }

    private function buildHtml(array $data): string
    {
        $visit        = $data['visit'];
        $zones        = $data['zones'];
        $photosByZone = $data['photosByZone'];

        $clientName = trim(($visit['first_name'] ?? '') . ' ' . ($visit['last_name'] ?? ''));
        if (!$clientName && $visit['company_name']) $clientName = $visit['company_name'];
        $clientName = htmlspecialchars($clientName ?: 'Client');

        $date     = $visit['scheduled_date'] ? date('F j, Y', strtotime($visit['scheduled_date'])) : '—';
        $address  = htmlspecialchars($visit['property_address'] ?? '');

        $totalSec    = array_sum(array_column($zones, 'in_seconds'));

        $green  = '#2D8659';
        $forest = '#0D3B2E';
        $light  = '#E8F3F0';

        $zonesHtml = '';
        foreach ($zones as $z) {
            $sec   = (int)$z['in_seconds'];
            $pct   = $totalSec > 0 ? round($sec / $totalSec * 100) : 0;
            $h     = intdiv($sec, 3600);
            $m     = intdiv($sec % 3600, 60);
            $label = htmlspecialchars($z['zone_label'] ?? 'Work Zone');
            $zId   = (int)$z['zone_id'];

            $photosHtml = '';
            $zonePhotos = $photosByZone[$zId] ?? [];
            if (!empty($zonePhotos)) {
                $photosHtml .= '<div style="margin-top:6px;">';
                foreach (array_slice($zonePhotos, 0, 4) as $ph) {
                    $imgPath = $this->publicRoot . ($ph['view_path'] ?? $ph['thumb_path'] ?? '');
                    if (!is_file($imgPath)) continue;
                    $b64  = base64_encode(file_get_contents($imgPath));
                    $mime = str_ends_with($imgPath, '.webp') ? 'image/webp' : 'image/jpeg';
                    $photosHtml .= '<img src="data:' . $mime . ';base64,' . $b64 . '" '
                        . 'style="width:120px;height:80px;object-fit:cover;border-radius:4px;margin:2px;" />';
                }
                $photosHtml .= '</div>';
            }

            $zonesHtml .= '
            <tr>
                <td style="padding:10px 8px;border-bottom:1px solid #e8f3f0;">
                    <strong style="color:' . $forest . ';">' . $label . '</strong>
                    ' . $photosHtml . '
                </td>
                <td style="padding:10px 8px;border-bottom:1px solid #e8f3f0;text-align:right;white-space:nowrap;">
                    <strong>' . $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm</strong>
                    <br><small style="color:#888;">' . $pct . '% of visit</small>
                </td>
            </tr>';
        }

        $totalH = intdiv($totalSec, 3600);
        $totalM = intdiv($totalSec % 3600, 60);

        // Unzoned photos (taken outside all zones)
        $unzonedHtml = '';
        $unzonedPhotos = $photosByZone['__unzoned__'] ?? [];
        if (!empty($unzonedPhotos)) {
            $unzonedHtml = '<p style="margin-top:16px;font-size:13px;color:#555;"><strong>Additional Photos</strong></p>';
            foreach (array_slice($unzonedPhotos, 0, 6) as $ph) {
                $imgPath = $this->publicRoot . ($ph['view_path'] ?? $ph['thumb_path'] ?? '');
                if (!is_file($imgPath)) continue;
                $b64  = base64_encode(file_get_contents($imgPath));
                $mime = str_ends_with($imgPath, '.webp') ? 'image/webp' : 'image/jpeg';
                $unzonedHtml .= '<img src="data:' . $mime . ';base64,' . $b64 . '" '
                    . 'style="width:140px;height:90px;object-fit:cover;border-radius:4px;margin:3px;" />';
            }
        }

        return '
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #333; margin: 0; padding: 0; }
  h1   { font-size: 20px; color: ' . $forest . '; margin: 0 0 4px; }
  h2   { font-size: 15px; color: ' . $green . '; margin: 16px 0 8px; }
  table { width: 100%; border-collapse: collapse; }
  .header-bar { background: ' . $forest . '; color: #fff; padding: 16px 20px; border-radius: 6px; margin-bottom: 20px; }
  .meta { color: #ccc; font-size: 12px; margin-top: 4px; }
  .total-row td { background: ' . $light . '; font-weight: bold; padding: 10px 8px; border-radius: 0 0 6px 6px; }
</style>
</head>
<body>

<div class="header-bar">
  <h1>Work Zone Report</h1>
  <div class="meta">' . $date . ' &nbsp;|&nbsp; ' . $address . '</div>
</div>

<table>
  <tr>
    <td><strong>Client:</strong> ' . $clientName . '</td>
    <td style="text-align:right;"><strong>Service:</strong> ' . htmlspecialchars($visit['service_type'] ?? '') . '</td>
  </tr>
</table>

<h2>Time Spent by Zone</h2>

<table style="border:1px solid #e8f3f0;border-radius:6px;">
  <thead>
    <tr style="background:' . $light . ';">
      <th style="padding:8px;text-align:left;">Zone</th>
      <th style="padding:8px;text-align:right;">Time</th>
    </tr>
  </thead>
  <tbody>
    ' . $zonesHtml . '
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td>Total</td>
      <td style="text-align:right;">' . $totalH . 'h ' . str_pad((string)$totalM, 2, '0', STR_PAD_LEFT) . 'm</td>
    </tr>
  </tfoot>
</table>

' . $unzonedHtml . '

<p style="margin-top:24px;font-size:11px;color:#aaa;text-align:center;">
  Generated by Mowology &mdash; mowology.ca &mdash; (778) 846-9273
</p>

</body>
</html>';
    }
}
