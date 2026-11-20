<?php
/**
 * Mia's job strip — her decisions on the Social Posts page (reconnect, a failed post and why, a post to approve, a
 * draft to schedule), across the top of the page in the Ittai desktop shell. Included from appstack_head.php after
 * Yui's strip, only when $itShell is on.
 *
 * Nothing is decided here: MiaJobAdapter reads the posts the page already lists and shapes each decision; each button
 * carries the request the page's own button sends (posts.php approve / retry / schedule) or a link (editor, Facebook
 * reconnect), and /crm/js/ittai-job-mia.js sends it. Shown only on the Social Posts page, only to the owner, only with
 * marketing.approve (Schedule also needs marketing.edit). Any failure renders nothing — it must never break the page.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
$__imPage = (string)($activePage ?? '');
if ($__imPage !== 'social' && $__imPage !== 'listings') return;   // Posts: her posts; Listings: her connections (MiaListingJobs, off unless mia_listing_job_enabled)
try {
    require_once __DIR__ . '/nav-items.php';
    $__imUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__imHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__imUser, (string)($activePage ?? '')) as $__imB) {
        if (($__imB['slug'] ?? '') === 'mia' && !empty($__imB['here'])) { $__imHere = true; break; }
    }
    if (!$__imHere) return;
    // approve / retry / the reconnect link all ask for marketing.approve; Schedule asks for marketing.edit.
    // The Listings page lets marketing.edit or marketing.approve save (listings.php $canEdit); Posts needs approve.
    if (function_exists('userHasPermission')) {
        if ($__imPage === 'social' && !userHasPermission('marketing.approve')) return;
        if ($__imPage === 'listings' && !(userHasPermission('marketing.edit') || userHasPermission('marketing.approve'))) return;
    }
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__imDb = getDB();
    $__imDesk = new CharlieDeskService($__imDb);
    if (!$__imDesk->ready() || !$__imDesk->isOwner($__imUser)) return;
    require_once APP_ROOT . '/Modules/Marketing/Services/MiaJobAdapter.php';
    if ($__imPage === 'listings') {
        require_once APP_ROOT . '/Modules/Marketing/Services/MiaListingJobs.php';
        $__imVizData = null;
        $__imJobs = MiaListingJobs::jobs($__imDb, true);
    } else {
        $__imVizData = MiaJobAdapter::viz($__imDb);
        $__imJobs = MiaJobAdapter::jobs($__imDb, $__imUser, !function_exists('userHasPermission') || userHasPermission('marketing.edit'));
    }
} catch (Throwable $__e) {
    error_log('Mia job strip unavailable: ' . $__e->getMessage());
    return;
}
// Local-only fixture so the strip can be drawn without live data (never on production).
if (($_GET['it_job_demo'] ?? '') === '1'
    && in_array(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {   // the Host header is the client's; the address is not
  if ($__imPage === 'listings') {
    require_once APP_ROOT . '/Modules/Marketing/Services/MiaListingJobs.php';
    $__imM = ['name' => 'Mowology Landscaping', 'address' => '2845 West 15th Ave Vancouver V6K 3A1', 'phone' => '778 846 9273', 'website' => 'mowology.ca', 'email' => '', 'hours' => 'Mon–Fri 08:00–17:00', 'categories' => 'Landscaper; Lawn care service; Snow removal service'];
    $__imR = static function (array $o) { return $o + ['id' => 1, 'site_key' => 'x', 'site_name' => 'Site', 'claim_url' => 'https://example.test/claim', 'status' => 'unknown', 'profile_url' => null, 'review_count' => null, 'rating' => null,
        'listed_name' => null, 'listed_address' => null, 'listed_phone' => null, 'listed_website' => null, 'notes' => null, 'is_optional' => 0, 'last_checked' => null, 'stale' => true, 'nap' => ['status' => 'unchecked', 'fields' => [], 'issues' => []]]; };
    $__imS = ['status' => 'connected', 'reason' => '', 'label' => '', 'account' => ''];
    $__imJobs = MiaListingJobs::queue($__imM, [
        $__imR(['id' => 9, 'site_key' => 'gbp', 'site_name' => 'Google Business Profile', 'claim_url' => 'https://business.google.com/', 'status' => 'verified', 'last_checked' => '2026-09-28']),
        $__imR(['id' => 2, 'site_key' => 'apple', 'site_name' => 'Apple Business Connect']),
        $__imR(['id' => 4, 'site_key' => 'facebook', 'site_name' => 'Facebook', 'status' => 'claimed', 'profile_url' => 'https://example.test/fb']),
        $__imR(['id' => 8, 'site_key' => 'yelp', 'site_name' => 'Yelp', 'status' => 'claimed', 'profile_url' => 'https://example.test/yelp', 'listed_name' => 'Mowology Landscaping', 'listed_address' => '1 Other St Vancouver', 'listed_phone' => '778-846-9273', 'listed_website' => 'mowology.ca', 'last_checked' => '2026-11-01', 'nap' => ['status' => 'mismatch', 'fields' => [], 'issues' => ['x']]]),
    ], ['name' => 'Mowology Landscaping', 'address' => '2845 West 15th Ave, Vancouver, BC, V6K 3A1', 'phone' => '604 555 0100', 'website' => 'https://mowology.ca/', 'hours' => 'Mon–Fri 08:00–17:00', 'categories' => 'Landscaper; Lawn care service', 'at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')],
        [4 => ['called' => 3, 'agreed' => 3], 8 => ['called' => 5, 'agreed' => 5]],
        ['facebook' => $__imS, 'instagram' => $__imS, 'gbp' => $__imS, 'needs_reconnect' => false], false, true, new DateTimeImmutable('now'));
  } else {
    $__imJobs = MiaJobAdapter::queue(
        ['needs_reconnect' => false, 'message' => '', 'action_url' => SocialConnectionState::RECONNECT_URL],
        [['id' => 1, 'caption' => 'Crew on-site. Work getting done. Strata and commercial grounds maintenance across Vancouver.', 'platforms' => 'facebook,instagram', 'thumb_path' => '',
          'detail' => 'Meta API error [Instagram media container creation] (code 36003): The aspect ratio is not supported.']],
        [['id' => 2, 'caption' => 'We talk a lot about lawns. Clean edges. Fresh cuts. Consistent service.', 'platforms' => 'facebook,instagram', 'scheduled_at' => null, 'auto_generated' => 1, 'created_by_name' => 'Mia', 'thumb_path' => '']],
        [['id' => 3, 'caption' => 'Fall cleanup is booking now.', 'platforms' => 'facebook', 'auto_generated' => 1, 'thumb_path' => '']],
        ['crew' => [5, 5], 'hand' => [0, 0]], true, new DateTimeImmutable('now'));
    $__imVizData = MiaJobAdapter::shapeViz(['2026-11' => 1, '2026-12' => 2, '2027-01' => 1],
        [['caption' => 'We talk a lot about lawns. Clean edges.'], ['caption' => 'Mowing season starts'], ['caption' => 'Aeration time'], ['caption' => 'Spring cleanup booking']], new DateTimeImmutable('now'));
  }
}
if (!$__imJobs) return;
if ($__imPage === 'social') $__miaStripShown = true;   // the page's own red reconnect banner stays quiet while the strip asks

require_once __DIR__ . '/ittai-icons.php';
$__imFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$__imSvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
// The chart the line runs on into: ready posts and posts held for their season, by month (same card as Penny's graph).
$__imViz = static function (?array $v) use ($__imSvg): string {
    if (!$v) return '';
    $w = 288; $h = 120; $pad = 8; $n = count($v['months']); $slot = (int)floor(($w - $pad * 2) / $n); $bw = max(4, (int)floor($slot / 2) - 2);
    $bars = '';
    foreach ($v['months'] as $i => $m) {
        $x = $pad + $i * $slot;
        foreach ([['ready', 'it-viz-now'], ['held', 'it-viz-bar']] as $j => [$k, $cls]) {
            $bh = (int)round(($m[$k] / $v['max']) * 80);
            if ($bh < 1 && $m[$k] > 0) $bh = 1;
            if ($m[$k] > 0) $bars .= '<rect x="' . ($x + $j * ($bw + 2)) . '" y="' . (96 - $bh) . '" width="' . $bw . '" height="' . max($bh, 2) . '" rx="3" class="' . $cls . '"><title>' . h($m['label'] . ': ' . $m[$k] . ($k === 'held' ? ' held for their season' : ' ready to go out')) . '</title></rect>';
        }
        $bars .= '<text x="' . ($x + $bw) . '" y="112" text-anchor="middle" class="it-viz-lbl' . ($m['now'] ? ' it-viz-lbl-now' : '') . '">' . h(substr($m['label'], 0, 1)) . '</text>';
    }
    $next = $v['next'] ? ' <span>Next season opens ' . h($v['next']['label']) . ': ' . (int)$v['next']['n'] . ' post' . ($v['next']['n'] === 1 ? '' : 's') . '</span>' : '';
    return '<figure class="it-job-viz it-job-viz-mia"><span class="it-ad it-ad-sm it-job-viz-node" aria-hidden="true">' . $__imSvg('chart') . '</span><figcaption><b>Posts by month</b><span>' . (int)$v['ready'] . ' ready · ' . (int)$v['held'] . ' held</span></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="Posts ready to go out and posts held for their season, next twelve months">' . $bars . '</svg>'
        . '<p class="it-job-viz-gst"><b>' . (int)$v['held'] . '</b> held for their season' . $next . '</p></figure>';
};
itIconSprite();
?>
<div class="it-job-outer"><div class="it-job-wrap">
<section class="it-job it-job-mia" data-head="mia" aria-label="Mia's job">
  <h2 class="it-vh it-job-tt"></h2>
  <div class="it-job-body"><div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="<?= $__imPage === 'listings' ? '/crm/marketing/listings.php' : '/crm/marketing/social.php' ?>"><span class="it-ad it-ad-fill"><?= $__imSvg($__imPage === 'listings' ? 'check' : 'eye') ?></span><span><?= $__imPage === 'listings' ? 'Master' : 'Posts' ?></span></a>
    <span class="it-job-conf is-forming" role="img"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#it-st-approval-forming"/></svg></span>
    <div class="it-job-split">
      <div class="it-job-col it-job-left"><div class="it-job-what"><b class="it-job-who"></b><span class="it-job-at"></span><p class="it-job-quote"></p><ul class="it-job-cmp" hidden></ul><a class="it-job-open" href="#" target="_blank" rel="noopener" hidden></a><p class="it-job-why"></p></div></div>
      <div class="it-job-col it-job-fig-col"><figure class="it-job-fig" hidden><img alt="" src=""></figure></div>
      <span class="it-job-disc it-job-disc-info" role="img" aria-label="becomes"><?= $__imSvg('arrow') ?></span>
      <div class="it-job-col it-job-right"><form class="it-job-form" novalidate></form></div>
      <div class="it-job-col it-job-acts"><button type="button" class="it-btn it-btn-fill" data-role="primary"><?= $__imSvg('check') ?><b></b><span></span></button><span class="it-job-m"></span><button type="button" class="it-btn it-btn-line" data-role="secondary"><?= $__imSvg('arrow') ?><b></b><span></span></button></div>
    </div>
    <?php if ($__imPage === 'listings'): ?><a class="it-job-anchor it-job-anchor-end" href="/crm/marketing/mia.php"><span class="it-ad"><?= $__imSvg('eye') ?></span><span>Mia</span></a>
    <?php else: ?><a class="it-job-anchor it-job-anchor-end" href="/crm/marketing/social-calendar.php"><span class="it-ad"><?= $__imSvg('chart') ?></span><span>Calendar</span></a><?php endif; ?>
  </div></div>
  <p class="it-job-foot"><span class="it-job-n"></span> · <button type="button" class="it-job-skip">Skip for now</button></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span></p>
  <template class="it-job-tpl-listing_enter">
    <label>Name <input name="listed_name" class="it-ctl"></label>
    <label>Address <input name="listed_address" class="it-ctl"></label>
    <label>Phone <input name="listed_phone" class="it-ctl"></label>
    <label>Website <input name="listed_website" class="it-ctl"></label>
  </template>
  <template class="it-job-tpl-schedule">
    <label>When it goes out <input name="scheduled_at" type="datetime-local" class="it-ctl"></label>
  </template>
  <script type="application/json" class="it-job-data"><?= json_encode($__imJobs, $__imFlags) ?></script>
</section>
<?= $__imViz($__imVizData ?? null) ?>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job-mia.js') : '/crm/js/ittai-job-mia.js' ?>" defer></script>
