<?php
/**
 * InvoiceSitePresence — the "Site Presence" panel beside the weather panel on a salt/snow invoice PDF.
 *
 * Counts the crew's real (non-mock) location readings within ~200 m of the property between the
 * visit's start and completion. It states only what was recorded: how many readings, first and last
 * time, and the nearest-reading accuracy. No names, no route, no claim about time on site beyond
 * the first and last reading. No readings, no property location or no visit window → null and the
 * invoice renders as before. Never throws.
 */
class InvoiceSitePresence
{
    /** @return array{count:int, first:string, last:string, address:string, best_accuracy_m:?int}|null */
    public static function forInvoice(PDO $db, array $invoice): ?array
    {
        $visitId = (int)($invoice['visit_id'] ?? 0);
        if ($visitId <= 0) {
            return null;
        }
        try {
            $s = $db->prepare("SELECT jv.started_at, jv.completed_at, p.latitude, p.longitude, p.address
                               FROM job_visits jv
                               LEFT JOIN calendar_stops cs ON cs.id = jv.stop_id
                               LEFT JOIN properties p ON p.id = cs.property_id
                               WHERE jv.id = ?");
            $s->execute([$visitId]);
            $v = $s->fetch(PDO::FETCH_ASSOC);
            if (!$v || empty($v['started_at']) || empty($v['completed_at'])
                || $v['latitude'] === null || $v['longitude'] === null) {
                return null;
            }
            $lat = (float)$v['latitude'];
            $lng = (float)$v['longitude'];
            $q = $db->prepare("SELECT COUNT(*) AS n, MIN(timestamp) AS first_ts, MAX(timestamp) AS last_ts,
                                      MIN(accuracy_meters) AS best_acc
                               FROM crew_location_history
                               WHERE is_mock = 0
                                 AND timestamp >= ? AND timestamp <= ?
                                 AND latitude  BETWEEN ? AND ?
                                 AND longitude BETWEEN ? AND ?");
            $q->execute([$v['started_at'], $v['completed_at'],
                         $lat - 0.0018, $lat + 0.0018, $lng - 0.0028, $lng + 0.0028]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int)$r['n'] <= 0) {
                return null;
            }
            return [
                'count'           => (int)$r['n'],
                'first'           => (string)$r['first_ts'],
                'last'            => (string)$r['last_ts'],
                'address'         => (string)($v['address'] ?? ''),
                'best_accuracy_m' => $r['best_acc'] !== null ? (int)round((float)$r['best_acc']) : null,
            ];
        } catch (Throwable $e) {
            error_log('InvoiceSitePresence: ' . $e->getMessage());
            return null;
        }
    }

    public static function html(?array $p, string $companyName = 'Mowology Landscaping'): string
    {
        if (!$p) {
            return '';
        }
        $e = function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $fmt = function (string $ts): string {
            $dt = new DateTime($ts, new DateTimeZone('America/Vancouver'));
            return $dt->format('g:i:s A') . ' ' . $dt->format('T');
        };
        $day = (new DateTime($p['first'], new DateTimeZone('America/Vancouver')))->format('l, F j, Y');
        $rows = [
            ['Location readings', $p['count'] . ' within 200 m of the property'],
            ['First reading', $fmt($p['first'])],
            ['Last reading', $fmt($p['last'])],
        ];
        if ($p['best_accuracy_m'] !== null) {
            $rows[] = ['Best accuracy', '± ' . $p['best_accuracy_m'] . ' m'];
        }
        $rows[] = ['Source', 'Crew mobile device'];

        $h  = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px; border:1.2pt solid #1e3a5f; font-size:9.5pt; color:#1f2937; page-break-inside:avoid;">';
        $h .= '<tr><td style="background:#1e3a5f; color:#ffffff; padding:7px 12px; font-weight:bold; letter-spacing:1px; font-size:8.5pt;">SITE PRESENCE</td>'
            . '<td style="background:#1e3a5f; color:#cbd5e1; padding:7px 12px; text-align:right; font-size:7.5pt;">GPS record</td></tr>';
        $h .= '<tr><td colspan="2" style="padding:10px 12px 4px 12px;"><span style="font-size:7.5pt; color:#64748b; letter-spacing:1px;">SERVICE LOCATION</span><br>'
            . '<span style="font-size:11pt; font-weight:bold; color:#1e3a5f;">' . $e($p['address'] ?: 'Service property') . '</span><br>'
            . '<span style="font-size:8pt; color:#64748b;">' . $e($day) . '</span></td></tr>';
        $h .= '<tr><td colspan="2" style="padding:2px 12px 8px 12px;"><table width="100%" cellpadding="0" cellspacing="0">';
        foreach ($rows as $i => $r) {
            $bg = $i % 2 ? '#f8fafc' : '#ffffff';
            $h .= '<tr><td width="42%" style="background:' . $bg . '; padding:3px 8px; color:#64748b; border-bottom:0.4pt solid #e2e8f0;">' . $e($r[0])
                . '</td><td style="background:' . $bg . '; padding:3px 8px; font-weight:bold; border-bottom:0.4pt solid #e2e8f0;">' . $e($r[1]) . '</td></tr>';
        }
        $h .= '</table></td></tr>';
        $h .= '<tr><td colspan="2" style="padding:6px 12px 9px 12px; font-size:8pt; color:#64748b; border-top:0.4pt solid #cbd5e1;">'
            . 'Location readings recorded automatically by ' . $e($companyName) . ' while the visit was open. Mock locations are excluded.'
            . '</td></tr></table>';
        return $h;
    }

    /** Weather and site-presence panels side by side; one alone fills the width; neither → ''. */
    public static function sideBySide(string $weatherHtml, string $presenceHtml): string
    {
        if ($weatherHtml === '' || $presenceHtml === '') {
            return $weatherHtml . $presenceHtml;
        }
        return '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
             . '<td width="52%" valign="top" style="padding-right:5px;">' . $weatherHtml . '</td>'
             . '<td width="48%" valign="top" style="padding-left:5px;">' . $presenceHtml . '</td>'
             . '</tr></table>';
    }
}
