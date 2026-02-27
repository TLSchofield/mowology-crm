<?php
/**
 * Geofence Settings UI — Admin card fragment.
 *
 * Include this file inside the admin settings page
 * (e.g. /crm/settings_appstack.php or a dedicated geofence page):
 *
 *   <?php
 *   require_once APP_ROOT . '/app/Modules/Geofence/Api/geofence-settings.php';
 *   geofenceSettingsCard($csrfToken);
 *   ?>
 *
 * Requires:
 *   - GeofenceModel.php loaded
 *   - Admin permissions already verified by caller
 *   - CSRF token available as $csrfToken
 *   - /crm/api/geofence.php accessible
 *   - mowology-brand.css loaded (uses --mw-* variables)
 *   - Feather icons initialised
 */

if (!function_exists('geofenceGetSettings')) {
    require_once dirname(__DIR__) . '/Models/GeofenceModel.php';
}

function geofenceSettingsCard(string $csrfToken): void {
    $settings = geofenceGetSettings();
    $global   = ($settings['geofence.global_enabled'] ?? '0') === '1';

    // Service type options (must match service_type values in job_plans)
    $knownServices = [
        'lawn_care'      => 'Lawn Care',
        'snow_removal'   => 'Snow Removal',
        'fertilizer'     => 'Fertilizer / Treatment',
        'landscaping'    => 'Landscaping',
        'hedge_trimming' => 'Hedge Trimming',
        'leaf_removal'   => 'Leaf Removal',
        'general'        => 'General',
    ];

    $servicesEnabled = json_decode($settings['geofence.services_enabled'] ?? '[]', true) ?: [];
    ?>
    <div class="card mw-geofence-settings-card" id="geofence-settings-card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="mb-0 d-flex align-items-center gap-2">
          <i data-feather="map-pin" style="color:var(--mw-green);"></i>
          Polygon Work-Zone Tracking
        </h5>
        <span class="badge <?= $global ? 'badge-success' : 'badge-secondary' ?>" id="geo-global-badge">
          <?= $global ? 'GLOBALLY ON' : 'OFF' ?>
        </span>
      </div>
      <div class="card-body">

        <!-- ─ Global toggle ─ -->
        <div class="mw-form-row align-items-center mb-3">
          <label class="font-weight-bold mb-0">
            Enable geofence tracking globally
          </label>
          <div class="ml-auto d-flex align-items-center gap-3">
            <small class="text-muted">When ON, falls back to per-service / per-plan settings</small>
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="geo-global-toggle"
                     <?= $global ? 'checked' : '' ?>>
              <label class="custom-control-label" for="geo-global-toggle"></label>
            </div>
          </div>
        </div>

        <hr>

        <!-- ─ Per-service toggles ─ -->
        <div class="mb-3">
          <label class="font-weight-bold">Enable for service types</label>
          <small class="text-muted d-block mb-2">
            These override the global setting for individual service types.
          </small>
          <div class="row">
            <?php foreach ($knownServices as $key => $label): ?>
            <div class="col-md-4 col-sm-6 mb-2">
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input geo-service-cb"
                       id="geo-svc-<?= $key ?>" value="<?= $key ?>"
                       <?= in_array($key, $servicesEnabled, true) ? 'checked' : '' ?>>
                <label class="custom-control-label" for="geo-svc-<?= $key ?>"><?= $label ?></label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <hr>

        <!-- ─ Sampling parameters ─ -->
        <div class="mb-3">
          <label class="font-weight-bold">GPS Sampling Settings</label>
          <div class="row mt-2">
            <div class="col-md-4 mb-3">
              <label for="geo-interval" class="small font-weight-semibold">Sample interval (seconds)</label>
              <input type="number" id="geo-interval" class="form-control form-control-sm"
                     min="10" max="300" step="5"
                     value="<?= (int)($settings['geofence.sample_interval_sec'] ?? 30) ?>">
              <small class="text-muted">GPS fix taken every N seconds (30 recommended)</small>
            </div>
            <div class="col-md-4 mb-3">
              <label for="geo-min-acc" class="small font-weight-semibold">Min accuracy (metres)</label>
              <input type="number" id="geo-min-acc" class="form-control form-control-sm"
                     min="5" max="100" step="5"
                     value="<?= (int)($settings['geofence.min_accuracy_m'] ?? 30) ?>">
              <small class="text-muted">Fixes better than this = high quality</small>
            </div>
            <div class="col-md-4 mb-3">
              <label for="geo-max-acc" class="small font-weight-semibold">Max accuracy (metres)</label>
              <input type="number" id="geo-max-acc" class="form-control form-control-sm"
                     min="50" max="500" step="10"
                     value="<?= (int)($settings['geofence.max_accuracy_m'] ?? 150) ?>">
              <small class="text-muted">Fixes worse than this = rejected / marked unknown</small>
            </div>
          </div>
        </div>

        <hr>

        <!-- ─ PoW integration ─ -->
        <div class="mw-form-row align-items-center mb-3">
          <div>
            <label class="font-weight-bold mb-0">Show in Proof-of-Work</label>
            <small class="text-muted d-block">Include work-zone compliance section in PoW PDFs</small>
          </div>
          <div class="ml-auto custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="geo-pow-toggle"
                   <?= ($settings['geofence.pow_section_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label class="custom-control-label" for="geo-pow-toggle"></label>
          </div>
        </div>

        <div class="mw-form-row align-items-center mb-0">
          <div>
            <label class="font-weight-bold mb-0">Show "Enhanced Tracking" badge</label>
            <small class="text-muted d-block">Display activation badge on job cards when geofence is active</small>
          </div>
          <div class="ml-auto custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="geo-badge-toggle"
                   <?= ($settings['geofence.show_ui_badge'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label class="custom-control-label" for="geo-badge-toggle"></label>
          </div>
        </div>

      </div><!-- /card-body -->
      <div class="card-footer d-flex align-items-center justify-content-between">
        <span id="geo-save-msg" class="text-muted small" style="min-height:1.2em;"></span>
        <button type="button" class="btn btn-primary btn-sm" id="geo-save-btn"
                onclick="geofenceSaveSettings('<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>')">
          Save Geofence Settings
        </button>
      </div>
    </div>

    <script>
    function geofenceSaveSettings(csrfToken) {
        const btn = document.getElementById('geo-save-btn');
        const msg = document.getElementById('geo-save-msg');
        btn.disabled = true;
        msg.textContent = 'Saving…';

        // Collect service checkboxes
        const servicesEnabled = Array.from(document.querySelectorAll('.geo-service-cb:checked'))
            .map(cb => cb.value);

        const settings = {
            'geofence.global_enabled':       document.getElementById('geo-global-toggle').checked ? '1' : '0',
            'geofence.services_enabled':     JSON.stringify(servicesEnabled),
            'geofence.sample_interval_sec':  document.getElementById('geo-interval').value,
            'geofence.min_accuracy_m':       document.getElementById('geo-min-acc').value,
            'geofence.max_accuracy_m':       document.getElementById('geo-max-acc').value,
            'geofence.pow_section_enabled':  document.getElementById('geo-pow-toggle').checked ? '1' : '0',
            'geofence.show_ui_badge':        document.getElementById('geo-badge-toggle').checked ? '1' : '0',
        };

        fetch('/crm/api/geofence.php', {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify({ action: 'save_settings', csrf_token: csrfToken, settings }),
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                msg.textContent = '✓ Saved';
                msg.style.color = 'var(--mw-green)';

                // Update global badge
                const isGlobal = settings['geofence.global_enabled'] === '1';
                const badge    = document.getElementById('geo-global-badge');
                if (badge) {
                    badge.textContent  = isGlobal ? 'GLOBALLY ON' : 'OFF';
                    badge.className    = 'badge ' + (isGlobal ? 'badge-success' : 'badge-secondary');
                }
            } else {
                msg.textContent = '✗ ' + (data.error || 'Save failed');
                msg.style.color = 'var(--bs-danger, #dc3545)';
            }
            setTimeout(() => { msg.textContent = ''; }, 4000);
        })
        .catch(err => {
            btn.disabled    = false;
            msg.textContent = '✗ Network error';
            msg.style.color = 'var(--bs-danger, #dc3545)';
        });
    }
    </script>
    <?php
}
