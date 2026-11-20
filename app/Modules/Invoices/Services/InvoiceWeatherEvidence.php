<?php
/**
 * InvoiceWeatherEvidence — the "Weather Authorization" panel on a salt/snow invoice PDF.
 *
 * Two grades of evidence, never mixed up on the page:
 *   1. official      — the Environment Canada decision the cron stored (salt_weather_decisions,
 *                      keyed by the invoice's visit): low, threshold, condition, station, capture time.
 *   2. supplementary — only when there is NO stored decision: a photo/screenshot of a broadcast
 *                      forecast, linked as invoice_attachments.document_type = 'weather_photo'
 *                      (pdf_path = image path relative to STORAGE_ROOT, label = "Source, captured …").
 *                      It is shown with its own heading and says plainly that it is a
 *                      supplementary record, not the automated record.
 * Nothing found → null, and the invoice renders exactly as before. Never throws.
 */
class InvoiceWeatherEvidence
{
    /** @return array{grade:string, ...}|null */
    public static function forInvoice(PDO $db, array $invoice): ?array
    {
        $visitId   = (int)($invoice['visit_id'] ?? 0);
        $invoiceId = (int)($invoice['id'] ?? 0);
        try {
            if ($visitId > 0) {
                $s = $db->prepare("SELECT id AS record_id, trigger_date, overnight_low_c, trigger_threshold_c, weather_condition,
                                          data_source, source_station, source_url, decision_at,
                                          (raw_api_response IS NOT NULL AND raw_api_response <> '') AS has_raw
                                   FROM salt_weather_decisions WHERE visit_id = ?
                                   ORDER BY decision_at DESC LIMIT 1");
                $s->execute([$visitId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['grade'] = 'official';
                    return $row;
                }
            }
            if ($invoiceId > 0) {
                $s = $db->prepare("SELECT pdf_path, label FROM invoice_attachments
                                   WHERE invoice_id = ? AND document_type = 'weather_photo'
                                   ORDER BY id DESC LIMIT 1");
                $s->execute([$invoiceId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $file = null;
                    if (class_exists('PdfStore')) {
                        $file = PdfStore::absoluteFromStored((string)$row['pdf_path']);
                    }
                    if ($file !== null && is_file($file)) {
                        return ['grade' => 'supplementary', 'file' => $file, 'label' => (string)($row['label'] ?? '')];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('InvoiceWeatherEvidence: ' . $e->getMessage());
        }
        return null;
    }

    /** The panel as mPDF-safe HTML (tables, no flex). Empty string when there is no evidence. */
    public static function html(?array $ev, string $companyName = 'Mowology Landscaping'): string
    {
        if (!$ev) {
            return '';
        }
        $e = function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $css = 'margin-top:8px; border:1.2pt solid #1e3a5f; font-size:9.5pt; color:#1f2937; page-break-inside:avoid;';
        $head = function (string $title, string $tag) use ($e): string {
            return '<tr><td style="background:#1e3a5f; color:#ffffff; padding:7px 12px; font-weight:bold; letter-spacing:1px; font-size:8.5pt;">'
                 . $e($title) . '</td><td style="background:#1e3a5f; color:#cbd5e1; padding:7px 12px; text-align:right; font-size:7.5pt;">'
                 . $e($tag) . '</td></tr>';
        };

        if ($ev['grade'] === 'official') {
            $date  = !empty($ev['trigger_date']) ? date('l, F j, Y', strtotime((string)$ev['trigger_date'])) : '';
            $low   = rtrim(rtrim(number_format((float)$ev['overnight_low_c'], 1), '0'), '.');
            $thr   = rtrim(rtrim(number_format((float)$ev['trigger_threshold_c'], 1), '0'), '.');
            $met   = (float)$ev['overnight_low_c'] <= (float)$ev['trigger_threshold_c'];
            $cap   = '';
            if (!empty($ev['decision_at'])) {
                // decision_at is stored in server time (America/Vancouver), not UTC
                $dt  = new DateTime((string)$ev['decision_at'], new DateTimeZone('America/Vancouver'));
                $cap = $dt->format('M j, Y \a\t g:i A') . ' ' . $dt->format('T');
            }
            $ref = !empty($ev['record_id']) ? 'WX-' . str_pad((string)(int)$ev['record_id'], 5, '0', STR_PAD_LEFT) : '';
            // Reason first, in one sentence; the evidence to check it sits together underneath.
            $reason = $met
                ? 'The forecast low of ' . $low . '°C met the service threshold of ' . $thr . '°C or below.'
                : 'The forecast low of ' . $low . '°C did not meet the service threshold of ' . $thr . '°C or below.';
            $evidence = array_filter([
                $ev['data_source'] ?: 'Environment Canada',
                !empty($ev['source_station']) ? 'Station: ' . $ev['source_station'] : '',
                !empty($ev['weather_condition']) ? 'Condition: ' . $ev['weather_condition'] : '',
                $cap !== '' ? 'Captured automatically ' . $cap : '',
                $ref !== '' ? 'Record ' . $ref : '',
                !empty($ev['has_raw']) ? 'Full unaltered forecast data kept on file' : '',
            ], fn($x) => $x !== '');
            $h  = '<table width="100%" cellpadding="0" cellspacing="0" style="' . $css . '">';
            $h .= $head('WEATHER AUTHORIZATION', 'Official forecast record');
            $h .= '<tr><td colspan="2" style="padding:8px 12px 3px 12px;">'
                . '<span style="font-size:7pt; color:#64748b; letter-spacing:1px;">NIGHT OF</span> <span style="font-size:8pt; color:#475569;">' . $e($date) . '</span><br>'
                . '<span style="font-size:10.5pt; font-weight:bold; color:#1e3a5f;">' . $e($reason) . '</span></td></tr>';
            $h .= '<tr><td colspan="2" style="padding:3px 12px 8px 12px; font-size:8.5pt; color:#475569;">'
                . $e(implode(' · ', $evidence)) . '</td></tr></table>';
            return $h;
        }

        // supplementary — a broadcast forecast photo; labelled for what it is
        $label = trim((string)($ev['label'] ?? ''));
        $h  = '<table width="100%" cellpadding="0" cellspacing="0" style="' . $css . '">';
        $h .= $head('WEATHER REFERENCE', 'Supplementary record');
        $h .= '<tr><td colspan="2" style="padding:10px 12px; text-align:center;">'
            . '<img src="' . $e($ev['file']) . '" style="max-width:100%; max-height:300pt; border:0.4pt solid #cbd5e1;">'
            . ($label !== '' ? '<br><span style="font-size:8pt; color:#475569;">' . $e($label) . '</span>' : '')
            . '</td></tr>';
        $h .= '<tr><td colspan="2" style="padding:6px 12px 9px 12px; font-size:8pt; color:#64748b; border-top:0.4pt solid #cbd5e1;">'
            . 'Photograph of a broadcast weather forecast kept as a supplementary record of conditions for this service date. '
            . 'It is not the automated Environment Canada record.'
            . '</td></tr></table>';
        return $h;
    }
}
