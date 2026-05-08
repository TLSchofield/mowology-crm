<?php
/**
 * TripReportPdf — Generates a commercial vehicle daily trip report PDF.
 *
 * Compliant with BC MVA 37.18.01b) Cycle 1 (within 160 km).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
 *   require_once APP_ROOT . '/Modules/Driver/TripReportPdf.php';
 *   $result = TripReportPdf::generate($reportId, $db);
 *   // $result = ['success' => bool, 'path' => '...', 'filename' => '...']
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/Core/paths.php')) {
            require_once $__dir . '/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

class TripReportPdf
{
    /**
     * Generate and save a trip report PDF for the given report ID.
     * Updates vehicle_trip_reports.pdf_path and pdf_generated_at.
     *
     * @param  int   $reportId
     * @param  PDO   $db
     * @return array{success: bool, path?: string, filename?: string, error?: string}
     */
    public static function generate(int $reportId, PDO $db): array
    {
        try {
            // Load PDF bootstrap (mPDF autoloader)
            require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';

            // Fetch report + driver info
            $stmt = $db->prepare("
                SELECT r.*, u.full_name as driver_name, u.phone as driver_phone
                FROM vehicle_trip_reports r
                JOIN users u ON r.driver_id = u.id
                WHERE r.id = ?
            ");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$report) {
                return ['success' => false, 'error' => 'Report not found'];
            }

            // Render HTML template
            $templatePath = CRM_ROOT . '/templates/pdf/trip-report.php';
            $html = self::renderTemplate($templatePath, ['report' => $report]);

            // Create mPDF instance
            $tmpDir = sys_get_temp_dir() . '/mpdf';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0755, true);
            }

            $mpdf = new \Mpdf\Mpdf([
                'mode'            => 'utf-8',
                'format'          => 'Letter',
                'margin_left'     => 15,
                'margin_right'    => 15,
                'margin_top'      => 15,
                'margin_bottom'   => 20,
                'margin_header'   => 10,
                'margin_footer'   => 10,
                'default_font'    => 'helvetica',
                'tempDir'         => $tmpDir,
            ]);

            $mpdf->WriteHTML($html);

            // Save to storage
            $dir = STORAGE_ROOT . '/pdfs/trip-reports';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $date     = $report['report_date'];
            $driverId = $report['driver_id'];
            $filename = 'trip_' . $driverId . '_' . $date . '.pdf';
            $fullPath = $dir . '/' . $filename;
            $relPath  = 'app/Storage/pdfs/trip-reports/' . $filename;

            $mpdf->Output($fullPath, \Mpdf\Output\Destination::FILE);

            // Update DB record
            $upd = $db->prepare("
                UPDATE vehicle_trip_reports
                SET pdf_path = ?, pdf_generated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$relPath, $reportId]);

            return [
                'success'  => true,
                'path'     => $fullPath,
                'filename' => $filename,
            ];

        } catch (Throwable $e) {
            error_log('TripReportPdf::generate error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Render a PHP template to HTML string. */
    private static function renderTemplate(string $templatePath, array $data): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
}
