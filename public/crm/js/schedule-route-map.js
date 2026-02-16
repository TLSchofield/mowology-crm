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
    var directionsService = null;
    var directionsRenderer = null;
    var userMarker = null;
    var stopMarkers = [];
    var isOpen = false;
    var mapInitialized = false;
    var currentIndex = 0;        // active card in carousel
    var optimizedRoute = null;   // cached DirectionsResult
    var optimizedOrder = null;   // waypoint_order from Google
    var userLat = null;
    var userLng = null;

    // ── DOM refs (cached on init) ──
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

        // Back button
        backBtn.addEventListener('click', close);

        // External link button
        externalBtn.addEventListener('click', openExternal);

        // Wire up card taps in the schedule scroll area to open map view
        wireCardTaps();

        // Setup touch swipe on the card track
        setupSwipe();
    }

    // ═══════════════════════════════════════════════════
    //  TOGGLE / OPEN / CLOSE
    // ═══════════════════════════════════════════════════

    function toggle() {
        if (isOpen) {
            close();
        } else {
            open(0); // open to first (next upcoming) stop
        }
    }

    function open(stopIndex) {
        if (typeof stopIndex !== 'number') stopIndex = 0;
        var stops = getStops();
        if (stops.length === 0) return;
        if (stopIndex >= stops.length) stopIndex = 0;

        currentIndex = stopIndex;
        isOpen = true;

        // Build carousel cards
        buildCarousel(stops);
        updateDots(stops.length, currentIndex);

        // Show the view
        viewEl.classList.add('mw-mv-visible');
        document.body.style.overflow = 'hidden';

        // Initialize map (lazy, first open only)
        if (!mapInitialized) {
            if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                // Maps API not loaded yet — wait
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
            center: { lat: 49.2827, lng: -123.1207 }, // Vancouver default
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

        if (userLat && userLng) {
            computeRoute(targetIdx);
            return;
        }

        if (!navigator.geolocation) {
            userLat = null;
            userLng = null;
            computeRoute(targetIdx);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                computeRoute(targetIdx);
            },
            function() {
                userLat = null;
                userLng = null;
                computeRoute(targetIdx);
            },
            { enableHighAccuracy: true, timeout: 6000, maximumAge: 60000 }
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

        // Determine origin
        var origin;
        if (userLat && userLng) {
            origin = new google.maps.LatLng(userLat, userLng);
            addUserMarker(userLat, userLng);
        } else {
            // No GPS — use map center as a fallback, skip directions, just show markers
            showMarkersOnly(stops, targetIdx);
            return;
        }

        // Destination = the currently focused stop
        var destination;
        if (target.lat && target.lng) {
            destination = new google.maps.LatLng(target.lat, target.lng);
        } else if (target.address) {
            destination = target.address;
        } else {
            showMarkersOnly(stops, targetIdx);
            return;
        }

        // If only going to one stop, simple A→B directions
        var request = {
            origin: origin,
            destination: destination,
            travelMode: google.maps.TravelMode.DRIVING
        };

        // If showing full route (Route button), add all stops as waypoints
        // For single-card tap, just show directions to that one stop
        // We always show directions to the focused stop from user location

        directionsService.route(request, function(result, status) {
            viewEl.classList.remove('mw-mv-loading');

            if (status === google.maps.DirectionsStatus.OK) {
                directionsRenderer.setDirections(result);

                // Add destination marker
                addStopMarker(target, targetIdx, true);

                // Add faded markers for other stops
                for (var i = 0; i < stops.length; i++) {
                    if (i !== targetIdx) {
                        addStopMarker(stops[i], i, false);
                    }
                }

                // Update title with ETA
                var leg = result.routes[0].legs[0];
                if (leg) {
                    titleEl.textContent = leg.duration.text + ' \u00b7 ' + leg.distance.text;
                }

                // Fit map to route bounds
                var bounds = result.routes[0].bounds;
                if (bounds) {
                    map.fitBounds(bounds, { top: 20, right: 20, bottom: 20, left: 20 });
                }
            } else {
                // Fallback: show markers without route line
                showMarkersOnly(stops, targetIdx);
            }
        });
    }

    function showMarkersOnly(stops, activeIdx) {
        viewEl.classList.remove('mw-mv-loading');

        if (directionsRenderer) {
            directionsRenderer.setMap(null);
            directionsRenderer.setMap(map);
        }

        var bounds = new google.maps.LatLngBounds();
        var hasPoints = false;

        if (userLat && userLng) {
            addUserMarker(userLat, userLng);
            bounds.extend(new google.maps.LatLng(userLat, userLng));
            hasPoints = true;
        }

        for (var i = 0; i < stops.length; i++) {
            if (stops[i].lat && stops[i].lng) {
                addStopMarker(stops[i], i, i === activeIdx);
                bounds.extend(new google.maps.LatLng(stops[i].lat, stops[i].lng));
                hasPoints = true;
            }
        }

        if (hasPoints) {
            map.fitBounds(bounds, { top: 30, right: 30, bottom: 30, left: 30 });
        }

        titleEl.textContent = 'Route';
    }

    // ═══════════════════════════════════════════════════
    //  MARKERS
    // ═══════════════════════════════════════════════════

    function addStopMarker(stop, index, isActive) {
        if (!stop.lat || !stop.lng) return;

        var color = isActive ? (serviceColors[stop.serviceType] || '#2D8659') : '#9CA3AF';
        var scale = isActive ? 16 : 12;

        var marker = new google.maps.Marker({
            position: { lat: stop.lat, lng: stop.lng },
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
        // Clear previous directions
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

            // Duration display
            var durStr = '';
            if (stop.duration > 0) {
                if (stop.duration >= 60) {
                    durStr = Math.floor(stop.duration / 60) + 'h' + (stop.duration % 60 > 0 ? ' ' + (stop.duration % 60) + 'm' : '');
                } else {
                    durStr = stop.duration + ' min';
                }
            }

            // Service label
            var serviceLabel = stop.planTitle || (stop.serviceType ? stop.serviceType.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }) : 'Service');

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
                        escHtml(stop.address) +
                    '</div>' +
                    (stop.contactName ? '<div class="mw-mv-card-client">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' +
                        escHtml(stop.contactName) +
                    '</div>' : '') +
                    (durStr ? '<span class="mw-mv-card-dur">' + escHtml(durStr) + '</span>' : '') +
                '</div>';

            trackEl.appendChild(card);
        });

        // Position track to current card
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
        // Each card is ~calc(100% - 32px) wide with 16px gap
        // We want the active card centered
        var cards = trackEl.querySelectorAll('.mw-mv-card');
        if (!cards.length) return;

        var cardWidth = cards[0].offsetWidth;
        var gap = 12;
        var trayWidth = trayEl.offsetWidth;
        var offset = idx * (cardWidth + gap);
        var center = (trayWidth - cardWidth) / 2;
        var translate = center - offset;

        // Clamp so we don't scroll past edges
        var maxTranslate = center;
        var minTranslate = trayWidth - (cards.length * (cardWidth + gap) - gap) - (trayWidth - cardWidth) / 2;
        if (cards.length === 1) minTranslate = center;
        translate = Math.min(maxTranslate, Math.max(minTranslate, translate));

        trackEl.style.transition = animate ? 'transform 0.3s ease' : 'none';
        trackEl.style.transform = 'translateX(' + translate + 'px)';

        // Update active states
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

            // Get current transform
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

            // Only start dragging if horizontal movement dominates
            if (!isDragging) {
                if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
                    isDragging = true;
                } else if (Math.abs(dy) > Math.abs(dx)) {
                    return; // vertical scroll, ignore
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
        // Wire the individual "Route" buttons inside each card's expanded detail
        document.querySelectorAll('.mw-mc-btn-route').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Find the parent card and its stop ID
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
