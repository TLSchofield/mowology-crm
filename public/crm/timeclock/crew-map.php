<?php
/**
 * Live Crew Map — Real-time employee location tracking + daily route trails
 * Admin/Manager access only.
 * Polls /crm/api/crew-location.php?action=live every 30 seconds.
 * Fetches day routes via ?action=day_routes&date=YYYY-MM-DD.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('team.view');

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
    <div class="d-flex align-items-center" style="gap: 8px;">
        <a href="/crm/team/index.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="users" style="width:14px;height:14px;"></i> Team
        </a>
    </div>
</div>

<!-- Route Controls -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="mw-route-controls">
            <div class="mw-route-controls-left">
                <label class="mw-route-toggle">
                    <input type="checkbox" id="routeToggle">
                    <span class="mw-route-toggle-label">Show Day Routes</span>
                </label>
                <div class="mw-route-date-wrap" id="routeDateWrap" style="display:none;">
                    <button type="button" class="btn btn-sm btn-outline-secondary mw-route-date-btn" id="routePrevDay">&lsaquo;</button>
                    <input type="date" id="routeDate" class="form-control form-control-sm mw-route-date-input">
                    <button type="button" class="btn btn-sm btn-outline-secondary mw-route-date-btn" id="routeNextDay">&rsaquo;</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="routeToday">Today</button>
                </div>
            </div>
            <div class="mw-route-crew-filters" id="routeCrewFilters" style="display:none;">
                <!-- Crew toggle chips rendered by JS -->
            </div>
        </div>
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
                    <div class="mw-crew-legend-section">
                        <div class="mw-crew-legend-title">Live Position</div>
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
                    <div class="mw-crew-legend-section" id="routeLegend" style="display:none;">
                        <div class="mw-crew-legend-title">Day Routes</div>
                        <div id="routeLegendItems">
                            <!-- Rendered by JS -->
                        </div>
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

        <!-- Route Stats (shown when routes active) -->
        <div class="card mt-3" id="routeStatsCard" style="display:none;">
            <div class="card-header" style="background: var(--mw-forest); color:#fff;">
                <h5 class="card-title mb-0" style="color:#fff;">
                    <i data-feather="activity" style="width:16px;height:16px;display:inline;"></i> Route Stats
                </h5>
            </div>
            <div class="card-body p-0" id="routeStatsContainer">
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

    // Route trail state
    var routePolylines = {}; // keyed by user_id
    var routeStartMarkers = {}; // keyed by user_id
    var routeData = []; // raw route data from API
    var routeVisible = {}; // user_id -> boolean (toggle per crew)
    var routesEnabled = false;

    // Distinct colors for crew route trails
    var ROUTE_COLORS = [
        '#2563eb', // blue
        '#dc2626', // red
        '#7c3aed', // purple
        '#ea580c', // orange
        '#0891b2', // teal
        '#c026d3', // magenta
        '#65a30d', // lime-green
        '#b91c1c'  // dark red
    ];

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
        initRouteControls();
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

    // ── Live Crew Tracking ─────────────────────────────

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
                animateMarker(crewMarkers[member.user_id].marker, pos);
                crewMarkers[member.user_id].marker.setIcon(createCrewIcon(color, initial));
            } else {
                var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    icon: createCrewIcon(color, initial),
                    title: member.full_name,
                    zIndex: 1000 // Keep live markers above route trails
                });

                var infoWindow = new google.maps.InfoWindow();
                marker.addListener('click', function() {
                    infoWindow.setContent(buildInfoContent(member));
                    infoWindow.open(gmap, marker);
                });

                crewMarkers[member.user_id] = { marker: marker, infoWindow: infoWindow };
            }

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
        if (!isClocked) return '#9ca3af';
        if (secondsAgo > 300) return '#f59e0b';
        return '#22c55e';
    }

    function createCrewIcon(color, initial) {
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

    // ── Route Trail System ─────────────────────────────

    function initRouteControls() {
        var toggle = document.getElementById('routeToggle');
        var dateInput = document.getElementById('routeDate');
        var prevBtn = document.getElementById('routePrevDay');
        var nextBtn = document.getElementById('routeNextDay');
        var todayBtn = document.getElementById('routeToday');

        // Set default date to today
        dateInput.value = todayStr();
        dateInput.max = todayStr();

        toggle.addEventListener('change', function() {
            routesEnabled = this.checked;
            var wrap = document.getElementById('routeDateWrap');
            var filters = document.getElementById('routeCrewFilters');
            var legend = document.getElementById('routeLegend');
            var statsCard = document.getElementById('routeStatsCard');

            if (routesEnabled) {
                wrap.style.display = 'flex';
                filters.style.display = 'flex';
                legend.style.display = '';
                statsCard.style.display = '';
                fetchRoutes();
            } else {
                wrap.style.display = 'none';
                filters.style.display = 'none';
                legend.style.display = 'none';
                statsCard.style.display = 'none';
                clearRoutes();
            }
        });

        dateInput.addEventListener('change', function() {
            if (routesEnabled) fetchRoutes();
        });

        prevBtn.addEventListener('click', function() {
            shiftDate(-1);
        });

        nextBtn.addEventListener('click', function() {
            shiftDate(1);
        });

        todayBtn.addEventListener('click', function() {
            dateInput.value = todayStr();
            if (routesEnabled) fetchRoutes();
        });
    }

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function shiftDate(days) {
        var input = document.getElementById('routeDate');
        var d = new Date(input.value + 'T12:00:00'); // noon to avoid timezone edge
        d.setDate(d.getDate() + days);

        // Don't go past today
        var today = new Date();
        today.setHours(23, 59, 59, 999);
        if (d > today) return;

        input.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        if (routesEnabled) fetchRoutes();
    }

    function fetchRoutes() {
        var date = document.getElementById('routeDate').value;
        if (!date) return;

        fetch('/crm/api/crew-location.php?action=day_routes&date=' + encodeURIComponent(date), {
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            routeData = data.routes || [];

            // Initialize visibility for all crew (default: visible)
            routeData.forEach(function(route) {
                if (typeof routeVisible[route.user_id] === 'undefined') {
                    routeVisible[route.user_id] = true;
                }
            });

            renderCrewFilters();
            drawRoutes();
            renderRouteStats();
            renderRouteLegend();
        })
        .catch(function(err) {
            console.error('Route fetch error:', err);
        });
    }

    function clearRoutes() {
        Object.keys(routePolylines).forEach(function(uid) {
            routePolylines[uid].setMap(null);
        });
        routePolylines = {};

        Object.keys(routeStartMarkers).forEach(function(uid) {
            routeStartMarkers[uid].setMap(null);
        });
        routeStartMarkers = {};

        routeData = [];
        document.getElementById('routeCrewFilters').innerHTML = '';
        document.getElementById('routeStatsContainer').innerHTML = '';
        document.getElementById('routeLegendItems').innerHTML = '';
    }

    function drawRoutes() {
        // Clear existing polylines
        Object.keys(routePolylines).forEach(function(uid) {
            routePolylines[uid].setMap(null);
        });
        routePolylines = {};

        Object.keys(routeStartMarkers).forEach(function(uid) {
            routeStartMarkers[uid].setMap(null);
        });
        routeStartMarkers = {};

        routeData.forEach(function(route, index) {
            if (!routeVisible[route.user_id]) return;
            if (route.points.length < 2) return;

            var color = ROUTE_COLORS[index % ROUTE_COLORS.length];
            var path = route.points.map(function(p) {
                return { lat: p.lat, lng: p.lng };
            });

            // Draw polyline
            var polyline = new google.maps.Polyline({
                path: path,
                geodesic: true,
                strokeColor: color,
                strokeOpacity: 0.8,
                strokeWeight: 3,
                map: gmap,
                zIndex: 500
            });

            routePolylines[route.user_id] = polyline;

            // Start marker (small circle)
            var startMarker = new google.maps.Marker({
                position: path[0],
                map: gmap,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 6,
                    fillColor: color,
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2
                },
                title: route.full_name + ' — Start (' + formatTime(route.points[0].time) + ')',
                zIndex: 600
            });

            var startInfo = new google.maps.InfoWindow({
                content: '<div style="padding:6px;font-size:12px;">' +
                    '<strong>' + escapeHtml(route.full_name) + '</strong><br>' +
                    'Started: ' + formatTime(route.points[0].time) + '<br>' +
                    'Last ping: ' + formatTime(route.points[route.points.length - 1].time) + '<br>' +
                    'Points: ' + route.points.length +
                    '</div>'
            });

            startMarker.addListener('click', function() {
                startInfo.open(gmap, startMarker);
            });

            routeStartMarkers[route.user_id] = startMarker;
        });
    }

    function renderCrewFilters() {
        var container = document.getElementById('routeCrewFilters');
        if (!routeData.length) {
            container.innerHTML = '<span class="text-muted" style="font-size:0.8rem;">No routes for this date</span>';
            return;
        }

        var html = '';
        routeData.forEach(function(route, index) {
            var color = ROUTE_COLORS[index % ROUTE_COLORS.length];
            var isActive = routeVisible[route.user_id] !== false;
            var cls = isActive ? 'mw-route-chip active' : 'mw-route-chip';

            html += '<button type="button" class="' + cls + '" ' +
                'data-user-id="' + route.user_id + '" ' +
                'style="--chip-color:' + color + ';">' +
                '<span class="mw-route-chip-dot" style="background:' + color + ';"></span>' +
                escapeHtml(route.full_name) +
                ' <span class="mw-route-chip-count">(' + route.points.length + ')</span>' +
                '</button>';
        });

        container.innerHTML = html;

        // Bind toggle events
        container.querySelectorAll('.mw-route-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                var uid = this.getAttribute('data-user-id');
                routeVisible[uid] = !routeVisible[uid];
                this.classList.toggle('active', routeVisible[uid]);
                drawRoutes();
                renderRouteStats();
            });
        });
    }

    function renderRouteLegend() {
        var container = document.getElementById('routeLegendItems');
        if (!routeData.length) {
            container.innerHTML = '<div class="mw-crew-legend-item" style="color:#999;">No data</div>';
            return;
        }

        var html = '';
        routeData.forEach(function(route, index) {
            var color = ROUTE_COLORS[index % ROUTE_COLORS.length];
            html += '<div class="mw-crew-legend-item">' +
                '<span class="mw-crew-legend-dot" style="background:' + color + ';"></span> ' +
                escapeHtml(route.full_name) +
                '</div>';
        });
        container.innerHTML = html;
    }

    function renderRouteStats() {
        var container = document.getElementById('routeStatsContainer');
        if (!routeData.length) {
            container.innerHTML = '<div class="text-center text-muted py-3" style="font-size:0.85rem;">No route data</div>';
            return;
        }

        var html = '';
        routeData.forEach(function(route, index) {
            if (!routeVisible[route.user_id]) return;
            if (!route.points.length) return;

            var color = ROUTE_COLORS[index % ROUTE_COLORS.length];
            var firstTime = route.points[0].time;
            var lastTime = route.points[route.points.length - 1].time;
            var distance = calcRouteDistance(route.points);

            html += '<div class="mw-route-stat-item">' +
                '<div class="mw-route-stat-dot" style="background:' + color + ';"></div>' +
                '<div class="mw-route-stat-info">' +
                    '<div class="mw-route-stat-name">' + escapeHtml(route.full_name) + '</div>' +
                    '<div class="mw-route-stat-detail">' +
                        formatTime(firstTime) + ' &mdash; ' + formatTime(lastTime) +
                    '</div>' +
                    '<div class="mw-route-stat-detail">' +
                        route.points.length + ' pings &middot; ~' + distance + ' km' +
                    '</div>' +
                '</div>' +
                '</div>';
        });

        if (!html) {
            html = '<div class="text-center text-muted py-3" style="font-size:0.85rem;">All routes hidden</div>';
        }

        container.innerHTML = html;
    }

    function calcRouteDistance(points) {
        if (points.length < 2) return '0.0';
        var total = 0;
        for (var i = 1; i < points.length; i++) {
            var p1 = new google.maps.LatLng(points[i - 1].lat, points[i - 1].lng);
            var p2 = new google.maps.LatLng(points[i].lat, points[i].lng);
            total += google.maps.geometry.spherical.computeDistanceBetween(p1, p2);
        }
        return (total / 1000).toFixed(1);
    }

    function formatTime(timestamp) {
        if (!timestamp) return '';
        // timestamp is like "2026-02-12 14:30:45"
        var parts = timestamp.split(' ');
        if (parts.length < 2) return timestamp;
        var timeParts = parts[1].split(':');
        var h = parseInt(timeParts[0]);
        var m = timeParts[1];
        var ampm = h >= 12 ? 'PM' : 'AM';
        if (h === 0) h = 12;
        else if (h > 12) h -= 12;
        return h + ':' + m + ' ' + ampm;
    }

    // ── Utilities ──────────────────────────────────────

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
