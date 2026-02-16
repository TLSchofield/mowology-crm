/**
 * Schedule Route Map — Map View with Swipeable Cards
 *
 * Full-screen map view activated from the mobile schedule.
 * Shows Google Maps directions to each stop with a swipeable
 * card carousel at the bottom. Uses DirectionsService with
 * optimizeWaypoints for full route optimization.
 *
 * Entry points:
 *   MwRouteMap.toggle()       — opens map view with next upcoming stop
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

    // Only run on mobile
    if (window.innerWidth > 991) return { toggle: function(){}, openToStop: function(){} };

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
        } else {
            open(0);
        }
    }

    function open(stopIndex) {
        if (typeof stopIndex !== 'number') stopIndex = 0;
        var stops = getStops();
        if (stops.length === 0) return;
        if (stopIndex >= stops.length) stopIndex = 0;

        currentIndex = stopIndex;
        isOpen = true;

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
                        getGPSAndRoute(currentIndex);
                    }
                }, 200);
                return;
            }
            initMap();
        } else {
            google.maps.event.trigger(map, 'resize');
        }

        getGPSAndRoute(currentIndex);
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
    //  GPS + ROUTE COMPUTATION
    // ═══════════════════════════════════════════════════

    function getGPSAndRoute(targetIdx) {
        viewEl.classList.add('mw-mv-loading');
        titleEl.textContent = 'Getting location...';

        // If we already have GPS cached, use it
        if (userLat && userLng) {
            computeRoute(targetIdx);
            return;
        }

        if (!navigator.geolocation) {
            computeRoute(targetIdx);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                computeRoute(targetIdx);
            },
            function(err) {
                console.warn('[RouteMap] GPS error:', err.message || err.code);
                computeRoute(targetIdx);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 120000 }
        );
    }

    function computeRoute(targetIdx) {
        var stops = getStops();
        if (!stops.length || !map) {
            viewEl.classList.remove('mw-mv-loading');
            return;
        }

        clearMarkers();

        var target = stops[targetIdx];
        if (!target) target = stops[0];

        var hasGPS = !!(userLat && userLng);

        // Build destination (address string works for DirectionsService even without lat/lng)
        var destination;
        if (target.lat && target.lng) {
            destination = new google.maps.LatLng(target.lat, target.lng);
        } else if (target.address) {
            destination = target.address;
        } else {
            viewEl.classList.remove('mw-mv-loading');
            titleEl.textContent = 'No address';
            return;
        }

        // Build origin
        var origin;
        if (hasGPS) {
            origin = new google.maps.LatLng(userLat, userLng);
            addUserMarker(userLat, userLng);
        } else if (stops.length > 1 && targetIdx > 0) {
            var prev = stops[targetIdx - 1];
            origin = (prev.lat && prev.lng)
                ? new google.maps.LatLng(prev.lat, prev.lng)
                : prev.address;
        } else {
            // No GPS, single stop — geocode the address to at least show it on the map
            geocodeAndShow(target, targetIdx);
            return;
        }

        // Build request
        var request = {
            origin: origin,
            destination: destination,
            travelMode: google.maps.TravelMode.DRIVING
        };

        // Multi-stop waypoint optimization
        if (hasGPS && stops.length > 1) {
            var waypoints = [];
            for (var w = 0; w < stops.length; w++) {
                if (w === targetIdx) continue;
                var ws = stops[w];
                var loc = (ws.lat && ws.lng)
                    ? new google.maps.LatLng(ws.lat, ws.lng)
                    : ws.address;
                if (loc) {
                    waypoints.push({ location: loc, stopover: true });
                }
            }
            if (waypoints.length > 0) {
                request.waypoints = waypoints;
                request.optimizeWaypoints = true;
            }
        }

        titleEl.textContent = 'Calculating route...';

        directionsService.route(request, function(result, status) {
            viewEl.classList.remove('mw-mv-loading');

            if (status === google.maps.DirectionsStatus.OK) {
                directionsRenderer.setDirections(result);

                // Place markers using the route legs (these have resolved lat/lng even for address-only stops)
                var legs = result.routes[0].legs;
                placeRouteMarkers(stops, targetIdx, legs, hasGPS);

                // ETA display
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
                titleEl.textContent = durationText + ' \u00b7 ' + distanceText;

                // Fit to route bounds
                var bounds = result.routes[0].bounds;
                if (bounds) {
                    map.fitBounds(bounds, { top: 20, right: 20, bottom: 20, left: 20 });
                }
            } else {
                console.warn('[RouteMap] Directions failed:', status);
                // Fallback: try to geocode and show the destination at least
                geocodeAndShow(target, targetIdx);
            }
        });
    }

    /**
     * Fallback when no route can be drawn:
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
                titleEl.textContent = stop.address.split(',')[0]; // Show street only
            } else {
                console.warn('[RouteMap] Geocode failed:', status);
                titleEl.textContent = 'Could not find address';
            }
        });
    }

    // ═══════════════════════════════════════════════════
    //  MARKERS
    // ═══════════════════════════════════════════════════

    /**
     * Place markers using the resolved positions from DirectionsService legs.
     * This works even when stops only have addresses (no lat/lng) because the
     * Directions API resolves addresses to coordinates in its response.
     */
    function placeRouteMarkers(stops, targetIdx, legs, hasGPS) {
        // legs[0].start_location = origin
        // legs[0].end_location = first waypoint or destination
        // legs[n].end_location = next waypoint or final destination

        // Collect all resolved positions from the route
        // If origin is user GPS, the stop positions start at legs[0].end_location
        // If origin is a stop, positions start at legs[0].start_location

        if (hasGPS) {
            // Origin is user GPS → first stop is at legs[0].end_location
            // But with waypoints, order may be optimized
            // Simplest: place marker at the destination end of the last leg
            var lastLeg = legs[legs.length - 1];
            placeMarkerAt(lastLeg.end_location, stops[targetIdx], targetIdx, true);

            // Place other stops at intermediate leg endpoints
            // This is approximate when waypoints are reordered, but gives good placement
            for (var i = 0; i < stops.length; i++) {
                if (i === targetIdx) continue;
                if (stops[i].lat && stops[i].lng) {
                    placeMarkerAt(
                        new google.maps.LatLng(stops[i].lat, stops[i].lng),
                        stops[i], i, false
                    );
                } else if (legs.length > 1 && i < legs.length) {
                    // Use leg endpoint as approximate position
                    placeMarkerAt(legs[i].end_location, stops[i], i, false);
                }
            }
        } else {
            // Origin is a stop, destination is the target
            placeMarkerAt(legs[legs.length - 1].end_location, stops[targetIdx], targetIdx, true);
            if (legs.length > 0) {
                placeMarkerAt(legs[0].start_location, stops[targetIdx > 0 ? targetIdx - 1 : 0], targetIdx > 0 ? targetIdx - 1 : 0, false);
            }
        }
    }

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

        stops.forEach(function(stop, idx) {
            var card = document.createElement('div');
            card.className = 'mw-mv-card';
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
                    (durStr ? '<span class="mw-mv-card-dur">' + escHtml(durStr) + '</span>' : '') +
                '</div>';

            trackEl.appendChild(card);
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
        computeRoute(idx);
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
        var stops = getStops();
        if (!stops.length) return;

        var target = stops[currentIndex] || stops[0];
        var url = 'https://maps.google.com/maps/dir/?api=1'
            + '&destination=' + encodeURIComponent(target.address)
            + '&travelmode=driving';

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
