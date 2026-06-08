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
    var geocoder = null;
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

        clearMarkers();
        optimizedStops = null;

        // Geocode any stops missing lat/lng, then proceed
        geocodeMissing(allStops, function() {
            var assigned = allStops.filter(function(s) { return s.assigned; });
            var unassigned = allStops.filter(function(s) { return !s.assigned; });

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
                placePurchaseTaskMarkers();
                return;
            }

            if (assigned.length === 1) {
                singleStopRoute(assigned[0], hasGPS);
                return;
            }

            // ── Multi-stop route ──
            multiStopRoute(assigned, hasGPS);
        });
    }

    /**
     * Geocode stops that have an address but no lat/lng.
     * Processes sequentially to respect API rate limits, then calls callback.
     */
    function geocodeMissing(stops, callback) {
        var missing = stops.filter(function(s) { return (!s.lat || !s.lng) && s.address; });
        if (missing.length === 0 || !geocoder) { callback(); return; }

        var idx = 0;
        function next() {
            if (idx >= missing.length) { callback(); return; }
            var stop = missing[idx];
            geocoder.geocode({ address: stop.address }, function(results, status) {
                if (status === google.maps.GeocoderStatus.OK && results[0]) {
                    stop.lat = results[0].geometry.location.lat();
                    stop.lng = results[0].geometry.location.lng();
                }
                idx++;
                next();
            });
        }
        next();
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
                    placePurchaseTaskMarkers();
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
        placePurchaseTaskMarkers();
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

                // Reorder cards to match optimized route
                reorderCards(ordered);
                placePurchaseTaskMarkers();
            } else {
                console.warn('[DayViewMap] Directions failed:', status);
                showAllMarkersSorted(allAssigned);
            }
        });
    }

    /**
     * Nearest-neighbor sort: order stops by proximity starting from
     * user GPS (or first stop if no GPS). Used as fallback when
     * Directions API is unavailable.
     */
    function nearestNeighborSort(stops, startLat, startLng) {
        if (stops.length <= 1) return stops.slice();
        var remaining = stops.slice();
        var sorted = [];
        var curLat = startLat;
        var curLng = startLng;

        while (remaining.length > 0) {
            var bestIdx = 0;
            var bestDist = Infinity;
            for (var i = 0; i < remaining.length; i++) {
                if (remaining[i].lat && remaining[i].lng) {
                    var d = haversine(curLat, curLng, remaining[i].lat, remaining[i].lng);
                    if (d < bestDist) {
                        bestDist = d;
                        bestIdx = i;
                    }
                }
            }
            var next = remaining.splice(bestIdx, 1)[0];
            sorted.push(next);
            if (next.lat && next.lng) {
                curLat = next.lat;
                curLng = next.lng;
            }
        }
        return sorted;
    }

    function showAllMarkersSorted(stops) {
        clearRoute();

        // Sort by nearest-neighbor for optimized display order
        var startLat = userLat || (stops[0] && stops[0].lat) || 49.2827;
        var startLng = userLng || (stops[0] && stops[0].lng) || -123.1207;
        var sorted = nearestNeighborSort(stops, startLat, startLng);
        optimizedStops = sorted;

        var bounds = new google.maps.LatLngBounds();
        var any = false;

        if (userLat && userLng) {
            addUserMarker(userLat, userLng);
            bounds.extend(new google.maps.LatLng(userLat, userLng));
            any = true;
        }

        sorted.forEach(function(stop, idx) {
            if (stop.lat && stop.lng) {
                var pos = new google.maps.LatLng(stop.lat, stop.lng);
                placeMarker(pos, stop, idx, false, false);
                bounds.extend(pos);
                any = true;
            }
        });

        placePurchaseTaskMarkers();

        if (window._mwPurchaseMarkers) {
            window._mwPurchaseMarkers.forEach(function(m) {
                var p = m.getPosition();
                if (p) { bounds.extend(p); any = true; }
            });
        }

        if (any) map.fitBounds(bounds, { top: 40, right: 40, bottom: 40, left: 40 });
        titleEl.textContent = sorted.length + ' stops';

        // Reorder cards to match proximity-optimized order
        reorderCards(sorted);
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
    //  MARKERS (teardrop pin style — consistent with crew map)
    // ═══════════════════════════════════════════════════

    function createPinIcon(color, label, size, isCompleted) {
        // Teardrop pin SVG matching crew map style
        var w = size || 36;
        var h = Math.round(w * 44 / 36);
        var cx = w / 2;
        var cy = Math.round(w * 16 / 36);
        var r = Math.round(w * 10 / 36);
        var fontSize = Math.round(w * 13 / 36);
        var textY = cy + Math.round(fontSize * 0.38);

        // For completed stops: pale green with a checkmark
        var innerContent;
        if (isCompleted) {
            var tickSize = Math.round(w * 0.3);
            var tx = cx - Math.round(tickSize * 0.5);
            var ty = cy - Math.round(tickSize * 0.15);
            innerContent = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="white" opacity="0.35"/>' +
                '<polyline points="' + tx + ',' + ty + ' ' + (tx + Math.round(tickSize * 0.35)) + ',' + (ty + Math.round(tickSize * 0.4)) + ' ' + (tx + tickSize) + ',' + (ty - Math.round(tickSize * 0.3)) + '" fill="none" stroke="white" stroke-width="' + Math.max(2, Math.round(w / 12)) + '" stroke-linecap="round" stroke-linejoin="round"/>';
        } else {
            innerContent = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="white" opacity="0.3"/>' +
                '<text x="' + cx + '" y="' + textY + '" text-anchor="middle" font-size="' + fontSize + '" font-weight="bold" fill="white" font-family="Arial">' + label + '</text>';
        }

        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '">' +
            '<path d="M' + cx + ' 0C' + Math.round(w * 8.06 / 36) + ' 0 0 ' + Math.round(w * 8.06 / 36) + ' 0 ' + cx + 'c0 ' + Math.round(w * 11.25 / 36) + ' ' + cx + ' ' + Math.round(w * 26 / 36) + ' ' + cx + ' ' + Math.round(w * 26 / 36) + 's' + cx + '-' + Math.round(w * 14.75 / 36) + ' ' + cx + '-' + Math.round(w * 26 / 36) + 'C' + w + ' ' + Math.round(w * 8.06 / 36) + ' ' + Math.round(w * 27.94 / 36) + ' 0 ' + cx + ' 0z" fill="' + color + '" stroke="white" stroke-width="2"/>' +
            innerContent +
            '</svg>';

        return {
            url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
            scaledSize: new google.maps.Size(w, h),
            anchor: new google.maps.Point(w / 2, h)
        };
    }

    function placeMarker(position, stop, index, isActive, isUnassigned) {
        var color, size, completed = false;

        if (stop.status === 'completed' || stop.isInvoiced) {
            color = '#9CA3AF';  // pale green
            size = 34;
            completed = true;
        } else if (isUnassigned) {
            color = '#9CA3AF';
            size = 30;
        } else if (stop.stopId === pinnedStopId) {
            color = '#e85d04';
            size = 40;
        } else {
            color = serviceColors[stop.serviceType] || '#2D8659';
            size = isActive ? 40 : 36;
        }

        var label = index !== null ? String(index + 1) : '\u2022';
        var icon = createPinIcon(color, label, size, completed);

        var marker = new google.maps.Marker({
            position: position,
            map: map,
            icon: icon,
            title: stop.address || '',
            zIndex: isActive ? 100 : (isUnassigned ? 1 : 10)
        });

        // Click marker → highlight card
        marker.addListener('click', function() {
            highlightCard(stop.stopId);
        });

        stopMarkers.push({
            marker: marker,
            stopId: stop.stopId,
            serviceType: stop.serviceType || '',
            label: label,
            isUnassigned: !!isUnassigned,
            isCompleted: completed
        });
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
        // Clear purchase task markers
        if (window._mwPurchaseMarkers) {
            window._mwPurchaseMarkers.forEach(function(m) { m.setMap(null); });
        }
        window._mwPurchaseMarkers = [];
        clearRoute();
    }

    // ═══════════════════════════════════════════════════
    //  PURCHASE TASK PINS
    // ═══════════════════════════════════════════════════

    function placePurchaseTaskMarkers() {
        if (!map) return;
        if (!window.MW_SCHEDULE_STATE || !Array.isArray(MW_SCHEDULE_STATE.purchaseTasks)) return;

        window._mwPurchaseMarkers = window._mwPurchaseMarkers || [];

        var statusLabels = { requested: 'Requested', assigned: 'Assigned', en_route: 'En Route', at_vendor: 'At Vendor', purchased: 'Purchased', delivered: 'Delivered', verified: 'Verified' };

        MW_SCHEDULE_STATE.purchaseTasks.forEach(function(task) {
            if (!task.lat || !task.lng) return;

            var pos = new google.maps.LatLng(task.lat, task.lng);
            var icon = createPinIcon('#e85d04', '\uD83D\uDED2', 34, false);

            // Use 🛒 symbol — falls back gracefully to P if SVG text renders oddly
            var svgLabel = 'P';
            icon = createPinIcon('#e85d04', svgLabel, 34, false);

            var statusLabel = statusLabels[task.purchase_status] || task.purchase_status || '';
            var infoContent =
                '<div style="font-family:sans-serif;font-size:13px;max-width:200px;line-height:1.5">' +
                '<strong style="color:#e85d04">' + (task.task_number || 'Purchase Task') + '</strong><br>' +
                '<strong>' + (task.vendor_name || '') + '</strong>' +
                (task.location_label ? '<br><span style="color:#666">' + task.location_label + '</span>' : '') +
                '<br><span style="background:#e85d04;color:#fff;padding:1px 6px;border-radius:10px;font-size:11px">' + statusLabel + '</span>' +
                (task.items_count ? '<br>' + task.items_count + ' item' + (task.items_count !== 1 ? 's' : '') : '') +
                (task.estimated_total ? ' &mdash; $' + parseFloat(task.estimated_total).toFixed(2) + ' est.' : '') +
                (task.assigned_to_name ? '<br><span style="color:#555">👤 ' + task.assigned_to_name + '</span>' : '') +
                '</div>';

            var infoWindow = new google.maps.InfoWindow({ content: infoContent });

            var marker = new google.maps.Marker({
                position: pos,
                map: map,
                icon: icon,
                title: (task.vendor_name || 'Purchase Task') + ' — ' + statusLabel,
                zIndex: 50
            });

            marker.addListener('click', function() {
                infoWindow.open(map, marker);
            });

            window._mwPurchaseMarkers.push(marker);
        });

        // Extend map viewport to include any purchase task pins outside current bounds.
        // Delay 400ms so any preceding fitBounds (route) can settle first.
        if (window._mwPurchaseMarkers.length) {
            setTimeout(function() {
                if (!map) return;
                var curBounds = map.getBounds();
                if (!curBounds) return;
                var extended = false;
                window._mwPurchaseMarkers.forEach(function(m) {
                    var p = m.getPosition();
                    if (p && !curBounds.contains(p)) {
                        curBounds.extend(p);
                        extended = true;
                    }
                });
                if (extended) map.fitBounds(curBounds, { top: 40, right: 40, bottom: 40, left: 40 });
            }, 400);
        }
    }

    // ═══════════════════════════════════════════════════
    //  REORDER CARDS TO MATCH OPTIMIZED ROUTE
    // ═══════════════════════════════════════════════════

    function reorderCards(orderedStops) {
        // Find the assigned section container
        var section = document.querySelector('.mw-dv-section:not(.mw-dv-section-unassigned)');
        if (!section) return;

        // Get all assigned cards in the section
        var cards = section.querySelectorAll('.mw-dv-card:not(.mw-dv-card-unassigned)');
        if (cards.length < 2) return;

        // Build a map of stopId → card element
        var cardMap = {};
        cards.forEach(function(card) {
            var id = parseInt(card.dataset.stopId, 10);
            cardMap[id] = card;
        });

        // Find the insertion point (after the section header)
        var header = section.querySelector('.mw-dv-section-header');

        // Reorder: move cards in optimized order
        orderedStops.forEach(function(stop, idx) {
            var card = cardMap[stop.stopId];
            if (card) {
                // Add route number badge
                var existingBadge = card.querySelector('.mw-dv-route-num');
                if (!existingBadge) {
                    var badge = document.createElement('span');
                    badge.className = 'mw-dv-route-num';
                    badge.textContent = idx + 1;
                    var body = card.querySelector('.mw-dv-card-body');
                    if (body) body.insertBefore(badge, body.firstChild);
                } else {
                    existingBadge.textContent = idx + 1;
                }

                // Append to section (moves it in DOM order)
                section.appendChild(card);
            }
        });
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
            if (sm.stopId === stopId) {
                // Enlarge the highlighted marker
                var color = sm.isCompleted ? '#9CA3AF' : (sm.stopId === pinnedStopId ? '#e85d04' : (serviceColors[sm.serviceType] || '#2D8659'));
                sm.marker.setIcon(createPinIcon(color, sm.label || '\u2022', 44, sm.isCompleted));
                sm.marker.setZIndex(200);
            } else {
                // Dim others slightly via smaller size
                var c = sm.isCompleted ? '#9CA3AF' : (sm.isUnassigned ? '#9CA3AF' : (sm.stopId === pinnedStopId ? '#e85d04' : (serviceColors[sm.serviceType] || '#2D8659')));
                sm.marker.setIcon(createPinIcon(c, sm.label || '\u2022', sm.isUnassigned ? 26 : 30, sm.isCompleted));
                sm.marker.setZIndex(10);
            }
        });
    }

    function unhighlightMarkers() {
        stopMarkers.forEach(function(sm) {
            var color, size;
            if (sm.isCompleted) {
                color = '#9CA3AF'; size = 34;
            } else if (sm.isUnassigned) {
                color = '#9CA3AF'; size = 30;
            } else if (sm.stopId === pinnedStopId) {
                color = '#e85d04'; size = 40;
            } else {
                color = serviceColors[sm.serviceType] || '#2D8659';
                size = 36;
            }
            sm.marker.setIcon(createPinIcon(color, sm.label || '\u2022', size, sm.isCompleted));
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

        // Use MwNavLauncher if available (handles Capacitor intents + lat/lng preference)
        if (typeof MwNavLauncher !== 'undefined') {
            if (stops.length === 1) {
                MwNavLauncher.launchNavigation(stops[0]);
            } else {
                MwNavLauncher.launchMultiStopNavigation(stops);
            }
            return;
        }

        // Fallback: build URL with lat/lng preference
        var url;
        if (stops.length === 1) {
            var s = stops[0];
            var dest = (s.lat && s.lng) ? (s.lat + ',' + s.lng) : s.address;
            url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(dest)
                + '&travelmode=driving&dir_action=navigate';
        } else {
            var last = stops[stops.length - 1];
            var destStr = (last.lat && last.lng) ? (last.lat + ',' + last.lng) : last.address;
            var waypointParts = [];
            for (var i = 0; i < stops.length - 1; i++) {
                var ws = stops[i];
                waypointParts.push((ws.lat && ws.lng) ? (ws.lat + ',' + ws.lng) : ws.address);
            }
            url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(destStr)
                + '&waypoints=' + encodeURIComponent(waypointParts.join('|'))
                + '&travelmode=driving&dir_action=navigate';
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
        getPinnedStopId: function() { return pinnedStopId; },
        getMap: function() { return map; }
    };

})();

// ═══════════════════════════════════════════════════════════════════════════
//  Desktop Day View — Per-Card Complete & Reopen Actions
// ═══════════════════════════════════════════════════════════════════════════
(function() {
    'use strict';

    function showDvToast(msg, type, linkHref, linkLabel) {
        var existing = document.getElementById('mw-dv-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'mw-dv-toast';
        toast.className = 'mw-dv-toast mw-dv-toast-' + (type || 'success');
        toast.innerHTML = msg;
        if (linkHref && linkLabel) {
            toast.innerHTML += ' <a href="' + linkHref + '" class="mw-dv-toast-link">' + linkLabel + ' &rarr;</a>';
        }
        document.body.appendChild(toast);
        setTimeout(function() { toast.classList.add('mw-dv-toast-visible'); }, 10);
        setTimeout(function() {
            toast.classList.remove('mw-dv-toast-visible');
            setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
        }, 5000);
    }

    function dvPost(payload, onSuccess, onError) {
        fetch('/crm/api/pow-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: (window.MW_CSRF || '') }, payload))
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                onSuccess(data);
            } else {
                onError(data.error || 'An error occurred.');
            }
        })
        .catch(function(err) { onError('Network error. Please try again.'); });
    }

    function setCardDoneState(card, invoiceId, invoiceNumber, withInvoice) {
        // Swap stop-status class
        card.className = card.className.replace(/mw-stop-status-\w+/g, '') + ' mw-stop-status-done';

        var footer = card.querySelector('.mw-dv-card-footer');
        if (!footer) return;

        var visitIds = (footer.querySelector('[data-visit-ids]') || {}).dataset
            ? footer.querySelector('[data-visit-ids]').dataset.visitIds || ''
            : (footer.querySelector('[data-stop-id]') ? footer.querySelector('[data-stop-id]').dataset.stopId : '');
        var stopId   = card.dataset.stopId || '';

        var invoiceHtml = '';
        if (invoiceId && invoiceNumber) {
            // Invoice was created — show link to view it
            invoiceHtml = '<a class="mw-dv-invoice-link" href="/crm/invoices/view.php?id=' + invoiceId + '">'
                        + escapeHtml(invoiceNumber) + ' &rarr;</a>';
        } else if (withInvoice) {
            // "Complete & Invoice" was requested but invoice creation failed — offer manual fallback
            invoiceHtml = '<a class="mw-dv-create-invoice-link" href="/crm/invoices/create.php?stop_id=' + escapeHtml(stopId) + '">'
                        + '+ Create Invoice</a>';
        }
        var reopenVisitIds = visitIds || stopId;

        footer.className = 'mw-dv-card-footer mw-dv-footer-done';
        footer.innerHTML =
            '<span class="mw-dv-done-badge">'
            + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
            + ' Completed</span>'
            + invoiceHtml
            + '<button class="mw-dv-btn-reopen" data-stop-id="' + escapeHtml(stopId) + '" data-visit-ids="' + escapeHtml(reopenVisitIds) + '" title="Reopen this stop">Reopen</button>';
    }

    function setCardPendingState(card) {
        card.className = card.className.replace(/mw-stop-status-\w+/g, '') + ' mw-stop-status-scheduled';

        var footer = card.querySelector('.mw-dv-card-footer');
        if (!footer) return;

        var stopId = card.dataset.stopId || '';
        footer.className = 'mw-dv-card-footer mw-dv-footer-pending';
        footer.innerHTML =
            '<button class="mw-dv-btn-complete mw-dv-btn-complete-invoice" data-stop-id="' + escapeHtml(stopId) + '" data-invoice="1">'
            + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
            + ' Complete &amp; Invoice</button>'
            + '<button class="mw-dv-btn-complete mw-dv-btn-complete-noinvoice" data-stop-id="' + escapeHtml(stopId) + '" data-invoice="0">'
            + 'Complete &#8212; No Invoice</button>';
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function dvCompleteStop(card, stopId, withInvoice) {
        var btns = card.querySelectorAll('.mw-dv-btn-complete');
        btns.forEach(function(b) { b.disabled = true; b.style.opacity = '0.6'; });

        dvPost(
            { action: 'complete_stop', stop_id: stopId, invoice: withInvoice ? 1 : 0 },
            function(data) {
                if (data.already_completed && !data.invoice_id) {
                    setCardDoneState(card, null, null, false);
                    showDvToast('Visit was completed in the field — stop marked done.', 'info');
                    return;
                }
                if (withInvoice && data.invoice_id) {
                    window.location.href = '/crm/invoices/view.php?id=' + data.invoice_id;
                    return;
                }
                setCardDoneState(card, data.invoice_id, data.invoice_number, withInvoice);
                showDvToast('Stop marked complete.', 'success');
            },
            function(errMsg) {
                btns.forEach(function(b) { b.disabled = false; b.style.opacity = ''; });
                showDvToast(errMsg, 'error');
            }
        );
    }

    function dvReopenStop(card, visitId) {
        dvPost(
            { action: 'reopen_visit', visit_id: visitId },
            function() {
                setCardPendingState(card);
                showDvToast('Stop reopened — marked as scheduled.', 'info');
            },
            function(errMsg) {
                showDvToast(errMsg, 'error');
            }
        );
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.mw-dv-btn-complete');
        if (btn) {
            var card = btn.closest('.mw-dv-card');
            if (!card) return;
            dvCompleteStop(card, parseInt(card.dataset.stopId, 10), btn.dataset.invoice === '1');
            return;
        }

        var reopen = e.target.closest('.mw-dv-btn-reopen');
        if (reopen) {
            if (!confirm('Reopen this stop? All visits will be marked as scheduled again.')) return;
            var rCard = reopen.closest('.mw-dv-card');
            if (!rCard) return;
            var visitIds = (reopen.dataset.visitIds || '').split(',').map(Number).filter(Boolean);
            if (!visitIds.length) return;
            dvReopenStop(rCard, visitIds[0]);
        }
    });

}());

// ═══════════════════════════════════════════════════════════════════════════
//  Truck Location Layer
//  ─────────────────────
//  Renders the Trackimo-tracked truck on the day-view map. Polls
//  /crm/api/truck-location.php every 30s for the current position; trail
//  fetched on demand when the user clicks the Trail toggle button.
//
//  Depends on MwDayViewMap.getMap() being available. Gracefully no-ops if
//  the map isn't on the page or if the API reports no tracker configured.
// ═══════════════════════════════════════════════════════════════════════════
(function() {
    'use strict';

    var POLL_INTERVAL_MS = 30000;
    var STALE_THRESHOLD_S = 600; // 10 min — show warning badge above this

    var truckOverlay = null;
    var truckInfoWindow = null;
    var truckLastLoc = null;
    var trailPolyline = null;
    var pollTimer = null;
    var trailShown = false;
    var trailButton = null;

    function init() {
        trailButton = document.getElementById('mwDvMapTruckTrailToggle');
        if (trailButton) {
            trailButton.addEventListener('click', toggleTrail);
        }
        waitForMap(function() {
            poll();
            pollTimer = setInterval(poll, POLL_INTERVAL_MS);
        });
    }

    function waitForMap(cb) {
        if (window.MwDayViewMap && MwDayViewMap.getMap && MwDayViewMap.getMap()) {
            cb();
            return;
        }
        var attempts = 0;
        var check = setInterval(function() {
            attempts++;
            if (window.MwDayViewMap && MwDayViewMap.getMap && MwDayViewMap.getMap()) {
                clearInterval(check);
                cb();
            } else if (attempts > 60) {
                clearInterval(check); // Give up after ~30s
            }
        }, 500);
    }

    function poll() {
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
        var map = MwDayViewMap.getMap();
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

    /**
     * Build a google.maps.OverlayView that renders the truck badge as an
     * HTML element so the surrounding CSS pulse ring (.mw-truck-overlay)
     * can actually animate — static Marker icons can't. Pointer-tip of the
     * badge sits exactly at the supplied LatLng (same anchor as a Marker).
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
        // White disc with a purple→cyan gradient line-art truck (Tim's ref).
        // Outer CSS provides the cyan pulse + disc.
        return '<div class="mw-truck-badge">' +
                 '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" ' +
                      'stroke-linecap="round" stroke-linejoin="round" ' +
                      'xmlns="http://www.w3.org/2000/svg">' +
                   '<defs>' +
                     '<linearGradient id="mwTruckGrad2" x1="0" y1="0" x2="1" y2="1">' +
                       '<stop offset="0%" stop-color="#553c9a"/>' +
                       '<stop offset="100%" stop-color="#3eb5c9"/>' +
                     '</linearGradient>' +
                   '</defs>' +
                   '<rect x="1" y="3" width="15" height="13" rx="2" stroke="url(#mwTruckGrad2)"/>' +
                   '<path d="M16 8 L20 8 L23 11 L23 16 L16 16 Z" stroke="url(#mwTruckGrad2)"/>' +
                   '<circle cx="5.5"  cy="18.5" r="2.5" stroke="url(#mwTruckGrad2)"/>' +
                   '<circle cx="18.5" cy="18.5" r="2.5" stroke="url(#mwTruckGrad2)"/>' +
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

    function toggleTrail() {
        if (!trailButton) return;
        if (trailShown) {
            hideTrail();
            return;
        }
        trailButton.setAttribute('aria-pressed', 'true');
        fetch('/crm/api/truck-trail.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.ok || !data.trail || !data.trail.length) {
                    hideTrail();
                    return;
                }
                drawTrail(data.trail);
            })
            .catch(function() { hideTrail(); });
    }

    function drawTrail(points) {
        var map = MwDayViewMap.getMap();
        if (!map || typeof google === 'undefined') return;

        var path = points.map(function(p) {
            return new google.maps.LatLng(p.lat, p.lng);
        });

        if (trailPolyline) trailPolyline.setMap(null);
        trailPolyline = new google.maps.Polyline({
            path: path,
            geodesic: false,
            strokeColor: '#e85d04',
            strokeOpacity: 0.6,
            strokeWeight: 3,
            map: map,
            zIndex: 100
        });
        trailShown = true;
    }

    function hideTrail() {
        if (trailPolyline) {
            trailPolyline.setMap(null);
            trailPolyline = null;
        }
        trailShown = false;
        if (trailButton) trailButton.setAttribute('aria-pressed', 'false');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
