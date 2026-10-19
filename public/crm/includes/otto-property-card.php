<?php
/**
 * Otto (operations head, "The Dispatcher") — what a property still needs before he can
 * route to it: a map pin, an arrival border, a lawn measurement. One row per gap with the
 * fix: "Geocode" (in place, public/crm/js/otto-property-card.js → /crm/api/geocode-save.php),
 * "Let Otto draw it" (the city lot, /crm/api/otto-lot-border.php), "Draw arrival border" (zone editor)
 * and "Measure" (area measurement tool).
 *
 * Included by quotes/view.php and properties/view.php. Expects $ottoPropertyId (int).
 * Renders nothing for users without jobs.edit, before Otto's migrations have run, when
 * nothing is missing, or on any error — it must never break the host page.
 */
if (!function_exists('userHasPermission') || !userHasPermission('jobs.edit') || empty($ottoPropertyId)) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php';
    require_once APP_ROOT . '/Modules/Operations/Services/PropertyReadinessService.php';
    if (!(new OpsDeskService(getDB()))->ready()) {
        return;
    }
    $__pg = (new PropertyReadinessService(getDB()))->gaps((int)$ottoPropertyId);
    // Nothing missing still shows the card when there is a pin: the map of the pin and arrival border.
    if (empty($__pg['property']) || (empty($__pg['missing']) && !$__pg['pin'])) {
        return;
    }
    $__pgId      = (int)$__pg['property']['id'];
    $__pgMissing = $__pg['missing'];
    $__pgSay     = PropertyReadinessService::message($__pgMissing);
    $__pgAfterPin = PropertyReadinessService::message(array_values(array_diff($__pgMissing, ['pin'])));
    $__pgAddress = PropertyReadinessService::geocodeAddress($__pg['property']);
    $__pgReturn  = (string)($_SERVER['REQUEST_URI'] ?? ('/crm/properties/view.php?id=' . $__pgId));
    $__pgKey     = defined('GOOGLE_MAPS_API_KEY') ? (string)GOOGLE_MAPS_API_KEY : '';
    // The map under the card (owner, 2026-10-18): the pin and the arrival border, so a border can be
    // checked at a glance. Read-only; the zone editor is still where a border is changed.
    $__pgMap = null;
    if ($__pg['pin']) {
        $__pgMap = ['lat' => null, 'lng' => null, 'ring' => [], 'source' => null];
        try {
            $__pgPin = getDB()->prepare('SELECT latitude, longitude FROM properties WHERE id = ?');
            $__pgPin->execute([$__pgId]);
            $__pgRow = $__pgPin->fetch(PDO::FETCH_ASSOC) ?: [];
            $__pgMap['lat'] = (float)($__pgRow['latitude'] ?? 0);
            $__pgMap['lng'] = (float)($__pgRow['longitude'] ?? 0);
            require_once APP_ROOT . '/Modules/Operations/Services/PropertyBorderService.php';
            $__pgB = getDB()->prepare("SELECT polygon_json, border_source FROM job_geofences WHERE property_id = ? AND zone_type = 'arrival_border' ORDER BY id LIMIT 1");
            $__pgB->execute([$__pgId]);
            if ($__pgBr = $__pgB->fetch(PDO::FETCH_ASSOC)) {
                $__pgMap['ring'] = PropertyBorderService::ring((string)$__pgBr['polygon_json']);
                $__pgMap['source'] = $__pgBr['border_source'] ?? null;
            }
        } catch (Throwable $__e) { /* border_source not migrated: the map shows the pin only */ }
    }
    $__pgMapCaption = [
        'parcel'         => 'Arrival border from the city lot map',
        'default_square' => "Otto's placeholder square — draw the real border",
        'default_hull'   => 'Arrival border from where crews worked',
        'kept'           => 'Arrival border (kept)',
    ];
    // Otto pins it himself when the page opens (owner, 2026-10-18): Google's browser geocoder,
    // rooftop matches only — same switch as his nightly run, same permission as the Geocode save.
    $__pgAuto = false;
    if (in_array('pin', $__pgMissing, true) && function_exists('userHasAnyPermission') && userHasAnyPermission(['clients.edit', 'jobs.edit'])) {
        try {
            $__pgSw = getDB()->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'otto_auto_place'");
            $__pgSw->execute();
            $__pgAuto = (string)$__pgSw->fetchColumn() === '1';
        } catch (Throwable $__e) { /* no switch: the button stays */ }
    }

    // Otto's brain, as on his dashboard card — a bonus, never a reason to hide the card
    $__pgBrain = null;
    try {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoBrainService.php';
        $__pgBrain = (new OttoBrainService(getDB()))->learned(null);
    } catch (Throwable $__e) { /* the photo still shows */ }
} catch (Throwable $__e) {
    error_log('Otto property card unavailable: ' . $__e->getMessage());
    return;
}
?>
                  <div class="card mw-otto-gaps" id="mw-otto-gaps"
                       data-property-id="<?= $__pgId ?>"
                       data-maps-key="<?= h($__pgKey) ?>"
                       data-after-pin="<?= h($__pgAfterPin) ?>"<?= $__pgAuto ? ' data-auto-pin="1"' : '' ?>>
                      <div class="card-header">
                          <div class="mw-otto-gaps-head">
                              <div class="mw-otto-gaps-photo">
                                  <img src="/crm/img/heads/otto.jpg" alt="Otto, operations" width="104" height="104">
                                  <?php if ($__pgBrain !== null): ?>
                                  <button type="button" class="mw-head-brain" aria-label="Otto's brain: <?= (int)$__pgBrain['units'] ?> things learned"
                                          data-head="Otto"
                                          data-units="<?= (int)$__pgBrain['units'] ?>"
                                          data-bright="<?= h(number_format((float)($__pgBrain['bright'] ?? 0.5), 2, '.', '')) ?>"
                                          data-parts="<?= h((string)json_encode($__pgBrain['parts'])) ?>"
                                          data-since="<?= h(!empty($__pgBrain['since']) ? date('M j, Y', strtotime($__pgBrain['since'])) : '') ?>"
                                          data-empty="Nothing learned yet. Every move, keep and time you set teaches me."
                                          data-teach="It glows brighter the more often you keep my call"><canvas></canvas></button>
                                  <?php endif; ?>
                              </div>
                              <div>
                                  <div class="mw-otto-gaps-name">Otto</div>
                                  <span class="mw-otto-gaps-who">Operations</span>
                                  <h5 class="card-title mb-0 mt-1"><?= $__pgMissing ? 'Before I can route here' : 'I can route here' ?></h5>
                              </div>
                          </div>
                      </div>
                      <div class="card-body">
                          <?php if ($__pgMissing): ?>
                          <p class="mw-otto-gaps-say" data-otto-say><?= h($__pgSay) ?></p>
                          <?php endif; ?>
                          <ul class="mw-otto-gaps-list">
                              <?php if (in_array('pin', $__pgMissing, true)): ?>
                              <li class="mw-otto-gap" data-gap="pin">
                                  <div class="mw-otto-gap-text">
                                      <strong>Map pin</strong>
                                      <span class="mw-otto-gap-note" data-otto-note><?= $__pgAddress !== '' ? h($__pgAddress) : 'No address on file to look up.' ?></span>
                                  </div>
                                  <?php if ($__pgAddress !== ''): ?>
                                  <button type="button" class="btn btn-sm btn-primary mw-otto-gap-btn" data-otto-geocode
                                          data-property-id="<?= $__pgId ?>"
                                          data-address="<?= h($__pgAddress) ?>">Geocode</button>
                                  <?php endif; ?>
                              </li>
                              <?php endif; ?>
                              <?php if (in_array('border', $__pgMissing, true)): ?>
                              <li class="mw-otto-gap" data-gap="border">
                                  <div class="mw-otto-gap-text">
                                      <strong>Arrival border</strong>
                                      <span class="mw-otto-gap-note" data-otto-note>Where the crew's arrival is counted.<?= $__pg['pin'] ? '' : ' Needs the pin first.' ?></span>
                                  </div>
                                  <?php if ($__pg['pin']): ?>
                                  <button type="button" class="btn btn-sm btn-primary mw-otto-gap-btn" data-otto-lot
                                          data-property-id="<?= $__pgId ?>">Let Otto draw it</button>
                                  <?php endif; ?>
                                  <a class="btn btn-sm btn-outline-primary mw-otto-gap-btn"
                                     href="/crm/jobs/zone-editor.php?property_id=<?= $__pgId ?>&amp;return_to=<?= h(urlencode($__pgReturn)) ?>">Draw arrival border</a>
                              </li>
                              <?php endif; ?>
                              <?php if (in_array('measured', $__pgMissing, true)): ?>
                              <li class="mw-otto-gap" data-gap="measured">
                                  <div class="mw-otto-gap-text">
                                      <strong>Lawn measurement</strong>
                                      <span class="mw-otto-gap-note">I plan visit time from the square footage.</span>
                                  </div>
                                  <a class="btn btn-sm btn-outline-primary mw-otto-gap-btn"
                                     href="/crm/products/area-measurement.php?property_id=<?= $__pgId ?>">Measure</a>
                              </li>
                              <?php endif; ?>
                          </ul>
                          <?php if ($__pgMap !== null && $__pgKey !== ''): ?>
                          <div class="mw-otto-map-wrap">
                              <div class="mw-otto-map" data-otto-map role="img"
                                   aria-label="Map of the property pin<?= $__pgMap['ring'] ? ' and its arrival border' : '' ?>"
                                   data-lat="<?= h((string)$__pgMap['lat']) ?>" data-lng="<?= h((string)$__pgMap['lng']) ?>"
                                   data-ring="<?= h((string)json_encode($__pgMap['ring'])) ?>"></div>
                              <div class="mw-otto-map-cap">
                                  <span data-otto-map-cap><?= $__pgMap['ring']
                                      ? h($__pgMapCaption[$__pgMap['source'] ?? ''] ?? 'Arrival border (drawn)')
                                      : 'No arrival border yet' ?></span>
                                  <span class="mw-otto-map-acts">
                                      <?php if (($__pgMap['source'] ?? '') === 'parcel'): ?>
                                      <button type="button" class="mw-otto-map-remove" data-otto-lot-remove data-property-id="<?= $__pgId ?>">Not right? Remove it</button>
                                      <?php endif; ?>
                                      <a href="/crm/jobs/zone-editor.php?property_id=<?= $__pgId ?>&amp;return_to=<?= h(urlencode($__pgReturn)) ?>"><?= $__pgMap['ring'] ? 'Edit border' : 'Draw it' ?></a>
                                  </span>
                              </div>
                          </div>
                          <?php endif; ?>
                      </div>
                  </div>
                  <script src="<?= function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js' ?>" defer></script>
                  <script src="<?= function_exists('_av') ? _av('/crm/js/otto-property-card.js') : '/crm/js/otto-property-card.js' ?>" defer></script>
