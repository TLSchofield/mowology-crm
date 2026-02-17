/**
 * Day View Map — Embedded Route Map with Pin-as-First
 *
 * Inline map panel (not overlay) for the schedule day view.
 * Shows assigned stops as numbered route markers, unassigned as gray markers.
 * Supports "pin as 1st stop" to fix a stop at the start of the route.
 *
 * Depends on:
 *   - Google Maps JS API (loaded via $extraHead with &libraries=geometry)
 *   - MW_DAY_VIEW_STOPS global (set by schedule.php)
 *
 * @package Mowology CRM
 */
var MwDayViewMap = (function() {
    'use strict';

    // ── State ──
    var map = null;
    var directionsService = null;
    var directionsRenderer = null;
    var userMarker = null;
    var stopMarkers = [];
    var pinnedStopId = null;
    var userLat = null;
    var userLng = null;
    var optimizedStops = null;

    // ── DOM refs ──
    var mapEl, titleEl, externalBtn;

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
        mapEl = document.getElementById('mwDvMap');
        titleEl = document.getElementById('mwDvMapTitle');
        externalBtn = document.getElementById('mwDvMapExternal');

        if (!mapEl) return;

        if (externalBtn) externalBtn.addEventListener('click', openExternal);
        wirePinButtons();
        wireCardHighlight();

        waitForMaps(function() {
            initMap();
            getGPS(function() {
                computeRoute();
            });
        });
    }

    function waitForMaps(cb) {
        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
            cb();
            return;
        }
        var check = setInterval(function() {
            if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                clearInterval(check);
                cb();
            }
        }, 200);
    }

    // ═══════════════════════════════════════════════════
    //  MAP INIT
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
    }

    // ═══════════════════════════════════════════════════
    //  GPS
    // ═══════════════════════════════════════════════════

    function getGPS(callback) {
        if (userLat && userLng) { callback(); return; }
        if (!navigator.geolocation) { callback(); return; }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                callback();
            },
            function() { callback(); },
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 120000 }
        );
    }

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

    // ═══════════════════════════════════════════════════
    //  ROUTE COMPUTATION
    // ═══════════════════════════════════════════════════

    function computeRoute() {
        var allStops = getStops();
        var assigned = allStops.filter(function(s) { return s.assigned; });
        var unassigned = allStops.filter(function(s) { return !s.assigned; });

        clearMarkers();
        optimizedStops = null;

        // Show user location
        var hasGPS = !!(userLat && userLng);
        if (hasGPS) addUserMarker(userLat, userLng);

        // Show unassigned as gray markers (not on route)
        unassigned.forEach(function(stop) {
            if (stop.lat && stop.lng) {
                placeMarker(new google.maps.LatLng(stop.lat, stop.lng), stop, null, false, true);
            }
        });

        if (assigned.length === 0) {
            titleEl.textContent = unassigned.length > 0 ? unassigned.length + ' unassigned stop' + (unassigned.length !== 1 ? 's' : '') : 'No stops';
            clearRoute();
            fitBoundsAll(unassigned);
            return;
        }

        if (assigned.length === 1) {
            singleStopRoute(assigned[0], hasGPS);
            return;
        }

        // ── Multi-stop route ──
        multiStopRoute(assigned, hasGPS);
    }

    function singleStopRoute(stop, hasGPS) {
        optimizedStops = [stop];

        if (hasGPS && stop.lat && stop.lng) {
            titleEl.textContent = 'Calculating route...';
            directionsService.route({
                origin: new google.maps.LatLng(userLat, userLng),
                destination: new google.maps.LatLng(stop.lat, stop.lng),
                travelMode: google.maps.TravelMode.DRIVING
            }, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    directionsRenderer.setDirections(result);
                    var leg = result.routes[0].legs[0];
                    placeMarker(leg.end_location, stop, 0, true, false);
                    titleEl.textContent = '1 stop \u00b7 ' + leg.duration.text + ' \u00b7 ' + leg.distance.text;
                    map.fitBounds(result.routes[0].bounds, { top: 40, right: 40, bottom: 40, left: 40 });
                } else {
                    showStopMarkerOnly(stop, 0);
                }
            });
        } else {
            showStopMarkerOnly(stop, 0);
        }
    }

    function showStopMarkerOnly(stop, idx) {
        clearRoute();
        if (stop.lat && stop.lng) {
            var pos = new google.maps.LatLng(stop.lat, stop.lng);
            placeMarker(pos, stop, idx, true, false);
            map.setCenter(pos);
            map.setZoom(14);
        }
        titleEl.textContent = '1 stop';
    }

    function multiStopRoute(assigned, hasGPS) {
        var origin, destination, waypoints = [], waypointStops = [];
        var pinnedStop = null;
        var otherStops = [];

        // Separate pinned from rest
        if (pinnedStopId) {
            for (var i = 0; i < assigned.length; i++) {
                if (assigned[i].stopId === pinnedStopId) {
                    pinnedStop = assigned[i];
                } else {
                    otherStops.push(assigned[i]);
                }
            }
            // If pinned stop not found in assigned, ignore pin
            if (!pinnedStop) {
                otherStops = assigned;
            }
        } else {
            otherStops = assigned;
        }

        if (pinnedStop) {
            // Pinned stop is origin, optimize the rest
            origin = stopToLatLng(pinnedStop);
            if (!origin) { titleEl.textContent = 'Missing location data'; return; }

            // Find farthest from pinned as destination
            var fIdx = findFarthest(otherStops, pinnedStop.lat, pinnedStop.lng);
            var destStop = otherStops[fIdx];
            destination = stopToLatLng(destStop);

            // Remaining as optimizable waypoints
            for (var j = 0; j < otherStops.length; j++) {
                if (j !== fIdx) {
                    var loc = stopToLatLng(otherStops[j]);
                    if (loc) {
                        waypoints.push({ location: loc, stopover: true });
                        waypointStops.push(otherStops[j]);
                    }
                }
            }

            // Build optimized order: pinned first, then Google-optimized rest
            fireDirections(origin, destination, waypoints, waypointStops, destStop, pinnedStop, otherStops, false);

        } else if (hasGPS) {
            // No pin, GPS available: origin = GPS, optimize all
            origin = new google.maps.LatLng(userLat, userLng);

            var fIdx = findFarthest(assigned, userLat, userLng);
            var destStop = assigned[fIdx];
            destination = stopToLatLng(destStop);

            for (var j = 0; j < assigned.length; j++) {
                if (j !== fIdx) {
                    var loc = stopToLatLng(assigned[j]);
                    if (loc) {
                        waypoints.push({ location: loc, stopover: true });
                        waypointStops.push(assigned[j]);
                    }
                }
            }

            fireDirections(origin, destination, waypoints, waypointStops, destStop, null, assigned, true);

        } else {
            // No pin, no GPS: first stop = origin, optimize rest
            origin = stopToLatLng(assigned[0]);
            if (!origin) { titleEl.textContent = 'Missing location data'; return; }

            var fIdx = findFarthest(assigned.slice(1), assigned[0].lat, assigned[0].lng);
            // fIdx is relative to slice(1), so actual index = fIdx + 1
            var destStop = assigned[fIdx + 1];
            destination = stopToLatLng(destStop);

            for (var j = 1; j < assigned.length; j++) {
                if (j !== fIdx + 1) {
                    var loc = stopToLatLng(assigned[j]);
                    if (loc) {
                        waypoints.push({ location: loc, stopover: true });
                        waypointStops.push(assigned[j]);
                    }
                }
            }

            fireDirections(origin, destination, waypoints, waypointStops, destStop, assigned[0], assigned, false);
        }
    }

    function fireDirections(origin, destination, waypoints, waypointStops, destStop, firstStop, allAssigned, originIsGPS) {
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
            if (status === google.maps.DirectionsStatus.OK) {
                directionsRenderer.setDirections(result);

                var legs = result.routes[0].legs;
                var waypointOrder = result.routes[0].waypoint_order || [];

                // Build optimized stop order
                var ordered = [];

                // If there's a fixed first stop (pinned or first-stop-as-origin)
                if (firstStop && !originIsGPS) {
                    ordered.push(firstStop);
                }

                // Waypoints in optimized order
                if (waypointOrder.length > 0) {
                    for (var oi = 0; oi < waypointOrder.length; oi++) {
                        ordered.push(waypointStops[waypointOrder[oi]]);
                    }
                } else {
                    for (var si = 0; si < waypointStops.length; si++) {
                        ordered.push(waypointStops[si]);
                    }
                }

                // Destination last
                ordered.push(destStop);

                optimizedStops = ordered;

                // Place numbered markers
                var legIdx = 0;
                for (var mi = 0; mi < ordered.length; mi++) {
                    var markerPos;
                    if (mi === 0 && !originIsGPS) {
                        markerPos = legs[0].start_location;
                    } else {
                        markerPos = legs[legIdx] ? legs[legIdx].end_location : null;
                        legIdx++;
                    }

                    if (markerPos) {
                        placeMarker(markerPos, ordered[mi], mi, false, false);
                    } else if (ordered[mi].lat && ordered[mi].lng) {
                        placeMarker(new google.maps.LatLng(ordered[mi].lat, ordered[mi].lng), ordered[mi], mi, false, false);
                    }
                }

                // Compute totals
                var totalDuration = 0;
                var totalDistance = 0;
                for (var l = 0; l < legs.length; l++) {
                    totalDuration += legs[l].duration.value;
                    totalDistance += legs[l].distance.value;
                }
                var durText = totalDuration < 3600
                    ? Math.round(totalDuration / 60) + ' min'
                    : Math.floor(totalDuration / 3600) + ' hr ' + Math.round((totalDuration % 3600) / 60) + ' min';
                var distText = totalDistance < 1000
                    ? totalDistance + ' m'
                    : (totalDistance / 1000).toFixed(1) + ' km';
                titleEl.textContent = ordered.length + ' stops \u00b7 ' + durText + ' \u00b7 ' + distText;

                // Fit bounds
                var bounds = result.routes[0].bounds;
                if (bounds) {
                    map.fitBounds(bounds, { top: 40, right: 40, bottom: 40, left: 40 });
                }
            } else {
                console.warn('[DayViewMap] Directions failed:', status);
                showAllMarkers(allAssigned);
            }
        });
    }

    function showAllMarkers(stops) {
        clearRoute();
        var bounds = new google.maps.LatLngBounds();
        var any = false;

        if (userLat && userLng) {
            addUserMarker(userLat, userLng);
            bounds.extend(new google.maps.LatLng(userLat, userLng));
            any = true;
        }

        stops.forEach(function(stop, idx) {
            if (stop.lat && stop.lng) {
                var pos = new google.maps.LatLng(stop.lat, stop.lng);
                placeMarker(pos, stop, idx, false, false);
                bounds.extend(pos);
                any = true;
            }
        });

        if (any) map.fitBounds(bounds, { top: 40, right: 40, bottom: 40, left: 40 });
        titleEl.textContent = stops.length + ' stops';
    }

    function fitBoundsAll(stops) {
        if (!map) return;
        var bounds = new google.maps.LatLngBounds();
        var any = false;

        if (userLat && userLng) {
            bounds.extend(new google.maps.LatLng(userLat, userLng));
            any = true;
        }
        stops.forEach(function(s) {
            if (s.lat && s.lng) {
                bounds.extend(new google.maps.LatLng(s.lat, s.lng));
                any = true;
            }
        });
        if (any) map.fitBounds(bounds, { top: 40, right: 40, bottom: 40, left: 40 });
    }

    function clearRoute() {
        if (directionsRenderer && map) {
            directionsRenderer.setMap(null);
            directionsRenderer.setMap(map);
        }
    }

    // ═══════════════════════════════════════════════════
    //  MARKERS
    // ═══════════════════════════════════════════════════

    function placeMarker(position, stop, index, isActive, isUnassigned) {
        var color;
        var scale;
        var opacity;

        if (isUnassigned) {
            color = '#9CA3AF';
            scale = 10;
            opacity = 0.4;
        } else if (stop.stopId === pinnedStopId) {
            color = '#e85d04';
            scale = 16;
            opacity = 1;
        } else {
            color = serviceColors[stop.serviceType] || '#2D8659';
            scale = isActive ? 16 : 13;
            opacity = isActive ? 1 : 0.7;
        }

        var label = null;
        if (index !== null) {
            label = {
                text: String(index + 1),
                color: '#FFFFFF',
                fontSize: scale >= 14 ? '12px' : '10px',
                fontWeight: 'bold'
            };
        }

        var marker = new google.maps.Marker({
            position: position,
            map: map,
            label: label,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: scale,
                fillColor: color,
                fillOpacity: opacity,
                strokeColor: '#FFFFFF',
                strokeWeight: 2
            },
            title: stop.address || '',
            zIndex: isActive ? 100 : (isUnassigned ? 1 : 10)
        });

        // Click marker → highlight card
        marker.addListener('click', function() {
            highlightCard(stop.stopId);
        });

        stopMarkers.push({ marker: marker, stopId: stop.stopId });
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
        stopMarkers.forEach(function(sm) { sm.marker.setMap(null); });
        stopMarkers = [];
        if (userMarker) {
            userMarker.setMap(null);
            userMarker = null;
        }
        clearRoute();
    }

    // ═══════════════════════════════════════════════════
    //  PIN AS 1ST STOP
    // ═══════════════════════════════════════════════════

    function wirePinButtons() {
        document.querySelectorAll('.mw-dv-pin-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var stopId = parseInt(btn.dataset.stopId, 10);

                if (pinnedStopId === stopId) {
                    pinnedStopId = null;
                    updatePinUI(null);
                } else {
                    pinnedStopId = stopId;
                    updatePinUI(stopId);
                }

                computeRoute();
            });
        });
    }

    function updatePinUI(activeStopId) {
        document.querySelectorAll('.mw-dv-pin-btn').forEach(function(btn) {
            var id = parseInt(btn.dataset.stopId, 10);
            var card = btn.closest('.mw-dv-card');

            if (id === activeStopId) {
                btn.classList.add('mw-dv-pin-active');
                card.classList.add('mw-dv-card-pinned');
                // Add badge
                if (!card.querySelector('.mw-dv-pin-badge')) {
                    var badge = document.createElement('span');
                    badge.className = 'mw-dv-pin-badge';
                    badge.textContent = '#1';
                    btn.parentNode.insertBefore(badge, btn);
                }
            } else {
                btn.classList.remove('mw-dv-pin-active');
                card.classList.remove('mw-dv-card-pinned');
                var badge = card.querySelector('.mw-dv-pin-badge');
                if (badge) badge.remove();
            }
        });
    }

    // ═══════════════════════════════════════════════════
    //  CARD-MAP INTERACTION
    // ═══════════════════════════════════════════════════

    function wireCardHighlight() {
        document.querySelectorAll('.mw-dv-card').forEach(function(card) {
            card.addEventListener('mouseenter', function() {
                var stopId = parseInt(card.dataset.stopId, 10);
                highlightMarker(stopId);
            });
            card.addEventListener('mouseleave', function() {
                unhighlightMarkers();
            });
        });
    }

    function highlightMarker(stopId) {
        stopMarkers.forEach(function(sm) {
            var icon = sm.marker.getIcon();
            if (sm.stopId === stopId) {
                icon.scale = 18;
                icon.fillOpacity = 1;
                sm.marker.setIcon(icon);
                sm.marker.setZIndex(200);
            } else {
                icon.scale = icon.scale > 14 ? icon.scale : 11;
                icon.fillOpacity = 0.5;
                sm.marker.setIcon(icon);
                sm.marker.setZIndex(10);
            }
        });
    }

    function unhighlightMarkers() {
        stopMarkers.forEach(function(sm) {
            var icon = sm.marker.getIcon();
            if (sm.stopId === pinnedStopId) {
                icon.scale = 16;
                icon.fillOpacity = 1;
            } else {
                icon.scale = 13;
                icon.fillOpacity = 0.7;
            }
            sm.marker.setIcon(icon);
            sm.marker.setZIndex(10);
        });
    }

    function highlightCard(stopId) {
        // Scroll card into view and flash highlight
        var card = document.querySelector('.mw-dv-card[data-stop-id="' + stopId + '"]');
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            card.classList.add('mw-dv-card-highlight');
            setTimeout(function() {
                card.classList.remove('mw-dv-card-highlight');
            }, 1500);
        }
    }

    // ═══════════════════════════════════════════════════
    //  EXTERNAL GOOGLE MAPS
    // ═══════════════════════════════════════════════════

    function openExternal() {
        var stops = optimizedStops || getStops().filter(function(s) { return s.assigned; });
        if (!stops.length) return;

        var url;
        if (stops.length === 1) {
            url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(stops[0].address)
                + '&travelmode=driving';
        } else {
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

        if (url) window.open(url, '_blank');
    }

    // ═══════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════

    function getStops() {
        return (typeof MW_DAY_VIEW_STOPS !== 'undefined' && Array.isArray(MW_DAY_VIEW_STOPS))
            ? MW_DAY_VIEW_STOPS
            : [];
    }

    function stopToLatLng(stop) {
        if (stop && stop.lat && stop.lng) {
            return new google.maps.LatLng(stop.lat, stop.lng);
        }
        return stop ? stop.address : null;
    }

    function findFarthest(stops, fromLat, fromLng) {
        if (!stops.length) return 0;
        var maxDist = -1;
        var fIdx = 0;
        for (var i = 0; i < stops.length; i++) {
            if (stops[i].lat && stops[i].lng) {
                var dist = haversine(fromLat, fromLng, stops[i].lat, stops[i].lng);
                if (dist > maxDist) {
                    maxDist = dist;
                    fIdx = i;
                }
            }
        }
        return fIdx;
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
        recompute: computeRoute,
        getPinnedStopId: function() { return pinnedStopId; }
    };

})();
