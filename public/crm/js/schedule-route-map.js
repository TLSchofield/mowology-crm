/**
 * Schedule Route Map — Map View with Swipeable Cards
 *
 * Full-screen map view activated from the mobile schedule.
 * Shows Google Maps directions to each stop with a swipeable
 * card carousel at the bottom. Uses DirectionsService with
 * optimizeWaypoints for full route optimization.
 *
 * Entry points:
 *   MwRouteMap.toggle()       — opens map view, sorts by proximity, shows closest first
 *   MwRouteMap.openToStop(id) — opens map view focused on a specific stop
 *
 * Depends on:
 *   - Google Maps JS API (loaded via $extraHead with &libraries=geometry)
 *   - MW_ROUTE_STOPS global (set by schedule.php)
 *   - MwNavLauncher (navigation-launcher.js) — for external nav
 *   - MwRouteEngine (route-engine.js) — for drive time computation
 *
 * @package Mowology CRM
 */
var MwRouteMap = (function() {
    'use strict';

    // Map View works at all screen sizes (mobile, tablet, desktop)

    // ── Debug ──
    var DEBUG = (typeof MW_DEBUG !== 'undefined' && MW_DEBUG) ||
                (typeof localStorage !== 'undefined' && localStorage.getItem('mw_route_debug') === '1');

    function log() {
        if (!DEBUG) return;
        var args = ['[RouteMap]'].concat(Array.prototype.slice.call(arguments));
        console.log.apply(console, args);
    }

    // The Maps script is loaded with loading=async, which lazy-loads classes
    // (Map, ControlPosition, Marker, Geocoder, DirectionsService, ...) rather
    // than defining them the moment google.maps exists. Wait for importLibrary
    // itself, then explicitly load the libraries this file touches.
    function ensureMapsLibraries(cb) {
        function ready() {
            return window.google && google.maps && typeof google.maps.importLibrary === 'function';
        }
        function loadAll() {
            Promise.all([
                google.maps.importLibrary('maps'),
                google.maps.importLibrary('marker'),
                google.maps.importLibrary('geocoding'),
                google.maps.importLibrary('routes')
            ]).then(cb);
        }
        if (ready()) {
            loadAll();
            return;
        }
        var check = setInterval(function() {
            if (ready()) {
                clearInterval(check);
                loadAll();
            }
        }, 200);
    }

    // ── State ──
    var map = null;
    var geocoder = null;
    var directionsService = null;
    var directionsRenderer = null;
    var userMarker = null;
    var stopMarkers = [];
    var isOpen = false;
    var mapInitialized = false;
    var currentIndex = 0;
    var userLat = null;
    var userLng = null;
    var gpsTimestamp = 0;                // When GPS was last fetched
    var GPS_MAX_AGE_MS = 30000;          // Refresh GPS if older than 30s
    var optimizedStops = null;           // Reordered stops after route optimization
    var targetStopId = null;             // Stop to focus on after optimization
    var singleRouteMode = false;         // true when showing GPS→single stop route
    var lastRouteSummary = null;         // Cached MwRouteEngine result for UI
    var legDurations = [];               // Per-leg durations from Directions result

    // ── In-App Navigation state ──
    // ── Schedule context sheet (3a) ──
    var schedSheet = null;          // bottom sheet DOM element
    var schedCurrentStop = null;    // stop currently shown in sheet

    var navActive = false;               // true when in-app nav panel is visible
    var navSteps = [];                   // Steps from DirectionsResult.routes[0].legs[0].steps
    var navStepIdx = 0;                  // Current step index
    var navWatchId = null;               // geolocation.watchPosition ID
    var navPanelEl = null;               // .mw-mv-nav-panel DOM element
    var navTargetStop = null;            // Stop object being navigated to
    var navEtaSecs = 0;                  // ETA in seconds from Directions API
    var navEtaStart = 0;                 // performance.now() when nav started

    // ── DOM refs ──
    var viewEl, mapEl, trackEl, dotsEl, titleEl, backBtn, externalBtn, trayEl, summaryEl;

    // ── Service type colors ──
    var serviceColors = {
        lawn_care: '#2E7D32',
        hedge_trimming: '#6A1B9A',
        garden_maintenance: '#EF6C00',
        snow_removal: '#1565C0',
        landscaping: '#2D8659',
        seasonal_cleanup: '#455A64'
    };

    // ═══════════════════════════════════════════════════
    //  INIT
    // ═══════════════════════════════════════════════════

    function init() {
        viewEl      = document.getElementById('mwMapView');
        mapEl       = document.getElementById('mwMapViewMap');
        trackEl     = document.getElementById('mwMapViewTrack');
        dotsEl      = document.getElementById('mwMapViewDots');
        titleEl     = document.getElementById('mwMapViewTitle');
        backBtn     = document.getElementById('mwMapViewBack');
        externalBtn = document.getElementById('mwMapViewExternal');
        trayEl      = document.getElementById('mwMapViewTray');
        summaryEl   = document.getElementById('mwMapViewSummary');

        if (!viewEl || !mapEl) return;

        // Create the in-app nav panel and inject it into the map view
        navPanelEl = buildNavPanel();
        viewEl.insertBefore(navPanelEl, trayEl);

        // Create the schedule context sheet and inject into the map view
        buildScheduleSheet();

        backBtn.addEventListener('click', function() {
            if (navActive) stopInAppNav();
            close();
        });
        externalBtn.addEventListener('click', openExternal);
        wireCardTaps();
        setupSwipe();
    }

    // ═══════════════════════════════════════════════════
    //  TOGGLE / OPEN / CLOSE
    // ═══════════════════════════════════════════════════

    function toggle() {
        if (isOpen) {
            close();
            return;
        }

        var stops = getStops();
        if (stops.length === 0) return;

        // Show the view immediately with a loading state
        isOpen = true;
        optimizedStops = null;
        viewEl.classList.add('mw-mv-visible', 'mw-mv-loading');
        document.body.style.overflow = 'hidden';
        titleEl.textContent = 'Getting location...';

        // Wait for Google Maps API
        function onMapsReady() {
            if (!mapInitialized) initMap();
            else google.maps.event.trigger(map, 'resize');

            // Always get fresh GPS, then sort by proximity and compute route
            getFreshGPS(function() {
                var closestIdx = findClosestStop(stops);
                currentIndex = closestIdx;
                buildCarousel(stops);
                updateDots(stops.length, currentIndex);
                computeFullRoute();
            });
        }

        ensureMapsLibraries(onMapsReady);
    }

    function open(stopIndex) {
        if (typeof stopIndex !== 'number') stopIndex = 0;
        var stops = getStops();
        if (stops.length === 0) return;
        if (stopIndex >= stops.length) stopIndex = 0;

        currentIndex = stopIndex;
        isOpen = true;
        optimizedStops = null;

        buildCarousel(stops);
        updateDots(stops.length, currentIndex);

        viewEl.classList.add('mw-mv-visible');
        document.body.style.overflow = 'hidden';

        if (!mapInitialized) {
            ensureMapsLibraries(function() {
                initMap();
                getFreshGPS(function() { computeFullRoute(); });
            });
            return;
        }

        google.maps.event.trigger(map, 'resize');
        getFreshGPS(function() { computeFullRoute(); });
    }

    function openToStop(stopId) {
        var stops = getStops();
        var idx = 0;
        for (var i = 0; i < stops.length; i++) {
            if (stops[i].stopId === stopId) {
                idx = i;
                break;
            }
        }

        log('openToStop:', stopId, 'found at index:', idx);
        targetStopId = stopId;
        open(idx);
    }

    function close() {
        if (navActive) stopInAppNav();
        isOpen = false;
        viewEl.classList.remove('mw-mv-visible');
        document.body.style.overflow = '';
    }

    // ═══════════════════════════════════════════════════
    //  GOOGLE MAP INIT
    // ═══════════════════════════════════════════════════

    function initMap() {
        map = new google.maps.Map(mapEl, {
            zoom: 13,
            center: { lat: 49.2827, lng: -123.1207 },
            disableDefaultUI: true,
            zoomControl: true,
            zoomControlOptions: {
                position: google.maps.ControlPosition.RIGHT_CENTER
            },
            gestureHandling: 'greedy',
            styles: [
                { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                { featureType: 'transit', stylers: [{ visibility: 'off' }] }
            ]
        });

        geocoder = new google.maps.Geocoder();
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer({
            map: map,
            suppressMarkers: true,
            polylineOptions: {
                strokeColor: '#2D8659',
                strokeWeight: 5,
                strokeOpacity: 0.85
            }
        });

        mapInitialized = true;
    }

    // ═══════════════════════════════════════════════════
    //  GPS HELPERS
    // ═══════════════════════════════════════════════════

    /**
     * Get FRESH GPS position every time (never use stale cached position).
     * GPS is refreshed if older than GPS_MAX_AGE_MS or not yet obtained.
     */
    function getFreshGPS(callback) {
        var now = Date.now();

        // Use cached if fresh enough
        if (userLat && userLng && (now - gpsTimestamp) < GPS_MAX_AGE_MS) {
            log('Using cached GPS (age:', Math.round((now - gpsTimestamp) / 1000) + 's)');
            callback();
            return;
        }

        if (!navigator.geolocation) {
            log('Geolocation not available');
            callback();
            return;
        }

        log('Requesting fresh GPS...');
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                gpsTimestamp = Date.now();
                log('GPS updated:', userLat, userLng);
                callback();
            },
            function(err) {
                console.warn('[RouteMap] GPS error:', err.message || err.code);
                callback();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
        );
    }

    /**
     * Haversine distance in meters between two lat/lng pairs.
     */
    function haversine(lat1, lng1, lat2, lng2) {
        var R = 6371000;
        var toRad = Math.PI / 180;
        var dLat = (lat2 - lat1) * toRad;
        var dLng = (lng2 - lng1) * toRad;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    /**
     * Find the index of the closest stop to the user's GPS position.
     * Falls back to 0 if no GPS.
     */
    function findClosestStop(stops) {
        if (!userLat || !userLng || !stops.length) return 0;

        var closestIdx = 0;
        var closestDist = Infinity;
        for (var i = 0; i < stops.length; i++) {
            if (stops[i].lat && stops[i].lng) {
                var dist = haversine(userLat, userLng, stops[i].lat, stops[i].lng);
                if (dist < closestDist) {
                    closestDist = dist;
                    closestIdx = i;
                }
            }
        }
        return closestIdx;
    }

    // ═══════════════════════════════════════════════════
    //  ROUTE COMPUTATION
    // ═══════════════════════════════════════════════════

    /**
     * Compute the full optimized route from user GPS through all stops.
     * Uses optimizeWaypoints so Google picks the best order.
     * Carousel card for currentIndex is highlighted.
     */
    function computeFullRoute() {
        var stops = getStops();
        if (!stops.length || !map) {
            viewEl.classList.remove('mw-mv-loading');
            return;
        }

        clearMarkers();
        legDurations = [];

        var hasGPS = !!(userLat && userLng);

        // ── Single stop ──
        if (stops.length === 1) {
            var target = stops[0];
            if (hasGPS) {
                addUserMarker(userLat, userLng);
                var dest = (target.lat && target.lng)
                    ? new google.maps.LatLng(target.lat, target.lng)
                    : target.address;
                if (!dest) { geocodeAndShow(target, 0); return; }

                titleEl.textContent = 'Calculating route...';
                directionsService.route({
                    origin: new google.maps.LatLng(userLat, userLng),
                    destination: dest,
                    travelMode: google.maps.TravelMode.DRIVING
                }, function(result, status) {
                    viewEl.classList.remove('mw-mv-loading');
                    if (status === google.maps.DirectionsStatus.OK) {
                        directionsRenderer.setDirections(result);
                        var leg = result.routes[0].legs[0];
                        placeMarkerAt(leg.end_location, target, 0, true);
                        titleEl.textContent = leg.duration.text + ' \u00b7 ' + leg.distance.text;
                        legDurations = [{ text: leg.duration.text, seconds: leg.duration.value }];
                        map.fitBounds(result.routes[0].bounds, { top: 60, right: 40, bottom: 200, left: 40 });
                        updateCardDriveTimes();
                    } else {
                        console.warn('[RouteMap] Single-stop directions failed:', status);
                        geocodeAndShow(target, 0);
                    }
                });
            } else {
                geocodeAndShow(target, 0);
            }
            return;
        }

        // ── Multiple stops ──
        // Origin: user GPS or first stop
        // Destination: last stop in the array
        // Waypoints: all remaining stops, with optimizeWaypoints = true
        // Google will reorder the waypoints for the best route

        var origin;
        var originIsUserGPS = false;
        if (hasGPS) {
            origin = new google.maps.LatLng(userLat, userLng);
            addUserMarker(userLat, userLng);
            originIsUserGPS = true;
        } else {
            // No GPS — use first stop as origin
            var first = stops[0];
            origin = (first.lat && first.lng)
                ? new google.maps.LatLng(first.lat, first.lng)
                : first.address;
            if (!origin) { geocodeAndShow(stops[0], 0); return; }
        }

        // Find the farthest stop from origin to use as destination
        // (with GPS: farthest from user; without GPS: use last stop)
        var destIdx = stops.length - 1;
        if (originIsUserGPS) {
            var maxDist = -1;
            for (var d = 0; d < stops.length; d++) {
                if (stops[d].lat && stops[d].lng) {
                    var dist = haversine(userLat, userLng, stops[d].lat, stops[d].lng);
                    if (dist > maxDist) {
                        maxDist = dist;
                        destIdx = d;
                    }
                }
            }
        }

        var destStop = stops[destIdx];
        var destination = (destStop.lat && destStop.lng)
            ? new google.maps.LatLng(destStop.lat, destStop.lng)
            : destStop.address;
        if (!destination) { geocodeAndShow(stops[0], 0); return; }

        // Waypoints: all stops except the destination (and origin if it's a stop)
        var waypoints = [];
        var waypointStopIndices = []; // track which stop index each waypoint refers to
        for (var w = 0; w < stops.length; w++) {
            if (w === destIdx) continue; // skip destination
            if (!originIsUserGPS && w === 0) continue; // skip origin stop
            var ws = stops[w];
            var loc = (ws.lat && ws.lng)
                ? new google.maps.LatLng(ws.lat, ws.lng)
                : ws.address;
            if (loc) {
                waypoints.push({ location: loc, stopover: true });
                waypointStopIndices.push(w);
            }
        }

        var request = {
            origin: origin,
            destination: destination,
            travelMode: google.maps.TravelMode.DRIVING
        };

        if (waypoints.length > 0) {
            request.waypoints = waypoints;
            request.optimizeWaypoints = true;
        }

        titleEl.textContent = 'Calculating route...';

        directionsService.route(request, function(result, status) {
            viewEl.classList.remove('mw-mv-loading');

            if (status === google.maps.DirectionsStatus.OK) {
                directionsRenderer.setDirections(result);

                var legs = result.routes[0].legs;
                var waypointOrder = result.routes[0].waypoint_order || [];

                // Build the optimized stop order for numbering
                // Leg 0 end = first waypoint (reordered), ..., last leg end = destination
                var orderedStopIndices = [];

                // If origin is a stop (no GPS), include it first
                if (!originIsUserGPS) {
                    orderedStopIndices.push(0);
                }

                // Waypoints in optimized order
                for (var oi = 0; oi < waypointOrder.length; oi++) {
                    orderedStopIndices.push(waypointStopIndices[waypointOrder[oi]]);
                }
                // If no optimization happened (single waypoint), use original order
                if (waypointOrder.length === 0 && waypointStopIndices.length > 0) {
                    for (var si = 0; si < waypointStopIndices.length; si++) {
                        orderedStopIndices.push(waypointStopIndices[si]);
                    }
                }

                // Destination last
                orderedStopIndices.push(destIdx);

                // Store optimized order for external maps and rebuild carousel
                optimizedStops = orderedStopIndices.map(function(i) { return stops[i]; });

                // Store per-leg durations for card display
                legDurations = [];
                for (var li = 0; li < legs.length; li++) {
                    legDurations.push({
                        text: legs[li].duration.text,
                        seconds: legs[li].duration.value,
                        distance: legs[li].distance.text
                    });
                }

                // Rebuild carousel with optimized stop order
                // Find the target stop in the optimized order (if user tapped a specific card)
                currentIndex = 0;
                if (targetStopId) {
                    for (var ti = 0; ti < optimizedStops.length; ti++) {
                        if (optimizedStops[ti].stopId === targetStopId) {
                            currentIndex = ti;
                            break;
                        }
                    }
                    targetStopId = null;
                }
                buildCarousel(optimizedStops);
                updateDots(optimizedStops.length, currentIndex);
                updateCardDriveTimes();

                // Place markers for each optimized stop
                // Use leg endpoints for accurate on-road marker positions
                var legIdx = 0;
                for (var oi = 0; oi < optimizedStops.length; oi++) {
                    var os = optimizedStops[oi];
                    var markerPos;

                    if (oi === 0 && !originIsUserGPS) {
                        // First stop is the origin (start of first leg)
                        markerPos = legs[0].start_location;
                    } else {
                        // Each subsequent stop = end of a leg
                        markerPos = legs[legIdx] ? legs[legIdx].end_location : null;
                        legIdx++;
                    }

                    if (markerPos) {
                        placeMarkerAt(markerPos, os, oi, oi === currentIndex);
                    } else if (os.lat && os.lng) {
                        placeMarkerAt(new google.maps.LatLng(os.lat, os.lng), os, oi, oi === currentIndex);
                    }
                }

                // Calculate totals
                var totalDuration = 0;
                var totalDistance = 0;
                for (var l = 0; l < legs.length; l++) {
                    totalDuration += legs[l].duration.value;
                    totalDistance += legs[l].distance.value;
                }
                var durationText = totalDuration < 3600
                    ? Math.round(totalDuration / 60) + ' min'
                    : Math.floor(totalDuration / 3600) + ' hr ' + Math.round((totalDuration % 3600) / 60) + ' min';
                var distanceText = totalDistance < 1000
                    ? totalDistance + ' m'
                    : (totalDistance / 1000).toFixed(1) + ' km';
                titleEl.textContent = stops.length + ' stops \u00b7 ' + durationText + ' \u00b7 ' + distanceText;

                // Compute and show feasibility if RouteEngine is available
                computeAndShowFeasibility(optimizedStops, totalDuration);

                // Fit to route bounds with padding for the card tray
                var bounds = result.routes[0].bounds;
                if (bounds) {
                    map.fitBounds(bounds, { top: 60, right: 40, bottom: 200, left: 40 });
                }
            } else {
                console.warn('[RouteMap] Directions failed:', status);
                // Fallback: show all stops as markers without a route line
                showAllStopsNoRoute(stops);
            }
        });
    }

    /**
     * Compute feasibility score and display in the summary area.
     */
    function computeAndShowFeasibility(stops, totalDriveSeconds) {
        if (typeof MwRouteEngine === 'undefined') return;

        var totalJobMin = 0;
        stops.forEach(function(s) {
            totalJobMin += (s.duration || 0);
        });

        var feasibility = MwRouteEngine.computeFeasibility(
            totalDriveSeconds / 60,
            totalJobMin
        );

        // Create or update summary chip
        if (!summaryEl) {
            summaryEl = document.getElementById('mwMapViewSummary');
        }
        if (!summaryEl) {
            // Create summary element dynamically
            summaryEl = document.createElement('div');
            summaryEl.id = 'mwMapViewSummary';
            summaryEl.className = 'mw-mv-summary';
            var titleBar = viewEl.querySelector('.mw-mv-header') || titleEl.parentNode;
            if (titleBar) {
                titleBar.appendChild(summaryEl);
            }
        }

        var colorMap = { green: '#2E7D32', yellow: '#F9A825', red: '#D32F2F' };
        var labelMap = { green: 'On Track', yellow: 'Tight', red: 'Overloaded' };
        var color = colorMap[feasibility.classification] || '#9E9E9E';
        var label = labelMap[feasibility.classification] || '';
        var b = feasibility.breakdown;

        summaryEl.innerHTML =
            '<span class="mw-mv-feas-badge" style="background:' + color + '">' +
            label + ' \u00b7 ' + b.utilizationPercent + '%</span>' +
            '<span class="mw-mv-feas-detail">' +
            'Drive ' + b.totalDriveMinutes + 'm + Work ' + b.totalJobMinutes + 'm + Buffer ' + b.bufferMinutes + 'm' +
            '</span>';

        summaryEl.style.display = 'flex';
        log('Feasibility:', feasibility.classification, b.utilizationPercent + '%');
    }

    /**
     * Update carousel cards with per-leg drive times.
     */
    function updateCardDriveTimes() {
        if (!legDurations.length) return;

        var cards = trackEl.querySelectorAll('.mw-mv-card');
        cards.forEach(function(card, idx) {
            var etaEl = card.querySelector('.mw-mv-card-eta');
            if (!etaEl) return;

            // Leg idx: for the first stop, the leg is legs[0] (GPS→stop1)
            // For stop N, the leg is legs[N] if origin is GPS, or legs[N-1] if origin is a stop
            var legIdx = idx;
            if (legIdx < legDurations.length) {
                etaEl.textContent = legDurations[legIdx].text;
                etaEl.style.display = '';
            }
        });
    }

    /**
     * Fallback: show all stop markers without a route line.
     */
    function showAllStopsNoRoute(stops) {
        viewEl.classList.remove('mw-mv-loading');
        if (directionsRenderer) {
            directionsRenderer.setMap(null);
            directionsRenderer.setMap(map);
        }

        var bounds = new google.maps.LatLngBounds();
        var hasAny = false;

        if (userLat && userLng) {
            addUserMarker(userLat, userLng);
            bounds.extend(new google.maps.LatLng(userLat, userLng));
            hasAny = true;
        }

        stops.forEach(function(stop, idx) {
            if (stop.lat && stop.lng) {
                var pos = new google.maps.LatLng(stop.lat, stop.lng);
                placeMarkerAt(pos, stop, idx, idx === currentIndex);
                bounds.extend(pos);
                hasAny = true;
            }
        });

        if (hasAny) {
            map.fitBounds(bounds, { top: 60, right: 40, bottom: 200, left: 40 });
        }
        titleEl.textContent = stops.length + ' stops';
    }

    // ═══════════════════════════════════════════════════
    //  SINGLE-STOP IN-APP ROUTE (Go button)
    // ═══════════════════════════════════════════════════

    /**
     * Draw a route from user GPS to a specific stop on the in-app map.
     * Shows ETA/distance in the title bar and a "All stops" pill to go back.
     */
    function routeToStop(idx) {
        var stops = optimizedStops || getStops();
        if (idx < 0 || idx >= stops.length) return;

        var target = stops[idx];
        singleRouteMode = true;

        // Get fresh GPS then draw route
        getFreshGPS(function() {
            if (!userLat || !userLng) {
                // No GPS — just center on the stop
                clearMarkers();
                if (target.lat && target.lng) {
                    var pos = new google.maps.LatLng(target.lat, target.lng);
                    placeMarkerAt(pos, target, idx, true);
                    map.setCenter(pos);
                    map.setZoom(15);
                }
                showSingleRouteTitle(target.address ? target.address.split(',')[0] : 'Stop ' + (idx + 1), null, null, stops.length > 1);
                return;
            }

            clearMarkers();
            addUserMarker(userLat, userLng);

            var dest = (target.lat && target.lng)
                ? new google.maps.LatLng(target.lat, target.lng)
                : target.address;

            if (!dest) {
                showSingleRouteTitle('No location data', null, null, stops.length > 1);
                return;
            }

            titleEl.textContent = 'Calculating route...';

            directionsService.route({
                origin: new google.maps.LatLng(userLat, userLng),
                destination: dest,
                travelMode: google.maps.TravelMode.DRIVING
            }, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    directionsRenderer.setDirections(result);
                    var leg = result.routes[0].legs[0];
                    // Store steps + ETA for in-app navigation
                    navSteps = leg.steps || [];
                    navEtaSecs = leg.duration ? leg.duration.value : 0;
                    navTargetStop = target;
                    placeMarkerAt(leg.end_location, target, idx, true);
                    map.fitBounds(result.routes[0].bounds, { top: 60, right: 40, bottom: 200, left: 40 });
                    showSingleRouteTitle(
                        leg.duration.text + ' \u00b7 ' + leg.distance.text,
                        target.address,
                        idx,
                        stops.length > 1
                    );
                } else {
                    // Fallback: show marker without route line
                    if (target.lat && target.lng) {
                        var pos = new google.maps.LatLng(target.lat, target.lng);
                        placeMarkerAt(pos, target, idx, true);
                        map.setCenter(pos);
                        map.setZoom(14);
                    }
                    showSingleRouteTitle(target.address ? target.address.split(',')[0] : 'Stop ' + (idx + 1), null, null, stops.length > 1);
                }
            });
        });
    }

    /**
     * Update title bar for single-stop route mode.
     * Shows ETA info and a "All stops" pill to return to full route.
     */
    function showSingleRouteTitle(text, address, stopIdx, showBackPill) {
        var html = '<span class="mw-mv-route-info">' + escHtml(text) + '</span>';
        if (showBackPill) {
            html = '<button type="button" class="mw-mv-back-pill" id="mwMvBackToAll">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>' +
                'All stops</button>' + html;
        }
        titleEl.innerHTML = html;

        // Wire the back pill
        var backPill = document.getElementById('mwMvBackToAll');
        if (backPill) {
            backPill.addEventListener('click', function() {
                returnToFullRoute();
            });
        }
    }

    /**
     * Return from single-stop route to the full multi-stop route view.
     */
    function returnToFullRoute() {
        singleRouteMode = false;
        titleEl.innerHTML = '';
        titleEl.textContent = 'Calculating route...';
        computeFullRoute();
    }

    /**
     * Fallback when no route can be drawn for a single stop:
     * Geocode the stop address and center the map on it with a marker.
     */
    function geocodeAndShow(stop, index) {
        viewEl.classList.remove('mw-mv-loading');

        // Clear any stale route line
        if (directionsRenderer) {
            directionsRenderer.setMap(null);
            directionsRenderer.setMap(map);
        }

        // If we have coordinates, use them directly
        if (stop.lat && stop.lng) {
            var pos = new google.maps.LatLng(stop.lat, stop.lng);
            placeMarkerAt(pos, stop, index, true);
            map.setCenter(pos);
            map.setZoom(15);
            titleEl.textContent = stop.address || 'Stop ' + (index + 1);
            return;
        }

        // Geocode the address string
        if (!stop.address || !geocoder) {
            titleEl.textContent = 'No location data';
            return;
        }

        titleEl.textContent = 'Finding address...';

        geocoder.geocode({ address: stop.address }, function(results, status) {
            if (status === google.maps.GeocoderStatus.OK && results[0]) {
                var pos = results[0].geometry.location;
                placeMarkerAt(pos, stop, index, true);
                map.setCenter(pos);
                map.setZoom(15);
                titleEl.textContent = stop.address.split(',')[0];
            } else {
                console.warn('[RouteMap] Geocode failed:', status);
                titleEl.textContent = 'Could not find address';
            }
        });
    }

    // ═══════════════════════════════════════════════════
    //  MARKERS
    // ═══════════════════════════════════════════════════

    function placeMarkerAt(position, stop, index, isActive) {
        var color = isActive ? (serviceColors[stop.serviceType] || '#2D8659') : '#9CA3AF';
        var scale = isActive ? 16 : 12;

        var marker = new google.maps.Marker({
            position: position,
            map: map,
            label: {
                text: String(index + 1),
                color: '#FFFFFF',
                fontSize: isActive ? '13px' : '11px',
                fontWeight: 'bold'
            },
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: scale,
                fillColor: color,
                fillOpacity: isActive ? 1 : 0.5,
                strokeColor: '#FFFFFF',
                strokeWeight: 2
            },
            title: stop.address,
            zIndex: isActive ? 100 : 10
        });

        // Click marker → open schedule context sheet (also scrolls carousel)
        marker.addListener('click', function() {
            showScheduleSheet(stop, index);
        });

        stopMarkers.push(marker);
    }

    function addUserMarker(lat, lng) {
        if (userMarker) userMarker.setMap(null);

        userMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: map,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 8,
                fillColor: '#4285F4',
                fillOpacity: 1,
                strokeColor: '#FFFFFF',
                strokeWeight: 2
            },
            title: 'Your location',
            zIndex: 999
        });
    }

    function clearMarkers() {
        stopMarkers.forEach(function(m) { m.setMap(null); });
        stopMarkers = [];
        if (userMarker) {
            userMarker.setMap(null);
            userMarker = null;
        }
        if (directionsRenderer && map) {
            directionsRenderer.setMap(null);
            directionsRenderer.setMap(map);
        }
    }

    // ═══════════════════════════════════════════════════
    //  CARD CAROUSEL
    // ═══════════════════════════════════════════════════

    function buildCarousel(stops) {
        trackEl.innerHTML = '';
        dotsEl.innerHTML = '';

        // Set card width from tray for precise centering (avoids 100vw mismatch on mobile)
        var trayW = trayEl.offsetWidth;
        var cardW = Math.min(Math.max(trayW - 48, 200), 360);

        stops.forEach(function(stop, idx) {
            var card = document.createElement('div');
            card.className = 'mw-mv-card';
            card.style.width = cardW + 'px';
            if (idx === currentIndex) card.classList.add('mw-mv-card-active');
            card.dataset.index = idx;

            var color = serviceColors[stop.serviceType] || '#455A64';

            var durStr = '';
            if (stop.duration > 0) {
                if (stop.duration >= 60) {
                    durStr = Math.floor(stop.duration / 60) + 'h' + (stop.duration % 60 > 0 ? ' ' + (stop.duration % 60) + 'm' : '');
                } else {
                    durStr = stop.duration + ' min';
                }
            }

            var serviceLabel = stop.planTitle || (stop.serviceType ? stop.serviceType.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }) : 'Service');

            // Show just street from full address (strip city/province/country)
            var displayAddress = stop.address ? stop.address.split(',')[0] : '';

            // Optimise-from-here button — re-orders remaining stops starting from this one
            var goBtn = '<button type="button" class="mw-mv-card-go" data-stop-id="' + (stop.stopId || '') + '" aria-label="Optimise route from this stop">' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>' +
                '</button>';

            // Google Maps button — opens navigation to the NEXT stop with traffic
            var isLastStop = (idx >= stops.length - 1);
            var navBtn = '<button type="button" class="mw-mv-card-nav" data-stop-idx="' + idx + '" aria-label="Open Google Maps to next stop"' + (isLastStop ? ' disabled style="opacity:0.35;cursor:default"' : '') + '>' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>' +
                '</button>';

            card.innerHTML =
                '<div class="mw-mv-card-accent" style="background:' + color + '"></div>' +
                '<div class="mw-mv-card-body">' +
                    '<div class="mw-mv-card-row1">' +
                        '<span class="mw-mv-card-number">' + (idx + 1) + '</span>' +
                        '<span class="mw-mv-card-service">' + escHtml(serviceLabel) + '</span>' +
                        '<span class="mw-mv-card-eta" style="display:none"></span>' +
                        (stop.time ? '<span class="mw-mv-card-time">' + escHtml(stop.time) + '</span>' : '') +
                    '</div>' +
                    '<div class="mw-mv-card-address">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>' +
                        escHtml(displayAddress) +
                    '</div>' +
                    (stop.contactName ? '<div class="mw-mv-card-client">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' +
                        escHtml(stop.contactName) +
                    '</div>' : '') +
                    '<div class="mw-mv-card-bottom">' +
                        (durStr ? '<span class="mw-mv-card-dur">' + escHtml(durStr) + '</span>' : '<span></span>') +
                        '<div class="mw-mv-card-btns">' +
                            goBtn +
                            navBtn +
                        '</div>' +
                    '</div>' +
                '</div>';

            trackEl.appendChild(card);
        });

        // Wire up Optimise-from-here buttons (green)
        trackEl.querySelectorAll('.mw-mv-card-go').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var stopId = parseInt(btn.dataset.stopId, 10);
                var date   = (typeof MW_SCHEDULE_DATE !== 'undefined') ? MW_SCHEDULE_DATE : todayStr();
                var csrf   = (typeof MW_CSRF !== 'undefined') ? MW_CSRF : '';
                if (!stopId || !csrf) { log('optimise-from-here: missing stopId or CSRF'); return; }

                btn.disabled = true;
                var origHTML = btn.innerHTML;
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

                fetch('/crm/api/optimize-route.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ date: date, from_stop_id: stopId, csrf_token: csrf })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = origHTML;
                        log('optimise-from-here failed:', data.error);
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.innerHTML = origHTML;
                });
            });
        });

        // Wire up nav buttons (orange) — launch navigation to NEXT stop via MwNavLauncher.
        // Uses google.navigation: intent on Android (Capacitor + Chrome PWA),
        // Apple Maps on iOS, Google Maps web URL as fallback.
        trackEl.querySelectorAll('.mw-mv-card-nav').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (btn.disabled) return;
                var card = btn.closest('.mw-mv-card');
                var idx  = card ? parseInt(card.dataset.index, 10) : currentIndex;
                var nextStop = stops[idx + 1] || null;
                if (!nextStop) { log('nav-next: no next stop'); return; }

                log('Navigating to next stop:', nextStop);

                if (typeof MwNavLauncher !== 'undefined') {
                    getFreshGPS(function() {
                        var navOptions = {};
                        if (userLat && userLng) {
                            navOptions.origin = { lat: userLat, lng: userLng };
                        }
                        MwNavLauncher.launchNavigation(nextStop, navOptions);
                    });
                } else {
                    // MwNavLauncher not loaded — plain URL fallback
                    var dest = (nextStop.lat && nextStop.lng)
                        ? (nextStop.lat + ',' + nextStop.lng)
                        : encodeURIComponent(nextStop.address || '');
                    if (!dest) return;
                    var url = 'https://www.google.com/maps/dir/?api=1&destination=' + dest + '&travelmode=driving&dir_action=navigate';
                    if (userLat && userLng) {
                        url = 'https://www.google.com/maps/dir/?api=1&origin=' + userLat + ',' + userLng + '&destination=' + dest + '&travelmode=driving&dir_action=navigate';
                    }
                    window.open(url, '_blank');
                }
            });
        });

        positionTrack(currentIndex, false);
    }

    /**
     * Build a fallback navigation URL when MwNavLauncher is not loaded.
     * Prefers lat/lng over address.
     */
    function buildFallbackNavUrl(stop) {
        var dest = '';
        if (stop.lat && stop.lng) {
            dest = stop.lat + ',' + stop.lng;
        } else if (stop.address) {
            dest = stop.address;
        } else {
            return null;
        }
        return 'https://www.google.com/maps/dir/?api=1&destination=' +
               encodeURIComponent(dest) + '&travelmode=driving&dir_action=navigate';
    }

    function updateDots(total, activeIdx) {
        dotsEl.innerHTML = '';
        if (total <= 1) return;

        for (var i = 0; i < total; i++) {
            var dot = document.createElement('span');
            dot.className = 'mw-mv-dot';
            if (i === activeIdx) dot.classList.add('mw-mv-dot-active');
            dotsEl.appendChild(dot);
        }
    }

    function positionTrack(idx, animate) {
        var cards = trackEl.querySelectorAll('.mw-mv-card');
        if (!cards.length) return;

        var cardWidth = cards[0].offsetWidth;
        var gap = 12;
        var trayWidth = trayEl.offsetWidth;
        var offset = idx * (cardWidth + gap);
        var center = (trayWidth - cardWidth) / 2;
        var translate = center - offset;

        var maxTranslate = center;
        var minTranslate = trayWidth - (cards.length * (cardWidth + gap) - gap) - (trayWidth - cardWidth) / 2;
        if (cards.length === 1) minTranslate = center;
        translate = Math.min(maxTranslate, Math.max(minTranslate, translate));

        trackEl.style.transition = animate ? 'transform 0.3s ease' : 'none';
        trackEl.style.transform = 'translateX(' + translate + 'px)';

        cards.forEach(function(c, i) {
            c.classList.toggle('mw-mv-card-active', i === idx);
        });
    }

    function goToCard(idx) {
        var stops = optimizedStops || getStops();
        if (idx < 0 || idx >= stops.length) return;
        currentIndex = idx;
        positionTrack(idx, true);
        updateDots(stops.length, idx);

        // Re-highlight markers without recomputing entire route
        clearMarkers();
        if (userLat && userLng) addUserMarker(userLat, userLng);
        stops.forEach(function(stop, i) {
            if (stop.lat && stop.lng) {
                placeMarkerAt(new google.maps.LatLng(stop.lat, stop.lng), stop, i, i === idx);
            }
        });
    }

    // ═══════════════════════════════════════════════════
    //  TOUCH SWIPE
    // ═══════════════════════════════════════════════════

    function setupSwipe() {
        var startX = 0;
        var startY = 0;
        var isDragging = false;
        var startTranslate = 0;
        var threshold = 50;
        var ignoreSwipe = false;

        trayEl.addEventListener('touchstart', function(e) {
            if (e.touches.length !== 1) return;

            // Don't intercept touches on the Go/Nav buttons
            var target = e.target;
            if (target.closest && (target.closest('.mw-mv-card-go') || target.closest('.mw-mv-card-nav'))) {
                ignoreSwipe = true;
                return;
            }
            ignoreSwipe = false;

            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isDragging = false;

            var matrix = window.getComputedStyle(trackEl).transform;
            if (matrix && matrix !== 'none') {
                var values = matrix.match(/matrix\((.+)\)/);
                if (values) {
                    startTranslate = parseFloat(values[1].split(',')[4]) || 0;
                }
            }
        }, { passive: true });

        trayEl.addEventListener('touchmove', function(e) {
            if (ignoreSwipe) return;
            if (e.touches.length !== 1) return;
            var dx = e.touches[0].clientX - startX;
            var dy = e.touches[0].clientY - startY;

            if (!isDragging) {
                if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
                    isDragging = true;
                } else if (Math.abs(dy) > Math.abs(dx)) {
                    return;
                }
            }

            if (isDragging) {
                e.preventDefault();
                trackEl.style.transition = 'none';
                trackEl.style.transform = 'translateX(' + (startTranslate + dx) + 'px)';
            }
        }, { passive: false });

        trayEl.addEventListener('touchend', function(e) {
            if (ignoreSwipe) {
                ignoreSwipe = false;
                return;
            }
            if (!isDragging) return;
            isDragging = false;

            var endX = e.changedTouches[0].clientX;
            var dx = endX - startX;

            if (dx < -threshold && currentIndex < (optimizedStops || getStops()).length - 1) {
                goToCard(currentIndex + 1);
            } else if (dx > threshold && currentIndex > 0) {
                goToCard(currentIndex - 1);
            } else {
                positionTrack(currentIndex, true);
            }
        }, { passive: true });
    }

    // ═══════════════════════════════════════════════════
    //  WIRE CARD TAPS IN SCHEDULE VIEW
    // ═══════════════════════════════════════════════════

    function wireCardTaps() {
        // Wire all route buttons (active card buttons + compact expanded buttons)
        document.querySelectorAll('.mw-mc-btn-route').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var stopId = parseInt(btn.dataset.stopId, 10);
                if (!stopId) {
                    var card = btn.closest('.mw-mc-card');
                    if (card) stopId = parseInt(card.dataset.stopId, 10);
                }

                log('Schedule card route button tapped: stopId', stopId);
                if (!stopId) return;

                // On mobile/touch: launch navigation directly — skip the map view.
                // The map view intermediate screen adds friction and has more failure
                // points (Maps JS API load, GPS fix, DirectionsService response).
                // MwNavLauncher picks the best method: Capacitor intent on Android,
                // Apple Maps on iOS, Google Maps web URL as fallback.
                var isMobile = window.matchMedia('(max-width: 991px)').matches ||
                               ('ontouchstart' in window && window.innerWidth <= 991);

                if (isMobile && typeof MwNavLauncher !== 'undefined') {
                    var stops = getStops();
                    var target = null;
                    for (var i = 0; i < stops.length; i++) {
                        if (stops[i].stopId === stopId) { target = stops[i]; break; }
                    }
                    if (target) {
                        log('Mobile direct nav to stop:', stopId, target);

                        // Get fresh GPS before launching so we can pass origin to
                        // Google Maps / Apple Maps. Without it, Maps stalls while
                        // acquiring its own GPS fix. getFreshGPS uses a 30s cache
                        // so it's instant if the map view was recently open.
                        getFreshGPS(function() {
                            var navOptions = {};
                            if (userLat && userLng) {
                                navOptions.origin = { lat: userLat, lng: userLng };
                                log('Launching with origin:', userLat, userLng);
                            } else {
                                log('No GPS available — launching without origin');
                            }
                            MwNavLauncher.launchNavigation(target, navOptions);
                        });
                        return;
                    }
                    // Stop not found in MW_ROUTE_STOPS — fall through to map view
                    log('Stop', stopId, 'not in MW_ROUTE_STOPS, falling back to map view');
                }

                // Desktop or MwNavLauncher not loaded: open the interactive map view
                openToStop(stopId);
            });
        });
    }

    // ═══════════════════════════════════════════════════
    //  IN-APP NAVIGATION PANEL
    // ═══════════════════════════════════════════════════

    /**
     * Build the nav panel DOM element (hidden by default).
     * Injected once into #mwMapView above the card tray.
     */
    function buildNavPanel() {
        var panel = document.createElement('div');
        panel.className = 'mw-mv-nav-panel';
        panel.id = 'mwNavPanel';
        panel.innerHTML =
            '<div class="mw-mv-nav-instruction" id="mwNavInstruction">Calculating...</div>' +
            '<div class="mw-mv-nav-meta">' +
                '<span class="mw-mv-nav-dist" id="mwNavDist"></span>' +
                '<span class="mw-mv-nav-eta" id="mwNavEta"></span>' +
                '<span class="mw-mv-nav-step" id="mwNavStep"></span>' +
            '</div>' +
            '<div class="mw-mv-nav-progress" id="mwNavProgress"><div class="mw-mv-nav-progress-fill" id="mwNavProgressFill"></div></div>' +
            '<button class="mw-mv-nav-stop" id="mwNavStop" type="button">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>' +
                'End Navigation' +
            '</button>';
        return panel;
    }

    // ═══════════════════════════════════════════════════
    //  SCHEDULE CONTEXT SHEET (3a)
    // ═══════════════════════════════════════════════════

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    function buildScheduleSheet() {
        if (!viewEl) return;
        schedSheet = document.createElement('div');
        schedSheet.className = 'mw-mv-sched-sheet';
        schedSheet.id = 'mwSchedSheet';
        schedSheet.innerHTML =
            '<div class="mw-mv-sched-handle"></div>' +
            '<button type="button" class="mw-mv-sched-close" id="mwSchedClose" aria-label="Dismiss">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
            '<div class="mw-mv-sched-service" id="mwSchedService"></div>' +
            '<div class="mw-mv-sched-meta">' +
                '<span class="mw-mv-sched-address" id="mwSchedAddress"></span>' +
                '<span class="mw-mv-sched-dot">\u00b7</span>' +
                '<span class="mw-mv-sched-client" id="mwSchedClient"></span>' +
            '</div>' +
            '<div class="mw-mv-sched-time" id="mwSchedTime"></div>' +
            '<div class="mw-mv-sched-actions">' +
                '<button type="button" class="mw-mv-sched-btn mw-mv-sched-today" id="mwSchedMoveToday">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
                    ' Move to Today' +
                '</button>' +
                '<button type="button" class="mw-mv-sched-btn mw-mv-sched-pick" id="mwSchedReschedule">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                    ' Reschedule\u2026' +
                '</button>' +
            '</div>' +
            '<div class="mw-mv-sched-picker" id="mwSchedPicker" style="display:none">' +
                '<button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#mwSchedDateInput" aria-haspopup="true" aria-expanded="false">' +
                    '<svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
                    '<span class="mw-datepicker-date" data-mw-dp-label></span>' +
                    '<svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>' +
                '</button>' +
                // min is fixed at build time (today never changes mid-session) so the
                // picker — constructed once, right after this markup is inserted —
                // picks it up; setting inp.min later would be too late for it to see.
                '<input type="date" class="mw-mv-sched-date-input" id="mwSchedDateInput" hidden min="' + todayStr() + '">' +
                '<button type="button" class="mw-mv-sched-btn mw-mv-sched-confirm" id="mwSchedConfirm">Confirm</button>' +
            '</div>' +
            '<div class="mw-mv-sched-msg" id="mwSchedMsg" role="status"></div>';

        viewEl.appendChild(schedSheet);
        if (window.mwInitDatePickers) window.mwInitDatePickers(schedSheet);

        document.getElementById('mwSchedClose').addEventListener('click', closeScheduleSheet);

        document.getElementById('mwSchedMoveToday').addEventListener('click', function() {
            if (schedCurrentStop) moveStopToDay(schedCurrentStop.stopId, todayStr(), false);
        });

        document.getElementById('mwSchedReschedule').addEventListener('click', function() {
            var picker = document.getElementById('mwSchedPicker');
            picker.style.display = '';
            var inp = document.getElementById('mwSchedDateInput');
            var tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            var tStr = tomorrow.getFullYear() + '-' +
                String(tomorrow.getMonth() + 1).padStart(2, '0') + '-' +
                String(tomorrow.getDate()).padStart(2, '0');
            if (!inp.value) {
                inp.value = tStr;
                inp.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        document.getElementById('mwSchedConfirm').addEventListener('click', function() {
            var d = document.getElementById('mwSchedDateInput').value;
            if (!d) { document.getElementById('mwSchedMsg').textContent = 'Please pick a date.'; return; }
            if (schedCurrentStop) moveStopToDay(schedCurrentStop.stopId, d, false);
        });
    }

    function showScheduleSheet(stop, idx) {
        goToCard(idx);
        schedCurrentStop = stop;

        var service = stop.planTitle ||
            (stop.serviceType
                ? stop.serviceType.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); })
                : 'Service');
        var addr = stop.address ? stop.address.split(',')[0] : '';

        document.getElementById('mwSchedService').textContent = service;
        document.getElementById('mwSchedAddress').textContent = addr;
        document.getElementById('mwSchedClient').textContent = stop.contactName || '';
        document.getElementById('mwSchedTime').textContent = stop.time
            ? ('Scheduled ' + stop.time + (stop.duration ? ' \u00b7 ' + stop.duration + ' min' : ''))
            : '';

        document.getElementById('mwSchedPicker').style.display = 'none';
        document.getElementById('mwSchedDateInput').value = '';
        document.getElementById('mwSchedMsg').textContent = '';

        ['mwSchedMoveToday', 'mwSchedReschedule', 'mwSchedConfirm'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.disabled = false;
        });

        if (schedSheet) schedSheet.classList.add('mw-mv-sched-visible');
    }

    function closeScheduleSheet() {
        if (schedSheet) schedSheet.classList.remove('mw-mv-sched-visible');
        schedCurrentStop = null;
    }

    function moveStopToDay(stopId, date, force) {
        var msg = document.getElementById('mwSchedMsg');
        if (msg) msg.textContent = 'Saving\u2026';

        var btns = schedSheet ? schedSheet.querySelectorAll('.mw-mv-sched-btn') : [];
        btns.forEach(function(b) { b.disabled = true; });

        fetch('/crm/api/reschedule-stop.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stop_id: stopId, new_date: date, force: force })
        })
        .then(function(r) {
            return r.json().then(function(d) { return { ok: r.ok, data: d }; });
        })
        .then(function(res) {
            if (res.data && res.data.warning && !force) {
                // Capacity warning — show inline message + force button
                if (msg) {
                    msg.textContent = '';
                    var warnSpan = document.createElement('span');
                    warnSpan.textContent = res.data.message + ' ';
                    var forceBtn = document.createElement('button');
                    forceBtn.type = 'button';
                    forceBtn.className = 'mw-mv-sched-btn mw-mv-sched-confirm';
                    forceBtn.style.cssText = 'font-size:11px;padding:4px 10px;';
                    forceBtn.textContent = 'Move anyway';
                    forceBtn.addEventListener('click', function() { moveStopToDay(stopId, date, true); });
                    msg.appendChild(warnSpan);
                    msg.appendChild(forceBtn);
                }
                btns.forEach(function(b) { b.disabled = false; });
            } else if (res.ok) {
                if (msg) msg.textContent = '\u2713 Moved to ' + date;
                setTimeout(function() {
                    closeScheduleSheet();
                    window.location.reload();
                }, 1400);
            } else {
                btns.forEach(function(b) { b.disabled = false; });
                if (msg) msg.textContent = (res.data && res.data.error) ? res.data.error : 'Could not reschedule. Try again.';
            }
        })
        .catch(function() {
            btns.forEach(function(b) { b.disabled = false; });
            if (msg) msg.textContent = 'Network error. Please try again.';
        });
    }

    /**
     * Start in-app navigation to a stop.
     * First draws the route (calls routeToStop), then slides up the nav panel
     * and begins a watchPosition loop to advance through steps.
     */
    function startInAppNav(idx) {
        var stops = optimizedStops || getStops();
        if (!stops[idx]) return;

        navStepIdx = 0;
        navActive = false; // will be set true after route is drawn

        // Draw route first (populates navSteps + navEtaSecs)
        routeToStop(idx);

        // Wait for route result (navSteps gets populated in the directions callback)
        // Use a short poll since the callback is async
        var attempts = 0;
        var waitForSteps = setInterval(function() {
            attempts++;
            if (navSteps.length > 0 || attempts > 20) {
                clearInterval(waitForSteps);
                if (navSteps.length === 0) {
                    showNavToast('Route unavailable — try external navigation');
                    return;
                }
                activateNavPanel();
            }
        }, 300);
    }

    function activateNavPanel() {
        navActive = true;
        navStepIdx = 0;
        navEtaStart = performance.now ? performance.now() : Date.now();

        // Show panel
        navPanelEl.classList.add('mw-mv-nav-panel-visible');
        trayEl.classList.add('mw-mv-tray-hidden'); // slide card tray down
        externalBtn.style.display = 'none';       // hide external button during nav

        // Wire stop button
        var stopBtn = document.getElementById('mwNavStop');
        if (stopBtn) {
            stopBtn.onclick = function() { stopInAppNav(); };
        }

        updateNavDisplay();
        startNavWatch();

        log('In-app nav started, steps:', navSteps.length, 'ETA:', navEtaSecs, 's');
    }

    function stopInAppNav() {
        navActive = false;
        if (navWatchId !== null && navigator.geolocation) {
            navigator.geolocation.clearWatch(navWatchId);
            navWatchId = null;
        }
        navPanelEl.classList.remove('mw-mv-nav-panel-visible');
        trayEl.classList.remove('mw-mv-tray-hidden');
        externalBtn.style.display = '';
        log('In-app nav stopped');
    }

    /**
     * Start watchPosition to advance through steps as user moves.
     */
    function startNavWatch() {
        if (!navigator.geolocation) return;

        navWatchId = navigator.geolocation.watchPosition(function(pos) {
            if (!navActive) return;
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;

            checkNavProgress(userLat, userLng);
            updateNavEta();
        }, function() {
            // GPS error — keep last known position, don't break nav
        }, {
            enableHighAccuracy: true,
            maximumAge: 5000,
            timeout: 10000
        });
    }

    /**
     * Check if user is close enough to the current step end_location
     * to auto-advance to the next step.
     */
    function checkNavProgress(lat, lng) {
        if (!navActive || !navSteps.length) return;
        if (navStepIdx >= navSteps.length) return;

        var step = navSteps[navStepIdx];
        if (!step || !step.end_location) return;

        var stepLat = step.end_location.lat();
        var stepLng = step.end_location.lng();
        var dist = haversineMeters(lat, lng, stepLat, stepLng);

        log('Nav step', navStepIdx, 'dist to step end:', Math.round(dist), 'm');

        // Advance if within 30m of step end point
        if (dist < 30) {
            navStepIdx++;
            if (navStepIdx >= navSteps.length) {
                // Arrived!
                onNavArrived();
            } else {
                updateNavDisplay();
            }
        }
    }

    function updateNavDisplay() {
        if (!navActive || !navSteps.length) return;

        var instrEl  = document.getElementById('mwNavInstruction');
        var distEl   = document.getElementById('mwNavDist');
        var etaEl    = document.getElementById('mwNavEta');
        var stepEl   = document.getElementById('mwNavStep');
        var fillEl   = document.getElementById('mwNavProgressFill');

        var step = navSteps[navStepIdx] || navSteps[navSteps.length - 1];

        // Strip HTML from Google's instruction text (it contains <b> etc.)
        var rawInstr = step.instructions || 'Continue';
        var tmpDiv = document.createElement('div');
        tmpDiv.innerHTML = rawInstr;
        instrEl.textContent = tmpDiv.textContent || tmpDiv.innerText || rawInstr;

        distEl.textContent = step.distance ? step.distance.text : '';
        stepEl.textContent = 'Step ' + (navStepIdx + 1) + ' of ' + navSteps.length;

        // Progress bar
        var pct = navSteps.length > 1 ? Math.round((navStepIdx / navSteps.length) * 100) : 0;
        fillEl.style.width = pct + '%';

        updateNavEta();
    }

    function updateNavEta() {
        var etaEl = document.getElementById('mwNavEta');
        if (!etaEl) return;

        if (navEtaSecs <= 0) { etaEl.textContent = ''; return; }

        // Estimate remaining: subtract elapsed from original ETA
        var elapsedSecs = navEtaStart
            ? ((performance.now ? performance.now() : Date.now()) - navEtaStart) / 1000
            : 0;
        var remaining = Math.max(0, Math.round(navEtaSecs - elapsedSecs));

        if (remaining < 60) {
            etaEl.textContent = 'Arriving soon';
        } else {
            var mins = Math.round(remaining / 60);
            etaEl.textContent = mins + ' min';
        }
    }

    function onNavArrived() {
        var instrEl = document.getElementById('mwNavInstruction');
        if (instrEl) instrEl.textContent = 'You have arrived!';
        var distEl = document.getElementById('mwNavDist');
        if (distEl) distEl.textContent = '';
        var etaEl = document.getElementById('mwNavEta');
        if (etaEl) etaEl.textContent = '';
        var fillEl = document.getElementById('mwNavProgressFill');
        if (fillEl) fillEl.style.width = '100%';

        // Auto-dismiss after 4 seconds
        setTimeout(function() {
            if (navActive) stopInAppNav();
        }, 4000);
    }

    /** Simple haversine distance in metres between two lat/lng points */
    function haversineMeters(lat1, lng1, lat2, lng2) {
        var R = 6371000;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function showNavToast(msg) {
        var t = document.createElement('div');
        t.className = 'mw-mc-toast';
        t.textContent = msg;
        t.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:10px 20px;border-radius:8px;font-size:0.82rem;font-weight:600;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.2);opacity:0;transition:opacity 0.3s ease;';
        document.body.appendChild(t);
        requestAnimationFrame(function() { t.style.opacity = '1'; });
        setTimeout(function() {
            t.style.opacity = '0';
            setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
        }, 3000);
    }

    // ═══════════════════════════════════════════════════
    //  OPEN IN EXTERNAL GOOGLE MAPS
    // ═══════════════════════════════════════════════════

    function openExternal() {
        var stops = optimizedStops || getStops();
        if (!stops.length) return;

        // In single-route mode, navigate to just the current stop
        if (singleRouteMode) {
            var target = stops[currentIndex];
            if (target && typeof MwNavLauncher !== 'undefined') {
                MwNavLauncher.launchNavigation(target);
            } else if (target) {
                var url = buildFallbackNavUrl(target);
                if (url) window.open(url, '_blank');
            }
            return;
        }

        // Full multi-stop route
        if (typeof MwNavLauncher !== 'undefined') {
            MwNavLauncher.launchMultiStopNavigation(stops);
        } else {
            // Fallback: use lat/lng where available, address as backup
            var dest = stops[stops.length - 1];
            var destStr = (dest.lat && dest.lng) ? (dest.lat + ',' + dest.lng) : dest.address;
            var waypointParts = [];
            for (var i = 0; i < stops.length - 1; i++) {
                var s = stops[i];
                if (s.lat && s.lng) {
                    waypointParts.push(s.lat + ',' + s.lng);
                } else if (s.address) {
                    waypointParts.push(s.address);
                }
            }
            var url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(destStr)
                + '&travelmode=driving'
                + '&dir_action=navigate';
            if (waypointParts.length > 0) {
                url += '&waypoints=' + encodeURIComponent(waypointParts.join('|'));
            }
            window.open(url, '_blank');
        }
    }

    // ═══════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════

    function getStops() {
        return (typeof MW_ROUTE_STOPS !== 'undefined' && Array.isArray(MW_ROUTE_STOPS))
            ? MW_ROUTE_STOPS
            : [];
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ═══════════════════════════════════════════════════
    //  BOOT
    // ═══════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * Launch navigation to a stop by ID, fetching fresh GPS first so
     * Google Maps / Apple Maps receives an explicit origin and doesn't
     * stall waiting to acquire its own GPS fix.
     * Called from compact card inline onclick handlers.
     *
     * @param {number} stopId
     */
    function launchNavToStop(stopId) {
        if (typeof MwNavLauncher === 'undefined') {
            openToStop(stopId);
            return;
        }
        var stops = getStops();
        var target = null;
        for (var i = 0; i < stops.length; i++) {
            if (stops[i].stopId === stopId) { target = stops[i]; break; }
        }
        if (!target) {
            openToStop(stopId);
            return;
        }
        getFreshGPS(function() {
            var navOptions = {};
            if (userLat && userLng) {
                navOptions.origin = { lat: userLat, lng: userLng };
            }
            MwNavLauncher.launchNavigation(target, navOptions);
        });
    }

    return {
        toggle: toggle,
        openToStop: openToStop,
        launchNavToStop: launchNavToStop,
        close: close,
        // Exposes the live Google map ONLY while the route view is open and
        // initialised — returns null when closed so the truck layer (below)
        // can pause polling and tear down its marker automatically.
        getMap: function() { return (isOpen && mapInitialized) ? map : null; }
    };

})();

// ═══════════════════════════════════════════════════════════════════════════
//  Truck Location Layer (mobile route map)
//  ───────────────────────────────────────
//  Mirrors the day-view truck layer (schedule-day-map.js) but drives off the
//  mobile MwRouteMap view. Polls /crm/api/truck-location.php every 30s while
//  the route view is OPEN, renders the same SVG truck badge as a
//  google.maps.OverlayView, and tears the marker down the moment the view
//  closes. Gracefully no-ops if MwRouteMap isn't present or no tracker is
//  configured.
// ═══════════════════════════════════════════════════════════════════════════
(function() {
    'use strict';

    var POLL_INTERVAL_MS = 30000;
    var STATE_CHECK_MS   = 1000;  // How often we watch MwRouteMap open/close
    var STALE_THRESHOLD_S = 600;  // 10 min — show grey/stale badge above this

    var truckOverlay = null;
    var truckInfoWindow = null;
    var truckLastLoc = null;
    var pollTimer = null;
    var watchTimer = null;
    var active = false;           // true while polling (route view is open)

    function routeMap() {
        return (window.MwRouteMap && MwRouteMap.getMap) ? MwRouteMap.getMap() : null;
    }

    function init() {
        // Watch for the route view opening/closing and start/stop accordingly.
        watchTimer = setInterval(watch, STATE_CHECK_MS);
    }

    function watch() {
        var map = routeMap();
        if (map && !active) startPolling();
        else if (!map && active) stopPolling();
    }

    function startPolling() {
        if (active) return;
        active = true;
        poll();
        pollTimer = setInterval(poll, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        active = false;
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        removeTruck();
    }

    function poll() {
        if (!routeMap()) { stopPolling(); return; }
        fetch('/crm/api/truck-location.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.ok) return;
                if (!data.location) return; // No ping yet
                placeTruck(data.location);
            })
            .catch(function() { /* silently ignore — next poll will retry */ });
    }

    function placeTruck(loc) {
        var map = routeMap();
        if (!map || typeof google === 'undefined') return;
        if (typeof loc.lat !== 'number' || typeof loc.lng !== 'number') return;

        truckLastLoc = loc;
        var pos = new google.maps.LatLng(loc.lat, loc.lng);
        var isStale = (loc.age_seconds || 0) > STALE_THRESHOLD_S;

        if (!truckOverlay) {
            truckOverlay = createTruckOverlay(pos, isStale);
            truckOverlay.setMap(map);
            truckInfoWindow = new google.maps.InfoWindow();
            truckOverlay.onDivClick = function() {
                truckInfoWindow.setContent(formatInfoBubble(truckLastLoc || loc));
                truckInfoWindow.setPosition(truckOverlay.getPosition());
                truckInfoWindow.open(map);
            };
        } else {
            truckOverlay.setPosition(pos);
            truckOverlay.setStale(isStale);
            truckOverlay.setTitle(formatTruckTitle(loc));
            if (truckInfoWindow && truckInfoWindow.getMap()) {
                truckInfoWindow.setContent(formatInfoBubble(loc));
            }
        }
    }

    function removeTruck() {
        if (truckInfoWindow) { truckInfoWindow.close(); truckInfoWindow = null; }
        if (truckOverlay) { truckOverlay.setMap(null); truckOverlay = null; }
        truckLastLoc = null;
    }

    /**
     * Build a google.maps.OverlayView that renders the truck badge as an
     * HTML element so the surrounding CSS pulse ring (.mw-truck-overlay) can
     * animate — static Marker icons can't. Disc is centred on the LatLng.
     */
    function createTruckOverlay(position, isStale) {
        var ov = new google.maps.OverlayView();
        ov._position = position;
        ov._div = null;

        ov.onAdd = function() {
            var div = document.createElement('div');
            div.className = 'mw-truck-overlay' + (isStale ? ' is-stale' : '');
            div.innerHTML = truckBadgeSvg();
            div.title = formatTruckTitle(truckLastLoc || {});
            div.addEventListener('click', function() {
                if (typeof ov.onDivClick === 'function') ov.onDivClick();
            });
            ov._div = div;
            this.getPanes().overlayMouseTarget.appendChild(div);
        };

        ov.draw = function() {
            if (!ov._div) return;
            var pos = this.getProjection().fromLatLngToDivPixel(ov._position);
            if (!pos) return;
            // Disc is 48×48 — anchor at centre so the badge sits on the GPS point.
            ov._div.style.left = (pos.x - 24) + 'px';
            ov._div.style.top  = (pos.y - 24) + 'px';
        };

        ov.onRemove = function() {
            if (ov._div && ov._div.parentNode) ov._div.parentNode.removeChild(ov._div);
            ov._div = null;
        };

        ov.setPosition = function(p) { ov._position = p; ov.draw(); };
        ov.getPosition = function()  { return ov._position; };
        ov.setStale = function(stale) {
            if (ov._div) ov._div.classList.toggle('is-stale', !!stale);
        };
        ov.setTitle = function(t) {
            if (ov._div) ov._div.title = t;
        };

        return ov;
    }

    function truckBadgeSvg() {
        // White disc with a purple→cyan gradient line-art truck (matches day view).
        // Outer CSS provides the cyan pulse + disc.
        return '<div class="mw-truck-badge">' +
                 '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" ' +
                      'stroke-linecap="round" stroke-linejoin="round" ' +
                      'xmlns="http://www.w3.org/2000/svg">' +
                   '<defs>' +
                     '<linearGradient id="mwTruckGradRM" x1="0" y1="0" x2="1" y2="1">' +
                       '<stop offset="0%" stop-color="#553c9a"/>' +
                       '<stop offset="100%" stop-color="#3eb5c9"/>' +
                     '</linearGradient>' +
                   '</defs>' +
                   '<rect x="1" y="3" width="15" height="13" rx="2" stroke="url(#mwTruckGradRM)"/>' +
                   '<path d="M16 8 L20 8 L23 11 L23 16 L16 16 Z" stroke="url(#mwTruckGradRM)"/>' +
                   '<circle cx="5.5"  cy="18.5" r="2.5" stroke="url(#mwTruckGradRM)"/>' +
                   '<circle cx="18.5" cy="18.5" r="2.5" stroke="url(#mwTruckGradRM)"/>' +
                 '</svg>' +
               '</div>';
    }

    function formatTruckTitle(loc) {
        var ageMin = Math.round((loc.age_seconds || 0) / 60);
        var ageLabel = ageMin < 1 ? 'just now' : ageMin + ' min ago';
        return 'Truck — last seen ' + ageLabel;
    }

    function formatInfoBubble(loc) {
        var ageMin = Math.round((loc.age_seconds || 0) / 60);
        var ageLabel = ageMin < 1 ? 'just now' : ageMin + ' min ago';
        var speed = (loc.speed_kph != null) ? Math.round(loc.speed_kph) + ' km/h' : '—';
        var batt  = (loc.battery_pct != null) ? loc.battery_pct + '%' : '—';
        return '<div style="font-family:sans-serif;font-size:13px;min-width:160px;line-height:1.5">' +
               '<strong style="color:#e85d04">🛻 Truck</strong><br>' +
               '<span style="color:#666">Last seen ' + ageLabel + '</span><br>' +
               'Speed: ' + speed + '<br>' +
               'Battery: ' + batt +
               '</div>';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
