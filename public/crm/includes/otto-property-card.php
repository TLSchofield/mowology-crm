<?php
/**
 * Otto (operations head, "The Dispatcher") — what a property still needs before he can
 * route to it: a map pin, an arrival border, a lawn measurement. One row per gap with the
 * fix: "Geocode" (in place, public/crm/js/otto-property-card.js → /crm/api/geocode-save.php),
 * "Draw arrival border" (zone editor) and "Measure" (area measurement tool).
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
    if (empty($__pg['property']) || empty($__pg['missing'])) {
        return;
    }
    $__pgId      = (int)$__pg['property']['id'];
    $__pgMissing = $__pg['missing'];
    $__pgSay     = PropertyReadinessService::message($__pgMissing);
    $__pgAfterPin = PropertyReadinessService::message(array_values(array_diff($__pgMissing, ['pin'])));
    $__pgAddress = PropertyReadinessService::geocodeAddress($__pg['property']);
    $__pgReturn  = (string)($_SERVER['REQUEST_URI'] ?? ('/crm/properties/view.php?id=' . $__pgId));
    $__pgKey     = defined('GOOGLE_MAPS_API_KEY') ? (string)GOOGLE_MAPS_API_KEY : '';

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
                       data-after-pin="<?= h($__pgAfterPin) ?>">
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
                                  <h5 class="card-title mb-0 mt-1">Before I can route here</h5>
                              </div>
                          </div>
                      </div>
                      <div class="card-body">
                          <p class="mw-otto-gaps-say" data-otto-say><?= h($__pgSay) ?></p>
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
                                      <span class="mw-otto-gap-note">Where the crew's arrival is counted.<?= $__pg['pin'] ? '' : ' Needs the pin first.' ?></span>
                                  </div>
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
                      </div>
                  </div>
                  <script src="<?= function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js' ?>" defer></script>
                  <script src="<?= function_exists('_av') ? _av('/crm/js/otto-property-card.js') : '/crm/js/otto-property-card.js' ?>" defer></script>
