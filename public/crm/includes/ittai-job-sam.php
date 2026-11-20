<?php
/**
 * Sam's job strip — "this quote has gone quiet: follow up, or decline it", across the top of the Quotes pages in the Ittai
 * desktop shell. Included from appstack_head.php after Mia's strips, only when $itShell is on.
 *
 * Nothing is decided here: QuoteJobAdapter reads the quotes the Quotes pages already list and shapes each decision; Decline
 * posts what the quote page's Decline posts (/crm/quotes/api-quotes.php → QuoteService::decline) and Follow up opens the
 * quote, whose own Send Follow-Up still asks before it emails. /crm/js/ittai-job-mia.js (shared with Mia's strips) sends it.
 * Shown only to the owner with billing.edit, and only while ops_settings it_quote_job is on. On a quote's own page it is
 * about that quote only. Any failure renders nothing — it must never break the page.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
if ((string)($activePage ?? '') !== 'quotes') return;
try {
    require_once __DIR__ . '/nav-items.php';
    $__isUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__isHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__isUser, 'quotes') as $__isB) {
        if (($__isB['slug'] ?? '') === 'sam' && !empty($__isB['here'])) { $__isHere = true; break; }
    }
    if (!$__isHere) return;
    if (function_exists('userHasPermission') && !userHasPermission('billing.edit')) return;   // the decline endpoint asks for it
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__isDb = getDB();
    $__isDesk = new CharlieDeskService($__isDb);
    if (!$__isDesk->ready() || !$__isDesk->isOwner($__isUser)) return;
    require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/QuoteJobAdapter.php';
    $__isOne = (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'view.php') ? (int)($_GET['id'] ?? 0) : 0;
    $__isJobs = QuoteJobAdapter::jobs($__isDb, $__isOne ?: null);
    if ($__isOne) $__isJobs = array_values(array_filter($__isJobs, static function ($j) use ($__isOne) { return (int)($j['quote_id'] ?? 0) === $__isOne; }));
} catch (Throwable $__e) {
    error_log('Sam quote strip unavailable: ' . $__e->getMessage());
    return;
}
// Local-only fixture so the strip can be drawn without live data (never on production).
if (($_GET['it_job_demo'] ?? '') === '1'
    && in_array(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/QuoteJobAdapter.php';
    $__isJobs = QuoteJobAdapter::queue([[ 'id' => 93, 'quote_number' => 'QUO-2026-0093', 'title' => 'Irrigation shutdown & spring start-up', 'amount' => 1260, 'sent_at' => '2026-10-15 11:06:00',
        'email_opened_at' => '2026-10-15 11:13:00', 'view_count' => 9, 'follow_up_count' => 0, 'first_name' => 'Linda', 'last_name' => 'Nimmerrichter', 'company_name' => 'VR15-40',
        'address' => '1685 West 14th Avenue', 'contact_id' => 5]], [], [], [0, 0], new DateTimeImmutable('2026-11-10 09:00:00'));
}
if (!$__isJobs) return;

require_once __DIR__ . '/ittai-icons.php';
$__isFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$__isSvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
itIconSprite();
?>
<div class="it-job-outer"><div class="it-job-wrap is-solo">
<section class="it-job it-job-mia it-job-sam" data-head="sam" data-who="Sam" aria-label="Sam's job">
  <h2 class="it-vh it-job-tt"></h2>
  <div class="it-job-body"><div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="/crm/quotes/index.php"><span class="it-ad it-ad-fill"><?= $__isSvg('eye') ?></span><span>Quotes</span></a>
    <span class="it-job-conf is-forming" role="img"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#it-st-approval-forming"/></svg></span>
    <div class="it-job-split">
      <div class="it-job-col it-job-left"><div class="it-job-what"><b class="it-job-who"></b><span class="it-job-at"></span><p class="it-job-quote"></p><ul class="it-job-cmp" hidden></ul><a class="it-job-open" href="#" target="_blank" rel="noopener" hidden></a><p class="it-job-why"></p></div></div>
      <div class="it-job-col it-job-fig-col"><figure class="it-job-fig" hidden><img alt="" src=""></figure></div>
      <span class="it-job-disc it-job-disc-info" role="img" aria-label="becomes"><?= $__isSvg('arrow') ?></span>
      <div class="it-job-col it-job-right"><form class="it-job-form" novalidate></form></div>
      <div class="it-job-col it-job-acts"><button type="button" class="it-btn it-btn-fill" data-role="primary"><?= $__isSvg('check') ?><b></b><span></span></button><span class="it-job-m"></span><button type="button" class="it-btn it-btn-line" data-role="secondary"><?= $__isSvg('arrow') ?><b></b><span></span></button></div>
    </div>
    <a class="it-job-anchor it-job-anchor-end" href="/crm/quotes/index.php?status=declined"><span class="it-ad"><?= $__isSvg('chart') ?></span><span>Declined</span></a>
  </div></div>
  <p class="it-job-foot"><span class="it-job-n"></span> · <button type="button" class="it-job-skip">Not yet</button></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span></p>
  <template class="it-job-tpl-quiet">
    <label>If declined, why? <select name="lost_reason" class="it-ctl"><option value="">No reason</option><?php foreach (QuoteService::LOST_REASONS as $__isK => $__isL): ?><option value="<?= h($__isK) ?>"><?= h($__isL) ?></option><?php endforeach; ?></select></label>
  </template>
  <script type="application/json" class="it-job-data"><?= json_encode($__isJobs, $__isFlags) ?></script>
</section>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job-mia.js') : '/crm/js/ittai-job-mia.js' ?>" defer></script>
