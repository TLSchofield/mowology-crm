/**
 * ZoneEditorManager — Multi-zone map editor for the Mowology CRM.
 *
 * Manages an arrival border polygon + multiple named work zone polygons
 * on a single Leaflet map for a property. Extends the single-polygon
 * GeofenceManager concept to the multi-zone model.
 *
 * Depends on: Leaflet.js, vanilla JS only.
 *
 * Usage:
 *   const zm = new ZoneEditorManager({
 *     mapContainer: 'zone-map',
 *     apiBase:      '/crm/api/geofence.php',
 *     csrfToken:    '...',
 *     propertyId:   42,
 *     plans:        [{id: 1, title: 'Lawn Mowing'}, ...],
 *     center:       [49.28, -123.12],
 *     zoom:         17,
 *     onZonesChanged: (zones) => {},
 *   });
 *   zm.init();
 */

/* global L */
'use strict';

const ZONE_COLORS = [
    { stroke: '#2D8659', fill: '#2D8659' },  // mw-green
    { stroke: '#e85d04', fill: '#e85d04' },  // mw-orange
    { stroke: '#0d6efd', fill: '#0d6efd' },  // blue
    { stroke: '#6f42c1', fill: '#6f42c1' },  // purple
    { stroke: '#20c997', fill: '#20c997' },  // teal
    { stroke: '#fd7e14', fill: '#fd7e14' },  // orange
    { stroke: '#dc3545', fill: '#dc3545' },  // red
    { stroke: '#0dcaf0', fill: '#0dcaf0' },  // cyan
];

class ZoneEditorManager {

    constructor(opts = {}) {
        this._opts = Object.assign({
            mapContainer:    'zone-map',
            apiBase:         '/crm/api/geofence.php',
            csrfToken:       '',
            propertyId:      null,
            plans:           [],
            center:          [49.2827, -123.1207],
            zoom:            17,
            tileUrl:         'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            tileAttrib:      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            labelsUrl:       null,
            satelliteUrl:    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            satelliteAttrib: 'Tiles &copy; Esri &mdash; Esri, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP',
            onZonesChanged:  null,
        }, opts);

        this._map         = null;
        this._zones       = [];   // [{id, zone_type, plan_id, label, ring, layer, colorIdx}, ...]
        this._drawMode    = null; // 'arrival_border' | 'work_zone' | null
        this._drawLatLngs = [];
        this._drawMarkers = [];
        this._drawLine    = null;
        this._isDrawing   = false;
        this._pendingPlanId = null;
        this._pendingLabel  = '';
        this._colorCounter  = 0;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    init() {
        this._initMap();
        if (this._opts.propertyId) {
            this._loadZones();
        }
        this._listenForTileMessages();
        return this;
    }

    destroy() {
        if (this._map) { this._map.remove(); this._map = null; }
    }

    // ── Map Setup ─────────────────────────────────────────────────────────────

    _initMap() {
        const container = typeof this._opts.mapContainer === 'string'
            ? document.getElementById(this._opts.mapContainer)
            : this._opts.mapContainer;

        if (!container || typeof L === 'undefined') return;

        this._map = L.map(container, { center: this._opts.center, zoom: this._opts.zoom });

        // Street map (default — always available)
        const street = L.tileLayer(this._opts.tileUrl, {
            attribution: this._opts.tileAttrib,
            maxNativeZoom: 19, maxZoom: 21,
        });

        // Satellite layer (optional — may be unavailable)
        let satellite = null;
        if (this._opts.satelliteUrl) {
            satellite = L.tileLayer(this._opts.satelliteUrl, {
                attribution: this._opts.satelliteAttrib || '',
                maxNativeZoom: 19, maxZoom: 21,
            });
        }

        // Satellite is default; fall back to street if no satellite URL
        (satellite || street).addTo(this._map);

        // Layer control for switching views
        const baseMaps = { 'Street': street };
        if (satellite) baseMaps['Satellite'] = satellite;
        if (Object.keys(baseMaps).length > 1) {
            L.control.layers(baseMaps, null, { position: 'topright' }).addTo(this._map);
        }

        if (this._opts.labelsUrl) {
            L.tileLayer(this._opts.labelsUrl, {
                attribution: '', maxNativeZoom: 19, maxZoom: 21, opacity: 0.85,
            }).addTo(this._map);
        }

        this._map.on('click', (e) => this._onMapClick(e));
        this._map.on('dblclick', () => {
            if (this._isDrawing && this._drawLatLngs.length >= 3) this._finishDraw();
        });
    }

    // ── Load Existing Zones ───────────────────────────────────────────────────

    _loadZones() {
        const url = `${this._opts.apiBase}?action=get_zones&property_id=${this._opts.propertyId}`;
        fetch(url, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.zones) {
                    data.zones.forEach(z => this._addZoneToMap(z));
                    this._fitAllZones();
                    this._notifyChanged();
                }
                // Pre-warm satellite tiles around the property for offline use
                this._triggerPrewarm();
            })
            .catch(err => console.warn('[ZoneEditorManager] Load zones failed:', err));
    }

    _addZoneToMap(zoneData) {
        const isArrival = zoneData.zone_type === 'arrival_border';
        const color = isArrival
            ? { stroke: '#FFD700', fill: '#FFD700' }
            : ZONE_COLORS[this._colorCounter++ % ZONE_COLORS.length];

        const layer = L.polygon(zoneData.ring.map(p => L.latLng(p[0], p[1])), {
            color:       color.stroke,
            fillColor:   color.fill,
            fillOpacity: isArrival ? 0.08 : 0.15,
            weight:      isArrival ? 3 : 2,
            dashArray:   isArrival ? '8 6' : null,
        });

        // Tooltip label
        const tooltipText = isArrival
            ? '⭕ Arrival Border'
            : ('📍 ' + (zoneData.label || 'Work Zone'));
        layer.bindTooltip(tooltipText, { permanent: false, direction: 'top' });

        layer.addTo(this._map);

        const entry = {
            id:        zoneData.id,
            zone_type: zoneData.zone_type || 'work_zone',
            plan_id:   zoneData.plan_id,
            label:     zoneData.label,
            ring:      zoneData.ring,
            layer,
            color,
        };
        this._zones.push(entry);
        return entry;
    }

    _fitAllZones() {
        if (!this._zones.length || !this._map) return;
        const allLatLngs = [];
        this._zones.forEach(z => z.ring.forEach(p => allLatLngs.push(L.latLng(p[0], p[1]))));
        if (allLatLngs.length) {
            try { this._map.fitBounds(L.latLngBounds(allLatLngs).pad(0.1)); } catch {}
        }
    }

    _triggerPrewarm() {
        // Determine the best center — use zone centroid if zones exist, else map center
        let lat, lng;
        if (this._zones.length > 0) {
            const allLats = [];
            const allLngs = [];
            this._zones.forEach(z => z.ring.forEach(p => { allLats.push(p[0]); allLngs.push(p[1]); }));
            lat = allLats.reduce((a, b) => a + b, 0) / allLats.length;
            lng = allLngs.reduce((a, b) => a + b, 0) / allLngs.length;
        } else if (this._map) {
            const c = this._map.getCenter();
            lat = c.lat;
            lng = c.lng;
        } else {
            lat = this._opts.center[0];
            lng = this._opts.center[1];
        }

        this.prewarmTiles(lat, lng, [17, 18, 19, 20]);
    }

    // ── Draw Mode ─────────────────────────────────────────────────────────────

    startDrawArrivalBorder() {
        this._drawMode    = 'arrival_border';
        this._pendingLabel  = 'Arrival Border';
        this._pendingPlanId = null;
        this._beginDraw();
    }

    startDrawWorkZone(planId, label) {
        this._drawMode    = 'work_zone';
        this._pendingPlanId = planId;
        this._pendingLabel  = label || 'Work Zone';
        this._beginDraw();
    }

    _beginDraw() {
        this._isDrawing   = true;
        this._drawLatLngs = [];
        this._clearDrawMarkers();
        if (this._map) {
            this._map.doubleClickZoom.disable();
            this._map.getContainer().style.cursor = 'crosshair';
        }
        this._showToast('Click to add vertices. Double-click or press Finish to close the shape.', 'info');
    }

    cancelDraw() {
        this._isDrawing = false;
        this._clearDrawMarkers();
        if (this._drawLine) { this._map.removeLayer(this._drawLine); this._drawLine = null; }
        if (this._map) {
            this._map.doubleClickZoom.enable();
            this._map.getContainer().style.cursor = '';
        }
    }

    finishDraw() {
        if (this._isDrawing) this._finishDraw();
    }

    _onMapClick(e) {
        if (!this._isDrawing) return;
        const { lat, lng } = e.latlng;
        this._drawLatLngs.push([lat, lng]);

        const marker = L.circleMarker([lat, lng], {
            radius: 5, color: '#0D3B2E', fillColor: '#7FD858', fillOpacity: 1, weight: 2,
        }).addTo(this._map);
        this._drawMarkers.push(marker);

        if (this._drawLine) this._map.removeLayer(this._drawLine);
        if (this._drawLatLngs.length > 1) {
            this._drawLine = L.polyline(this._drawLatLngs, {
                color: this._drawMode === 'arrival_border' ? '#FFD700' : '#2D8659',
                weight: 2, dashArray: '5 5',
            }).addTo(this._map);
        }
    }

    _finishDraw() {
        if (this._drawLatLngs.length < 3) {
            this._showToast('Need at least 3 vertices.', 'warning');
            return;
        }
        const ring = [...this._drawLatLngs];
        this.cancelDraw();

        // Save immediately to server
        this._saveZone(ring, this._drawMode, this._pendingPlanId, this._pendingLabel);
    }

    _clearDrawMarkers() {
        this._drawMarkers.forEach(m => { if (this._map) this._map.removeLayer(m); });
        this._drawMarkers = [];
        this._drawLatLngs = [];
    }

    // ── Save / Delete ─────────────────────────────────────────────────────────

    _saveZone(ring, zoneType, planId, label) {
        const body = JSON.stringify({
            action:      'save_zone',
            csrf_token:  this._opts.csrfToken,
            property_id: this._opts.propertyId,
            zone_type:   zoneType,
            plan_id:     planId || null,
            ring,
            label:       label || null,
        });

        fetch(this._opts.apiBase, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body,
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Save failed');
            // Add to map immediately
            const entry = this._addZoneToMap({
                id:        data.geofence_id,
                zone_type: zoneType,
                plan_id:   planId,
                label,
                ring,
            });
            this._showToast(data.message || 'Zone saved.', 'success');
            this._notifyChanged();
        })
        .catch(err => {
            this._showToast('Save failed: ' + err.message, 'error');
        });
    }

    deleteZone(geofenceId) {
        if (!confirm('Delete this zone?')) return;

        const body = JSON.stringify({
            action:      'delete_zone',
            csrf_token:  this._opts.csrfToken,
            geofence_id: geofenceId,
        });

        fetch(this._opts.apiBase, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body,
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Delete failed');
            // Remove from map
            const idx = this._zones.findIndex(z => z.id === geofenceId);
            if (idx >= 0) {
                if (this._map && this._zones[idx].layer) {
                    this._map.removeLayer(this._zones[idx].layer);
                }
                this._zones.splice(idx, 1);
            }
            this._showToast('Zone deleted.', 'success');
            this._notifyChanged();
        })
        .catch(err => this._showToast('Delete failed: ' + err.message, 'error'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    _notifyChanged() {
        if (typeof this._opts.onZonesChanged === 'function') {
            this._opts.onZonesChanged(this._zones);
        }
    }

    _showToast(msg, type = 'info') {
        if (window.showToast)  window.showToast(msg, type);
        else if (window.mowToast) window.mowToast(msg, type);
        else console.log(`[ZoneEditorManager][${type}] ${msg}`);
    }

    // ── Offline Tile Pre-warming ────────────────────────────────────────────

    /**
     * Request the service worker to pre-cache satellite tiles around a point.
     * Called automatically after zones load (centers on property).
     */
    prewarmTiles(lat, lng, zooms) {
        if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) return;

        navigator.serviceWorker.controller.postMessage({
            type:      'prewarm-tiles',
            lat:       lat,
            lng:       lng,
            zooms:     zooms || [17, 18, 19, 20],
            tileUrl:   this._opts.tileUrl,
            labelsUrl: this._opts.labelsUrl || null,
            radius:    2,
        });

        this._updateTileStatus('caching', 'Caching tiles for offline...');
    }

    /**
     * Listen for progress/completion messages from the service worker.
     */
    _listenForTileMessages() {
        if (!('serviceWorker' in navigator)) return;

        navigator.serviceWorker.addEventListener('message', (event) => {
            if (!event.data) return;

            if (event.data.type === 'prewarm-progress') {
                const pct = Math.round(event.data.done / event.data.total * 100);
                this._updateTileStatus('caching', 'Caching tiles... ' + pct + '%');
            }

            if (event.data.type === 'prewarm-complete') {
                const msg = event.data.fetched > 0
                    ? event.data.fetched + ' tiles cached for offline use'
                    : 'All tiles already cached';
                this._updateTileStatus('ready', msg);

                // Fade out after 4 seconds
                setTimeout(() => this._updateTileStatus('hidden'), 4000);
            }

            if (event.data.type === 'tile-cache-stats') {
                this._updateTileStatus('info', event.data.count + ' tiles in offline cache');
            }
        });
    }

    /**
     * Update the tile cache status indicator in the UI (if present).
     * Looks for an element with id="tileCacheStatus".
     */
    _updateTileStatus(state, text) {
        const el = document.getElementById('tileCacheStatus');
        if (!el) return;

        if (state === 'hidden') {
            el.style.display = 'none';
            return;
        }

        el.style.display = 'flex';
        const icon = state === 'caching' ? '&#9683;' : (state === 'ready' ? '&#10003;' : '&#9432;');
        el.innerHTML = '<span style="margin-right:6px;">' + icon + '</span> ' + (text || '');
        el.className = 'mw-tile-status mw-tile-status--' + state;
    }

    get zones()      { return this._zones; }
    get isDrawing()  { return this._isDrawing; }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ZoneEditorManager };
} else {
    window.ZoneEditorManager = ZoneEditorManager;
}
