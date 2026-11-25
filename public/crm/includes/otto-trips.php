<?php
/**
 * Otto — "Today's runs" block inside Otto's card (included by otto-card.php).
 *
 * The truck's day from the Trackimo trail as stops (TripSegmentService), each dump / supply run
 * priced (TripCostService), unnamed stops to name once, and the one-man baseline per place.
 * Name / one-man toggles post to /crm/api/trips.php (public/crm/js/otto-trips.js).
 * Penny's evidence (StopEvidenceService) shows inline: a scale ticket's Time In → Time Out replaces
 * the GPS minutes, places she named say so, and a bank-line guess asks Yes / No (mode=confirm_stop).
 * Receipt money is "receipts on this run", not job cost (one slip can be job material + shop stock).
 * Silent until migration 1216 has run; any failure renders nothing.
 *
 * Expects: $__tripDay (TripCostService::pricedDay) and $__tripBase (baseline()) — set by the
 * card; renders nothing when they are missing.
 */
if (empty($__tripDay) || !is_array($__tripDay)) {
    return;
}
$__tHm = fn($t) => $t === null ? '' : date('g:i', (int)$t);
$__tMoney = fn(?float $v) => $v === null ? '—' : '$' . number_format($v, $v >= 100 ? 0 : 2);
$__tIcon = ['dump' => '🗑', 'supplier' => '🧱', 'yard' => '🏠', 'fuel' => '⛽', 'other' => '📍'];
$__tStops = array_values(array_filter($__tripDay['segments'], fn($s) => $s['type'] === 'stop'));
$__tEv = $__tripDay['evidence'] ?? [];            // [stop start => Penny's receipt evidence]
$__tAsk = $__tripDay['proposals'] ?? [];          // [stop start => Penny's guess (bank card line)]
$__tPennyPl = $__tripDay['penny_places'] ?? [];   // [place id => {source, evidence}]
$__tBasis = ['clock' => 'only one on the clock', 'phone' => 'phone GPS', 'default' => 'assumed — someone stayed on site', 'manual' => 'set by you', 'none' => 'nobody on the clock'];
?>
<div class="mw-otto-trips" id="mw-otto-trips" data-date="<?= h($__tripDay['date']) ?>">
  <div class="mw-otto-trips-hd">
    <b>Today's runs</b>
    <small><?= $__tripDay['pings'] ? 'Truck GPS ' . h($__tripDay['first']) . '–' . h($__tripDay['last']) : 'No truck GPS yet today' ?></small>
  </div>

  <?php if ($__tStops): ?>
  <ol class="mw-otto-stops">
    <?php foreach ($__tStops as $__s): $__l = $__s['label'];
      $__e = $__tEv[$__s['start']][0] ?? null;            // Penny's receipt for this stop
      $__tk = $__e['ticket'] ?? null;                     // a scale ticket's Time In / Out beats GPS
      $__pp = $__l['type'] === 'place' ? ($__tPennyPl[(int)$__l['id']] ?? null) : null; ?>
      <li class="is-<?= h($__l['type'] === 'place' ? (string)$__l['kind'] : $__l['type']) ?>">
        <span class="mw-otto-stop-t"><?= h($__tHm($__tk ? $__tk['in'] : $__s['start'])) ?>–<?= h($__tHm($__tk ? $__tk['out'] : $__s['end'])) ?></span>
        <span class="mw-otto-stop-nm"><?= $__l['type'] === 'place' ? h($__tIcon[$__l['kind']] ?? '📍') . ' ' : '' ?><?= h($__l['name']) ?>
          <?php if ($__e): ?><small class="mw-otto-ev">· <?= h(StopEvidenceService::evidenceLine($__e, false)) ?></small><?php endif; ?>
          <?php if ($__pp): ?><small class="mw-otto-ev" title="<?= h($__pp['evidence']) ?>">· <?= $__pp['source'] === 'confirmed' ? 'Penny guessed, you said yes' : 'named by Penny' ?></small><?php endif; ?>
        </span>
        <span class="mw-otto-stop-m"><?= h(TripCostService::mins($__tk ? (float)$__tk['minutes'] : (float)$__s['minutes'])) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <?php foreach ($__tripDay['runs'] as $__r): $__c = $__r['crew']; $__drv = $__r['driver']; ?>
  <div class="mw-otto-run<?= $__r['returned_at'] === null ? ' is-open' : '' ?>">
    <p>
      <b><?= h(implode(' + ', array_unique(array_map(fn($l) => TripCostService::KIND_LABEL[$l['kind']] ?? 'unnamed stop', $__r['legs'])))) ?></b>
      from <?= h($__r['from']['name'] ?? 'the yard') ?> at <?= h($__tHm($__r['left_at'])) ?>
      <?= $__r['returned_at'] !== null ? '· back ' . h($__tHm($__r['returned_at'])) . ' · ' . h(TripCostService::mins((float)$__r['minutes'])) : '· <em>still out</em>' ?>
      · <?= h(rtrim(rtrim(number_format((float)$__r['km'], 1), '0'), '.')) ?> km
      <span class="mw-otto-run-cost"><?= h($__tMoney((float)$__r['total'])) ?><?= array_filter($__r['legs'], fn($l) => $l['cost']['rate_missing']) ? ' <small>+ labour</small>' : '' ?></span>
    </p>
    <ul class="mw-otto-legs">
      <?php foreach ($__r['legs'] as $__l): ?>
        <li><?= h($__l['name']) ?>: <?= h(TripCostService::mins((float)$__l['onsite_min'])) ?> there<?= ($__l['onsite_basis'] ?? 'gps') === 'ticket' ? ' (scale ticket)' : '' ?> + <?= h(TripCostService::mins((float)$__l['drive_min'])) ?> driving
          · labour <?= h($__tMoney($__l['cost']['labour'])) ?> · truck <?= h($__tMoney($__l['cost']['truck'])) ?>
          <?php if ($__l['place_id']): ?>· <?= $__l['receipt_ids'] ? 'receipts on this run ' . h($__tMoney($__l['cost']['receipts'])) . (!empty($__l['evidence_line']) ? ' <small>(' . h($__l['evidence_line']) . ')</small>' : '') : '<span class="mw-otto-warn">no receipt yet</span>' ?><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="mw-otto-btns">
      <span class="mw-otto-crew">
        <?= $__c['one_man'] === null ? 'Crew unknown' : ($__c['one_man'] ? 'One man' : (int)$__r['people'] . ' in the truck') ?>
        <?= $__drv['name'] !== '' ? '· ' . h(OpsDeskService::firstName($__drv['name'])) : ($__c['one_man'] ? '· driver unknown' : '') ?>
        <small>(<?= h($__tBasis[$__c['basis']] ?? $__c['basis']) ?>)</small>
      </span>
      <?php if ($__r['returned_at'] !== null): ?>
        <button type="button" data-trip="<?= h($__r['trip_key']) ?>" data-one-man="1" class="<?= $__c['one_man'] === true ? 'is-main' : '' ?>">One man</button>
        <button type="button" data-trip="<?= h($__r['trip_key']) ?>" data-one-man="0" class="<?= $__c['one_man'] === false ? 'is-main' : '' ?>">Two man</button>
      <?php endif; ?>
      <?php if ($__drv['id'] && $__drv['rate'] === null): ?>
        <a class="mw-otto-warn" href="/crm/team/profile.php?id=<?= (int)$__drv['id'] ?>"><?= h(OpsDeskService::firstName($__drv['name'])) ?>: rate missing — set it</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($__tripDay['unnamed'] as $__u): ?>
  <?php $__g = $__tAsk[$__u['start']] ?? null; if ($__g): ?>
  <div class="mw-otto-ask" data-lat="<?= h((string)$__u['lat']) ?>" data-lng="<?= h((string)$__u['lng']) ?>" data-name="<?= h($__g['name']) ?>"
       data-kind="<?= h((string)$__g['kind']) ?>" data-vendor="<?= (int)($__g['vendor_id'] ?? 0) ?>" data-evidence="<?= h(StopEvidenceService::evidenceLine($__g)) ?>">
    <span>🧾 Penny thinks the <?= h($__tHm($__u['start'])) ?>–<?= h($__tHm($__u['end'])) ?> stop was <b><?= h($__g['name']) ?></b> (<?= h(StopEvidenceService::evidenceLine($__g)) ?>).</span>
    <div class="mw-otto-btns">
      <button type="button" data-answer="1" class="is-main">Yes</button>
      <button type="button" data-answer="0">No</button>
    </div>
  </div>
  <?php endif; ?>
  <form class="mw-otto-name" data-lat="<?= h((string)$__u['lat']) ?>" data-lng="<?= h((string)$__u['lng']) ?>">
    <span>📍 <?= h($__tHm($__u['start'])) ?>–<?= h($__tHm($__u['end'])) ?>, <?= h(TripCostService::mins((float)$__u['minutes'])) ?> somewhere I don't know<?= $__g ? '' : ' — I\'ve asked Penny; I\'ll name it myself when a receipt or card charge shows up' ?>
      <a href="https://www.google.com/maps?q=<?= h(number_format((float)$__u['lat'], 5, '.', '') . ',' . number_format((float)$__u['lng'], 5, '.', '')) ?>" target="_blank" rel="noopener">map</a></span>
    <div class="mw-otto-btns">
      <input type="text" name="name" maxlength="120" placeholder="Name it once (e.g. Lawnboy)" aria-label="Place name" required>
      <select name="kind" aria-label="Kind of place">
        <option value="supplier">Supplier</option><option value="dump">Dump</option><option value="fuel">Fuel</option>
        <option value="yard">Yard</option><option value="other">Other</option>
      </select>
      <button type="submit" class="is-main">Save</button>
    </div>
  </form>
  <?php endforeach; ?>

  <?php if (!empty($__tripBase)): ?>
  <div class="mw-otto-base">
    <?php foreach ($__tripBase as $__b): ?><div><?= h($__tIcon[$__b['kind']] ?? '📍') ?> <?= h(TripCostService::baselineLine($__b)) ?></div><?php endforeach; ?>
  </div>
  <?php elseif ($__tripDay['runs']): ?>
  <div class="mw-otto-base"><div>Baseline starts tomorrow: each finished run is priced overnight and averaged here.</div></div>
  <?php endif; ?>
  <div class="mw-otto-trips-ft">Truck <?= h('$' . number_format((float)$__tripDay['settings']['per_km'], 2)) ?>/km<?= $__tripDay['settings']['per_km_default'] ? ' (CRA-style default, edit me: ops setting truck_cost_per_km)' : '' ?> · labour = driver's rate + <?= h((string)(float)$__tripDay['settings']['burden_pct']) ?>% burden</div>
</div>
