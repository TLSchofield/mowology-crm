<?php
/**
 * Mia's Before & After strip: the decision for the pair that needs one now (the photo check, the client's agreement for
 * strata / managed property, then her offered text), across the top of the Before & After page in the Ittai desktop
 * shell. Included from appstack_head.php after Mia's Social strip, only when $itShell is on. It draws with Mia's own
 * script (/crm/js/ittai-job-mia.js), the same one the Social strip uses; only the cards and the page differ.
 *
 * Nothing is decided here: BeforeAfterJobAdapter shapes the cards and every button sends the request the page's own
 * buttons send (before-after_appstack.php queue_privacy / queue_consent / queue_approve / queue_reject), so
 * BeforeAfterService::approve() still decides what publishing does. Off unless ops_settings ba_flowline = '1'; shown
 * only on this page, only to the owner, only with portfolio.edit. Any failure renders nothing.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
if ((string)($activePage ?? '') !== 'before-after') return;
try {
    require_once __DIR__ . '/nav-items.php';
    $__ibUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__ibHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__ibUser, (string)($activePage ?? '')) as $__ibB) {
        if (($__ibB['slug'] ?? '') === 'mia' && !empty($__ibB['here'])) { $__ibHere = true; break; }
    }
    if (!$__ibHere) return;
    if (function_exists('userHasPermission') && !userHasPermission('portfolio.edit')) return;
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__ibDb = getDB();
    $__ibDesk = new CharlieDeskService($__ibDb);
    if (!$__ibDesk->ready() || !$__ibDesk->isOwner($__ibUser)) return;
    require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
    require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterJobAdapter.php';
    require_once APP_ROOT . '/Modules/Marketing/Services/VoiceProfile.php';
    $__ibJobs = BeforeAfterJobAdapter::load($__ibDb);
} catch (Throwable $__e) {
    error_log('Mia before-after strip unavailable: ' . $__e->getMessage());
    return;
}
// Local-only fixture so the strip can be drawn without live data (never on production).
if (($_GET['it_job_demo'] ?? '') === '1'
    && in_array(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {   // the Host header is the client's; the address is not
    require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
    require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterJobAdapter.php';
    $__ibPix = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="264" height="264"><rect width="264" height="264" fill="#c9d8c5"/><rect y="150" width="264" height="114" fill="#7da46a"/></svg>');
    $__ibBase = ['service' => 'Lawn Care', 'area' => 'Kitsilano', 'category' => 'lawn', 'property_name' => 'Sample Property', 'address' => '1 Sample Street',
        'before_url' => $__ibPix, 'after_url' => $__ibPix, 'completed_at' => date('Y-m-d H:i:s'), 'consent_state' => 'not_needed', 'label' => 'Lawn Care — Kitsilano'];
    $__ibJobs = BeforeAfterJobAdapter::jobs([
        array_merge($__ibBase, ['id' => 1, 'privacy_checked_at' => null]),
        array_merge($__ibBase, ['id' => 2, 'privacy_checked_at' => date('Y-m-d H:i:s'), 'consent_state' => 'needed', 'area' => 'Point Grey']),
        array_merge($__ibBase, ['id' => 3, 'privacy_checked_at' => date('Y-m-d H:i:s'), 'service' => 'Garden Maintenance', 'area' => 'Kerrisdale']),
    ], ((string)($_GET['it_job_sure'] ?? '') === 'gold') ? [5, 5] : [3, 3]);
}
if (!$__ibJobs) return;

require_once __DIR__ . '/ittai-icons.php';
$__ibFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$__ibSvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
itIconSprite();
?>
<div class="it-job-outer"><div class="it-job-wrap">
<section class="it-job it-job-mia mw-baq-strip" data-head="mia" data-csrf="<?= h(function_exists('generateCSRFToken') ? generateCSRFToken() : '') ?>" hidden aria-label="Mia's job">
  <h2 class="it-vh it-job-tt"></h2>
  <div class="it-job-body"><div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="#baQueue"><span class="it-ad it-ad-fill"><?= $__ibSvg('eye') ?></span><span>Pairs</span></a>
    <span class="it-job-conf is-forming" role="img"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#it-st-approval-forming"/></svg></span>
    <div class="it-job-split">
      <div class="it-job-col it-job-left"><div class="it-job-what"><b class="it-job-who"></b><span class="it-job-at"></span><p class="it-job-quote"></p><p class="it-job-why"></p></div></div>
      <div class="it-job-col it-job-fig-col">
        <figure class="it-job-fig" hidden><a href="#" target="_blank" rel="noopener"><img alt="Before" src=""></a></figure>
        <figure class="it-job-fig" hidden><a href="#" target="_blank" rel="noopener"><img alt="After" src=""></a></figure>
      </div>
      <span class="it-job-disc it-job-disc-info" role="img" aria-label="becomes"><?= $__ibSvg('arrow') ?></span>
      <div class="it-job-col it-job-right"><form class="it-job-form" novalidate></form></div>
      <div class="it-job-col it-job-acts"><button type="button" class="it-btn it-btn-fill" data-role="primary"><?= $__ibSvg('check') ?><b></b><span></span></button><span class="it-job-m"></span><button type="button" class="it-btn it-btn-line" data-role="secondary"><?= $__ibSvg('arrow') ?><b></b><span></span></button></div>
    </div>
    <a class="it-job-anchor it-job-anchor-end" href="/portfolio.php"><span class="it-ad"><?= $__ibSvg('chart') ?></span><span>Website</span></a>
  </div></div>
  <p class="it-job-foot"><span class="it-job-n"></span> · <button type="button" class="it-job-skip">Skip for now</button></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span></p>
  <template class="it-job-tpl-ba_consent">
    <label>How they agreed <input name="note" class="it-ctl" maxlength="255" placeholder="e.g. Email from Ron 21 Sep"></label>
  </template>
  <template class="it-job-tpl-ba_offer">
    <label>Public label <input name="label" class="it-ctl" maxlength="120"></label>
    <label>Neighbourhood <input name="area" class="it-ctl" maxlength="80"></label>
    <label>Before photo description <input name="alt_before" class="it-ctl" maxlength="160"></label>
    <label>After photo description <input name="alt_after" class="it-ctl" maxlength="160"></label>
  </template>
  <script type="application/json" class="it-job-data"><?= json_encode($__ibJobs, $__ibFlags) ?></script>
</section>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job-mia.js') : '/crm/js/ittai-job-mia.js' ?>" defer></script>
