<?php
/**
 * Live Crew Map — Real-time employee location tracking on Google Maps
 * Admin/Manager access only.
 * Polls /crm/api/crew-location.php?action=live every 30 seconds.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'manager'])) {
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

$pageTitle = 'Crew Map';
$activePage = 'team';
$extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars(GOOGLE_MAPS_API_KEY, ENT_QUOTES, 'UTF-8') . '&libraries=geometry" defer></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">Crew Map</h1>
        <p class="text-muted mb-0">
            <span id="crewCount">0</span> tracked employee<span id="crewPlural">s</span> &mdash;
            Last updated: <span id="lastUpdate">loading...</span>
        </p>
    </div>
    <div class="d-flex" style="gap: 8px;">
        <a href="/crm/team/index.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="users" style="width:14px;height:14px;"></i> Team
        </a>
    </div>
</div>

<div class="row">
    <!-- Map Column -->
    <div class="col-lg-9 mb-3">
        <div class="card">
            <div class="card-body p-0 mw-crew-map-container">
                <div id="crewMapContainer"></div>

                <!-- Legend -->
                <div class="mw-crew-map-legend">
                    <div class="mw-crew-legend-item">
                        <span class="mw-crew-legend-dot" style="background:#22c55e;"></span> Active (recent)
                    </div>
                    <div class="mw-crew-legend-item">
                        <span class="mw-crew-legend-dot" style="background:#f59e0b;"></span> Stale (&gt;5 min)
                    </div>
                    <div class="mw-crew-legend-item">
                        <span class="mw-crew-legend-dot" style="background:#9ca3af;"></span> Offline
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Crew List Column -->
    <div class="col-lg-3 mb-3">
        <div class="card">
            <div class="card-header" style="background: var(--mw-forest); color:#fff;">
                <h5 class="card-title mb-0" style="color:#fff;">
                    <i data-feather="users" style="width:16px;height:16px;display:inline;"></i> Crew
                </h5>
            </div>
            <div class="card-body p-0" id="crewListContainer" style="max-height: 600px; overflow-y: auto;">
                <div class="text-center text-muted py-4" id="crewListLoading">
                    Loading crew positions...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var gmap = null;
    var crewMarkers = {}; // keyed by user_id
    var REFRESH_MS = 30000;
    var refreshTimer = null;

    // Wait for Google Maps to load
    function waitForMaps(cb) {
        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
            cb();
        } else {
            setTimeout(function() { waitForMaps(cb); }, 200);
        }
    }

    waitForMaps(function() {
        initMap();
        fetchCrew();
        refreshTimer = setInterval(fetchCrew, REFRESH_MS);
    });

    function initMap() {
        gmap = new google.maps.Map(document.getElementById('crewMapContainer'), {
            zoom: 12,
            center: { lat: 49.2827, lng: -123.1207 }, // Vancouver default
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            styles: [
                { elementType: 'geometry', stylers: [{ color: '#f5f5f5' }] },
                { elementType: 'labels.text.stroke', stylers: [{ color: '#ffffff' }] },
                { elementType: 'labels.text.fill', stylers: [{ color: '#616161' }] }
            ]
        });
    }

    function fetchCrew() {
        fetch('/crm/api/crew-location.php?action=live', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) return;
                updateMap(data.crew || []);
                updateList(data.crew || []);
                updateHeader(data.crew || []);
            })
            .catch(function() { /* silent retry next interval */ });
    }

    function updateMap(crew) {
        var activeIds = {};
        var bounds = new google.maps.LatLngBounds();
        var hasPositions = false;

        crew.forEach(function(member) {
            if (!member.lat || !member.lng) return;

            activeIds[member.user_id] = true;
            hasPositions = true;

            var pos = { lat: parseFloat(member.lat), lng: parseFloat(member.lng) };
            bounds.extend(pos);

            var secondsAgo = parseInt(member.seconds_ago) || 0;
            var isClocked = parseInt(member.is_clocked_in) === 1;
            var color = getMarkerColor(secondsAgo, isClocked);
            var initial = (member.full_name || 'U').charAt(0).toUpperCase();

            if (crewMarkers[member.user_id]) {
                // Update existing marker position with smooth animation
                animateMarker(crewMarkers[member.user_id].marker, pos);
                crewMarkers[member.user_id].marker.setIcon(createCrewIcon(color, initial));
            } else {
                // Create new marker
                var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    icon: createCrewIcon(color, initial),
                    title: member.full_name
                });

                var infoWindow = new google.maps.InfoWindow();
                marker.addListener('click', function() {
                    infoWindow.setContent(buildInfoContent(member));
                    infoWindow.open(gmap, marker);
                });

                crewMarkers[member.user_id] = { marker: marker, infoWindow: infoWindow };
            }

            // Update stored data for info windows
            crewMarkers[member.user_id].data = member;
        });

        // Remove markers for crew no longer tracked
        Object.keys(crewMarkers).forEach(function(uid) {
            if (!activeIds[uid]) {
                crewMarkers[uid].marker.setMap(null);
                delete crewMarkers[uid];
            }
        });

        // Fit bounds only on first load
        if (hasPositions && !gmap._hasFitBounds) {
            gmap.fitBounds(bounds);
            if (crew.length === 1) gmap.setZoom(15);
            gmap._hasFitBounds = true;
        }
    }

    function animateMarker(marker, newPos) {
        var start = marker.getPosition();
        if (!start) { marker.setPosition(newPos); return; }

        var startLat = start.lat();
        var startLng = start.lng();
        var endLat = newPos.lat;
        var endLng = newPos.lng;

        // If distance is very small, just set directly
        if (Math.abs(startLat - endLat) < 0.00001 && Math.abs(startLng - endLng) < 0.00001) {
            marker.setPosition(newPos);
            return;
        }

        var steps = 20;
        var step = 0;
        var interval = setInterval(function() {
            step++;
            var t = step / steps;
            marker.setPosition({
                lat: startLat + (endLat - startLat) * t,
                lng: startLng + (endLng - startLng) * t
            });
            if (step >= steps) clearInterval(interval);
        }, 50);
    }

    function getMarkerColor(secondsAgo, isClocked) {
        if (!isClocked) return '#9ca3af'; // gray — offline
        if (secondsAgo > 300) return '#f59e0b'; // yellow — stale (>5 min)
        return '#22c55e'; // green — active
    }

    function createCrewIcon(color, initial) {
        // SVG crew marker with initial
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="44" viewBox="0 0 36 44">' +
            '<path d="M18 0C8.06 0 0 8.06 0 18c0 11.25 18 26 18 26s18-14.75 18-26C36 8.06 27.94 0 18 0z" fill="' + color + '" stroke="white" stroke-width="2"/>' +
            '<circle cx="18" cy="16" r="10" fill="white" opacity="0.3"/>' +
            '<text x="18" y="21" text-anchor="middle" font-size="13" font-weight="bold" fill="white" font-family="Arial">' + initial + '</text>' +
            '</svg>';

        return {
            url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
            scaledSize: new google.maps.Size(36, 44),
            anchor: new google.maps.Point(18, 44)
        };
    }

    function buildInfoContent(member) {
        var secondsAgo = parseInt(member.seconds_ago) || 0;
        var ago = secondsAgo < 60 ? secondsAgo + 's ago' : Math.floor(secondsAgo / 60) + 'm ago';
        var accuracy = member.accuracy_meters ? member.accuracy_meters + 'm' : 'unknown';
        var isClocked = parseInt(member.is_clocked_in) === 1;
        var status = isClocked ? (secondsAgo > 300 ? 'Stale' : 'Active') : 'Offline';
        var statusColor = isClocked ? (secondsAgo > 300 ? '#f59e0b' : '#22c55e') : '#9ca3af';

        return '<div style="padding:8px;min-width:180px;">' +
            '<h6 style="margin:0 0 6px 0;color:var(--mw-forest,#0D3B2E);">' + escapeHtml(member.full_name) + '</h6>' +
            '<p style="margin:0 0 4px 0;font-size:11px;">' +
                '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + statusColor + ';margin-right:4px;"></span>' +
                '<strong>' + status + '</strong> &mdash; ' + escapeHtml(member.role) +
            '</p>' +
            '<p style="margin:2px 0;font-size:11px;color:#666;">Updated: ' + ago + '</p>' +
            '<p style="margin:2px 0;font-size:11px;color:#666;">Accuracy: ' + accuracy + '</p>' +
            '</div>';
    }

    function updateList(crew) {
        var container = document.getElementById('crewListContainer');
        if (!crew.length) {
            container.innerHTML = '<div class="text-center text-muted py-4">' +
                '<p>No tracked employees</p>' +
                '<p class="small">Enable tracking on the Team page</p>' +
                '</div>';
            return;
        }

        var html = '';
        crew.forEach(function(member) {
            var secondsAgo = parseInt(member.seconds_ago) || 0;
            var isClocked = parseInt(member.is_clocked_in) === 1;
            var color = getMarkerColor(secondsAgo, isClocked);
            var ago = secondsAgo < 60 ? secondsAgo + 's' : Math.floor(secondsAgo / 60) + 'm';
            var status = isClocked ? (secondsAgo > 300 ? 'Stale' : 'Active') : 'Offline';
            var initial = (member.full_name || 'U').charAt(0).toUpperCase();

            html += '<div class="mw-crew-list-item" onclick="locateCrew(' + member.user_id + ')">' +
                '<div class="mw-crew-list-avatar" style="background:' + color + ';">' + initial + '</div>' +
                '<div class="mw-crew-list-info">' +
                    '<div class="mw-crew-list-name">' + escapeHtml(member.full_name) + '</div>' +
                    '<div class="mw-crew-list-meta">' +
                        '<span style="color:' + color + ';">' + status + '</span> &middot; ' + ago + ' ago' +
                    '</div>' +
                '</div>' +
                '</div>';
        });

        container.innerHTML = html;
    }

    function updateHeader(crew) {
        var count = crew.length;
        document.getElementById('crewCount').textContent = count;
        document.getElementById('crewPlural').textContent = count === 1 ? '' : 's';
        var now = new Date();
        document.getElementById('lastUpdate').textContent =
            pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
    }

    // Global function for crew list click
    window.locateCrew = function(userId) {
        var entry = crewMarkers[userId];
        if (entry && entry.marker) {
            var pos = entry.marker.getPosition();
            gmap.panTo(pos);
            gmap.setZoom(16);
            entry.infoWindow.setContent(buildInfoContent(entry.data));
            entry.infoWindow.open(gmap, entry.marker);
        }
    };

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
