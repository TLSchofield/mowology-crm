/**
 * Route Engine — Total Drive Time & Day Feasibility Calculator
 *
 * Computes total drive time, distance, day load, and feasibility score
 * for a crew's daily route. Uses Google Maps Directions API with
 * cost-controlled caching.
 *
 * Public API:
 *   MwRouteEngine.computeRouteSummary(stops, options)  → Promise<RouteSummary>
 *   MwRouteEngine.invalidateCache(crewId, date)
 *   MwRouteEngine.getCachedSummary(crewId, date)
 *
 * @package Mowology CRM
 */
var MwRouteEngine = (function() {
    'use strict';

    // ── Debug ──
    var DEBUG = (typeof MW_DEBUG !== 'undefined' && MW_DEBUG) ||
                (typeof localStorage !== 'undefined' && localStorage.getItem('mw_route_debug') === '1');

    function log() {
        if (!DEBUG) return;
        var args = ['[RouteEngine]'].concat(Array.prototype.slice.call(arguments));
        console.log.apply(console, args);
    }

    // ── Configuration ──
    var CONFIG = {
        CACHE_TTL_MS: 15 * 60 * 1000,    // 15 minutes for live traffic cache
        MAX_API_CALLS_PER_SESSION: 50,     // Cost control per session
        WORK_WINDOW_MINUTES: 480,          // 8 hours default
        DEFAULT_BUFFER_PERCENT: 10,        // 10% buffer on drive time
        DEFAULT_BUFFER_FIXED_MIN: 15,      // 15 min fixed buffer per day
        FEASIBILITY_THRESHOLDS: {
            green: 0.85,
            yellow: 1.0
            // > 1.0 = red
        },
        OUTLIER_THRESHOLD_MINUTES: 20      // Flag stops adding >20 min detour
    };

    // ── State ──
    var cache = {};         // keyed by cacheKey string
    var apiCallCount = 0;

    // ── Haversine ──
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

    // ── Cache Key Builder ──

    /**
     * Build a deterministic cache key from route parameters.
     * Key components: date, crewId, ordered stop IDs, departure hour bucket.
     */
    function buildCacheKey(stops, options) {
        var ids = stops.map(function(s) { return s.stopId || s.id || 0; }).join('-');
        var date = options.date || new Date().toISOString().split('T')[0];
        var crewId = options.crewId || 0;
        var bucket = options.departureBucket || Math.floor(new Date().getHours() / 2);
        return date + ':' + crewId + ':' + ids + ':' + bucket;
    }

    /**
     * Check if a cached result is still valid.
     */
    function getCached(key) {
        var entry = cache[key];
        if (!entry) return null;
        if (Date.now() - entry.timestamp > CONFIG.CACHE_TTL_MS) {
            delete cache[key];
            return null;
        }
        log('Cache hit:', key);
        return entry.data;
    }

    function setCache(key, data) {
        cache[key] = {
            data: data,
            timestamp: Date.now()
        };
    }

    // ── Outlier Detection ──

    /**
     * Identify stops that add excessive detour time compared to the
     * cluster median. Uses haversine distance as a proxy.
     */
    function detectOutliers(stops, legs) {
        var warnings = [];
        if (!legs || legs.length < 2) return warnings;

        // Get all leg durations in minutes
        var legMinutes = legs.map(function(l) { return l.durationSeconds / 60; });
        var median = getMedian(legMinutes);
        var threshold = Math.max(CONFIG.OUTLIER_THRESHOLD_MINUTES, median * 2.5);

        for (var i = 0; i < legMinutes.length; i++) {
            if (legMinutes[i] > threshold) {
                var stopIdx = Math.min(i, stops.length - 1);
                warnings.push({
                    type: 'outlier',
                    stopIndex: stopIdx,
                    stopId: stops[stopIdx] ? stops[stopIdx].stopId : null,
                    message: 'Outlier: adds +' + Math.round(legMinutes[i]) + ' min to route',
                    legMinutes: Math.round(legMinutes[i])
                });
            }
        }

        return warnings;
    }

    function getMedian(arr) {
        if (arr.length === 0) return 0;
        var sorted = arr.slice().sort(function(a, b) { return a - b; });
        var mid = Math.floor(sorted.length / 2);
        return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
    }

    // ── Feasibility Scoring ──

    /**
     * Compute day load and feasibility classification.
     *
     * @param {number} totalDriveMinutes
     * @param {number} totalJobMinutes - sum of estimated_duration for all stops
     * @param {Object} bufferPolicy - { percent, fixedMinutes }
     * @param {number} workWindowMinutes
     * @returns {Object} { load, classification, breakdown }
     */
    function computeFeasibility(totalDriveMinutes, totalJobMinutes, bufferPolicy, workWindowMinutes) {
        bufferPolicy = bufferPolicy || {};
        var bufferPercent = bufferPolicy.percent !== undefined ? bufferPolicy.percent : CONFIG.DEFAULT_BUFFER_PERCENT;
        var bufferFixed = bufferPolicy.fixedMinutes !== undefined ? bufferPolicy.fixedMinutes : CONFIG.DEFAULT_BUFFER_FIXED_MIN;
        var window = workWindowMinutes || CONFIG.WORK_WINDOW_MINUTES;

        var driveBuffer = totalDriveMinutes * (bufferPercent / 100);
        var totalBufferMinutes = driveBuffer + bufferFixed;
        var totalDayMinutes = totalDriveMinutes + totalJobMinutes + totalBufferMinutes;
        var load = totalDayMinutes / window;

        var classification;
        if (load <= CONFIG.FEASIBILITY_THRESHOLDS.green) {
            classification = 'green';
        } else if (load <= CONFIG.FEASIBILITY_THRESHOLDS.yellow) {
            classification = 'yellow';
        } else {
            classification = 'red';
        }

        return {
            load: Math.round(load * 100) / 100,
            classification: classification,
            breakdown: {
                totalDriveMinutes: Math.round(totalDriveMinutes),
                totalJobMinutes: Math.round(totalJobMinutes),
                bufferMinutes: Math.round(totalBufferMinutes),
                totalDayMinutes: Math.round(totalDayMinutes),
                workWindowMinutes: window,
                utilizationPercent: Math.round(load * 100)
            }
        };
    }

    // ── Main Route Computation ──

    /**
     * Compute a full route summary for a set of stops.
     *
     * @param {Array} stops - ordered stops [{ stopId, lat, lng, address, duration, ... }]
     * @param {Object} options
     *   - orderMode: 'scheduled' (keep order) | 'optimized' (Google optimizes)
     *   - trafficMode: 'live' | 'typical' | 'none'
     *   - departureTime: Date or 'now'
     *   - bufferPolicy: { percent, fixedMinutes }
     *   - workWindowMinutes: number
     *   - crewId: number
     *   - date: 'YYYY-MM-DD'
     *   - originLat, originLng: crew start location (GPS)
     *   - forceRefresh: boolean
     *
     * @returns {Promise<RouteSummary>}
     *
     * RouteSummary shape:
     *   totalDriveSeconds, totalDistanceMeters,
     *   legs: [{ durationSeconds, distanceMeters, startAddress, endAddress }],
     *   optimizedStopOrder: stops[] (if orderMode=optimized),
     *   warnings: [{ type, stopId, message }],
     *   feasibility: { load, classification, breakdown },
     *   confidence: 'high' | 'medium' | 'low',
     *   cachedAt: timestamp, fromCache: boolean
     */
    function computeRouteSummary(stops, options) {
        options = options || {};

        // Validate inputs
        if (!stops || stops.length === 0) {
            return Promise.resolve(emptyResult('No stops provided'));
        }

        var validStops = stops.filter(function(s) { return (s.lat && s.lng) || s.address; });
        if (validStops.length === 0) {
            return Promise.resolve(emptyResult('No stops with location data'));
        }

        // Check cache
        var cacheKey = buildCacheKey(validStops, options);
        if (!options.forceRefresh) {
            var cached = getCached(cacheKey);
            if (cached) {
                cached.fromCache = true;
                return Promise.resolve(cached);
            }
        }

        // Check API call budget
        if (apiCallCount >= CONFIG.MAX_API_CALLS_PER_SESSION) {
            log('API budget exceeded (' + apiCallCount + '/' + CONFIG.MAX_API_CALLS_PER_SESSION + '), using haversine estimate');
            var estimate = computeHaversineEstimate(validStops, options);
            return Promise.resolve(estimate);
        }

        // Single stop: trivial case
        if (validStops.length === 1) {
            return computeSingleStop(validStops[0], options, cacheKey);
        }

        // Multi-stop: use Directions API
        return computeMultiStop(validStops, options, cacheKey);
    }

    /**
     * Single stop: if we have origin GPS, compute one leg.
     */
    function computeSingleStop(stop, options, cacheKey) {
        var hasOrigin = !!(options.originLat && options.originLng);

        if (!hasOrigin) {
            // No origin — can't compute drive time, just return job duration
            var result = {
                totalDriveSeconds: 0,
                totalDistanceMeters: 0,
                legs: [],
                optimizedStopOrder: [stop],
                warnings: [{ type: 'no_origin', message: 'No GPS origin — drive time unknown' }],
                feasibility: computeFeasibility(0, stop.duration || 0, options.bufferPolicy, options.workWindowMinutes),
                confidence: 'low',
                cachedAt: Date.now(),
                fromCache: false
            };
            setCache(cacheKey, result);
            return Promise.resolve(result);
        }

        // Use Directions API for origin→stop
        return new Promise(function(resolve) {
            if (typeof google === 'undefined' || !google.maps || !google.maps.DirectionsService) {
                resolve(computeHaversineEstimate([stop], options));
                return;
            }

            var ds = new google.maps.DirectionsService();
            apiCallCount++;

            var request = {
                origin: new google.maps.LatLng(options.originLat, options.originLng),
                destination: stopToLocation(stop),
                travelMode: google.maps.TravelMode.DRIVING
            };

            // Add departure time for traffic-aware results
            if (options.trafficMode === 'live' || options.trafficMode === 'typical') {
                request.drivingOptions = {
                    departureTime: options.departureTime instanceof Date ? options.departureTime : new Date(),
                    trafficModel: options.trafficMode === 'live' ? 'bestguess' : 'pessimistic'
                };
            }

            ds.route(request, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    var leg = result.routes[0].legs[0];
                    var durSeconds = leg.duration_in_traffic ? leg.duration_in_traffic.value : leg.duration.value;

                    var summary = {
                        totalDriveSeconds: durSeconds,
                        totalDistanceMeters: leg.distance.value,
                        legs: [{
                            durationSeconds: durSeconds,
                            distanceMeters: leg.distance.value,
                            startAddress: leg.start_address,
                            endAddress: leg.end_address
                        }],
                        optimizedStopOrder: [stop],
                        warnings: [],
                        feasibility: computeFeasibility(
                            durSeconds / 60,
                            stop.duration || 0,
                            options.bufferPolicy,
                            options.workWindowMinutes
                        ),
                        confidence: options.trafficMode === 'live' ? 'high' : 'medium',
                        cachedAt: Date.now(),
                        fromCache: false
                    };

                    setCache(cacheKey, summary);
                    resolve(summary);
                } else {
                    log('Directions failed for single stop:', status);
                    resolve(computeHaversineEstimate([stop], options));
                }
            });
        });
    }

    /**
     * Multi-stop route computation via Directions API.
     */
    function computeMultiStop(stops, options, cacheKey) {
        return new Promise(function(resolve) {
            if (typeof google === 'undefined' || !google.maps || !google.maps.DirectionsService) {
                resolve(computeHaversineEstimate(stops, options));
                return;
            }

            var ds = new google.maps.DirectionsService();
            apiCallCount++;

            var hasOrigin = !!(options.originLat && options.originLng);
            var optimize = options.orderMode === 'optimized';

            // Build origin, destination, waypoints
            var origin, destination, waypoints = [], waypointStopIndices = [];

            if (hasOrigin) {
                origin = new google.maps.LatLng(options.originLat, options.originLng);
            } else {
                origin = stopToLocation(stops[0]);
            }

            // Destination = last stop
            destination = stopToLocation(stops[stops.length - 1]);

            // Waypoints = middle stops (or all if origin is GPS)
            var startIdx = hasOrigin ? 0 : 1;
            var endIdx = stops.length - 1;

            for (var i = startIdx; i < endIdx; i++) {
                var loc = stopToLocation(stops[i]);
                if (loc) {
                    waypoints.push({ location: loc, stopover: true });
                    waypointStopIndices.push(i);
                }
            }

            var request = {
                origin: origin,
                destination: destination,
                travelMode: google.maps.TravelMode.DRIVING
            };

            if (waypoints.length > 0) {
                request.waypoints = waypoints;
                if (optimize) {
                    request.optimizeWaypoints = true;
                }
            }

            // Traffic-aware timing
            if (options.trafficMode === 'live' || options.trafficMode === 'typical') {
                request.drivingOptions = {
                    departureTime: options.departureTime instanceof Date ? options.departureTime : new Date(),
                    trafficModel: options.trafficMode === 'live' ? 'bestguess' : 'pessimistic'
                };
            }

            log('API call #' + apiCallCount + ':', stops.length, 'stops, optimize:', optimize);

            ds.route(request, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    var route = result.routes[0];
                    var googleLegs = route.legs;
                    var waypointOrder = route.waypoint_order || [];

                    // Build optimized stop order
                    var orderedStops = [];
                    if (!hasOrigin) {
                        orderedStops.push(stops[0]);
                    }
                    if (waypointOrder.length > 0) {
                        for (var oi = 0; oi < waypointOrder.length; oi++) {
                            orderedStops.push(stops[waypointStopIndices[waypointOrder[oi]]]);
                        }
                    } else {
                        for (var si = 0; si < waypointStopIndices.length; si++) {
                            orderedStops.push(stops[waypointStopIndices[si]]);
                        }
                    }
                    orderedStops.push(stops[stops.length - 1]);

                    // Compute totals
                    var totalDrive = 0;
                    var totalDist = 0;
                    var legs = [];

                    for (var l = 0; l < googleLegs.length; l++) {
                        var gl = googleLegs[l];
                        var durSec = gl.duration_in_traffic ? gl.duration_in_traffic.value : gl.duration.value;
                        totalDrive += durSec;
                        totalDist += gl.distance.value;
                        legs.push({
                            durationSeconds: durSec,
                            distanceMeters: gl.distance.value,
                            startAddress: gl.start_address,
                            endAddress: gl.end_address
                        });
                    }

                    // Total job duration from all stops
                    var totalJobMin = 0;
                    orderedStops.forEach(function(s) {
                        totalJobMin += (s.duration || 0);
                    });

                    // Detect outlier legs
                    var warnings = detectOutliers(orderedStops, legs);

                    // Check for missing coordinates
                    stops.forEach(function(s) {
                        if (!s.lat || !s.lng) {
                            warnings.push({
                                type: 'missing_coords',
                                stopId: s.stopId,
                                message: 'Missing coordinates for: ' + (s.address || 'unknown')
                            });
                        }
                    });

                    var confidence;
                    if (options.trafficMode === 'live') {
                        confidence = 'high';
                    } else if (options.trafficMode === 'typical') {
                        confidence = 'medium';
                    } else {
                        confidence = warnings.length > 0 ? 'low' : 'medium';
                    }

                    var summary = {
                        totalDriveSeconds: totalDrive,
                        totalDistanceMeters: totalDist,
                        legs: legs,
                        optimizedStopOrder: orderedStops,
                        warnings: warnings,
                        feasibility: computeFeasibility(
                            totalDrive / 60,
                            totalJobMin,
                            options.bufferPolicy,
                            options.workWindowMinutes
                        ),
                        confidence: confidence,
                        cachedAt: Date.now(),
                        fromCache: false
                    };

                    setCache(cacheKey, summary);
                    log('Route computed:', summary.feasibility.breakdown);
                    resolve(summary);
                } else {
                    log('Directions failed:', status, '— using haversine estimate');
                    resolve(computeHaversineEstimate(stops, options));
                }
            });
        });
    }

    // ── Fallback: Haversine Estimate ──

    /**
     * Estimate drive time from straight-line distances when API is unavailable.
     * Uses a 1.4x detour factor and 40 km/h average speed for urban driving.
     */
    function computeHaversineEstimate(stops, options) {
        var DETOUR_FACTOR = 1.4;
        var AVG_SPEED_MPS = 40 * 1000 / 3600; // 40 km/h in m/s

        var totalDist = 0;
        var legs = [];
        var hasOrigin = !!(options.originLat && options.originLng);

        // Build points list starting from origin
        var points = [];
        if (hasOrigin) {
            points.push({ lat: options.originLat, lng: options.originLng, label: 'Start' });
        }
        stops.forEach(function(s) {
            if (s.lat && s.lng) {
                points.push({ lat: s.lat, lng: s.lng, label: s.address || '' });
            }
        });

        for (var i = 1; i < points.length; i++) {
            var dist = haversine(points[i - 1].lat, points[i - 1].lng, points[i].lat, points[i].lng);
            var roadDist = dist * DETOUR_FACTOR;
            var durSec = roadDist / AVG_SPEED_MPS;
            totalDist += roadDist;
            legs.push({
                durationSeconds: Math.round(durSec),
                distanceMeters: Math.round(roadDist),
                startAddress: points[i - 1].label,
                endAddress: points[i].label
            });
        }

        var totalDriveSec = legs.reduce(function(sum, l) { return sum + l.durationSeconds; }, 0);
        var totalJobMin = 0;
        stops.forEach(function(s) { totalJobMin += (s.duration || 0); });

        return {
            totalDriveSeconds: totalDriveSec,
            totalDistanceMeters: Math.round(totalDist),
            legs: legs,
            optimizedStopOrder: stops,
            warnings: [{ type: 'estimated', message: 'Drive times are estimates (no API available)' }],
            feasibility: computeFeasibility(
                totalDriveSec / 60,
                totalJobMin,
                options.bufferPolicy,
                options.workWindowMinutes
            ),
            confidence: 'low',
            cachedAt: Date.now(),
            fromCache: false
        };
    }

    // ── Helpers ──

    function stopToLocation(stop) {
        if (stop.lat && stop.lng) {
            return new google.maps.LatLng(stop.lat, stop.lng);
        }
        return stop.address || null;
    }

    function emptyResult(msg) {
        return {
            totalDriveSeconds: 0,
            totalDistanceMeters: 0,
            legs: [],
            optimizedStopOrder: [],
            warnings: [{ type: 'empty', message: msg }],
            feasibility: { load: 0, classification: 'green', breakdown: {} },
            confidence: 'low',
            cachedAt: Date.now(),
            fromCache: false
        };
    }

    /**
     * Invalidate cached results for a crew/date.
     */
    function invalidateCache(crewId, date) {
        var prefix = (date || '') + ':' + (crewId || 0) + ':';
        var deleted = 0;
        for (var key in cache) {
            if (cache.hasOwnProperty(key) && key.indexOf(prefix) === 0) {
                delete cache[key];
                deleted++;
            }
        }
        log('Cache invalidated:', prefix, 'deleted:', deleted);
    }

    /**
     * Get cached summary without recomputing.
     */
    function getCachedSummary(crewId, date) {
        var prefix = (date || '') + ':' + (crewId || 0) + ':';
        for (var key in cache) {
            if (cache.hasOwnProperty(key) && key.indexOf(prefix) === 0) {
                var entry = cache[key];
                if (Date.now() - entry.timestamp <= CONFIG.CACHE_TTL_MS) {
                    return entry.data;
                }
            }
        }
        return null;
    }

    // ── Format Helpers (for UI) ──

    /**
     * Format seconds as human-readable duration.
     */
    function formatDuration(seconds) {
        if (seconds < 60) return '< 1 min';
        if (seconds < 3600) return Math.round(seconds / 60) + ' min';
        var hours = Math.floor(seconds / 3600);
        var mins = Math.round((seconds % 3600) / 60);
        return hours + ' hr' + (mins > 0 ? ' ' + mins + ' min' : '');
    }

    /**
     * Format meters as human-readable distance.
     */
    function formatDistance(meters) {
        if (meters < 1000) return meters + ' m';
        return (meters / 1000).toFixed(1) + ' km';
    }

    return {
        computeRouteSummary: computeRouteSummary,
        invalidateCache: invalidateCache,
        getCachedSummary: getCachedSummary,
        computeFeasibility: computeFeasibility,
        formatDuration: formatDuration,
        formatDistance: formatDistance,

        // Exposed for testing
        _buildCacheKey: buildCacheKey,
        _detectOutliers: detectOutliers,
        _computeHaversineEstimate: computeHaversineEstimate,
        _apiCallCount: function() { return apiCallCount; },
        _resetApiCounter: function() { apiCallCount = 0; },
        CONFIG: CONFIG
    };
})();
