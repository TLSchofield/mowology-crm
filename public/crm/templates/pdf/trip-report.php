<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 10pt;
        color: #1a1a1a;
        line-height: 1.4;
    }

    /* ── Header ── */
    .doc-header {
        display: table;
        width: 100%;
        border-bottom: 3px solid #2D8659;
        padding-bottom: 10px;
        margin-bottom: 12px;
    }
    .doc-header-left  { display: table-cell; vertical-align: middle; width: 60%; }
    .doc-header-right { display: table-cell; vertical-align: middle; width: 40%; text-align: right; }

    .company-name { font-size: 18pt; font-weight: bold; color: #2D8659; letter-spacing: -0.5px; }
    .doc-title    { font-size: 13pt; font-weight: bold; color: #1A5F4A; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; }
    .doc-subtitle { font-size: 9pt; color: #555; margin-top: 2px; }

    .meta-table { border-collapse: collapse; width: 100%; }
    .meta-table td { padding: 2px 4px; font-size: 9pt; }
    .meta-label { font-weight: bold; color: #2D8659; white-space: nowrap; }

    /* ── Legal Box ── */
    .legal-box {
        background: #f5f5f5;
        border: 1px solid #ccc;
        border-left: 4px solid #2D8659;
        padding: 8px 10px;
        font-size: 8pt;
        color: #444;
        line-height: 1.5;
        margin-bottom: 14px;
    }
    .legal-title { font-weight: bold; font-size: 9pt; color: #1A5F4A; margin-bottom: 4px; }

    /* ── Section headers ── */
    .section-header {
        background: #1A5F4A;
        color: white;
        font-size: 10pt;
        font-weight: bold;
        padding: 5px 10px;
        margin-top: 12px;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* ── Checklist ── */
    .checklist-table { width: 100%; border-collapse: collapse; }
    .checklist-table td { padding: 4px 6px; font-size: 9.5pt; border-bottom: 1px solid #eee; }
    .chk-box { width: 20px; text-align: center; font-size: 13pt; color: #2D8659; }
    .chk-box.fail { color: #cc3333; }
    .chk-label { color: #222; }

    /* ── Two-column layout ── */
    .two-col { display: table; width: 100%; border-collapse: collapse; }
    .col-left  { display: table-cell; width: 50%; padding-right: 8px; vertical-align: top; }
    .col-right { display: table-cell; width: 50%; padding-left: 8px;  vertical-align: top; }

    /* ── Fields ── */
    .field-row { margin-bottom: 8px; }
    .field-label { font-size: 8pt; font-weight: bold; color: #555; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
    .field-value {
        border-bottom: 1px solid #aaa;
        min-height: 18px;
        padding: 2px 4px;
        font-size: 10pt;
        color: #111;
    }
    .field-value.empty { color: #aaa; font-style: italic; font-size: 9pt; }
    .field-value.multiline {
        border: 1px solid #aaa;
        padding: 4px 6px;
        min-height: 40px;
        font-size: 9.5pt;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    /* ── HOS Table ── */
    .hos-table { width: 100%; border-collapse: collapse; }
    .hos-table th {
        background: #E8F3F0;
        color: #1A5F4A;
        font-size: 8.5pt;
        font-weight: bold;
        padding: 5px 8px;
        border: 1px solid #c5ddd7;
        text-align: left;
    }
    .hos-table td {
        border: 1px solid #c5ddd7;
        padding: 5px 8px;
        font-size: 9.5pt;
        min-height: 22px;
    }

    /* ── Acknowledgment ── */
    .ack-box {
        border: 2px solid #2D8659;
        padding: 8px 10px;
        margin-top: 10px;
        background: #f9fffe;
    }
    .ack-text { font-size: 9pt; color: #333; line-height: 1.5; margin-bottom: 6px; }
    .ack-status { font-size: 11pt; font-weight: bold; }
    .ack-status.safe    { color: #2D8659; }
    .ack-status.not-safe { color: #cc3333; }

    /* ── Signature Block ── */
    .sig-block { display: table; width: 100%; margin-top: 12px; }
    .sig-col { display: table-cell; width: 50%; padding-right: 12px; vertical-align: bottom; }
    .sig-col:last-child { padding-right: 0; padding-left: 12px; }
    .sig-line { border-bottom: 1.5px solid #333; margin-bottom: 3px; height: 25px; }
    .sig-caption { font-size: 8pt; color: #555; }

    /* ── Footer ── */
    .doc-footer {
        border-top: 1px solid #ccc;
        padding-top: 6px;
        margin-top: 14px;
        font-size: 8pt;
        color: #888;
        text-align: center;
    }

    .status-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 8pt;
        font-weight: bold;
        text-transform: uppercase;
    }
    .badge-complete { background: #E8F3F0; color: #1A5F4A; border: 1px solid #2D8659; }
    .badge-pending  { background: #FFF4E6; color: #c45d00; border: 1px solid #e87d04; }
</style>
</head>
<body>

<?php
// Helper
function r($v, $fallback = '—') {
    $s = trim((string)$v);
    return $s !== '' ? htmlspecialchars($s) : $fallback;
}
function chk($v) {
    return $v ? '<span class="chk-box">&#10003;</span>' : '<span class="chk-box fail">&#9633;</span>';
}
$d = $report;
$preAt  = !empty($d['pre_trip_at'])  ? date('g:i a', strtotime($d['pre_trip_at']))  : null;
$postAt = !empty($d['post_trip_at']) ? date('g:i a', strtotime($d['post_trip_at'])) : null;
$dateFormatted = !empty($d['report_date']) ? date('l, F j, Y', strtotime($d['report_date'])) : '—';
?>

<!-- ══ HEADER ══ -->
<div class="doc-header">
    <div class="doc-header-left">
        <div class="company-name">Mowology</div>
        <div class="doc-title">Commercial Vehicle Trip Report</div>
        <div class="doc-subtitle">BC MVA 37.18.01(b) — Cycle 1 Daily Inspection Record</div>
    </div>
    <div class="doc-header-right">
        <table class="meta-table">
            <tr>
                <td class="meta-label">Date:</td>
                <td><?php echo $dateFormatted; ?></td>
            </tr>
            <tr>
                <td class="meta-label">Vehicle:</td>
                <td><?php echo r($d['vehicle_id']); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Driver:</td>
                <td><?php echo r($d['driver_name']); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Status:</td>
                <td>
                    <?php if ($d['status'] === 'complete'): ?>
                        <span class="status-badge badge-complete">Complete</span>
                    <?php elseif ($d['status'] === 'pre_complete'): ?>
                        <span class="status-badge badge-pending">Pre-Trip Only</span>
                    <?php else: ?>
                        <span class="status-badge badge-pending">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- ══ MVA LEGAL TEXT ══ -->
<div class="legal-box">
    <div class="legal-title">MVA 37.18.01b) — Driver Elected: Cycle 1 — Within 160 Km</div>
    (a) the driver operates or is instructed by the carrier to operate a commercial motor vehicle within a radius of 160 km of the home terminal,
    (b) the driver returns to the home terminal each day to begin a minimum of 8 consecutive hours of off-duty time, and
    (c) the carrier maintains accurate and legible records showing, for each day, the driver's duty status and elected cycle, the hour at which each duty status begins and ends and the total number of hours spent in each status and keeps those records for a minimum period of 6 months after the day on which they were recorded.
</div>

<!-- ══ PRE-TRIP INSPECTION ══ -->
<div class="section-header">Pre-Trip Inspection<?php echo $preAt ? ' &mdash; Completed ' . htmlspecialchars($preAt) : ''; ?></div>

<div class="two-col">
    <div class="col-left">
        <table class="checklist-table">
            <tr><td><?php echo chk($d['chk_leaks']); ?></td><td class="chk-label">Check Beneath Truck for Leaks</td></tr>
            <tr><td><?php echo chk($d['chk_hitch']); ?></td><td class="chk-label">Hitch Secure &amp; Light Plug Attached</td></tr>
            <tr><td><?php echo chk($d['chk_rear_gate']); ?></td><td class="chk-label">Rear Truck Gate Secure</td></tr>
            <tr><td><?php echo chk($d['chk_loads_secure']); ?></td><td class="chk-label">Loads Secure</td></tr>
            <tr><td><?php echo chk($d['chk_trailer_lights']); ?></td><td class="chk-label">Trailer Lights</td></tr>
        </table>
    </div>
    <div class="col-right">
        <table class="checklist-table">
            <tr><td><?php echo chk($d['chk_truck_brakes']); ?></td><td class="chk-label">Truck Brakes</td></tr>
            <tr><td><?php echo chk($d['chk_truck_lights']); ?></td><td class="chk-label">Truck Lights</td></tr>
            <tr><td><?php echo chk($d['chk_mirrors']); ?></td><td class="chk-label">Mirrors</td></tr>
            <tr><td><?php echo chk($d['chk_tire_pressure']); ?></td><td class="chk-label">Tire Pressure</td></tr>
            <tr><td><?php echo chk($d['chk_washer_wipers']); ?></td><td class="chk-label">Washer Fluids &amp; Wipers</td></tr>
        </table>
    </div>
</div>

<!-- Odometer -->
<div class="two-col" style="margin-top:10px;">
    <div class="col-left">
        <div class="field-row">
            <div class="field-label">Odometer — Start of Day (km)</div>
            <div class="field-value"><?php echo r($d['odometer_start'], '&nbsp;'); ?></div>
        </div>
    </div>
    <div class="col-right">
        <div class="field-row">
            <div class="field-label">Odometer — End of Day (km)</div>
            <div class="field-value"><?php echo r($d['odometer_end'], '&nbsp;'); ?></div>
        </div>
    </div>
</div>

<!-- ══ DEFECTS ══ -->
<div class="section-header">Defects &amp; Repairs</div>

<div class="field-row">
    <div class="field-label">Defects / Repairs — MUST Be Fixed Before Being Safe To Drive <small>(Contact Vital Auto Repair &amp; Office immediately)</small></div>
    <div class="field-value multiline"><?php
        $crit = trim((string)($d['defects_critical'] ?? ''));
        $extras = [];
        if (!empty($d['defect_unhitch'])) $extras[] = 'UNHITCH TRAILER';
        if ($crit !== '') echo htmlspecialchars($crit);
        if (!empty($extras)) echo ($crit !== '' ? "\n" : '') . implode(', ', $extras);
        if ($crit === '' && empty($extras)) echo '<span style="color:#aaa;font-style:italic;">None</span>';
    ?></div>
</div>

<div class="field-row" style="margin-top:8px;">
    <div class="field-label">Defects / Repairs — Non-Urgent</div>
    <div class="field-value multiline"><?php
        $nu = trim((string)($d['defects_non_urgent'] ?? ''));
        echo $nu !== '' ? htmlspecialchars($nu) : '<span style="color:#aaa;font-style:italic;">None</span>';
    ?></div>
</div>

<!-- ══ SAFE TO DRIVE ══ -->
<div class="ack-box" style="margin-top:12px;">
    <div class="ack-text">
        <strong>Safe To Drive Declaration</strong> — By checking this box the driver acknowledges that they take full legal responsibility for the truck being safe to drive on Canadian highways.
    </div>
    <div class="ack-status <?php echo $d['safe_to_drive'] ? 'safe' : 'not-safe'; ?>">
        <?php echo $d['safe_to_drive'] ? '&#10003; SAFE TO DRIVE — Confirmed' : '&#9633; Not yet confirmed'; ?>
    </div>
</div>

<!-- ══ POST-TRIP / END OF DAY ══ -->
<div class="section-header">End of Day Remarks<?php echo $postAt ? ' &mdash; Completed ' . htmlspecialchars($postAt) : ''; ?></div>

<div class="field-row">
    <div class="field-label">End of Day Remarks &amp; Defect Report <small>(if none, leave blank)</small></div>
    <div class="field-value multiline"><?php
        $rem = trim((string)($d['end_of_day_remarks'] ?? ''));
        echo $rem !== '' ? htmlspecialchars($rem) : '<span style="color:#aaa;font-style:italic;">None</span>';
    ?></div>
</div>

<!-- ══ HOURS OF SERVICE ══ -->
<div class="section-header">Hours of Service — Bundled to Equal 24 hrs (Midnight to Midnight)</div>

<table class="hos-table">
    <tr>
        <th style="width:40%">Category</th>
        <th>Hours / Description</th>
    </tr>
    <tr>
        <td><strong>On-Duty Driving Time</strong> — Start time to finish</td>
        <td><?php echo r($d['hos_on_duty_driving'], '&nbsp;'); ?></td>
    </tr>
    <tr>
        <td><strong>On-Duty Other</strong></td>
        <td><?php echo r($d['hos_on_duty_other'], '&nbsp;'); ?></td>
    </tr>
    <tr>
        <td><strong>Off-Duty</strong></td>
        <td><?php echo r($d['hos_off_duty'], '&nbsp;'); ?></td>
    </tr>
</table>

<!-- ══ DRIVER SIGNATURE ══ -->
<div class="sig-block" style="margin-top:16px;">
    <div class="sig-col">
        <div class="sig-line"><?php echo !empty($d['driver_name']) ? '<span style="color:#1A5F4A;font-style:italic;font-size:10pt;line-height:25px;padding:0 4px;">' . htmlspecialchars($d['driver_name']) . '</span>' : ''; ?></div>
        <div class="sig-caption">Driver Name</div>
    </div>
    <div class="sig-col">
        <div class="sig-line"></div>
        <div class="sig-caption">Driver Signature</div>
    </div>
</div>

<!-- ══ FOOTER ══ -->
<div class="doc-footer">
    Mowology Landscaping &bull; (778) 846-9273 &bull; Generated: <?php echo date('F j, Y g:i a'); ?>
    &bull; Record retained minimum 6 months per BC MVA 37.18.01b)
</div>

</body>
</html>
