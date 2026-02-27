/**
 * GeofenceManager — Polygon draw / edit / display for the Mowology CRM.
 *
 * Depends on:  Leaflet.js (maps), vanilla JS only, no frameworks.
 * Used by:     Plan detail page, Visit work page (display-only mode).
 *
 * Public API:
 *
 *   const gm = new GeofenceManager({
 *     mapContainer: 'map',          // DOM element id or Element
 *     apiBase:      '/crm/api/geofence.php',
 *     csrfToken:    '...',
 *     planId:       42,             // required for save/delete
 *     mode:         'edit',         // 'edit' | 'view'
 *     center:       [49.28, -123.12],  // initial map center
 *     zoom:         17,
 *     onSave:       (geofenceId) => {},
 *     onDelete:     () => {},
 *     onInZoneChange: (inZone) => {},  // called during live tracking
 *   });
 *
 *   gm.init();           // init map + load existing polygon
 *   gm.startDraw();      // switch to drawing mode
 *   gm.cancelDraw();     // discard in-progress draw
 *   gm.save();           // POST polygon to server
 *   gm.deletePolygon();  // DELETE polygon from server
 *   gm.setPolygon(ring); // programmatic polygon set (array of [lat,lng])
 *   gm.checkPoint(lat,lng); // returns {inZone: bool} using stored polygon
 *   gm.destroy();        // tear down map + listeners
 *
 * ── Notes on Leaflet dependency ──────────────────────────────────────────────
 * Leaflet must be loaded before this file. If using Google Maps instead, swap
 * the _initMap / _renderPolygon / _clearPolygon / _startDraw internals only —
 * the public API and server communication stay the same.
 */

/* global L */

'use strict';

class GeofenceManager {

    // ──────────────────────────────────────────────────────────────────────────
    // Constructor & Config
    // ──────────────────────────────────────────────────────────────────────────

    constructor(opts = {}) {
        this._opts = Object.assign({
            mapContainer:     'geofence-map',
            apiBase:          '/crm/api/geofence.php',
            csrfToken:        '',
            planId:           null,
            mode:             'view',   // 'edit' | 'view'
            center:           [49.2827, -123.1207],
            zoom:             17,
            tileUrl:          'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            tileAttrib:       '© OpenStreetMap contributors',
            strokeColor:      '#2D8659',
            fillColor:        '#2D8659',
            fillOpacity:      0.12,
            onSave:           null,
            onDelete:         null,
            onLoad:           null,   // (hasPolygon: bool) called after get_polygon resolves
            onInZoneChange:   null,
        }, opts);

        this._map          = null;
        this._polygonLayer = null;
        this._drawMarkers  = [];       // temp markers during draw
        this._drawLatLngs  = [];       // vertices being drawn
        this._drawLine     = null;     // polyline preview during draw
        this._ring         = [];       // saved ring [[lat,lng], ...]
        this._bbox         = null;     // {lat_min, lat_max, lng_min, lng_max}
        this._isDrawing    = false;
        this._isSaving     = false;
        this._geofenceId   = null;

        // Current in-zone state (for live feedback)
        this._lastInZone   = null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────────────────────────────────

    init() {
        this._initMap();
        if (this._opts.planId) {
            this._loadPolygon();
        }
        return this;
    }

    destroy() {
        if (this._map) {
            this._map.remove();
            this._map = null;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Map setup
    // ──────────────────────────────────────────────────────────────────────────

    _initMap() {
        const container = typeof this._opts.mapContainer === 'string'
            ? document.getElementById(this._opts.mapContainer)
            : this._opts.mapContainer;

        if (!container) {
            console.warn('[GeofenceManager] Map container not found:', this._opts.mapContainer);
            return;
        }

        // Leaflet must be available
        if (typeof L === 'undefined') {
            console.error('[GeofenceManager] Leaflet.js is not loaded.');
            return;
        }

        this._map = L.map(container, {
            center:    this._opts.center,
            zoom:      this._opts.zoom,
            zoomControl: true,
        });

        L.tileLayer(this._opts.tileUrl, {
            attribution: this._opts.tileAttrib,
            maxZoom:     19,
        }).addTo(this._map);

        if (this._opts.mode === 'edit') {
            this._map.on('click', (e) => this._onMapClick(e));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Load existing polygon from server
    // ──────────────────────────────────────────────────────────────────────────

    _loadPolygon() {
        const url = `${this._opts.apiBase}?action=get_polygon&plan_id=${this._opts.planId}`;
        fetch(url, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                const hasPolygon = !!(data.polygon && data.polygon.ring);
                if (hasPolygon) {
                    this._geofenceId = data.polygon.id;
                    this._ring       = data.polygon.ring;
                    this._bbox       = data.polygon.bbox || null;
                    this._renderPolygon(data.polygon.ring);
                    this._fitBounds();
                }
                if (typeof this._opts.onLoad === 'function') {
                    this._opts.onLoad(hasPolygon);
                }
            })
            .catch(err => console.warn('[GeofenceManager] Load polygon failed:', err));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Polygon rendering
    // ──────────────────────────────────────────────────────────────────────────

    _renderPolygon(ring) {
        this._clearPolygon();
        if (!ring || ring.length < 3 || !this._map) return;

        const latLngs = ring.map(pt => L.latLng(pt[0], pt[1]));
        this._polygonLayer = L.polygon(latLngs, {
            color:       this._opts.strokeColor,
            fillColor:   this._opts.fillColor,
            fillOpacity: this._opts.fillOpacity,
            weight:      2.5,
            dashArray:   this._opts.mode === 'edit' ? '6 4' : null,
        }).addTo(this._map);
    }

    _clearPolygon() {
        if (this._polygonLayer) {
            this._map.removeLayer(this._polygonLayer);
            this._polygonLayer = null;
        }
    }

    _fitBounds() {
        if (this._ring.length && this._map) {
            const latLngs = this._ring.map(pt => L.latLng(pt[0], pt[1]));
            try { this._map.fitBounds(L.latLngBounds(latLngs).pad(0.1)); } catch {}
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Draw mode
    // ──────────────────────────────────────────────────────────────────────────

    startDraw() {
        if (this._opts.mode !== 'edit') return;
        this._isDrawing  = true;
        this._drawLatLngs = [];
        this._clearDrawMarkers();
        this._clearPolygon();

        // Visual hint
        if (this._map) {
            this._map.getContainer().style.cursor = 'crosshair';
            this._showToast('Tap/click to add polygon vertices. Double-click to finish.', 'info');
        }
    }

    cancelDraw() {
        this._isDrawing = false;
        this._clearDrawMarkers();
        if (this._drawLine) { this._map.removeLayer(this._drawLine); this._drawLine = null; }
        if (this._map) this._map.getContainer().style.cursor = '';
        // Re-render saved polygon if any
        if (this._ring.length) this._renderPolygon(this._ring);
    }

    _onMapClick(e) {
        if (!this._isDrawing) return;
        const { lat, lng } = e.latlng;

        this._drawLatLngs.push([lat, lng]);

        // Marker for each vertex
        const marker = L.circleMarker([lat, lng], {
            radius:      5,
            color:       '#0D3B2E',
            fillColor:   '#7FD858',
            fillOpacity: 1,
            weight:      2,
        }).addTo(this._map);
        this._drawMarkers.push(marker);

        // Update preview polyline
        if (this._drawLine) this._map.removeLayer(this._drawLine);
        if (this._drawLatLngs.length > 1) {
            this._drawLine = L.polyline(this._drawLatLngs, {
                color: this._opts.strokeColor,
                weight: 2,
                dashArray: '5 5',
            }).addTo(this._map);
        }

        // Double-click (Leaflet fires click then dblclick — we detect ≥3 vertices + fast click)
        if (e.originalEvent && e.originalEvent.detail >= 2 && this._drawLatLngs.length >= 3) {
            this._finishDraw();
        }
    }

    _finishDraw() {
        if (this._drawLatLngs.length < 3) {
            this._showToast('Need at least 3 vertices to form a polygon.', 'warning');
            return;
        }
        const ring = [...this._drawLatLngs];
        this._isDrawing = false;
        this._clearDrawMarkers();
        if (this._drawLine) { this._map.removeLayer(this._drawLine); this._drawLine = null; }
        if (this._map) this._map.getContainer().style.cursor = '';

        this._ring = ring;
        this._bbox = this._computeBbox(ring);
        this._renderPolygon(ring);
        this._fitBounds();
        this._showToast(`Polygon drawn (${ring.length} vertices). Click Save to confirm.`, 'success');
    }

    _clearDrawMarkers() {
        this._drawMarkers.forEach(m => { if (this._map) this._map.removeLayer(m); });
        this._drawMarkers = [];
        this._drawLatLngs = [];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Save / Delete
    // ──────────────────────────────────────────────────────────────────────────

    save(label = '', notes = '') {
        if (this._isSaving) return Promise.reject(new Error('Already saving'));
        if (this._ring.length < 3) return Promise.reject(new Error('No polygon to save'));
        if (!this._opts.planId) return Promise.reject(new Error('planId not set'));

        this._isSaving = true;

        const body = JSON.stringify({
            action:     'save_polygon',
            csrf_token: this._opts.csrfToken,
            plan_id:    this._opts.planId,
            ring:       this._ring,
            label:      label || null,
            notes:      notes || null,
        });

        return fetch(this._opts.apiBase, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body,
        })
        .then(r => r.json())
        .then(data => {
            this._isSaving = false;
            if (!data.success) throw new Error(data.error || 'Save failed');
            this._geofenceId = data.geofence_id;
            this._showToast('Polygon saved.', 'success');
            if (typeof this._opts.onSave === 'function') {
                this._opts.onSave(data.geofence_id);
            }
            return data;
        })
        .catch(err => {
            this._isSaving = false;
            this._showToast('Save failed: ' + err.message, 'error');
            throw err;
        });
    }

    deletePolygon() {
        if (!this._opts.planId) return Promise.reject(new Error('planId not set'));

        const body = JSON.stringify({
            action:     'delete_polygon',
            csrf_token: this._opts.csrfToken,
            plan_id:    this._opts.planId,
        });

        return fetch(this._opts.apiBase, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body,
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Delete failed');
            this._geofenceId = null;
            this._ring       = [];
            this._bbox       = null;
            this._clearPolygon();
            this._showToast('Polygon deleted.', 'success');
            if (typeof this._opts.onDelete === 'function') this._opts.onDelete();
            return data;
        })
        .catch(err => {
            this._showToast('Delete failed: ' + err.message, 'error');
            throw err;
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Programmatic polygon set (called by live tracking context)
    // ──────────────────────────────────────────────────────────────────────────

    setPolygon(ring, geofenceId = null) {
        this._ring       = ring || [];
        this._bbox       = ring ? this._computeBbox(ring) : null;
        this._geofenceId = geofenceId;
        if (this._map) {
            this._renderPolygon(ring);
            this._fitBounds();
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Point-in-polygon check (client-side, mirrors server logic)
    // ──────────────────────────────────────────────────────────────────────────

    checkPoint(lat, lng) {
        if (!this._ring.length || this._ring.length < 3) {
            return { inZone: false, noPolygon: true };
        }

        // Bounding box fast rejection
        if (this._bbox) {
            if (lat < this._bbox.lat_min || lat > this._bbox.lat_max) return { inZone: false };
            if (lng < this._bbox.lng_min || lng > this._bbox.lng_max) return { inZone: false };
        }

        const inZone = this._pointInPolygon(lat, lng, this._ring);

        // Fire onInZoneChange if status changed
        if (inZone !== this._lastInZone) {
            this._lastInZone = inZone;
            if (typeof this._opts.onInZoneChange === 'function') {
                this._opts.onInZoneChange(inZone);
            }
        }

        return { inZone };
    }

    _pointInPolygon(lat, lng, polygon) {
        let inside = false;
        const n = polygon.length;
        let j = n - 1;
        for (let i = 0; i < n; i++) {
            const xi = polygon[i][0], yi = polygon[i][1];
            const xj = polygon[j][0], yj = polygon[j][1];
            const intersect = ((yi > lng) !== (yj > lng))
                && (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
            j = i;
        }
        return inside;
    }

    _computeBbox(ring) {
        if (!ring || !ring.length) return null;
        const lats = ring.map(p => p[0]);
        const lngs = ring.map(p => p[1]);
        return {
            lat_min: Math.min(...lats),
            lat_max: Math.max(...lats),
            lng_min: Math.min(...lngs),
            lng_max: Math.max(...lngs),
        };
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Current player position marker (updated by LocationSampler)
    // ──────────────────────────────────────────────────────────────────────────

    updateCrewPosition(lat, lng, inZone) {
        if (!this._map) return;

        const color = inZone ? '#2D8659' : '#dc3545';

        if (!this._crewMarker) {
            this._crewMarker = L.circleMarker([lat, lng], {
                radius: 9, color, fillColor: color, fillOpacity: 0.8, weight: 2,
            }).addTo(this._map);
        } else {
            this._crewMarker.setLatLng([lat, lng]);
            this._crewMarker.setStyle({ color, fillColor: color });
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // UI helpers
    // ──────────────────────────────────────────────────────────────────────────

    _showToast(msg, type = 'info') {
        // Try to use the app's existing toast system; fall back to console
        if (window.showToast) {
            window.showToast(msg, type);
        } else if (window.mowToast) {
            window.mowToast(msg, type);
        } else {
            console.log(`[GeofenceManager][${type}] ${msg}`);
        }
    }

    // Getters
    get ring()       { return this._ring; }
    get geofenceId() { return this._geofenceId; }
    get hasPolygon() { return this._ring.length >= 3; }
    get isDrawing()  { return this._isDrawing; }
}

// Export for both module and global contexts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { GeofenceManager };
} else {
    window.GeofenceManager = GeofenceManager;
}
