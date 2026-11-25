<!-- ── Work Zone (Geofence) Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="workZoneModal" tabindex="-1" role="dialog" aria-labelledby="workZoneModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="workZoneModalLabel">
          <i data-feather="map-pin" class="mw-icon-sm"></i> Work Zone — Auto Clock-In Area
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-3">

        <!-- ── 3-Step Guide ──────────────────────────────────────────────── -->
        <div class="mw-wz-steps mb-3">
          <div class="mw-wz-step mw-wz-step--active" id="wz-step-1">
            <div class="mw-wz-step-num">1</div>
            <div class="mw-wz-step-body">
              <div class="mw-wz-step-title">Select Plan</div>
              <div class="mw-wz-step-desc">Choose which job plan this zone applies to</div>
            </div>
          </div>
          <div class="mw-wz-step-connector"></div>
          <div class="mw-wz-step" id="wz-step-2">
            <div class="mw-wz-step-num">2</div>
            <div class="mw-wz-step-body">
              <div class="mw-wz-step-title">Draw Boundary</div>
              <div class="mw-wz-step-desc">Click the map to outline the property work area</div>
            </div>
          </div>
          <div class="mw-wz-step-connector"></div>
          <div class="mw-wz-step" id="wz-step-3">
            <div class="mw-wz-step-num">3</div>
            <div class="mw-wz-step-body">
              <div class="mw-wz-step-title">Save Zone</div>
              <div class="mw-wz-step-desc">Zone activates on next crew clock-in visit</div>
            </div>
          </div>
        </div>

        <!-- ── Plan Selector ────────────────────────────────────────────── -->
        <div class="form-group mb-3">
          <label class="font-weight-bold small mb-1">Job Plan</label>
          <select id="wz-plan-select" class="form-control form-control-sm" onchange="onWZPlanChange()">
            <option value="">-- Select a plan --</option>
          </select>
          <small class="text-muted">Work zones are saved per plan — each plan can have its own zone.</small>
        </div>

        <!-- ── Map ──────────────────────────────────────────────────────── -->
        <div class="mw-wz-map-wrap">
          <div id="wz-map"></div>
          <div id="wz-map-placeholder" class="mw-wz-map-placeholder">
            <i data-feather="map" style="width:36px;height:36px;color:#ccc;"></i>
            <p class="mt-2 mb-0 text-muted small">Select a plan above to load the map</p>
          </div>
        </div>

        <!-- ── Contextual Hint ───────────────────────────────────────────── -->
        <div id="wz-hint" class="mw-wz-hint mt-2">
          <i class="mw-wz-hint-icon" data-feather="info" style="width:13px;height:13px;flex-shrink:0;"></i>
          <span id="wz-hint-text">Select a job plan above to get started.</span>
        </div>

        <!-- ── Status (success / error feedback) ────────────────────────── -->
        <div id="wz-status" class="mw-wz-status mt-2" style="display:none;"></div>

        <!-- ── Drawing Tips (collapsed by default, shown during draw) ────── -->
        <div id="wz-draw-tips" class="mw-wz-draw-tips mt-2" style="display:none;">
          <div class="mw-wz-draw-tips-title">
            <i data-feather="crosshair" class="mw-icon-xs"></i> Drawing mode active
          </div>
          <ul class="mw-wz-draw-tips-list">
            <li><strong>Click</strong> anywhere on the map to add a corner point</li>
            <li><strong>Double-click</strong> the last point (or click the first) to close the shape</li>
            <li>Aim for 4–8 points — trace the property edge, not just the house</li>
            <li>Press <kbd>Esc</kbd> or click <strong>Cancel Draw</strong> to start over</li>
          </ul>
        </div>

      </div>
      <div class="modal-footer d-flex justify-content-between align-items-center">
        <button type="button" class="btn btn-outline-danger btn-sm" id="wz-delete-btn" disabled
                onclick="wzDeleteZone()">
          <i data-feather="trash-2" class="mw-icon-xs"></i> Delete Zone
        </button>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="wz-cancel-draw-btn"
                  style="display:none" onclick="wzCancelDraw()">
            Cancel Draw
          </button>
          <button type="button" class="btn btn-outline-primary btn-sm mr-2" id="wz-draw-btn" disabled
                  onclick="wzStartDraw()">
            <i data-feather="edit-2" class="mw-icon-xs"></i> Draw Zone
          </button>
          <button type="button" class="btn btn-success btn-sm" id="wz-save-btn" disabled
                  onclick="wzSave()">
            <i data-feather="save" class="mw-icon-xs"></i> Save Zone
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="/crm/js/geofence/geofence-manager.js?v=3"></script>
<script>(window.mwClients = window.mwClients || {}).clients_workzone = { c0: <?php echo json_encode($plansByProperty ?? []); ?>, c1: <?php echo json_encode(generateCSRFToken()); ?> };</script>
<script src="<?= _av('/crm/js/clients-workzone.js') ?>"></script>

