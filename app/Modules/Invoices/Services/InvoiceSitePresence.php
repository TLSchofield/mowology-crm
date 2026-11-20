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

    /**
     * Street map of the property with the readings joined in order, drawn from OpenStreetMap tiles
     * with GD (Google's key is refused for static maps on this host — 403). Tiles are cached; the
     * finished picture is cached by content. Null on any failure, and the panel renders without it.
     */
    public static function mapFile(array $p): ?string
    {
        try {
            $root = defined('STORAGE_ROOT') ? STORAGE_ROOT : '';
            if ($root === '' || empty($p['points']) || !function_exists('imagecreatetruecolor')) {
                return null;
            }
            $dir = rtrim($root, '/') . '/invoice-maps';
            if (!is_dir($dir . '/tiles') && !@mkdir($dir . '/tiles', 0755, true)) {
                return null;
            }
            $coords = array_map(function ($r) { return round((float)$r['lat'], 6) . ',' . round((float)$r['lng'], 6); }, $p['points']);
            $file = $dir . '/ping2_' . substr(sha1(implode('|', $coords) . $p['prop_lat'] . $p['prop_lng']), 0, 16) . '.png';
            if (is_file($file) && filesize($file) > 1000) {
                return $file;
            }
            $z = 18; $W = 640; $H = 400; $n = 2 ** $z;
            $px = function (float $lat, float $lng) use ($n): array {
                $x = ($lng + 180.0) / 360.0 * $n;
                $y = (1 - asinh(tan(deg2rad($lat))) / M_PI) / 2 * $n;
                return [$x * 256, $y * 256];
            };
            [$cx, $cy] = $px((float)$p['prop_lat'], (float)$p['prop_lng']);
            $left = $cx - $W / 2; $top = $cy - $H / 2;
            $img = imagecreatetruecolor($W, $H);
            imagefill($img, 0, 0, imagecolorallocate($img, 226, 232, 240));
            $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: Mowology-CRM/1.0 (invoice site-presence maps)\r\n"]]);
            $got = 0;
            for ($tx = (int)floor($left / 256); $tx <= (int)floor(($left + $W) / 256); $tx++) {
                for ($ty = (int)floor($top / 256); $ty <= (int)floor(($top + $H) / 256); $ty++) {
                    $tf = $dir . "/tiles/{$z}_{$tx}_{$ty}.png";
                    if (!is_file($tf)) {
                        $data = @file_get_contents("https://tile.openstreetmap.org/{$z}/{$tx}/{$ty}.png", false, $ctx);
                        if ($data === false || substr($data, 1, 3) !== 'PNG') { continue; }
                        file_put_contents($tf, $data);
                    }
                    $tile = @imagecreatefrompng($tf);
                    if ($tile) {
                        imagecopy($img, $tile, (int)round($tx * 256 - $left), (int)round($ty * 256 - $top), 0, 0, 256, 256);
                        imagedestroy($tile);
                        $got++;
                    }
                }
            }
            if ($got === 0) {
                imagedestroy($img);
                return null;
            }
            imageantialias($img, true);
            $green = imagecolorallocate($img, 45, 134, 89);
            $lime  = imagecolorallocate($img, 127, 216, 88);
            $white = imagecolorallocate($img, 255, 255, 255);
            $dark  = imagecolorallocate($img, 26, 95, 74);
            $pts = array_map(function ($r) use ($px, $left, $top) {
                [$x, $y] = $px((float)$r['lat'], (float)$r['lng']);
                return [(int)round($x - $left), (int)round($y - $top)];
            }, $p['points']);
            imagesetthickness($img, 4);
            for ($i = 1; $i < count($pts); $i++) {
                imageline($img, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $green);
            }
            imagesetthickness($img, 1);
            foreach ($pts as $i => [$x, $y]) {
                imagefilledellipse($img, $x, $y, 16, 16, $white);
                imagefilledellipse($img, $x, $y, 11, 11, $lime);
            }
            imagefilledellipse($img, $pts[0][0], $pts[0][1], 26, 26, $white);
            imagefilledellipse($img, $pts[0][0], $pts[0][1], 20, 20, $dark);
            imagepng($img, $file);
            imagedestroy($img);
            return is_file($file) ? $file : null;
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
