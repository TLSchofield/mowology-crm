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
 *
 * @package Mowology CRM
 */
var MwRouteMap = (function() {
    'use strict';

    // Map View works at all screen sizes (mobile, tablet, desktop)

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
    var optimizedStops = null; // Reordered stops after route optimization

    // ── DOM refs ──
    var viewEl, mapEl, trackEl, dotsEl, titleEl, backBtn, externalBtn, trayEl;

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

        if (!viewEl || !mapEl) return;

        backBtn.addEventListener('click', close);
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

            // Get GPS, then sort by proximity and compute route
            getGPS(function() {
                var closestIdx = findClosestStop(stops);
                currentIndex = closestIdx;
                buildCarousel(stops);
                updateDots(stops.length, currentIndex);
                computeFullRoute();
            });
        }

        if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
            var check = setInterval(function() {
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                    clearInterval(check);
                    onMapsReady();
                }
            }, 200);
        } else {
            onMapsReady();
        }
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
            if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                var check = setInterval(function() {
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                        clearInterval(check);
                        initMap();
                        getGPS(function() { computeFullRoute(); });
                    }
                }, 200);
                return;
            }
            initMap();
        } else {
            google.maps.event.trigger(map, 'resize');
        }

        getGPS(function() { computeFullRoute(); });
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
        open(idx);
    }

    function close() {
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
     * Get GPS position, then call callback. Caches result.
     */
    function getGPS(callback) {
        if (userLat && userLng) {
            callback();
            return;
        }
        if (!navigator.geolocation) {
            callback();
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                callback();
            },
            function(err) {
                console.warn('[RouteMap] GPS error:', err.message || err.code);
                callback();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 120000 }
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
                        map.fitBounds(result.routes[0].bounds, { top: 60, right: 40, bottom: 200, left: 40 });
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

                // Store optimized order for external maps
                optimizedStops = orderedStopIndices.map(function(i) { return stops[i]; });

                // Place markers along the route with correct numbering
                var markerNum = 1;

                // Origin stop marker (if origin is a stop)
                if (!originIsUserGPS) {
                    placeMarkerAt(legs[0].start_location, stops[0], 0, currentIndex === 0);
                    markerNum++;
                }

                // Each leg ends at a stop
                for (var li = 0; li < legs.length; li++) {
                    var stopOrigIdx;
                    if (li < waypointOrder.length) {
                        stopOrigIdx = waypointStopIndices[waypointOrder[li]];
                    } else if (li < waypointStopIndices.length && waypointOrder.length === 0) {
                        stopOrigIdx = waypointStopIndices[li];
                    } else {
                        stopOrigIdx = destIdx;
                    }

                    if (stopOrigIdx >= 0 && stopOrigIdx < stops.length) {
                        placeMarkerAt(
                            legs[li].end_location,
                            stops[stopOrigIdx],
                            stopOrigIdx,
                            stopOrigIdx === currentIndex
                        );
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

            // Navigation arrow icon (same as the external link concept but for single stop)
            var goBtn = '<button type="button" class="mw-mv-card-go" data-address="' + escAttr(stop.address) + '" aria-label="Navigate to this stop">' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>' +
                '</button>';

            card.innerHTML =
                '<div class="mw-mv-card-accent" style="background:' + color + '"></div>' +
                '<div class="mw-mv-card-body">' +
                    '<div class="mw-mv-card-row1">' +
                        '<span class="mw-mv-card-number">' + (idx + 1) + '</span>' +
                        '<span class="mw-mv-card-service">' + escHtml(serviceLabel) + '</span>' +
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
                        goBtn +
                    '</div>' +
                '</div>';

            trackEl.appendChild(card);
        });

        // Wire up Go buttons
        trackEl.querySelectorAll('.mw-mv-card-go').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var addr = this.getAttribute('data-address');
                if (addr) {
                    window.open('https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addr) + '&travelmode=driving', '_blank');
                }
            });
        });

        positionTrack(currentIndex, false);
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
        var stops = getStops();
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

        trayEl.addEventListener('touchstart', function(e) {
            if (e.touches.length !== 1) return;
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
            if (!isDragging) return;
            isDragging = false;

            var endX = e.changedTouches[0].clientX;
            var dx = endX - startX;

            if (dx < -threshold && currentIndex < getStops().length - 1) {
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
        document.querySelectorAll('.mw-mc-btn-route').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var card = btn.closest('.mw-mc-card');
                if (!card) return;
                var stopId = parseInt(card.dataset.stopId, 10);
                if (stopId) {
                    openToStop(stopId);
                }
            });
        });
    }

    // ═══════════════════════════════════════════════════
    //  OPEN IN EXTERNAL GOOGLE MAPS
    // ═══════════════════════════════════════════════════

    function openExternal() {
        var stops = optimizedStops || getStops();
        if (!stops.length) return;

        // Build a multi-stop Google Maps directions URL
        // Format: /maps/dir/?api=1&origin=...&destination=...&waypoints=...|...
        var url;
        if (stops.length === 1) {
            url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(stops[0].address)
                + '&travelmode=driving';
        } else {
            // Destination = last stop
            var dest = stops[stops.length - 1];
            var waypointAddrs = [];
            for (var i = 0; i < stops.length - 1; i++) {
                waypointAddrs.push(stops[i].address);
            }
            url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(dest.address)
                + '&waypoints=' + encodeURIComponent(waypointAddrs.join('|'))
                + '&travelmode=driving';
        }

        window.open(url, '_blank');
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

    return {
        toggle: toggle,
        openToStop: openToStop,
        close: close
    };

})();
