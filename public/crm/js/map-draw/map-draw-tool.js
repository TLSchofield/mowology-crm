/**
 * MapDrawTool — the single, shared map-drawing engine for the Mowology CRM.
 *
 * One Google Maps drawing surface used by every page that needs to draw a
 * polygon on a property: the quote workflow measure tool, the standalone area
 * measurement page, and the zone editor (arrival border + work zones).
 *
 * This is an ENGINE, not a UI. It owns the map, the DrawingManager, the
 * area/perimeter geometry, rendering of existing shapes, and the save adapters
 * for BOTH backends (measurements → property_measurements, zones → job_geofences).
 * Each page keeps its own sidebar and wires it to the callbacks/methods below.
 *
 * Replaces three drifting inline copies of Google-Maps drawing code (and the
 * separate Leaflet zone editor). The guarded init here is what fixes the
 * "Cannot read properties of null (reading 'setDrawingMode')" crash: draw
 * methods no-op until the map + DrawingManager actually exist.
 *
 * Depends on: Google Maps JS API loaded with `libraries=drawing,geometry`.
 *
 * Usage:
 *   const tool = new MapDrawTool({
 *     mapContainer: 'measureMap',
 *     center: {lat: 49.28, lng: -123.12},   // or null
 *     zoom: 19,
 *     address: '123 Main St',               // geocode fallback when no center
 *     mapTypeSelectorId: 'mapTypeSelector', // optional <select> to bind
 *     marker: true,
 *     onReady: () => enableDrawButtons(),
 *     onDraw:  (m) => showCurrentMeasurement(m),
 *   });
 *   // From the Google Maps script `&callback=...`:
 *   window.initMapDraw = () => tool.init();
 *
 *   tool.startDraw('polygon');            // guarded — no-ops until ready
 *   const m = tool.getCurrent();          // live metrics for the drawn shape
 *   const overlay = tool.adoptCurrent({color:'#3b82f6', editable:false});
 *   tool.renderShape(coords, {shapeType:'polygon', color:'#FFD700'}); // existing
 */

/* global google */
'use strict';

(function (global) {

    var M2_TO_FT2 = 10.764;
    var M2_TO_ACRE = 0.000247105;
    var M_TO_FT = 3.28084;

    var DEFAULT_COLORS = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#6366f1'];

    function MapDrawTool(opts) {
        opts = opts || {};
        this._opts = Object.assign({
            mapContainer:      'map',
            center:            null,                 // {lat,lng}
            zoom:              19,
            address:           '',
            mapType:           'satellite',
            mapTypeSelectorId: null,
            marker:            false,
            fallbackCenter:    { lat: 49.2827, lng: -123.1207 }, // Vancouver
            polygonColor:      '#2D8659',
            polygonStroke:     '#0D3B2E',
            polylineColor:     '#e85d04',
            drawPreviewColor:  '#ff1d1d',   // in-progress rubber-band line + nodes (bright, high-contrast on satellite)
            colors:            DEFAULT_COLORS,
            onReady:           null,
            onDraw:            null,   // fn(measurement) — on complete + on every edit (live display)
            onComplete:        null,   // fn(measurement) — once, when a shape is first closed
            onError:           null,   // fn(message)
        }, opts);

        this._map            = null;
        this._geocoder       = null;
        this._ready          = false;
        this._current        = null;  // {overlay, shapeType}

        // Manual drawing state (replaces the removed google.maps.drawing.DrawingManager)
        this._drawMode    = null;   // 'polygon' | 'polyline' | 'rectangle' | null
        this._drawVerts   = [];     // committed google.maps.LatLng vertices
        this._drawPreview = null;   // live in-progress overlay (Polyline or Rectangle)
        this._closeMarker = null;   // clickable marker on the first vertex (polygon close)
        this._nodeMarkers = [];     // small dots on each committed vertex (visibility while drawing)
        this._mapClickL   = null;   // saved map event listeners
        this._mapDblL     = null;
        this._mapMoveL    = null;
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    MapDrawTool.prototype.init = function () {
        if (this._ready) return this;

        var el = typeof this._opts.mapContainer === 'string'
            ? document.getElementById(this._opts.mapContainer)
            : this._opts.mapContainer;

        if (!el) { this._fail('Map container not found: ' + this._opts.mapContainer); return this; }
        if (typeof google === 'undefined' || !google.maps) { this._fail('Google Maps not loaded'); return this; }
        if (!google.maps.geometry) { this._fail('Google Maps "geometry" library not loaded'); return this; }

        var hasCenter = !!(this._opts.center && this._opts.center.lat && this._opts.center.lng);
        var center = hasCenter ? this._opts.center : this._opts.fallbackCenter;

        this._map = new google.maps.Map(el, {
            center:            center,
            zoom:              hasCenter ? this._opts.zoom : 17,
            mapTypeId:         this._opts.mapType,
            tilt:              0,
            mapTypeControl:    false,
            streetViewControl: false,
            fullscreenControl: true,
            zoomControl:       true,
            gestureHandling:   'greedy',
        });

        this._geocoder = new google.maps.Geocoder();

        // Manual drawing listeners. google.maps.drawing.DrawingManager was removed
        // from the Maps JS API in v3.65, so we build polygons/lines/rectangles by
        // hand from map clicks. Listeners stay attached and no-op unless a draw
        // mode is active (_drawMode set by startDraw()).
        var self = this;
        this._mapClickL = google.maps.event.addListener(this._map, 'click', function (e) {
            self._onDrawClick(e.latLng);
        });
        this._mapDblL = google.maps.event.addListener(this._map, 'dblclick', function (e) {
            if (self._drawMode) self._finishDraw();
        });
        this._mapMoveL = google.maps.event.addListener(this._map, 'mousemove', function (e) {
            self._onDrawMove(e.latLng);
        });

        // Optional map-type <select> binding
        if (this._opts.mapTypeSelectorId) {
            var sel = document.getElementById(this._opts.mapTypeSelectorId);
            if (sel) {
                sel.addEventListener('change', function () { self.setMapType(this.value); });
            }
        }

        // Property marker
        if (hasCenter && this._opts.marker) {
            new google.maps.Marker({
                map: this._map,
                position: center,
                title: 'Property',
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 8,
                    fillColor: this._opts.polygonColor,
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2,
                },
            });
        } else if (!hasCenter && this._opts.address) {
            this._geocodeAddress(this._opts.address);
        }

        this._ready = true;
        if (typeof this._opts.onReady === 'function') this._opts.onReady();
        return this;
    };

    MapDrawTool.prototype.isReady = function () { return this._ready; };
    MapDrawTool.prototype.getMap = function () { return this._map; };

    // ── Drawing (manual — DrawingManager was removed from Maps JS v3.65) ─────────

    /**
     * Begin drawing. Click the map to add vertices.
     *   polygon  — click each corner; click the first point or double-click to close (min 3).
     *   polyline — click each point; double-click to finish (min 2).
     *   rectangle— click two opposite corners.
     * No-ops until the map is ready.
     */
    MapDrawTool.prototype.startDraw = function (type) {
        if (!this._ready || !this._map) return false;
        this._resetDrawState();
        this._drawMode = (type === 'rectangle' || type === 'polyline') ? type : 'polygon';
        this._map.setOptions({ draggableCursor: 'crosshair', disableDoubleClickZoom: true });
        return true;
    };

    /** Cancel the in-progress draw (does not touch any completed shape). */
    MapDrawTool.prototype.stopDraw = function () {
        this._resetDrawState();
        if (this._map) this._map.setOptions({ draggableCursor: null, disableDoubleClickZoom: false });
    };

    /** Tear down preview overlays + vertices for the in-progress shape. */
    MapDrawTool.prototype._resetDrawState = function () {
        this._drawMode = null;
        this._drawVerts = [];
        if (this._drawPreview) { this._drawPreview.setMap(null); this._drawPreview = null; }
        if (this._closeMarker) { this._closeMarker.setMap(null); this._closeMarker = null; }
        this._nodeMarkers.forEach(function (m) { m.setMap(null); });
        this._nodeMarkers = [];
    };

    /** Map click while a draw mode is active — add a vertex / corner. */
    MapDrawTool.prototype._onDrawClick = function (latLng) {
        if (!this._drawMode || !latLng) return;
        var self = this;

        if (this._drawMode === 'rectangle') {
            this._drawVerts.push(latLng);
            if (this._drawVerts.length >= 2) { this._finishDraw(); }
            return;
        }

        // polygon / polyline
        this._drawVerts.push(latLng);

        // First vertex of a polygon → drop a clickable "close" marker (ringed, bright).
        if (this._drawMode === 'polygon' && this._drawVerts.length === 1) {
            this._closeMarker = new google.maps.Marker({
                position: latLng,
                map: this._map,
                title: 'Click to close shape',
                zIndex: 1000,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 7,
                    fillColor: '#fff',
                    fillOpacity: 1,
                    strokeColor: this._opts.drawPreviewColor,
                    strokeWeight: 3,
                },
            });
            google.maps.event.addListener(this._closeMarker, 'click', function () {
                if (self._drawVerts.length >= 3) self._finishDraw();
            });
        } else {
            // Subsequent vertices → small solid red dots so every node is visible.
            this._nodeMarkers.push(new google.maps.Marker({
                position: latLng,
                map: this._map,
                clickable: false,
                zIndex: 999,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 5,
                    fillColor: this._opts.drawPreviewColor,
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 1.5,
                },
            }));
        }

        this._renderDrawPreview(this._drawVerts);
    };

    /** Map mousemove — rubber-band the preview to the cursor. */
    MapDrawTool.prototype._onDrawMove = function (latLng) {
        if (!this._drawMode || !latLng || !this._drawVerts.length) return;

        if (this._drawMode === 'rectangle') {
            this._renderRectPreview(this._drawVerts[0], latLng);
            return;
        }
        this._renderDrawPreview(this._drawVerts.concat([latLng]));
    };

    MapDrawTool.prototype._renderDrawPreview = function (verts) {
        if (!this._drawPreview) {
            this._drawPreview = new google.maps.Polyline({
                map: this._map,
                strokeColor: this._opts.drawPreviewColor,
                strokeWeight: 4,
                strokeOpacity: 1,
                clickable: false,
                zIndex: 998,
            });
        }
        this._drawPreview.setPath(verts);
    };

    MapDrawTool.prototype._renderRectPreview = function (c1, c2) {
        var bounds = new google.maps.LatLngBounds();
        bounds.extend(c1); bounds.extend(c2);
        if (!this._drawPreview || !this._drawPreview.setBounds) {
            if (this._drawPreview) this._drawPreview.setMap(null);
            this._drawPreview = new google.maps.Rectangle({
                map: this._map,
                fillColor: this._opts.drawPreviewColor,
                fillOpacity: 0.12,
                strokeColor: this._opts.drawPreviewColor,
                strokeWeight: 4,
                clickable: false,
                zIndex: 998,
            });
        }
        this._drawPreview.setBounds(bounds);
    };

    /** Build the final editable shape, mirror the old overlaycomplete behaviour. */
    MapDrawTool.prototype._finishDraw = function () {
        var mode = this._drawMode;
        var verts = this._dedupeVerts(this._drawVerts);
        var minPts = mode === 'polyline' ? 2 : (mode === 'rectangle' ? 2 : 3);

        // Tear down preview/markers but keep the collected vertices for building.
        this.stopDraw();

        if (verts.length < minPts) return;

        // Replace any previously-completed scratch shape (matches old DrawingManager).
        if (this._current && this._current.overlay) this._current.overlay.setMap(null);

        var overlay, shapeType;
        if (mode === 'polyline') {
            shapeType = 'polyline';
            overlay = new google.maps.Polyline({
                path: verts, map: this._map,
                strokeColor: this._opts.polylineColor, strokeWeight: 3,
                editable: true, draggable: true,
            });
        } else if (mode === 'rectangle') {
            shapeType = 'rectangle';
            var b = new google.maps.LatLngBounds();
            b.extend(verts[0]); b.extend(verts[1]);
            overlay = new google.maps.Rectangle(Object.assign(this._shapeStyle(), { bounds: b, map: this._map }));
        } else {
            shapeType = 'polygon';
            overlay = new google.maps.Polygon(Object.assign(this._shapeStyle(), { paths: verts, map: this._map }));
        }

        this._current = { overlay: overlay, shapeType: shapeType };
        this._bindEditListeners(overlay, shapeType);
        this._emitDraw();
        if (typeof this._opts.onComplete === 'function') {
            this._opts.onComplete(this.getCurrent());
        }
    };

    /** Drop consecutive vertices within ~0.5m of each other (double-click dupes). */
    MapDrawTool.prototype._dedupeVerts = function (verts) {
        var out = [];
        for (var i = 0; i < verts.length; i++) {
            var prev = out[out.length - 1];
            if (prev && google.maps.geometry.spherical.computeDistanceBetween(prev, verts[i]) < 0.5) continue;
            out.push(verts[i]);
        }
        return out;
    };

    /** Remove the in-progress / just-completed shape. */
    MapDrawTool.prototype.clearCurrent = function () {
        if (this._current && this._current.overlay) this._current.overlay.setMap(null);
        this._current = null;
    };

    /** Live metrics for the current shape, or null. */
    MapDrawTool.prototype.getCurrent = function () {
        if (!this._current || !this._current.overlay) return null;
        return MapDrawTool.metricsForOverlay(this._current.overlay, this._current.shapeType);
    };

    MapDrawTool.prototype.hasCurrent = function () {
        return !!(this._current && this._current.overlay);
    };

    /**
     * Finalize the current shape as a kept shape (restyle, stop editing) and
     * release the "current" pointer. Returns {overlay, shapeType, metrics}.
     */
    MapDrawTool.prototype.adoptCurrent = function (style) {
        if (!this._current || !this._current.overlay) return null;
        style = style || {};
        var overlay = this._current.overlay;
        var shapeType = this._current.shapeType;
        var metrics = MapDrawTool.metricsForOverlay(overlay, shapeType);

        if (shapeType === 'polyline') {
            overlay.setOptions({
                strokeColor: style.color || this._opts.polylineColor,
                strokeWeight: 3, editable: false, draggable: false,
            });
        } else {
            overlay.setOptions({
                fillColor: style.color || this._opts.polygonColor,
                fillOpacity: style.fillOpacity != null ? style.fillOpacity : 0.3,
                strokeColor: style.strokeColor || style.color || this._opts.polygonStroke,
                editable: false, draggable: false,
            });
        }

        this._current = null;
        return { overlay: overlay, shapeType: shapeType, metrics: metrics };
    };

    /** A color from the palette by index (wraps). */
    MapDrawTool.prototype.colorAt = function (i) {
        var c = this._opts.colors;
        return c[((i % c.length) + c.length) % c.length];
    };

    // ── Rendering existing shapes ───────────────────────────────────────────────

    /**
     * Draw a previously-saved shape from coordinates.
     * @param coords  [{lat,lng}, ...] OR [[lat,lng], ...]
     * @param style   {shapeType, color, strokeColor, fillOpacity, weight, dashArray, editable, label}
     * @return the Google Maps overlay (or null)
     */
    MapDrawTool.prototype.renderShape = function (coords, style) {
        if (!this._map || !coords || !coords.length) return null;
        style = style || {};
        var path = MapDrawTool._toLatLngArray(coords);
        if (!path.length) return null;

        var overlay;
        if (style.shapeType === 'polyline') {
            overlay = new google.maps.Polyline({
                path: path,
                strokeColor: style.color || this._opts.polylineColor,
                strokeWeight: style.weight || 3,
                editable: !!style.editable,
                map: this._map,
            });
        } else {
            overlay = new google.maps.Polygon({
                paths: path,
                fillColor: style.color || this._opts.polygonColor,
                fillOpacity: style.fillOpacity != null ? style.fillOpacity : 0.25,
                strokeColor: style.strokeColor || style.color || this._opts.polygonStroke,
                strokeWeight: style.weight || 2,
                editable: !!style.editable,
                map: this._map,
            });
        }
        return overlay;
    };

    MapDrawTool.prototype.removeOverlay = function (overlay) {
        if (overlay) overlay.setMap(null);
    };

    /**
     * Make a rendered polygon's vertices draggable. Fires cb(coords) — debounced —
     * whenever the user drags, inserts, or removes a node. coords = [{lat,lng},...].
     * Returns the overlay (now editable).
     */
    MapDrawTool.prototype.bindZoneEdit = function (overlay, cb) {
        if (!overlay || !overlay.getPath) return overlay;
        overlay.setEditable(true);
        var timer = null;
        var fire = function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                cb(MapDrawTool._pathToCoords(overlay.getPath()));
            }, 600);
        };
        var path = overlay.getPath();
        google.maps.event.addListener(path, 'set_at', fire);
        google.maps.event.addListener(path, 'insert_at', fire);
        google.maps.event.addListener(path, 'remove_at', fire);
        return overlay;
    };

    MapDrawTool.prototype.zoomTo = function (overlay) {
        if (!overlay || !this._map) return;
        var bounds = new google.maps.LatLngBounds();
        if (overlay.getPath) {
            overlay.getPath().forEach(function (p) { bounds.extend(p); });
        } else if (overlay.getBounds) {
            bounds = overlay.getBounds();
        } else { return; }
        this._map.fitBounds(bounds);
    };

    /** Fit the viewport to a list of coordinate arrays. */
    MapDrawTool.prototype.fitAll = function (coordArrays) {
        if (!this._map || !coordArrays || !coordArrays.length) return;
        var bounds = new google.maps.LatLngBounds();
        var any = false;
        coordArrays.forEach(function (coords) {
            MapDrawTool._toLatLngArray(coords).forEach(function (ll) { bounds.extend(ll); any = true; });
        });
        if (any) { try { this._map.fitBounds(bounds); } catch (e) {} }
    };

    MapDrawTool.prototype.setMapType = function (type) {
        if (this._map) this._map.setMapTypeId(type);
    };

    MapDrawTool.prototype.resize = function () {
        if (this._map) google.maps.event.trigger(this._map, 'resize');
    };

    MapDrawTool.prototype.geocode = function (address) {
        this._geocodeAddress(address);
    };

    // ── Internals ───────────────────────────────────────────────────────────────

    MapDrawTool.prototype._shapeStyle = function () {
        return {
            fillColor: this._opts.polygonColor,
            fillOpacity: 0.4,
            strokeWeight: 2,
            strokeColor: this._opts.polygonStroke,
            editable: true,
            draggable: true,
        };
    };

    MapDrawTool.prototype._bindEditListeners = function (overlay, type) {
        var self = this;
        var emit = function () { self._emitDraw(); };
        if (type === 'rectangle') {
            google.maps.event.addListener(overlay, 'bounds_changed', emit);
        } else if (overlay.getPath) {
            var path = overlay.getPath();
            google.maps.event.addListener(path, 'set_at', emit);
            google.maps.event.addListener(path, 'insert_at', emit);
            google.maps.event.addListener(path, 'remove_at', emit);
        }
    };

    MapDrawTool.prototype._emitDraw = function () {
        if (typeof this._opts.onDraw === 'function') {
            this._opts.onDraw(this.getCurrent());
        }
    };

    MapDrawTool.prototype._geocodeAddress = function (address) {
        if (!this._geocoder || !address) return;
        var self = this;
        this._geocoder.geocode({ address: address }, function (results, status) {
            if (status === 'OK' && results[0]) {
                var loc = results[0].geometry.location;
                self._map.setCenter(loc);
                self._map.setZoom(self._opts.zoom);
                if (self._opts.marker) {
                    new google.maps.Marker({ map: self._map, position: loc, title: 'Property' });
                }
            }
        });
    };

    MapDrawTool.prototype._fail = function (msg) {
        if (typeof this._opts.onError === 'function') this._opts.onError(msg);
        else if (typeof console !== 'undefined') console.error('[MapDrawTool] ' + msg);
    };

    // ── Static: geometry ────────────────────────────────────────────────────────

    /**
     * Compute metrics for a Google Maps overlay.
     * @return {shapeType, coords:[{lat,lng}], sqMeters, sqFeet, acres,
     *          perimeterMeters, perimeterFeet, linearMeters, linearFeet}
     */
    MapDrawTool.metricsForOverlay = function (overlay, shapeType) {
        var area = 0, perimeter = 0, linear = 0, coords = [];

        if (shapeType === 'polyline') {
            var lpath = overlay.getPath();
            linear = google.maps.geometry.spherical.computeLength(lpath);
            coords = MapDrawTool._pathToCoords(lpath);
        } else if (overlay.getPath) {
            // Polygon
            var path = overlay.getPath();
            area = google.maps.geometry.spherical.computeArea(path);
            var n = path.getLength();
            for (var i = 0; i < n; i++) {
                perimeter += google.maps.geometry.spherical.computeDistanceBetween(
                    path.getAt(i), path.getAt((i + 1) % n));
            }
            coords = MapDrawTool._pathToCoords(path);
        } else if (overlay.getBounds) {
            // Rectangle
            var b = overlay.getBounds();
            var ne = b.getNorthEast(), sw = b.getSouthWest();
            var nw = new google.maps.LatLng(ne.lat(), sw.lng());
            var se = new google.maps.LatLng(sw.lat(), ne.lng());
            var ring = [nw, ne, se, sw];
            area = google.maps.geometry.spherical.computeArea(ring);
            var w = google.maps.geometry.spherical.computeDistanceBetween(nw, ne);
            var h = google.maps.geometry.spherical.computeDistanceBetween(ne, se);
            perimeter = 2 * (w + h);
            coords = ring.map(function (p) { return { lat: p.lat(), lng: p.lng() }; });
        }

        return {
            shapeType:       shapeType || 'polygon',
            coords:          coords,
            sqMeters:        area,
            sqFeet:          area * M2_TO_FT2,
            acres:           area * M2_TO_ACRE,
            perimeterMeters: perimeter,
            perimeterFeet:   perimeter * M_TO_FT,
            linearMeters:    linear,
            linearFeet:      linear * M_TO_FT,
        };
    };

    MapDrawTool._pathToCoords = function (path) {
        return path.getArray().map(function (p) { return { lat: p.lat(), lng: p.lng() }; });
    };

    /** Accept [{lat,lng}] or [[lat,lng]] → [google.maps.LatLng]. */
    MapDrawTool._toLatLngArray = function (coords) {
        var out = [];
        if (!coords) return out;
        coords.forEach(function (c) {
            if (c == null) return;
            if (Array.isArray(c)) {
                out.push(new google.maps.LatLng(c[0], c[1]));
            } else if (c.lat != null && c.lng != null) {
                out.push(new google.maps.LatLng(c.lat, c.lng));
            }
        });
        return out;
    };

    // ── Static: coordinate format helpers ───────────────────────────────────────

    /** [{lat,lng}] → [[lat,lng]] (geofence "ring" format). */
    MapDrawTool.ringFromCoords = function (coords) {
        return (coords || []).map(function (c) {
            return Array.isArray(c) ? [c[0], c[1]] : [c.lat, c.lng];
        });
    };

    /** [[lat,lng]] → [{lat,lng}] (measurement "coords" format). */
    MapDrawTool.coordsFromRing = function (ring) {
        return (ring || []).map(function (p) {
            return Array.isArray(p) ? { lat: p[0], lng: p[1] } : p;
        });
    };

    /** Parse a property_measurements.polygon_coords value (JSON string or array). */
    MapDrawTool.parsePolygonCoords = function (raw) {
        if (!raw) return [];
        if (typeof raw === 'string') {
            try { raw = JSON.parse(raw); } catch (e) { return []; }
        }
        return Array.isArray(raw) ? raw : [];
    };

    // ── Static: save adapters ───────────────────────────────────────────────────

    /**
     * Save measurements for a property (replace-all). Posts to the shared
     * measurements API which calls saveMeasurementsForProperty().
     * @param measurements [{name,type,sqFt,perimeter,coords:[{lat,lng}],shape,linearFt}]
     */
    MapDrawTool.saveMeasurements = function (apiUrl, propertyId, csrfToken, measurements) {
        // The measurements API resolves the action from the query string.
        var url = apiUrl + (apiUrl.indexOf('?') === -1 ? '?' : '&') + 'action=save';
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token:   csrfToken,
                property_id:  propertyId,
                measurements: measurements,
            }),
        }).then(function (r) { return r.json(); });
    };

    /**
     * Save one zone (arrival_border or work_zone) to job_geofences.
     * @param o {csrfToken, propertyId, zoneType, planId?, coords|ring, label?, notes?}
     */
    MapDrawTool.saveZone = function (apiUrl, o) {
        var ring = o.ring || MapDrawTool.ringFromCoords(o.coords);
        return fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                action:      'save_zone',
                csrf_token:  o.csrfToken,
                property_id: o.propertyId,
                zone_type:   o.zoneType,
                plan_id:     o.planId || null,
                ring:        ring,
                label:       o.label || null,
                notes:       o.notes || null,
            }),
        }).then(function (r) { return r.json(); });
    };

    MapDrawTool.loadZones = function (apiUrl, propertyId) {
        var url = apiUrl + '?action=get_zones&property_id=' + encodeURIComponent(propertyId);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { return (d && d.success && d.zones) ? d.zones : []; });
    };

    /**
     * Persist an edited zone's new polygon (node drag/insert/remove).
     * @param o {csrfToken, geofenceId, coords|ring}
     */
    MapDrawTool.updateZone = function (apiUrl, o) {
        var ring = o.ring || MapDrawTool.ringFromCoords(o.coords);
        return fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                action:      'update_zone',
                csrf_token:  o.csrfToken,
                geofence_id: o.geofenceId,
                ring:        ring,
            }),
        }).then(function (r) { return r.json(); });
    };

    MapDrawTool.deleteZone = function (apiUrl, csrfToken, geofenceId) {
        return fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                action: 'delete_zone',
                csrf_token: csrfToken,
                geofence_id: geofenceId,
            }),
        }).then(function (r) { return r.json(); });
    };

    // ── Export ──────────────────────────────────────────────────────────────────

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { MapDrawTool: MapDrawTool };
    } else {
        global.MapDrawTool = MapDrawTool;
    }

})(typeof window !== 'undefined' ? window : this);
