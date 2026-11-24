/* Moved out of public/crm/includes/clients/view-contact-modals.php (clients refactor, no change in behaviour). PHP values arrive in window.mwClients.clients_workzone. */
(function() {
  // ── PHP data ─────────────────────────────────────────────────────────────
  var WZ_PLANS_BY_PROP = mwClients.clients_workzone.c0;
  var WZ_CSRF          = mwClients.clients_workzone.c1;

  var wzMgr       = null;
  var wzPropLat   = 49.2827;
  var wzPropLng   = -123.1207;
  var wzMapReady  = false;

  // ── Open modal ────────────────────────────────────────────────────────────
  window.openWorkZoneModal = function(propId, lat, lng) {
    wzPropLat = lat && lat !== 0 ? lat : 49.2827;
    wzPropLng = lng && lng !== 0 ? lng : -123.1207;

    var plans = WZ_PLANS_BY_PROP[propId] || [];
    var sel   = document.getElementById('wz-plan-select');
    sel.innerHTML = '<option value="">-- Select a plan --</option>';
    plans.forEach(function(pl) {
      var opt   = document.createElement('option');
      opt.value = pl.id;
      var label = pl.plan_number;
      if (pl.title)             label += ' — ' + pl.title;
      else if (pl.service_type) label += ' — ' + pl.service_type.replace(/_/g,' ');
      if (pl.status !== 'active') label += ' (' + pl.status + ')';
      opt.textContent = label;
      sel.appendChild(opt);
    });

    // Auto-select if exactly one plan
    if (plans.length === 1) sel.value = plans[0].id;

    wzSetControls(false);
    wzShowStatus('');
    wzSetStep(1);
    wzSetHint('Select a job plan above to load the map and start drawing.', 'info');
    wzShowDrawTips(false);
    $('#workZoneModal').modal('show');
  };

  // ── Modal shown / hidden — bind after jQuery+Bootstrap have loaded ───────
  document.addEventListener('DOMContentLoaded', function() {
    $('#workZoneModal').on('shown.bs.modal', function() {
      wzMapReady = true;
      var planId = parseInt(document.getElementById('wz-plan-select').value) || null;
      if (planId) {
        wzInitMap(planId);
      } else {
        wzShowMapPlaceholder(true);
        wzSetStep(1);
        wzSetHint('Select a job plan above to load the map and start drawing.', 'info');
      }
      wzSafeFeather();
    });

    $('#workZoneModal').on('hidden.bs.modal', function() {
      wzDestroyMap();
      wzMapReady  = false;
      wzShowDrawTips(false);
    });
  });

  // ── Plan selector changed ─────────────────────────────────────────────────
  window.onWZPlanChange = function() {
    if (!wzMapReady) return;
    var planId = parseInt(document.getElementById('wz-plan-select').value) || null;
    wzDestroyMap();
    wzShowDrawTips(false);
    if (planId) {
      wzInitMap(planId);
    } else {
      wzShowMapPlaceholder(true);
      wzSetControls(false);
      wzSetStep(1);
      wzSetHint('Select a job plan above to load the map and start drawing.', 'info');
    }
  };

  function wzInitMap(planId) {
    wzShowMapPlaceholder(false);
    if (wzMgr) { wzMgr.destroy(); wzMgr = null; }

    wzMgr = new GeofenceManager({
      mapContainer: 'wz-map',
      planId:       planId,
      csrfToken:    WZ_CSRF,
      mode:         'edit',
      center:       [wzPropLat, wzPropLng],
      zoom:         18,
      onLoad: function(hasPolygon) {
        // Called after get_polygon response — show correct state
        if (hasPolygon) {
          wzSetStep(3, 'done');
          wzSetHint('Zone is active — crew will auto-track when they enter this area. Click <strong>Draw Zone</strong> to redraw it.', 'success');
        } else {
          wzSetStep(2);
          wzSetHint('Click <strong>Draw Zone</strong> below to start drawing the property boundary on the map.', 'info');
        }
        wzSetControls(true);
      },
      onSave: function(id) {
        wzShowStatus('Work zone saved — GPS auto-tracking activates on the next crew visit.', 'success');
        wzSetStep(3, 'done');
        wzSetHint('Zone saved! Crew will auto clock-in when they enter this area.', 'success');
        wzShowDrawTips(false);
        wzSetControls(true);
        document.getElementById('wz-draw-btn').style.display        = 'inline-block';
        document.getElementById('wz-cancel-draw-btn').style.display = 'none';
      },
      onDraw: function() {
        document.getElementById('wz-save-btn').disabled              = false;
        document.getElementById('wz-draw-btn').style.display         = 'inline-block';
        document.getElementById('wz-cancel-draw-btn').style.display  = 'none';
        wzSetStep(2);
        wzSetHint('Zone outlined — click <strong>Save Zone</strong> to activate GPS tracking.', 'success');
        wzShowDrawTips(false);
        wzSafeFeather();
      },
      onDelete: function() {
        wzShowStatus('Work zone removed.', 'info');
        wzSetStep(2);
        wzSetHint('Zone removed. Click <strong>Draw Zone</strong> to create a new boundary.', 'info');
        wzShowDrawTips(false);
        wzSetControls(true);
      },
    });
    wzMgr.init();

    // Fallback hint while map is loading (onLoad callback will update it)
    wzSetStep(2);
    wzSetHint('Map loading — click <strong>Draw Zone</strong> to outline the property boundary.', 'info');
    wzSetControls(true);
  }

  function wzDestroyMap() {
    if (wzMgr) { wzMgr.destroy(); wzMgr = null; }
  }

  // ── Button actions ────────────────────────────────────────────────────────
  window.wzStartDraw = function() {
    if (!wzMgr) return;
    wzMgr.startDraw();
    document.getElementById('wz-draw-btn').style.display        = 'none';
    document.getElementById('wz-cancel-draw-btn').style.display = 'inline-block';
    document.getElementById('wz-save-btn').disabled = true;
    wzSetStep(2);
    wzSetHint('Drawing mode active — click the map to place corners, then double-click to close the shape.', 'draw');
    wzShowDrawTips(true);
    wzSafeFeather();
  };

  window.wzCancelDraw = function() {
    if (!wzMgr) return;
    wzMgr.cancelDraw();
    document.getElementById('wz-draw-btn').style.display        = 'inline-block';
    document.getElementById('wz-cancel-draw-btn').style.display = 'none';
    wzSetStep(2);
    wzSetHint('Draw cancelled. Click <strong>Draw Zone</strong> again to start over.', 'info');
    wzShowDrawTips(false);
    wzSafeFeather();
  };

  window.wzSave = function() {
    if (!wzMgr) return;
    document.getElementById('wz-draw-btn').style.display        = 'inline-block';
    document.getElementById('wz-cancel-draw-btn').style.display = 'none';
    wzSetHint('Saving zone\u2026', 'info');
    wzMgr.save();
    wzSafeFeather();
  };

  window.wzDeleteZone = function() {
    if (!wzMgr) return;
    if (!confirm('Remove the work zone for this plan?\nThe crew will no longer be auto-tracked at this property.')) return;
    wzMgr.deletePolygon();
  };

  // ── Step indicator ────────────────────────────────────────────────────────
  function wzSetStep(n, doneTo) {
    [1, 2, 3].forEach(function(i) {
      var el = document.getElementById('wz-step-' + i);
      if (!el) return;
      el.className = 'mw-wz-step';
      if (doneTo === 'done' && i <= n) {
        el.className += ' mw-wz-step--done';
      } else if (i === n) {
        el.className += ' mw-wz-step--active';
      } else if (i < n) {
        el.className += ' mw-wz-step--done';
      }
    });
  }

  // ── Contextual hint ───────────────────────────────────────────────────────
  function wzSetHint(html, variant) {
    var el   = document.getElementById('wz-hint');
    var text = document.getElementById('wz-hint-text');
    if (!el || !text) return;
    text.innerHTML = html;
    el.className   = 'mw-wz-hint mt-2 mw-wz-hint--' + (variant || 'info');
  }

  // ── Drawing tips panel ────────────────────────────────────────────────────
  function wzShowDrawTips(show) {
    var el = document.getElementById('wz-draw-tips');
    if (el) el.style.display = show ? 'block' : 'none';
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function wzSetControls(enabled) {
    ['wz-draw-btn', 'wz-save-btn', 'wz-delete-btn'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.disabled = !enabled;
    });
    if (!enabled) {
      document.getElementById('wz-cancel-draw-btn').style.display = 'none';
      document.getElementById('wz-draw-btn').style.display        = 'inline-block';
    }
  }

  function wzShowMapPlaceholder(show) {
    var map = document.getElementById('wz-map');
    var ph  = document.getElementById('wz-map-placeholder');
    if (map) map.style.display = show ? 'none' : 'block';
    if (ph)  ph.style.display  = show ? 'flex'  : 'none';
  }

  function wzShowStatus(msg, type) {
    var el = document.getElementById('wz-status');
    if (!el) return;
    if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
    el.className = 'mw-wz-status mt-2 alert alert-' + (type === 'success' ? 'success' : type === 'info' ? 'info' : 'secondary');
    el.textContent = msg;
    el.style.display = 'block';
  }

  function wzSafeFeather() {
    if (typeof feather !== 'undefined') feather.replace();
  }
})();
