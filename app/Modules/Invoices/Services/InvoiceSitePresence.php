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
            $q = $db->prepare("SELECT latitude AS lat, longitude AS lng, accuracy_meters AS acc, timestamp AS ts
                               FROM crew_location_history
                               WHERE is_mock = 0
                                 AND timestamp >= ? AND timestamp <= ?
                                 AND latitude  BETWEEN ? AND ?
                                 AND longitude BETWEEN ? AND ?
                               ORDER BY timestamp ASC LIMIT 150");
            $q->execute([$v['started_at'], $v['completed_at'],
                         $lat - 0.0018, $lat + 0.0018, $lng - 0.0028, $lng + 0.0028]);
            $pts = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$pts) {
                return null;
            }
            $accs = array_filter(array_map(function ($r) { return $r['acc'] === null ? null : (float)$r['acc']; }, $pts), function ($x) { return $x !== null; });
            return [
                'count'           => count($pts),
                'first'           => (string)$pts[0]['ts'],
                'last'            => (string)$pts[count($pts) - 1]['ts'],
                'address'         => (string)($v['address'] ?? ''),
                'best_accuracy_m' => $accs ? (int)round(min($accs)) : null,
                'points'          => $pts,
                'prop_lat'        => $lat,
                'prop_lng'        => $lng,
            ];
        } catch (Throwable $e) {
            error_log('InvoiceSitePresence: ' . $e->getMessage());
            return null;
        }
    }

    /** Street map of the property with the readings joined in order (cached by content). Null on any failure. */
    public static function mapFile(array $p): ?string
    {
        try {
            $key = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '');
            $root = defined('STORAGE_ROOT') ? STORAGE_ROOT : '';
            if ($key === '' || $root === '' || empty($p['points'])) {
                return null;
            }
            $dir = rtrim($root, '/') . '/invoice-maps';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return null;
            }
            $coords = array_map(function ($r) { return round((float)$r['lat'], 6) . ',' . round((float)$r['lng'], 6); }, $p['points']);
            $file = $dir . '/ping_' . substr(sha1(implode('|', $coords)), 0, 16) . '.png';
            if (is_file($file) && filesize($file) > 1000) {
                return $file;
            }
            $qs = http_build_query([
                'center'  => round((float)$p['prop_lat'], 6) . ',' . round((float)$p['prop_lng'], 6),
                'zoom'    => 19,
                'size'    => '640x400',
                'scale'   => 2,
                'maptype' => 'roadmap',
                'key'     => $key,
            ]);
            if (count($coords) >= 2) {
                $qs .= '&path=' . urlencode('color:0x2D8659ff|weight:4|' . implode('|', $coords));
            }
            $qs .= '&markers=' . urlencode('size:small|color:0x7FD858|' . implode('|', $coords));
            $qs .= '&markers=' . urlencode('size:mid|color:0x1A5F4A|' . $coords[0]);
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => 'User-Agent: Mowology-CRM/1.0'],
                                          'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $img = @file_get_contents('https://maps.googleapis.com/maps/api/staticmap?' . $qs, false, $ctx);
            if ($img === false || substr($img, 1, 3) !== 'PNG') {
                error_log('InvoiceSitePresence: static map failed: ' . substr((string)$img, 0, 200));
                return null;
            }
            file_put_contents($file, $img);
            return $file;
        } catch (Throwable $e) {
            error_log('InvoiceSitePresence map: ' . $e->getMessage());
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
        $mapFile = self::mapFile($p);
        if ($mapFile !== null) {
            $h .= '<tr><td colspan="2" style="padding:0;"><img src="' . $e($mapFile) . '" style="width:100%; display:block;"></td></tr>';
            $h .= '<tr><td colspan="2" style="padding:6px 12px 0 12px;"><span style="background:#0d3b2e; color:#ffffff; font-weight:bold; font-size:9pt; padding:3px 10px;">&nbsp;' . (int)$p['count'] . ' GPS ping' . ((int)$p['count'] === 1 ? '' : 's') . '&nbsp;</span></td></tr>';
        }
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
